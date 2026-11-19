<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use LaravelResilienceKit\CircuitBreaker\Drivers\CacheCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

it('rejects cache stores without atomic lock support', function (): void {
    $repository = new Repository(Mockery::mock(Store::class));

    new CacheCircuitStore($repository);
})->throws(InvalidArgumentException::class, 'não suporta locks atômicos');

it('persists data using the keys defined in the spec', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = new CacheCircuitStore($cache, 'custom:');

    $store->incrementFailures('payments', 'timeout');
    $store->forceState('payments', CircuitState::OPEN);

    expect($cache->get('custom:payments:state'))->toBe('open')
        ->and($cache->get('custom:payments:failures'))->toBe(1)
        ->and($cache->get('custom:payments:last_failure_reason'))->toBe('timeout')
        ->and($cache->get('custom:payments:last_tripped_at'))->toBeInt()
        ->and($cache->get('custom:circuits'))->toBe(['payments']);
});

it('releases the circuit lock after each operation', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = new CacheCircuitStore($cache, lockSeconds: 1);

    $store->incrementFailures('svc');

    // Se o lock não fosse liberado, esta chamada estouraria LockTimeoutException.
    expect($store->incrementFailures('svc'))->toBe(2);
});
