<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Drivers\ArrayCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\CacheCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\RedisCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitKeys;
use Redis;
use Throwable;

/**
 * Fábrica de drivers para os testes, com limpeza automática das chaves Redis.
 */
final class StoreFactory
{
    /** @var list<array{store: CircuitStoreInterface, keys: CircuitKeys, connection: Connection}> */
    private static array $redisStores = [];

    /**
     * Cria um driver novo e isolado.
     *
     * @param  'array'|'cache'|'redis'  $driver
     */
    public static function make(string $driver): CircuitStoreInterface
    {
        return match ($driver) {
            'array' => new ArrayCircuitStore,
            'cache' => new CacheCircuitStore(new Repository(new ArrayStore)),
            'redis' => self::makeRedis(),
            default => throw new \InvalidArgumentException("Driver desconhecido: {$driver}"),
        };
    }

    /**
     * Indica se o driver pode ser exercitado no ambiente atual.
     */
    public static function isAvailable(string $driver): bool
    {
        return $driver !== 'redis' || self::redisAvailable();
    }

    /**
     * Indica se há um Redis acessível via extensão phpredis.
     */
    public static function redisAvailable(): bool
    {
        if (! extension_loaded('redis')) {
            return false;
        }

        try {
            self::rawConnection()->command('ping');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Conexão phpredis "crua" (sem prefixo de chaves do Laravel), idêntica à
     * usada pelos processos de teste de concorrência.
     */
    public static function rawConnection(): PhpRedisConnection
    {
        $client = new Redis;
        $client->connect(self::host(), self::port(), 2.0);

        return new PhpRedisConnection($client);
    }

    public static function host(): string
    {
        $host = getenv('REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    public static function port(): int
    {
        $port = getenv('REDIS_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 6379;
    }

    /**
     * Gera um prefixo único para isolar cada teste no Redis.
     */
    public static function uniquePrefix(): string
    {
        return 'resilience:test:'.bin2hex(random_bytes(6)).':';
    }

    /**
     * Cria um RedisCircuitStore isolado por prefixo único.
     */
    public static function makeRedis(?string $prefix = null): RedisCircuitStore
    {
        $prefix ??= self::uniquePrefix();
        $connection = self::rawConnection();
        $store = new RedisCircuitStore($connection, $prefix);

        self::$redisStores[] = ['store' => $store, 'keys' => new CircuitKeys($prefix), 'connection' => $connection];

        return $store;
    }

    /**
     * Remove todas as chaves criadas pelos stores Redis dos testes.
     */
    public static function cleanup(): void
    {
        foreach (self::$redisStores as ['store' => $store, 'keys' => $keys, 'connection' => $connection]) {
            foreach ($store->circuits() as $circuit) {
                $store->reset($circuit);
                $connection->command('del', $keys->configuration($circuit));
            }

            $connection->command('del', [$keys->index()]);
        }

        self::$redisStores = [];
    }
}
