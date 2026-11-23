<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\CircuitBreakerManager;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Drivers\ArrayCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\CacheCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Drivers\RedisCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\Facades\Resilience;
use LaravelResilienceKit\Tests\Support\StoreFactory;

it('binds the manager as a singleton with the "resilience" alias', function (): void {
    expect(app(CircuitBreakerManager::class))->toBe(app(CircuitBreakerManager::class))
        ->and(app('resilience'))->toBe(app(CircuitBreakerManager::class));
});

it('resolves the facade to the manager', function (): void {
    expect(Resilience::getFacadeRoot())->toBe(app(CircuitBreakerManager::class));
});

it('creates circuit breakers using the defaults from the configuration', function (): void {
    config()->set('resilience-kit.circuit_breaker.failure_threshold', 7);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 45);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 4);

    $circuit = Resilience::circuitBreaker('payments');

    expect($circuit)->toBeInstanceOf(CircuitBreakerInterface::class)
        ->and($circuit->name())->toBe('payments')
        ->and($circuit->getFailureThreshold())->toBe(7)
        ->and($circuit->getRecoveryTimeout())->toBe(45)
        ->and($circuit->getHalfOpenTrials())->toBe(4);
});

it('applies named circuit limits from the configuration', function (): void {
    config()->set('resilience-kit.circuit_breaker.failure_threshold', 5);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 30);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);
    config()->set('resilience-kit.circuits.payment-gateway', [
        'failure_threshold' => 3,
        'recovery_timeout' => 60,
        'half_open_trials' => 2,
    ]);

    $circuit = Resilience::circuitBreaker('payment-gateway');

    expect($circuit->getFailureThreshold())->toBe(3)
        ->and($circuit->getRecoveryTimeout())->toBe(60)
        ->and($circuit->getHalfOpenTrials())->toBe(2);
});

it('inherits omitted named keys from the global circuit breaker defaults', function (): void {
    config()->set('resilience-kit.circuit_breaker.failure_threshold', 5);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 30);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);
    config()->set('resilience-kit.circuits.payment-gateway', [
        'failure_threshold' => 3,
    ]);

    $circuit = Resilience::circuitBreaker('payment-gateway');

    expect($circuit->getFailureThreshold())->toBe(3)
        ->and($circuit->getRecoveryTimeout())->toBe(30)
        ->and($circuit->getHalfOpenTrials())->toBe(3);
});

it('lets the fluent API override named circuit limits and persist them', function (): void {
    config()->set('resilience-kit.circuits.payment-gateway', [
        'failure_threshold' => 3,
        'recovery_timeout' => 60,
        'half_open_trials' => 2,
    ]);

    Resilience::circuitBreaker('payment-gateway')
        ->failureThreshold(1)
        ->recoveryTimeout(15)
        ->halfOpenTrials(1);

    $again = Resilience::circuitBreaker('payment-gateway');

    expect($again->getFailureThreshold())->toBe(1)
        ->and($again->getRecoveryTimeout())->toBe(15)
        ->and($again->getHalfOpenTrials())->toBe(1);
});

it('registers the circuit in the store index as soon as it is created', function (): void {
    Resilience::circuitBreaker('payment-service')->getState();

    expect(Resilience::store()->circuits())->toBe(['payment-service']);
});

it('binds the default store for dependency injection', function (): void {
    expect(app(CircuitStoreInterface::class))->toBeInstanceOf(ArrayCircuitStore::class)
        ->and(app(CircuitStoreInterface::class))->toBe(Resilience::store());
});

it('resolves the cache driver', function (): void {
    config()->set('resilience-kit.default_driver', 'cache');
    config()->set('resilience-kit.circuit_breaker.cache_store', 'array');

    $circuit = Resilience::circuitBreaker('cached');
    $circuit->trip();

    expect(Resilience::store())->toBeInstanceOf(CacheCircuitStore::class)
        ->and(Resilience::circuitBreaker('cached')->getState())->toBe(CircuitState::OPEN);
});

it('resolves the redis driver', function (): void {
    if (! StoreFactory::redisAvailable()) {
        $this->markTestSkipped('Redis/phpredis indisponível neste ambiente.');
    }

    config()->set('resilience-kit.default_driver', 'redis');
    config()->set('resilience-kit.circuit_breaker.prefix', StoreFactory::uniquePrefix());

    $circuit = Resilience::circuitBreaker('redis-circuit');

    try {
        $circuit->trip();

        expect(Resilience::store())->toBeInstanceOf(RedisCircuitStore::class)
            ->and(Resilience::circuitBreaker('redis-circuit')->getState())->toBe(CircuitState::OPEN);
    } finally {
        $circuit->reset();
        Resilience::store()->reset('redis-circuit');
    }
});

it('supports custom drivers through extend()', function (): void {
    $custom = new ArrayCircuitStore;

    Resilience::extend('custom', fn (): CircuitStoreInterface => $custom);
    config()->set('resilience-kit.default_driver', 'custom');

    Resilience::circuitBreaker('x')->trip();

    expect(Resilience::store())->toBe($custom)
        ->and($custom->getState('x'))->toBe(CircuitState::OPEN);
});

it('fails for an unknown driver', function (): void {
    config()->set('resilience-kit.default_driver', 'memcached-magic');

    Resilience::circuitBreaker('x');
})->throws(InvalidArgumentException::class);

it('fails when a custom driver does not implement the store contract', function (): void {
    Resilience::extend('broken', fn (): stdClass => new stdClass);
    config()->set('resilience-kit.default_driver', 'broken');

    Resilience::store();
})->throws(UnexpectedValueException::class);
