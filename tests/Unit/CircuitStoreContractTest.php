<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;

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
        ->and($store->getWindow('svc', 10))->toEqual(CallWindow::empty())
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

it('records outcomes in a sliding window and keeps the latest failure reason', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->recordOutcome('svc', true, 3, 'first')->failures)->toBe(1)
        ->and($store->recordOutcome('svc', false, 3)->calls)->toBe(2)
        ->and($store->recordOutcome('svc', true, 3, 'second')->failures)->toBe(2)
        ->and($store->recordOutcome('svc', true, 3, 'third'))->toEqual(new CallWindow(3, 2))
        ->and($store->getWindow('svc', 3))->toEqual(new CallWindow(3, 2))
        ->and($store->getWindow('svc', 2))->toEqual(new CallWindow(2, 2))
        ->and($store->getLastFailureReason('svc'))->toBe('third')
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

    $store->recordOutcome('svc', true, 10, 'boom');
    $store->recordOutcome('svc', true, 10, 'boom');
    $store->incrementHalfOpenSuccesses('svc');
    $store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN);

    expect($store->getLastTrippedAt('svc'))->toBe(Carbon::parse('2026-01-01 12:00:00')->getTimestamp())
        ->and($store->getWindow('svc', 10))->toEqual(new CallWindow(2, 2))
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

    $store->recordOutcome('svc', true, 10, 'boom');
    $store->forceState('svc', CircuitState::OPEN);
    $store->incrementHalfOpenSuccesses('svc');
    $store->transitionState('svc', CircuitState::OPEN, CircuitState::CLOSED);

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getWindow('svc', 10))->toEqual(CallWindow::empty())
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

    $store->recordOutcome('svc', true, 10, 'boom');
    $store->incrementHalfOpenSuccesses('svc');
    $store->forceState('svc', CircuitState::OPEN);

    $store->reset('svc');

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getWindow('svc', 10))->toEqual(CallWindow::empty())
        ->and($store->getHalfOpenSuccesses('svc'))->toBe(0)
        ->and($store->getLastTrippedAt('svc'))->toBeNull()
        ->and($store->getLastFailureReason('svc'))->toBeNull()
        ->and($store->circuits())->toBe(['svc']);
})->with('stores');

it('persists circuit limits and keeps them across reset', function (string $driver): void {
    $store = circuitStore($driver);

    expect($store->getSlidingWindowSize('svc'))->toBeNull()
        ->and($store->getMinimumCalls('svc'))->toBeNull()
        ->and($store->getFailureRateThreshold('svc'))->toBeNull()
        ->and($store->getRecoveryTimeout('svc'))->toBeNull()
        ->and($store->getHalfOpenTrials('svc'))->toBeNull();

    $store->setSlidingWindowSize('svc', 20);
    $store->setMinimumCalls('svc', 8);
    $store->setFailureRateThreshold('svc', 40);
    $store->setRecoveryTimeout('svc', 60);
    $store->setHalfOpenTrials('svc', 2);
    $store->forceState('svc', CircuitState::OPEN);

    $store->reset('svc');

    expect($store->getSlidingWindowSize('svc'))->toBe(20)
        ->and($store->getMinimumCalls('svc'))->toBe(8)
        ->and($store->getFailureRateThreshold('svc'))->toBe(40)
        ->and($store->getRecoveryTimeout('svc'))->toBe(60)
        ->and($store->getHalfOpenTrials('svc'))->toBe(2)
        ->and($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->circuits())->toBe(['svc']);
})->with('stores');

it('forgets a circuit, including its limits and index entry', function (string $driver): void {
    $store = circuitStore($driver);

    $store->recordOutcome('keep', true, 10, 'stay');
    $store->setSlidingWindowSize('drop', 4);
    $store->setMinimumCalls('drop', 4);
    $store->setFailureRateThreshold('drop', 50);
    $store->setRecoveryTimeout('drop', 12);
    $store->setHalfOpenTrials('drop', 2);
    $store->recordOutcome('drop', true, 10, 'boom');
    $store->forceState('drop', CircuitState::OPEN);

    $store->forget('drop');

    expect($store->circuits())->toBe(['keep'])
        ->and($store->getState('drop'))->toBe(CircuitState::CLOSED)
        ->and($store->getWindow('drop', 10))->toEqual(CallWindow::empty())
        ->and($store->getLastTrippedAt('drop'))->toBeNull()
        ->and($store->getLastFailureReason('drop'))->toBeNull()
        ->and($store->getSlidingWindowSize('drop'))->toBeNull()
        ->and($store->getMinimumCalls('drop'))->toBeNull()
        ->and($store->getFailureRateThreshold('drop'))->toBeNull()
        ->and($store->getRecoveryTimeout('drop'))->toBeNull()
        ->and($store->getHalfOpenTrials('drop'))->toBeNull()
        ->and($store->getState('keep'))->toBe(CircuitState::CLOSED)
        ->and($store->getWindow('keep', 10))->toEqual(new CallWindow(1, 1));
})->with('stores');

it('forgets a single persisted limit and leaves the others', function (string $driver): void {
    $store = circuitStore($driver);

    $store->setSlidingWindowSize('svc', 4);
    $store->setMinimumCalls('svc', 4);
    $store->setFailureRateThreshold('svc', 50);
    $store->setRecoveryTimeout('svc', 12);
    $store->setHalfOpenTrials('svc', 2);

    $store->forgetSlidingWindowSize('svc');
    $store->forgetMinimumCalls('svc');
    $store->forgetFailureRateThreshold('svc');

    expect($store->getSlidingWindowSize('svc'))->toBeNull()
        ->and($store->getMinimumCalls('svc'))->toBeNull()
        ->and($store->getFailureRateThreshold('svc'))->toBeNull()
        ->and($store->getRecoveryTimeout('svc'))->toBe(12)
        ->and($store->getHalfOpenTrials('svc'))->toBe(2);

    $store->forgetRecoveryTimeout('svc');
    $store->forgetHalfOpenTrials('svc');

    expect($store->getRecoveryTimeout('svc'))->toBeNull()
        ->and($store->getHalfOpenTrials('svc'))->toBeNull();
})->with('stores');

it('isolates configured limits between circuits', function (string $driver): void {
    $store = circuitStore($driver);

    $store->setSlidingWindowSize('a', 20);
    $store->setFailureRateThreshold('a', 40);
    $store->setRecoveryTimeout('a', 60);

    expect($store->getSlidingWindowSize('b'))->toBeNull()
        ->and($store->getFailureRateThreshold('b'))->toBeNull()
        ->and($store->getRecoveryTimeout('b'))->toBeNull();
})->with('stores');

it('isolates state between circuits', function (string $driver): void {
    $store = circuitStore($driver);

    $store->forceState('a', CircuitState::OPEN);
    $store->recordOutcome('a', true, 10);

    expect($store->getState('b'))->toBe(CircuitState::CLOSED)
        ->and($store->getWindow('b', 10))->toEqual(CallWindow::empty());
})->with('stores');
