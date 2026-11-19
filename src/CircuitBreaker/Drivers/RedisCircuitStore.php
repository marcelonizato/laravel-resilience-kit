<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Drivers;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitKeys;

/**
 * Driver Redis com atomicidade garantida.
 *
 * Operações simples usam comandos atômicos nativos (`INCR`, `DEL`, `SADD`).
 * Operações compostas (compare-and-set de estado + efeitos colaterais, e
 * incremento de falhas + motivo) são executadas como scripts Lua, que o Redis
 * processa de forma indivisível, eliminando race conditions entre processos e
 * servidores.
 *
 * O timestamp da abertura é fornecido pelo PHP (e não pelo `TIME` do Redis)
 * para que `Carbon::setTestNow()` e `travel()` funcionem nos testes.
 */
final readonly class RedisCircuitStore implements CircuitStoreInterface
{
    /**
     * Compare-and-set de estado com efeitos colaterais.
     *
     * KEYS: [1] state, [2] failures, [3] half_open_successes, [4] last_tripped_at
     * ARGV: [1] estado esperado, [2] novo estado, [3] timestamp atual, [4] forçar ('1'|'0')
     *
     * Retorna 1 se a transição foi efetuada, 0 caso contrário.
     */
    private const string TRANSITION_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    current = 'closed'
end

if ARGV[4] ~= '1' and current ~= ARGV[1] then
    return 0
end

redis.call('SET', KEYS[1], ARGV[2])

if ARGV[2] == 'open' then
    redis.call('SET', KEYS[4], ARGV[3])
    redis.call('SET', KEYS[3], 0)
elseif ARGV[2] == 'half_open' then
    redis.call('SET', KEYS[3], 0)
else
    redis.call('DEL', KEYS[2], KEYS[3])
end

return 1
LUA;

    /**
     * Incrementa o contador de falhas e registra o motivo (se houver).
     *
     * KEYS: [1] failures, [2] last_failure_reason
     * ARGV: [1] motivo ('' = não registrar)
     *
     * Retorna o novo valor do contador.
     */
    private const string INCREMENT_FAILURES_SCRIPT = <<<'LUA'
local failures = redis.call('INCR', KEYS[1])

if ARGV[1] ~= '' then
    redis.call('SET', KEYS[2], ARGV[1])
end

return failures
LUA;

    private CircuitKeys $keys;

    /**
     * @param  Connection  $connection  Conexão Redis do Laravel (phpredis ou predis).
     * @param  string  $prefix  Prefixo das chaves.
     */
    public function __construct(
        private Connection $connection,
        string $prefix = 'resilience:cb:',
    ) {
        $this->keys = new CircuitKeys($prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function register(string $circuit): void
    {
        $this->connection->command('sadd', [$this->keys->index(), $circuit]);
    }

    /**
     * {@inheritDoc}
     */
    public function circuits(): array
    {
        $members = $this->connection->command('smembers', [$this->keys->index()]);

        if (! is_array($members)) {
            return [];
        }

        $names = array_values(array_map(strval(...), array_filter($members, is_string(...))));
        sort($names);

        return $names;
    }

    /**
     * {@inheritDoc}
     */
    public function getState(string $circuit): CircuitState
    {
        return CircuitState::fromStored($this->read($this->keys->state($circuit)));
    }

    /**
     * {@inheritDoc}
     */
    public function transitionState(string $circuit, CircuitState $from, CircuitState $to): bool
    {
        return $this->runTransition($circuit, $from, $to, force: false);
    }

    /**
     * {@inheritDoc}
     */
    public function forceState(string $circuit, CircuitState $state): void
    {
        $this->runTransition($circuit, $state, $state, force: true);
    }

    /**
     * {@inheritDoc}
     */
    public function incrementFailures(string $circuit, ?string $reason = null): int
    {
        $this->register($circuit);

        $result = $this->evaluate(
            self::INCREMENT_FAILURES_SCRIPT,
            [$this->keys->failures($circuit), $this->keys->lastFailureReason($circuit)],
            [$reason ?? ''],
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getFailures(string $circuit): int
    {
        return $this->readInt($this->keys->failures($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function incrementHalfOpenSuccesses(string $circuit): int
    {
        $this->register($circuit);

        $result = $this->connection->command('incr', [$this->keys->halfOpenSuccesses($circuit)]);

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenSuccesses(string $circuit): int
    {
        return $this->readInt($this->keys->halfOpenSuccesses($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function getLastTrippedAt(string $circuit): ?int
    {
        $value = $this->read($this->keys->lastTrippedAt($circuit));

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastFailureReason(string $circuit): ?string
    {
        $value = $this->read($this->keys->lastFailureReason($circuit));

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $circuit): void
    {
        $this->connection->command('del', $this->keys->all($circuit));
    }

    /**
     * Executa o script Lua de transição de estado.
     */
    private function runTransition(string $circuit, CircuitState $from, CircuitState $to, bool $force): bool
    {
        $this->register($circuit);

        $result = $this->evaluate(
            self::TRANSITION_SCRIPT,
            [
                $this->keys->state($circuit),
                $this->keys->failures($circuit),
                $this->keys->halfOpenSuccesses($circuit),
                $this->keys->lastTrippedAt($circuit),
            ],
            [
                $from->value,
                $to->value,
                (string) Carbon::now()->getTimestamp(),
                $force ? '1' : '0',
            ],
        );

        return $result === 1 || $result === '1';
    }

    /**
     * Executa um script Lua de forma atômica.
     *
     * A assinatura de `EVAL` difere entre os clientes: o phpredis recebe
     * `(script, args, numKeys)` e o predis `(script, numKeys, ...args)`. O
     * {@see PhpRedisConnection} do Laravel já normaliza para
     * `(script, numKeys, ...args)`; para os demais clientes o comando é
     * repassado no formato do predis.
     *
     * @param  list<string>  $keys  Chaves acessadas (KEYS).
     * @param  list<string>  $arguments  Argumentos adicionais (ARGV).
     */
    private function evaluate(string $script, array $keys, array $arguments): mixed
    {
        if ($this->connection instanceof PhpRedisConnection) {
            return $this->connection->eval($script, count($keys), ...$keys, ...$arguments);
        }

        return $this->connection->command('eval', [$script, count($keys), ...$keys, ...$arguments]);
    }

    /**
     * Lê uma chave normalizando a ausência (`false`/`null`) para `null`.
     */
    private function read(string $key): mixed
    {
        $value = $this->connection->command('get', [$key]);

        return $value === false ? null : $value;
    }

    private function readInt(string $key): int
    {
        $value = $this->read($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
