<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\Attributes\WithCircuitBreaker;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use LaravelResilienceKit\CircuitBreaker\Support\CircuitBreakerProxy;
use LaravelResilienceKit\Facades\Resilience;
use LaravelResilienceKit\Tests\Support\Fixtures\PaymentService;

beforeEach(function (): void {
    $this->service = new PaymentService;
    $this->protected = Resilience::protect($this->service);
});

it('runs an annotated method through its circuit and returns the result', function (): void {
    expect($this->protected->processPayment(['amount' => 10]))->toBe(['status' => 'ok'])
        ->and(Resilience::circuitBreaker('payment-gateway')->getState())->toBe(CircuitState::CLOSED);
});

it('invokes the fallback method with the original arguments followed by the exception', function (): void {
    $result = $this->protected->processPayment(['fail' => true, 'order' => 7]);

    expect($result)->toBe([
        'status' => 'queued_for_retry',
        'reason' => 'gateway down',
        'payload' => ['fail' => true, 'order' => 7],
    ])
        ->and(Resilience::circuitBreaker('payment-gateway')->getFailures())->toBe(1);
});

it('opens the circuit using the threshold declared in the attribute and stops calling the method', function (): void {
    $this->protected->processPayment(['fail' => true]);
    $this->protected->processPayment(['fail' => true]);

    expect(Resilience::circuitBreaker('payment-gateway')->getState())->toBe(CircuitState::OPEN)
        ->and($this->service->calls)->toBe(2);

    $result = $this->protected->processPayment(['fail' => false]);

    // O circuito está aberto: o corpo não executa e o fallback recebe a CircuitBreakerOpenException.
    expect($this->service->calls)->toBe(2)
        ->and($result['status'])->toBe('queued_for_retry')
        ->and($result['reason'])->toContain('payment-gateway');
});

it('honors the recovery timeout declared in the attribute', function (): void {
    $this->protected->processPayment(['fail' => true]);
    $this->protected->processPayment(['fail' => true]);

    $this->travel(29)->seconds();
    expect($this->protected->processPayment([])['status'])->toBe('queued_for_retry')
        ->and($this->service->calls)->toBe(2);

    $this->travel(1)->seconds();
    expect($this->protected->processPayment([]))->toBe(['status' => 'ok'])
        ->and(Resilience::circuitBreaker('payment-gateway')->getState())->toBe(CircuitState::HALF_OPEN);
});

it('honors the half-open trials declared in the attribute', function (): void {
    expect($this->protected->singleTrial(true))->toBe('fallback')
        ->and(Resilience::circuitBreaker('single-trial')->getState())->toBe(CircuitState::OPEN);

    $this->travel(5)->seconds();

    // Com halfOpenTrials: 1 (o padrão da config é 3), um único sucesso fecha o circuito.
    expect($this->protected->singleTrial(false))->toBe('recovered')
        ->and(Resilience::circuitBreaker('single-trial')->getState())->toBe(CircuitState::CLOSED);
});

it('derives the circuit name from Class::method when none is given', function (): void {
    expect($this->protected->unnamed())->toBe('unnamed-result')
        ->and(Resilience::store()->circuits())->toContain(PaymentService::class.'::unnamed');
});

it('rethrows the original exception when the attribute has no fallback', function (): void {
    expect(fn () => $this->protected->withoutFallback())->toThrow(RuntimeException::class, 'no fallback failure');
    expect(fn () => $this->protected->withoutFallback())->toThrow(CircuitBreakerOpenException::class);
});

it('supports a fallback handler class', function (): void {
    expect($this->protected->withHandlerFallback())->toBe('handled:RuntimeException');
});

it('supports private fallback methods', function (): void {
    expect($this->protected->withPrivateFallback())->toBe('private:private failure');
});

it('passes the exception by the last parameter name when arguments are named', function (): void {
    expect($this->protected->named(amount: 5, label: 'abc'))->toBe('fallback abc:5:failed abc:5');
});

it('fails clearly when the declared fallback does not exist', function (): void {
    $this->protected->withMissingFallback();
})->throws(InvalidArgumentException::class, 'doesNotExist');

it('passes methods without the attribute straight through, unprotected', function (): void {
    expect(fn () => $this->protected->plain())->toThrow(RuntimeException::class, 'plain failure')
        ->and(Resilience::store()->circuits())->toBe([]);
});

it('supports Resilience::call() without creating a proxy', function (): void {
    $result = Resilience::call($this->service, 'processPayment', [['fail' => true]]);

    expect($result['status'])->toBe('queued_for_retry')
        ->and(Resilience::circuitBreaker('payment-gateway')->getFailures())->toBe(1);
});

it('exposes the original object and does not protect direct calls', function (): void {
    expect($this->protected)->toBeInstanceOf(CircuitBreakerProxy::class)
        ->and($this->protected->__unwrap())->toBe($this->service);

    expect(fn () => $this->service->processPayment(['fail' => true]))->toThrow(RuntimeException::class);
    expect(Resilience::store()->circuits())->toBe([]);
});

it('fails like a regular call for undefined methods', function (): void {
    $this->protected->doesNotExist();
})->throws(Error::class, 'undefined method');

it('is declared as a method-only attribute with optional settings', function (): void {
    $attribute = (new ReflectionClass(WithCircuitBreaker::class))->getAttributes(Attribute::class)[0]->newInstance();
    $defaults = new WithCircuitBreaker;

    expect($attribute->flags)->toBe(Attribute::TARGET_METHOD)
        ->and($defaults->name)->toBeNull()
        ->and($defaults->fallback)->toBeNull()
        ->and($defaults->slidingWindowSize)->toBeNull()
        ->and($defaults->minimumCalls)->toBeNull()
        ->and($defaults->failureRateThreshold)->toBeNull()
        ->and($defaults->recoveryTimeout)->toBeNull()
        ->and($defaults->halfOpenTrials)->toBeNull();
});
