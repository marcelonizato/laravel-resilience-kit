<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitClosed;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitHalfOpened;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitOpened;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use LaravelResilienceKit\CircuitBreaker\Services\CircuitBreakerService;
use LaravelResilienceKit\Facades\Resilience;

beforeEach(function (): void {
    Event::fake([CircuitOpened::class, CircuitClosed::class, CircuitHalfOpened::class]);

    $this->circuit = Resilience::circuitBreaker('test-service')
        ->failureThreshold(2)
        ->recoveryTimeout(10)
        ->halfOpenTrials(2);
});

/*
|--------------------------------------------------------------------------
| CLOSED
|--------------------------------------------------------------------------
*/

it('starts in CLOSED state and executes the action successfully', function (): void {
    $result = $this->circuit->run(fn () => 'success');

    expect($result)->toBe('success')
        ->and($this->circuit->getState())->toBe(CircuitState::CLOSED)
        ->and($this->circuit->getFailures())->toBe(0);
});

it('increments the failure counter and rethrows the original exception', function (): void {
    expect(fn () => $this->circuit->run(failingAction('Service error')))
        ->toThrow(RuntimeException::class, 'Service error');

    expect($this->circuit->getFailures())->toBe(1)
        ->and($this->circuit->getLastFailureReason())->toBe('Service error');
});

it('does not open the circuit while failures are below the threshold', function (): void {
    attempt($this->circuit, failingAction());

    expect($this->circuit->getState())->toBe(CircuitState::CLOSED);
    Event::assertNotDispatched(CircuitOpened::class);
});

it('records the exception class when the message is empty', function (): void {
    attempt($this->circuit, fn () => throw new LogicException);

    expect($this->circuit->getLastFailureReason())->toBe(LogicException::class);
});

/*
|--------------------------------------------------------------------------
| OPEN
|--------------------------------------------------------------------------
*/

it('trips to OPEN exactly when the failure threshold is reached and dispatches CircuitOpened once', function (): void {
    attempt($this->circuit, failingAction('first'));
    expect($this->circuit->getState())->toBe(CircuitState::CLOSED);

    attempt($this->circuit, failingAction('second'));

    expect($this->circuit->getState())->toBe(CircuitState::OPEN)
        ->and($this->circuit->getLastTrippedAt())->not->toBeNull();

    Event::assertDispatchedTimes(CircuitOpened::class, 1);
    Event::assertDispatched(
        CircuitOpened::class,
        fn (CircuitOpened $event): bool => $event->circuit === 'test-service'
            && $event->previousState === CircuitState::CLOSED
            && $event->failures === 2
            && $event->reason === 'second',
    );
});

it('throws CircuitBreakerOpenException without running the action when OPEN and there is no fallback', function (): void {
    $this->circuit->trip();
    $executed = false;

    try {
        $this->circuit->run(function () use (&$executed): string {
            $executed = true;

            return 'unreachable';
        });

        $this->fail('Expected CircuitBreakerOpenException.');
    } catch (CircuitBreakerOpenException $exception) {
        expect($exception->circuit)->toBe('test-service')
            ->and($exception->retryAfter)->toBe(10)
            ->and($executed)->toBeFalse();
    }
});

it('exposes the remaining wait time and last failure reason in the open exception', function (): void {
    attempt($this->circuit, failingAction());
    attempt($this->circuit, failingAction('database down'));

    $this->travel(4)->seconds();

    try {
        $this->circuit->run(fn () => 'x');
        $this->fail('Expected CircuitBreakerOpenException.');
    } catch (CircuitBreakerOpenException $exception) {
        expect($exception->retryAfter)->toBe(6)
            ->and($exception->lastFailureReason)->toBe('database down')
            ->and($exception->getMessage())->toContain('test-service');
    }
});

it('keeps short-circuiting before the recovery timeout expires', function (): void {
    $this->circuit->trip();
    $this->travel(9)->seconds();

    expect(fn () => $this->circuit->run(fn () => 'x'))->toThrow(CircuitBreakerOpenException::class);
    expect($this->circuit->getState())->toBe(CircuitState::OPEN);
    Event::assertNotDispatched(CircuitHalfOpened::class);
});

