<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Drivers;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitKeys;

/**
 * Driver baseado em qualquer store do Cache do Laravel que suporte locks
 * atômicos (redis, memcached, database, file, dynamodb, array...).
 *
 * Como a API de Cache não oferece operações compostas atômicas, toda
 * operação de escrita é serializada por um lock distribuído por circuito
 * (`{prefix}{circuit}:lock`). Para máxima performance em produção, prefira o
 * {@see RedisCircuitStore}, que usa scripts Lua.
 */
final readonly class CacheCircuitStore implements CircuitStoreInterface
{
    private CircuitKeys $keys;

    private LockProvider $locks;

    /**
     * @param  Repository  $cache  Repositório de cache; seu store DEVE implementar {@see LockProvider}.
     * @param  string  $prefix  Prefixo das chaves.
     * @param  int  $lockSeconds  Tempo máximo de posse e de espera do lock, em segundos.
     *
     * @throws InvalidArgumentException Se o store não suportar locks atômicos.
     */
    public function __construct(
        private Repository $cache,
        string $prefix = 'resilience:cb:',
        private int $lockSeconds = 5,
    ) {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidArgumentException(
                'O store de cache informado não suporta locks atômicos, requisito do CacheCircuitStore.'
            );
        }

        $this->locks = $store;
        $this->keys = new CircuitKeys($prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function register(string $circuit): void
    {
        if (in_array($circuit, $this->readIndex(), true)) {
            return;
        }

        $this->withLock($this->keys->indexLock(), function () use ($circuit): void {
            $index = $this->readIndex();

            if (! in_array($circuit, $index, true)) {
                $index[] = $circuit;
                sort($index);
                $this->cache->forever($this->keys->index(), $index);
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function circuits(): array
    {
        return $this->readIndex();
    }

    /**
     * {@inheritDoc}
     */
    public function getState(string $circuit): CircuitState
    {
        return CircuitState::fromStored($this->cache->get($this->keys->state($circuit)));
    }

    /**
     * {@inheritDoc}
     */
    public function transitionState(string $circuit, CircuitState $from, CircuitState $to): bool
    {
        $this->register($circuit);

        return $this->withCircuitLock($circuit, function () use ($circuit, $from, $to): bool {
            if ($this->getState($circuit) !== $from) {
                return false;
            }

            $this->apply($circuit, $to);

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function forceState(string $circuit, CircuitState $state): void
    {
        $this->register($circuit);

        $this->withCircuitLock($circuit, function () use ($circuit, $state): void {
            $this->apply($circuit, $state);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function incrementFailures(string $circuit, ?string $reason = null): int
    {
        $this->register($circuit);

        return $this->withCircuitLock($circuit, function () use ($circuit, $reason): int {
            $failures = $this->getFailures($circuit) + 1;
            $this->cache->forever($this->keys->failures($circuit), $failures);

            if ($reason !== null && $reason !== '') {
                $this->cache->forever($this->keys->lastFailureReason($circuit), $reason);
            }

            return $failures;
        });
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

        return $this->withCircuitLock($circuit, function () use ($circuit): int {
            $successes = $this->getHalfOpenSuccesses($circuit) + 1;
            $this->cache->forever($this->keys->halfOpenSuccesses($circuit), $successes);

            return $successes;
        });
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
        $value = $this->cache->get($this->keys->lastTrippedAt($circuit));

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastFailureReason(string $circuit): ?string
    {
        $value = $this->cache->get($this->keys->lastFailureReason($circuit));

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $circuit): void
    {
        $this->withCircuitLock($circuit, function () use ($circuit): void {
            foreach ($this->keys->all($circuit) as $key) {
                $this->cache->forget($key);
            }
        });
    }

    /**
     * Aplica o novo estado e seus efeitos colaterais.
     *
     * DEVE ser chamado com o lock do circuito adquirido.
     */
    private function apply(string $circuit, CircuitState $state): void
    {
        $this->cache->forever($this->keys->state($circuit), $state->value);

        match ($state) {
            CircuitState::OPEN => $this->applyOpen($circuit),
            CircuitState::HALF_OPEN => $this->cache->forever($this->keys->halfOpenSuccesses($circuit), 0),
            CircuitState::CLOSED => $this->applyClosed($circuit),
        };
    }

    private function applyOpen(string $circuit): void
    {
        $this->cache->forever($this->keys->lastTrippedAt($circuit), Carbon::now()->getTimestamp());
        $this->cache->forever($this->keys->halfOpenSuccesses($circuit), 0);
    }

    private function applyClosed(string $circuit): void
    {
        $this->cache->forget($this->keys->failures($circuit));
        $this->cache->forget($this->keys->halfOpenSuccesses($circuit));
    }

    /**
     * @return list<string>
     */
    private function readIndex(): array
    {
        $index = $this->cache->get($this->keys->index());

        if (! is_array($index)) {
            return [];
        }

        return array_values(array_filter($index, is_string(...)));
    }

    private function readInt(string $key): int
    {
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Executa o callback sob o lock do circuito.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withCircuitLock(string $circuit, Closure $callback): mixed
    {
        return $this->withLock($this->keys->lock($circuit), $callback);
    }

    /**
     * Executa o callback sob um lock nomeado, aguardando até `$lockSeconds`.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws LockTimeoutException Se o lock não for obtido a tempo.
     */
    private function withLock(string $name, Closure $callback): mixed
    {
        /** @var TReturn */
        return $this->locks->lock($name, $this->lockSeconds)->block($this->lockSeconds, $callback);
    }
}
