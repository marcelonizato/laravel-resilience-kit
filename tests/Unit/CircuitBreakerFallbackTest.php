<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use LaravelResilienceKit\Facades\Resilience;
use LaravelResilienceKit\Tests\Support\StubFallbackHandler;

it('executes the fallback and blocks execution when the circuit is open', function (): void {
    $circuit = openAfterConsecutiveFailures(Resilience::circuitBreaker('external-api'), 1)
        ->recoveryTimeout(60)
        ->fallback(fn (Throwable $e) => 'fallback-data');

    attempt($circuit, failingAction('Connection failed'));

    expect($circuit->getState())->toBe(CircuitState::OPEN);

    $wasExecuted = false;
    $response = $circuit->run(function () use (&$wasExecuted): string {
        $wasExecuted = true;

        return 'normal-data';
    });

    expect($wasExecuted)->toBeFalse()
        ->and($response)->toBe('fallback-data');
});

it('passes a CircuitBreakerOpenException to the fallback when the circuit is open', function (): void {
    $received = null;

    $circuit = Resilience::circuitBreaker('svc')->fallback(function (Throwable $e) use (&$received): string {
        $received = $e;

        return 'degraded';
    });

    $circuit->trip();
    $circuit->run(fn () => 'x');

    expect($received)->toBeInstanceOf(CircuitBreakerOpenException::class);
});

it('executes the fallback when the action fails, counting the failure first', function (): void {
    $circuit = openAfterConsecutiveFailures(Resilience::circuitBreaker('svc'), 3)
        ->fallback(fn (Throwable $e) => 'recovered from: '.$e->getMessage());

    $result = $circuit->run(failingAction('boom'));

    expect($result)->toBe('recovered from: boom')
        ->and($circuit->getFailures())->toBe(1)
        ->and($circuit->getState())->toBe(CircuitState::CLOSED);
});

it('opens the circuit through failures even when a fallback masks the exceptions', function (): void {
    $circuit = openAfterConsecutiveFailures(Resilience::circuitBreaker('svc'), 2)
        ->fallback(fn () => 'fallback');

    $circuit->run(failingAction());
    $circuit->run(failingAction());

    expect($circuit->getState())->toBe(CircuitState::OPEN);
});

it('does not use the fallback when the action succeeds', function (): void {
    $circuit = Resilience::circuitBreaker('svc')->fallback(fn () => 'fallback');

    expect($circuit->run(fn () => 'real'))->toBe('real');
});

it('accepts a dedicated handler instance', function (): void {
    $circuit = Resilience::circuitBreaker('svc')->fallback(new StubFallbackHandler);

    $circuit->trip();

    expect($circuit->run(fn () => 'x'))->toBe('handled:CircuitBreakerOpenException');
});

it('resolves a handler class through the container', function (): void {
    $circuit = Resilience::circuitBreaker('svc')->fallback(StubFallbackHandler::class);

    expect($circuit->run(failingAction()))->toBe('handled:RuntimeException');
});

it('rejects a fallback class that does not implement the handler contract', function (): void {
    Resilience::circuitBreaker('svc')->fallback(stdClass::class);
})->throws(InvalidArgumentException::class, 'must implement');

it('fails clearly when the container resolves a fallback class to an invalid handler', function (): void {
    app()->bind(StubFallbackHandler::class, fn (): stdClass => new stdClass);

    $circuit = Resilience::circuitBreaker('svc')->fallback(StubFallbackHandler::class);
    $circuit->trip();

    $circuit->run(fn () => 'x');
})->throws(UnexpectedValueException::class, 'must implement');

it('propagates exceptions thrown by the fallback itself', function (): void {
    $circuit = Resilience::circuitBreaker('svc')->fallback(fn () => throw new DomainException('fallback failed'));

    $circuit->trip();

    $circuit->run(fn () => 'x');
})->throws(DomainException::class, 'fallback failed');
