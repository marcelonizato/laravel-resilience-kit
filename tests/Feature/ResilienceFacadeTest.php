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
    config()->set('resilience-kit.circuit_breaker.sliding_window_size', 20);
    config()->set('resilience-kit.circuit_breaker.minimum_calls', 8);
    config()->set('resilience-kit.circuit_breaker.failure_rate_threshold', 40);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 45);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 4);

    $circuit = Resilience::circuitBreaker('payments');

    expect($circuit)->toBeInstanceOf(CircuitBreakerInterface::class)
        ->and($circuit->name())->toBe('payments')
        ->and($circuit->getSlidingWindowSize())->toBe(20)
        ->and($circuit->getMinimumCalls())->toBe(8)
        ->and($circuit->getFailureRateThreshold())->toBe(40)
        ->and($circuit->getRecoveryTimeout())->toBe(45)
        ->and($circuit->getHalfOpenTrials())->toBe(4);
});

it('applies named circuit limits from the configuration', function (): void {
    config()->set('resilience-kit.circuit_breaker.sliding_window_size', 10);
    config()->set('resilience-kit.circuit_breaker.minimum_calls', 10);
    config()->set('resilience-kit.circuit_breaker.failure_rate_threshold', 50);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 30);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);
    config()->set('resilience-kit.circuits.payment-gateway', [
        'sliding_window_size' => 20,
        'minimum_calls' => 8,
        'failure_rate_threshold' => 40,
        'recovery_timeout' => 60,
        'half_open_trials' => 2,
    ]);

    $circuit = Resilience::circuitBreaker('payment-gateway');

    expect($circuit->getSlidingWindowSize())->toBe(20)
        ->and($circuit->getMinimumCalls())->toBe(8)
        ->and($circuit->getFailureRateThreshold())->toBe(40)
        ->and($circuit->getRecoveryTimeout())->toBe(60)
        ->and($circuit->getHalfOpenTrials())->toBe(2);
});

it('inherits omitted named keys from the global circuit breaker defaults', function (): void {
    config()->set('resilience-kit.circuit_breaker.sliding_window_size', 10);
    config()->set('resilience-kit.circuit_breaker.minimum_calls', 10);
    config()->set('resilience-kit.circuit_breaker.failure_rate_threshold', 50);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 30);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);
    config()->set('resilience-kit.circuits.payment-gateway', [
        'failure_rate_threshold' => 40,
    ]);

    $circuit = Resilience::circuitBreaker('payment-gateway');

    expect($circuit->getFailureRateThreshold())->toBe(40)
        ->and($circuit->getSlidingWindowSize())->toBe(10)
        ->and($circuit->getMinimumCalls())->toBe(10)
        ->and($circuit->getRecoveryTimeout())->toBe(30)
        ->and($circuit->getHalfOpenTrials())->toBe(3);
});

it('shows fluent limits on a later instance until a call omits them', function (): void {
    config()->set('resilience-kit.circuit_breaker.sliding_window_size', 10);
    config()->set('resilience-kit.circuit_breaker.minimum_calls', 10);
    config()->set('resilience-kit.circuit_breaker.failure_rate_threshold', 50);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 30);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);
    config()->set('resilience-kit.circuits.payment-gateway', [
        'sliding_window_size' => 20,
        'minimum_calls' => 8,
        'failure_rate_threshold' => 40,
        'recovery_timeout' => 60,
        'half_open_trials' => 2,
    ]);

    $configured = Resilience::circuitBreaker('payment-gateway')
        ->slidingWindowSize(4)
        ->minimumCalls(4)
        ->failureRateThreshold(80)
        ->recoveryTimeout(15)
        ->halfOpenTrials(1);

    expect($configured->getSlidingWindowSize())->toBe(4)
        ->and($configured->getMinimumCalls())->toBe(4)
        ->and($configured->getFailureRateThreshold())->toBe(80)
        ->and($configured->getRecoveryTimeout())->toBe(15)
        ->and($configured->getHalfOpenTrials())->toBe(1);

    $again = Resilience::circuitBreaker('payment-gateway');

    expect($again->getSlidingWindowSize())->toBe(4)
        ->and($again->getFailureRateThreshold())->toBe(80)
        ->and($again->getRecoveryTimeout())->toBe(15)
        ->and($again->getHalfOpenTrials())->toBe(1);

    $again->run(fn (): string => 'ok');

    $after = Resilience::circuitBreaker('payment-gateway');

    expect($after->getSlidingWindowSize())->toBe(20)
        ->and($after->getMinimumCalls())->toBe(8)
        ->and($after->getFailureRateThreshold())->toBe(40)
        ->and($after->getRecoveryTimeout())->toBe(60)
        ->and($after->getHalfOpenTrials())->toBe(2);
});

it('returns to the global defaults when a later call omits fluent limits', function (): void {
    config()->set('resilience-kit.circuit_breaker.sliding_window_size', 10);
    config()->set('resilience-kit.circuit_breaker.failure_rate_threshold', 50);
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 25);
    config()->set('resilience-kit.circuit_breaker.half_open_trials', 3);

    Resilience::circuitBreaker('payment-gateway2')
        ->slidingWindowSize(4)
        ->failureRateThreshold(80)
        ->recoveryTimeout(30);

    $shown = Resilience::circuitBreaker('payment-gateway2');

    expect($shown->getSlidingWindowSize())->toBe(4)
        ->and($shown->getFailureRateThreshold())->toBe(80)
        ->and($shown->getRecoveryTimeout())->toBe(30)
        ->and($shown->getHalfOpenTrials())->toBe(3);

    $shown->run(fn (): string => 'ok');

    $again = Resilience::circuitBreaker('payment-gateway2');

    expect($again->getSlidingWindowSize())->toBe(10)
        ->and($again->getFailureRateThreshold())->toBe(50)
        ->and($again->getRecoveryTimeout())->toBe(25)
        ->and($again->getHalfOpenTrials())->toBe(3);
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
