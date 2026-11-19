<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelResilienceKit\CircuitBreaker\CircuitBreakerManager;

/**
 * Facade do Laravel Resilience Kit.
 *
 * @method static \LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface circuitBreaker(string $name)
 * @method static \LaravelResilienceKit\CircuitBreaker\Support\CircuitBreakerProxy<object> protect(object $target)
 * @method static mixed call(object $target, string $method, array<int|string, mixed> $arguments = [])
 * @method static \LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface store(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static CircuitBreakerManager extend(string $driver, \Closure $callback)
 *
 * @see CircuitBreakerManager
 */
class Resilience extends Facade
{
    /**
     * Nome do binding no container.
     */
    protected static function getFacadeAccessor(): string
    {
        return CircuitBreakerManager::class;
    }
}
