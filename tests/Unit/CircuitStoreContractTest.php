<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/*
|--------------------------------------------------------------------------
| Contrato do CircuitStoreInterface
|--------------------------------------------------------------------------
|
| Os mesmos cenários são executados contra os drivers Array, Cache e Redis,
| garantindo comportamento idêntico entre eles.
|
*/

it('starts a new circuit as CLOSED with zeroed counters', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getFailures('svc'))->toBe(0)
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(0)
        ->and($store->getLastTrippedAt('svc'))->toBeNull()
        ->and($store->getLastFailureReason('svc'))->toBeNull()
        ->and($store->circuits())->toBe([]);
})->with('stores');

it('registers circuits idempotently and lists them alphabetically', function (string $driver): void {
    $store = circuitStore($driver);

    $store->register('zeta');
    $store->register('alpha');
    $store->register('zeta');

    expect($store->circuits())->toBe(['alpha', 'zeta']);
})->with('stores');

it('increments failures atomically and records the last reason', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->incrementFailures('svc', 'first error'))->toBe(1)
        ->and($store->incrementFailures('svc', 'second error'))->toBe(2)
        ->and($store->incrementFailures('svc'))->toBe(3)
        ->and($store->getFailures('svc'))->toBe(3)
        ->and($store->getLastFailureReason('svc'))->toBe('second error')
        ->and($store->circuits())->toBe(['svc']);
})->with('stores');

it('increments half-open successes', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->incrementHalfOpenSuccesses('svc'))->toBe(1)
        ->and($store->incrementHalfOpenSuccesses('svc'))->toBe(2)
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(2);
})->with('stores');

it('transitions state only when the expected state matches (compare-and-set)', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN))->toBeTrue()
        ->and($store->getState('svc'))->toBe(CircuitState::OPEN)
        // O segundo chamador concorrente perde a corrida.
        ->and($store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN))->toBeFalse()
        ->and($store->transitionState('svc', CircuitState::OPEN, CircuitState::HALF_OPEN))->toBeTrue()
        ->and($store->getState('svc'))->toBe(CircuitState::HALF_OPEN);
})->with('stores');

it('records last_tripped_at, keeps failures and clears half-open successes when opening', function (string $driver): void {
    $store = circuitStore($driver);
    Carbon::setTestNow('2026-01-01 12:00:00');

    $store->incrementFailures('svc', 'boom');
    $store->incrementFailures('svc', 'boom');
    $store->incrementHalfOpenSuccesses('svc');
    $store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN);

    expect($store->getLastTrippedAt('svc'))->toBe(Carbon::parse('2026-01-01 12:00:00')->getTimestamp())
        ->and($store->getFailures('svc'))->toBe(2)
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(0)
        ->and($store->getLastFailureReason('svc'))->toBe('boom');
})->with('stores');

it('restarts last_tripped_at when re-opening from HALF-OPEN', function (string $driver): void {
    $store = circuitStore($driver);

    Carbon::setTestNow('2026-01-01 12:00:00');
    $store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN);
    $store->transitionState('svc', CircuitState::OPEN, CircuitState::HALF_OPEN);

    Carbon::setTestNow('2026-01-01 12:05:00');
    $store->transitionState('svc', CircuitState::HALF_OPEN, CircuitState::OPEN);

    expect($store->getLastTrippedAt('svc'))->toBe(Carbon::parse('2026-01-01 12:05:00')->getTimestamp());
})->with('stores');

it('clears half-open successes when entering HALF-OPEN', function (string $driver): void {
    $store = circuitStore($driver);

    $store->incrementHalfOpenSuccesses('svc');
    $store->forceState('svc', CircuitState::OPEN);
    $store->incrementHalfOpenSuccesses('svc');
    $store->transitionState('svc', CircuitState::OPEN, CircuitState::HALF_OPEN);

    expect($store->getHalfOpenSuccesses('svc'))->toBe(0);
})->with('stores');

it('clears failures and half-open successes when closing but keeps last_tripped_at', function (string $driver): void {
    $store = circuitStore($driver);
    Carbon::setTestNow('2026-01-01 12:00:00');

    $store->incrementFailures('svc', 'boom');
    $store->forceState('svc', CircuitState::OPEN);
    $store->incrementHalfOpenSuccesses('svc');
    $store->transitionState('svc', CircuitState::OPEN, CircuitState::CLOSED);

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getFailures('svc'))->toBe(0)
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(0)
        ->and($store->getLastTrippedAt('svc'))->toBe(Carbon::parse('2026-01-01 12:00:00')->getTimestamp());
})->with('stores');

it('forces state unconditionally', function (string $driver): void {
    $store = circuitStore($driver);

    $store->forceState('svc', CircuitState::HALF_OPEN);
    expect($store->getState('svc'))->toBe(CircuitState::HALF_OPEN);

    $store->forceState('svc', CircuitState::OPEN);
    expect($store->getState('svc'))->toBe(CircuitState::OPEN)
        ->and($store->getLastTrippedAt('svc'))->not->toBeNull();
})->with('stores');

it('resets state and counters but keeps the circuit registered', function (string $driver): void {
    $store = circuitStore($driver);

    $store->incrementFailures('svc', 'boom');
    $store->incrementHalfOpenSuccesses('svc');
    $store->forceState('svc', CircuitState::OPEN);

    $store->reset('svc');

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getFailures('svc'))->toBe(0)
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(0)
        ->and($store->getLastTrippedAt('svc'))->toBeNull()
        ->and($store->getLastFailureReason('svc'))->toBeNull()
        ->and($store->circuits())->toBe(['svc']);
})->with('stores');

it('persists circuit limits and keeps them across reset', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->getFailureThreshold('svc'))->toBeNull()
        ->and($store->getRecoveryTimeout('svc'))->toBeNull()
        ->and($store->getHalfOpenTrials('svc'))->toBeNull();

    $store->setFailureThreshold('svc', 3);
    $store->setRecoveryTimeout('svc', 60);
    $store->setHalfOpenTrials('svc', 2);
    $store->forceState('svc', CircuitState::OPEN);

    $store->reset('svc');

    expect($store->getFailureThreshold('svc'))->toBe(3)
        ->and($store->getRecoveryTimeout('svc'))->toBe(60)
        ->and($store->getHalfOpenTrials('svc'))->toBe(2)
        ->and($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->circuits())->toBe(['svc']);
})->with('stores');

it('isolates configured limits between circuits', function (string $driver): void {
    $store = circuitStore($driver);

    $store->setFailureThreshold('a', 3);
    $store->setRecoveryTimeout('a', 60);

    expect($store->getFailureThreshold('b'))->toBeNull()
        ->and($store->getRecoveryTimeout('b'))->toBeNull();
})->with('stores');

it('isolates state between circuits', function (string $driver): void {
    $store = circuitStore($driver);

    $store->forceState('a', CircuitState::OPEN);
    $store->incrementFailures('a');

    expect($store->getState('b'))->toBe(CircuitState::CLOSED)
        ->and($store->getFailures('b'))->toBe(0);
})->with('stores');
