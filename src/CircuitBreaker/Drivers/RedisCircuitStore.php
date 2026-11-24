<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Drivers;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitKeys;

/**
 * Driver Redis com atomicidade garantida.
 *
 * Operações simples usam comandos atômicos nativos (`INCR`, `DEL`, `SADD`).
 * Operações compostas (compare-and-set de estado + efeitos colaterais, e a
 * gravação da janela deslizante) são executadas como scripts Lua, que o Redis
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
     * KEYS: [1] state, [2] window, [3] half_open_successes, [4] last_tripped_at
     * ARGV: [1] estado esperado, [2] novo estado, [3] timestamp atual, [4] forçar ('1'|'0')
     *
     * Retorna 1 se a transição foi efetuada, 0 caso contrário.
     * OPEN preserva a janela. CLOSED apaga a janela.
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
     * Empilha um resultado na janela e devolve {chamadas, falhas}.
     *
     * KEYS: [1] window, [2] last_failure_reason
     * ARGV: [1] resultado ('1' falha, '0' sucesso), [2] tamanho da janela, [3] motivo ('' = não registrar)
     */
    private const string RECORD_OUTCOME_SCRIPT = <<<'LUA'
local size = tonumber(ARGV[2])
if not size or size < 1 then
    size = 1
end

redis.call('LPUSH', KEYS[1], ARGV[1])
redis.call('LTRIM', KEYS[1], 0, size - 1)

if ARGV[1] == '1' and ARGV[3] ~= '' then
    redis.call('SET', KEYS[2], ARGV[3])
end

local items = redis.call('LRANGE', KEYS[1], 0, -1)
local failures = 0
for i = 1, #items do
    if items[i] == '1' then
        failures = failures + 1
    end
end

return {#items, failures}
LUA;

    /**
     * Apara a janela ao tamanho configurado e devolve {chamadas, falhas}.
     *
     * KEYS: [1] window
     * ARGV: [1] tamanho da janela
     */
    private const string READ_WINDOW_SCRIPT = <<<'LUA'
local size = tonumber(ARGV[1])
if not size or size < 1 then
    size = 1
end

redis.call('LTRIM', KEYS[1], 0, size - 1)

local items = redis.call('LRANGE', KEYS[1], 0, -1)
local failures = 0
for i = 1, #items do
    if items[i] == '1' then
        failures = failures + 1
    end
end

return {#items, failures}
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
    public function recordOutcome(string $circuit, bool $failure, int $windowSize, ?string $reason = null): CallWindow
    {
        $this->register($circuit);

        $result = $this->evaluate(
            self::RECORD_OUTCOME_SCRIPT,
            [$this->keys->window($circuit), $this->keys->lastFailureReason($circuit)],
            [$failure ? '1' : '0', (string) max(1, $windowSize), $failure ? ($reason ?? '') : ''],
        );

        return $this->windowFromResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function getWindow(string $circuit, int $windowSize): CallWindow
    {
        $result = $this->evaluate(
            self::READ_WINDOW_SCRIPT,
            [$this->keys->window($circuit)],
            [(string) max(1, $windowSize)],
        );

        return $this->windowFromResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function rememberFailureReason(string $circuit, string $reason): void
    {
        $this->register($circuit);

        if ($reason === '') {
            return;
        }

        $this->connection->command('set', [$this->keys->lastFailureReason($circuit), $reason]);
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
     * {@inheritDoc}
     */
    public function forget(string $circuit): void
    {
        $this->connection->command('srem', [$this->keys->index(), $circuit]);
        $this->connection->command('del', [
            ...$this->keys->all($circuit),
            ...$this->keys->configuration($circuit),
            $this->keys->lock($circuit),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function setSlidingWindowSize(string $circuit, int $size): void
    {
        $this->writeConfig($circuit, $this->keys->slidingWindowSize($circuit), $size);
    }

    /**
     * {@inheritDoc}
     */
    public function getSlidingWindowSize(string $circuit): ?int
    {
        return $this->readOptionalInt($this->keys->slidingWindowSize($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function forgetSlidingWindowSize(string $circuit): void
    {
        $this->connection->command('del', [$this->keys->slidingWindowSize($circuit)]);
    }

    /**
     * {@inheritDoc}
     */
    public function setMinimumCalls(string $circuit, int $calls): void
    {
        $this->writeConfig($circuit, $this->keys->minimumCalls($circuit), $calls);
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimumCalls(string $circuit): ?int
    {
        return $this->readOptionalInt($this->keys->minimumCalls($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function forgetMinimumCalls(string $circuit): void
    {
        $this->connection->command('del', [$this->keys->minimumCalls($circuit)]);
    }

    /**
     * {@inheritDoc}
     */
    public function setFailureRateThreshold(string $circuit, int $percentage): void
    {
        $this->writeConfig($circuit, $this->keys->failureRateThreshold($circuit), $percentage);
    }

    /**
     * {@inheritDoc}
     */
    public function getFailureRateThreshold(string $circuit): ?int
    {
        return $this->readOptionalInt($this->keys->failureRateThreshold($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function forgetFailureRateThreshold(string $circuit): void
    {
        $this->connection->command('del', [$this->keys->failureRateThreshold($circuit)]);
    }

    /**
     * {@inheritDoc}
     */
    public function setRecoveryTimeout(string $circuit, int $seconds): void
    {
        $this->writeConfig($circuit, $this->keys->recoveryTimeout($circuit), $seconds);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecoveryTimeout(string $circuit): ?int
    {
        return $this->readOptionalInt($this->keys->recoveryTimeout($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function forgetRecoveryTimeout(string $circuit): void
    {
        $this->connection->command('del', [$this->keys->recoveryTimeout($circuit)]);
    }

    /**
     * {@inheritDoc}
     */
    public function setHalfOpenTrials(string $circuit, int $trials): void
    {
        $this->writeConfig($circuit, $this->keys->halfOpenTrials($circuit), $trials);
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenTrials(string $circuit): ?int
    {
        return $this->readOptionalInt($this->keys->halfOpenTrials($circuit));
    }

    /**
     * {@inheritDoc}
     */
    public function forgetHalfOpenTrials(string $circuit): void
    {
        $this->connection->command('del', [$this->keys->halfOpenTrials($circuit)]);
    }

    /**
     * Grava um limite do circuito. `SET` é atômico; o valor não entra no reset.
     */
    private function writeConfig(string $circuit, string $key, int $value): void
    {
        $this->register($circuit);
        $this->connection->command('set', [$key, (string) $value]);
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
                $this->keys->window($circuit),
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
     * Converte o retorno `{chamadas, falhas}` do Lua em uma janela.
     *
     * Um retorno inesperado (conexão vazia, script antigo) vira janela vazia.
     */
    private function windowFromResult(mixed $result): CallWindow
    {
        if (! is_array($result) || ! isset($result[0], $result[1]) || ! is_numeric($result[0]) || ! is_numeric($result[1])) {
            return CallWindow::empty();
        }

        return new CallWindow(max(0, (int) $result[0]), max(0, (int) $result[1]));
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
        return $this->readOptionalInt($key) ?? 0;
    }

    /**
     * Lê um inteiro opcional. Ausência ou valor não numérico viram `null`.
     */
    private function readOptionalInt(string $key): ?int
    {
        $value = $this->read($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