/*
|--------------------------------------------------------------------------
| HALF-OPEN
|--------------------------------------------------------------------------
*/

it('moves from OPEN to HALF-OPEN on the first request after the recovery timeout', function (): void {
    $this->circuit->trip();
    $this->travel(10)->seconds();

    $result = $this->circuit->run(fn () => 'probe');

    expect($result)->toBe('probe')
        ->and($this->circuit->getState())->toBe(CircuitState::HALF_OPEN);

    Event::assertDispatchedTimes(CircuitHalfOpened::class, 1);
    Event::assertDispatched(CircuitHalfOpened::class, fn (CircuitHalfOpened $event): bool => $event->circuit === 'test-service');
});

it('resolves OPEN to HALF-OPEN without executing a business request', function (): void {
    $this->circuit->trip();
    $this->travel(10)->seconds();

    expect($this->circuit->getState())->toBe(CircuitState::OPEN)
        ->and($this->circuit->resolveState())->toBe(CircuitState::HALF_OPEN)
        ->and($this->circuit->getState())->toBe(CircuitState::HALF_OPEN);

    Event::assertDispatchedTimes(CircuitHalfOpened::class, 1);
});

it('closes the circuit and zeroes failures after the configured number of successful trials', function (): void {
    attempt($this->circuit, failingAction());
    attempt($this->circuit, failingAction());
    $this->travel(10)->seconds();

    $this->circuit->run(fn () => 'ok-1');
    expect($this->circuit->getState())->toBe(CircuitState::HALF_OPEN);
    Event::assertNotDispatched(CircuitClosed::class);

    $this->circuit->run(fn () => 'ok-2');

    expect($this->circuit->getState())->toBe(CircuitState::CLOSED)
        ->and($this->circuit->getFailures())->toBe(0);

    Event::assertDispatchedTimes(CircuitClosed::class, 1);
    Event::assertDispatched(
        CircuitClosed::class,
        fn (CircuitClosed $event): bool => $event->previousState === CircuitState::HALF_OPEN,
    );
});

it('reopens immediately and restarts the timer when a HALF-OPEN trial fails', function (): void {
    $this->circuit->trip();
    $firstTrip = $this->circuit->getLastTrippedAt();

    $this->travel(10)->seconds();
    attempt($this->circuit, failingAction('still down'));

    expect($this->circuit->getState())->toBe(CircuitState::OPEN)
        ->and($this->circuit->getLastTrippedAt())->toBeGreaterThan($firstTrip);

    Event::assertDispatched(
        CircuitOpened::class,
        fn (CircuitOpened $event): bool => $event->previousState === CircuitState::HALF_OPEN
            && $event->reason === 'still down',
    );

    // O timer reiniciou: ainda curto-circuita logo após a reabertura.
    expect(fn () => $this->circuit->run(fn () => 'x'))->toThrow(CircuitBreakerOpenException::class);
});

it('can recover again after a failed HALF-OPEN cycle', function (): void {
    $this->circuit->trip();
    $this->travel(10)->seconds();
    attempt($this->circuit, failingAction());

    $this->travel(10)->seconds();
    $this->circuit->run(fn () => 'ok-1');
    $this->circuit->run(fn () => 'ok-2');

    expect($this->circuit->getState())->toBe(CircuitState::CLOSED);
});

it('treats an OPEN circuit without last_tripped_at as eligible for recovery', function (): void {
    $store = Mockery::mock(CircuitStoreInterface::class)->shouldIgnoreMissing();
    $store->shouldReceive('getState')->with('legacy')->andReturn(
        CircuitState::OPEN,      // leitura inicial em run()
        CircuitState::HALF_OPEN, // releitura após a transição
        CircuitState::HALF_OPEN, // leitura em recordSuccess()
    );
    $store->shouldReceive('getLastTrippedAt')->with('legacy')->andReturnNull();
    $store->shouldReceive('transitionState')
        ->once()
        ->with('legacy', CircuitState::OPEN, CircuitState::HALF_OPEN)
        ->andReturnTrue();
    $store->shouldReceive('incrementHalfOpenSuccesses')->once()->with('legacy')->andReturn(1);

    $circuit = new CircuitBreakerService('legacy', $store, app(Dispatcher::class), Container::getInstance());

    expect($circuit->run(fn () => 'ok'))->toBe('ok');
    Event::assertDispatched(CircuitHalfOpened::class);
});

