<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Manager;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Drivers\ArrayCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\CacheCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\RedisCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Services\AttributeCircuitBreakerRunner;
use LaravelResilienceKit\CircuitBreaker\Services\CircuitBreakerService;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitBreakerProxy;
use UnexpectedValueException;

/**
 * Ponto de entrada do módulo de Circuit Breaker (acessível via `Resilience`).
 *
 * Funciona como um Manager do Laravel: resolve o driver de armazenamento
 * (`array`, `cache`, `redis`) a partir de `resilience-kit.default_driver` e
 * permite registrar drivers customizados com {@see self::extend()}.
 */
class CircuitBreakerManager extends Manager
{
    /** @var array<string, true> Circuitos já registrados no índice nesta execução. */
    private array $registered = [];

    private ?AttributeCircuitBreakerRunner $runner = null;

    /**
     * Cria um Circuit Breaker nomeado.
     *
     * Precedência dos limites:
     *  1. defaults de `resilience-kit.circuit_breaker.*`;
     *  2. entrada em `resilience-kit.circuits.{name}` (chaves omitidas herdam o global);
     *  3. valores já persistidos no store (API fluente / atributo).
     *
     * O circuito é registrado no índice do driver (uma vez por processo) para
     * aparecer no dashboard mesmo antes da primeira execução.
     *
     * @param  string  $name  Nome único do circuito.
     */
    public function circuitBreaker(string $name): CircuitBreakerInterface
    {
        $store = $this->store();

        $registryKey = $this->getDefaultDriver().'|'.$name;

        if (! isset($this->registered[$registryKey])) {
            $store->register($name);
            $this->registered[$registryKey] = true;
        }

        return new CircuitBreakerService(
            $name,
            $store,
            $this->container->make(Dispatcher::class),
            $this->container,
            $this->limitFor($name, 'failure_threshold', 5),
            $this->limitFor($name, 'recovery_timeout', 30),
            $this->limitFor($name, 'half_open_trials', 3),
        );
    }

    /**
     * Envolve um objeto em um proxy que aplica `#[WithCircuitBreaker]` aos seus métodos.
     *
     * @template TTarget of object
     *
     * @param  TTarget  $target
     * @return CircuitBreakerProxy<TTarget>
     */
    public function protect(object $target): CircuitBreakerProxy
    {
        return new CircuitBreakerProxy($target, $this->runner());
    }

    /**
     * Invoca um método aplicando `#[WithCircuitBreaker]`, sem criar um proxy.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function call(object $target, string $method, array $arguments = []): mixed
    {
        return $this->runner()->call($target, $method, $arguments);
    }

    /**
     * Retorna o store de persistência do driver informado (ou do padrão).
     *
     * @param  string|null  $driver  `array`, `cache`, `redis` ou driver customizado.
     */
    public function store(?string $driver = null): CircuitStoreInterface
    {
        $store = $this->driver($driver);

        if (! $store instanceof CircuitStoreInterface) {
            throw new UnexpectedValueException(
                sprintf('Resilience driver [%s] must implement %s.', $driver ?? $this->getDefaultDriver(), CircuitStoreInterface::class)
            );
        }

        return $store;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('resilience-kit.default_driver', 'redis');

        return is_string($driver) ? $driver : 'redis';
    }

    /**
     * Cria o driver em memória (testes isolados).
     */
    protected function createArrayDriver(): CircuitStoreInterface
    {
        return new ArrayCircuitStore;
    }

    /**
     * Cria o driver baseado no Cache do Laravel, com locks atômicos.
     */
    protected function createCacheDriver(): CircuitStoreInterface
    {
        $cacheStore = $this->config->get('resilience-kit.circuit_breaker.cache_store');

        /** @var CacheFactory $cache */
        $cache = $this->container->make('cache');

        /** @var Repository $repository */
        $repository = $cache->store(is_string($cacheStore) ? $cacheStore : null);

        return new CacheCircuitStore($repository, $this->prefix());
    }

    /**
     * Cria o driver Redis, com scripts Lua atômicos.
     */
    protected function createRedisDriver(): CircuitStoreInterface
    {
        $connection = $this->config->get('resilience-kit.circuit_breaker.redis_connection');

        /** @var RedisManager $redis */
        $redis = $this->container->make('redis');

        return new RedisCircuitStore(
            $redis->connection(is_string($connection) ? $connection : null),
            $this->prefix(),
        );
    }

    /**
     * Runner de atributos, criado sob demanda e reutilizado (mantém o cache de Reflection).
     */
    private function runner(): AttributeCircuitBreakerRunner
    {
        return $this->runner ??= new AttributeCircuitBreakerRunner($this);
    }

    /**
     * Prefixo das chaves definido na configuração.
     */
    private function prefix(): string
    {
        $prefix = $this->config->get('resilience-kit.circuit_breaker.prefix', 'resilience:cb:');

        return is_string($prefix) ? $prefix : 'resilience:cb:';
    }

    /**
     * Resolve um limite: entrada nomeada em `circuits.{name}` ou default global.
     */
    private function limitFor(string $circuit, string $key, int $default): int
    {
        $named = $this->namedCircuitConfig($circuit)[$key] ?? null;

        if (is_numeric($named)) {
            return (int) $named;
        }

        return $this->intConfig($key, $default);
    }

    /**
     * Lê a entrada de `resilience-kit.circuits.{name}`, se for um array.
     *
     * @return array<string, mixed>
     */
    private function namedCircuitConfig(string $circuit): array
    {
        $named = $this->config->get('resilience-kit.circuits.'.$circuit);

        return is_array($named) ? $named : [];
    }

    /**
     * Lê um inteiro de `resilience-kit.circuit_breaker.{key}`.
     */
    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get('resilience-kit.circuit_breaker.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