/*
|--------------------------------------------------------------------------
| Concorrência: perder a corrida de uma transição
|--------------------------------------------------------------------------
|
| Com requisições simultâneas, apenas o processo que vence o compare-and-set
| efetua a transição e dispara o evento. Estes testes simulam o perdedor.
|
*/

it('only counts the failure when another process already opened the circuit during the action', function (): void {
    $circuit = $this->circuit;

    // A ação abre o circuito (outra requisição concorrente) e só então falha.
    attempt($circuit, function () use ($circuit): never {
        $circuit->trip();

        throw new RuntimeException('late failure');
    });

    expect($circuit->getState())->toBe(CircuitState::OPEN)
        ->and($circuit->getFailures())->toBe(1);

    // Apenas o trip() manual abriu o circuito: nenhum CircuitOpened duplicado.
    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('does not dispatch CircuitOpened when it loses the CLOSED -> OPEN race', function (): void {
    $store = Mockery::mock(CircuitStoreInterface::class)->shouldIgnoreMissing();
    $store->shouldReceive('getState')->with('racy')->andReturn(CircuitState::CLOSED);
    $store->shouldReceive('incrementFailures')->once()->with('racy', 'boom')->andReturn(2);
    $store->shouldReceive('transitionState')
        ->once()
        ->with('racy', CircuitState::CLOSED, CircuitState::OPEN)
        ->andReturnFalse();

    $circuit = (new CircuitBreakerService('racy', $store, app(Dispatcher::class), Container::getInstance()))
        ->failureThreshold(2);

    attempt($circuit, failingAction('boom'));

    Event::assertNotDispatched(CircuitOpened::class);
});

it('does not dispatch HALF-OPEN or CLOSED events when it loses those races', function (): void {
    $store = Mockery::mock(CircuitStoreInterface::class)->shouldIgnoreMissing();
    $store->shouldReceive('getState')->with('racy')->andReturn(
        CircuitState::OPEN,      // leitura inicial
        CircuitState::HALF_OPEN, // releitura: outro processo venceu OPEN -> HALF-OPEN
        CircuitState::HALF_OPEN, // leitura em recordSuccess()
    );
    $store->shouldReceive('getLastTrippedAt')->with('racy')->andReturn(1);
    $store->shouldReceive('transitionState')
        ->once()
        ->with('racy', CircuitState::OPEN, CircuitState::HALF_OPEN)
        ->andReturnFalse();
    $store->shouldReceive('incrementHalfOpenSuccesses')->once()->with('racy')->andReturn(1);
    $store->shouldReceive('transitionState')
        ->once()
        ->with('racy', CircuitState::HALF_OPEN, CircuitState::CLOSED)
        ->andReturnFalse(); // outro processo venceu HALF-OPEN -> CLOSED

    $circuit = (new CircuitBreakerService('racy', $store, app(Dispatcher::class), Container::getInstance()))
        ->halfOpenTrials(1);

    expect($circuit->run(fn () => 'ok'))->toBe('ok');

    Event::assertNotDispatched(CircuitHalfOpened::class);
    Event::assertNotDispatched(CircuitClosed::class);
});

it('reports a zero retry time when an OPEN circuit has no last_tripped_at and the recovery race is lost', function (): void {
    $store = Mockery::mock(CircuitStoreInterface::class)->shouldIgnoreMissing();
    $store->shouldReceive('getState')->with('racy')->andReturn(CircuitState::OPEN);
    $store->shouldReceive('getLastTrippedAt')->with('racy')->andReturnNull();
    $store->shouldReceive('getLastFailureReason')->with('racy')->andReturnNull();
    $store->shouldReceive('transitionState')
        ->once()
        ->with('racy', CircuitState::OPEN, CircuitState::HALF_OPEN)
        ->andReturnFalse();

    $circuit = new CircuitBreakerService('racy', $store, app(Dispatcher::class), Container::getInstance());

    try {
        $circuit->run(fn () => 'x');
        $this->fail('Expected CircuitBreakerOpenException.');
    } catch (CircuitBreakerOpenException $exception) {
        expect($exception->retryAfter)->toBe(0)
            ->and($exception->lastFailureReason)->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Reset / trip manuais
|--------------------------------------------------------------------------
*/

it('trips manually and dispatches CircuitOpened only when the state actually changes', function (): void {
    $this->circuit->trip();
    $this->circuit->trip();

    expect($this->circuit->getState())->toBe(CircuitState::OPEN);
    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('forces CLOSED and zeroes every counter on reset()', function (): void {
    attempt($this->circuit, failingAction());
    attempt($this->circuit, failingAction());

    $this->circuit->reset();

    expect($this->circuit->getState())->toBe(CircuitState::CLOSED)
        ->and($this->circuit->getFailures())->toBe(0)
        ->and($this->circuit->getLastTrippedAt())->toBeNull()
        ->and($this->circuit->getLastFailureReason())->toBeNull()
        ->and($this->circuit->run(fn () => 'again'))->toBe('again');

    Event::assertDispatched(
        CircuitClosed::class,
        fn (CircuitClosed $event): bool => $event->previousState === CircuitState::OPEN,
    );
});

it('does not dispatch CircuitClosed when resetting an already closed circuit', function (): void {
    $this->circuit->reset();

    Event::assertNotDispatched(CircuitClosed::class);
});

/*
|--------------------------------------------------------------------------
| Configuração
|--------------------------------------------------------------------------
*/

it('exposes the configured limits', function (): void {
    expect($this->circuit->name())->toBe('test-service')
        ->and($this->circuit->getFailureThreshold())->toBe(2)
        ->and($this->circuit->getRecoveryTimeout())->toBe(10)
        ->and($this->circuit->getHalfOpenTrials())->toBe(2);
});

it('rejects invalid limits', function (Closure $configure): void {
    $configure(Resilience::circuitBreaker('invalid'));
})->throws(InvalidArgumentException::class)->with([
    'threshold below 1' => [fn ($circuit) => $circuit->failureThreshold(0)],
    'negative timeout' => [fn ($circuit) => $circuit->recoveryTimeout(-1)],
    'trials below 1' => [fn ($circuit) => $circuit->halfOpenTrials(0)],
]);

it('shares state between instances of the same circuit name', function (): void {
    $other = Resilience::circuitBreaker('test-service');

    $this->circuit->trip();

    expect($other->getState())->toBe(CircuitState::OPEN);
});

it('rehydrates the persisted limits on a new instance of the same circuit', function (): void {
    $again = Resilience::circuitBreaker('test-service');

    expect($again->getFailureThreshold())->toBe(2)
        ->and($again->getRecoveryTimeout())->toBe(10)
        ->and($again->getHalfOpenTrials())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Ciclo completo em todos os drivers
|--------------------------------------------------------------------------
*/

it('runs the complete CLOSED -> OPEN -> HALF-OPEN -> CLOSED lifecycle', function (string $driver): void {
    $circuit = (new CircuitBreakerService(
        'lifecycle',
        circuitStore($driver),
        app(Dispatcher::class),
        Container::getInstance(),
    ))->failureThreshold(2)->recoveryTimeout(10)->halfOpenTrials(2);

    attempt($circuit, failingAction());
    attempt($circuit, failingAction());
    expect($circuit->getState())->toBe(CircuitState::OPEN);

    $this->travel(10)->seconds();
    $circuit->run(fn () => 'ok-1');
    expect($circuit->getState())->toBe(CircuitState::HALF_OPEN);

    $circuit->run(fn () => 'ok-2');
    expect($circuit->getState())->toBe(CircuitState::CLOSED)
        ->and($circuit->getFailures())->toBe(0);

    Event::assertDispatchedTimes(CircuitOpened::class, 1);
    Event::assertDispatchedTimes(CircuitHalfOpened::class, 1);
    Event::assertDispatchedTimes(CircuitClosed::class, 1);
})->with('stores');
