<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitClosed;
use LaravelResilienceKit\Facades\Resilience;

beforeEach(function (): void {
    // Middlewares padrão do pacote: ['web', 'auth'].
    $this->actingAs(new GenericUser(['id' => 1]));
});

/*
|--------------------------------------------------------------------------
| API: listagem
|--------------------------------------------------------------------------
*/

it('can fetch circuit status list via dashboard api', function (): void {
    Resilience::circuitBreaker('payment-service')->getState();

    $this->getJson('/resilience/api/circuits')
        ->assertStatus(200)
        ->assertJsonStructure([
            'circuits' => [
                '*' => ['name', 'state', 'failures_count', 'failure_threshold', 'recovery_timeout'],
            ],
        ]);
});

it('returns an empty list when no circuit is registered', function (): void {
    $this->getJson('/resilience/api/circuits')
        ->assertOk()
        ->assertExactJson(['circuits' => []]);
});

it('reports the live state and metrics of every circuit, sorted by name', function (): void {
    config()->set('resilience-kit.circuit_breaker.recovery_timeout', 60);

    Resilience::circuitBreaker('zeta-service')->getState();

    $broken = Resilience::circuitBreaker('alpha-service')->failureThreshold(1);
    attempt($broken, failingAction('database down'));

    $this->travel(15)->seconds();

    $this->getJson('/resilience/api/circuits')
        ->assertOk()
        ->assertJsonCount(2, 'circuits')
        ->assertJsonPath('circuits.0.name', 'alpha-service')
        ->assertJsonPath('circuits.0.state', 'open')
        ->assertJsonPath('circuits.0.state_label', 'OPEN')
        ->assertJsonPath('circuits.0.failures_count', 1)
        ->assertJsonPath('circuits.0.recovery_timeout', 60)
        ->assertJsonPath('circuits.0.half_open_trials', 3)
        ->assertJsonPath('circuits.0.last_failure_reason', 'database down')
        ->assertJsonPath('circuits.0.retry_after', 45)
        ->assertJsonPath('circuits.1.name', 'zeta-service')
        ->assertJsonPath('circuits.1.state', 'closed')
        ->assertJsonPath('circuits.1.state_label', 'CLOSED')
        ->assertJsonPath('circuits.1.failures_count', 0)
        ->assertJsonPath('circuits.1.last_tripped_at', null)
        ->assertJsonPath('circuits.1.last_failure_reason', null)
        ->assertJsonPath('circuits.1.retry_after', null);

    $trippedAt = $this->getJson('/resilience/api/circuits')->json('circuits.0.last_tripped_at');

    expect($trippedAt)->toBeString()
        ->and(Carbon\Carbon::parse($trippedAt)->getTimestamp())->toBe($broken->getLastTrippedAt());
});

/*
|--------------------------------------------------------------------------
| API: reset manual
|--------------------------------------------------------------------------
*/

it('can manually reset a circuit via dashboard api', function (): void {
    $cb = Resilience::circuitBreaker('order-service')->failureThreshold(1);

    try {
        $cb->run(fn () => throw new Exception('Error'));
    } catch (Throwable) {
    }

    expect($cb->getState())->toBe(CircuitState::OPEN);

    $this->postJson('/resilience/api/circuits/order-service/reset')
        ->assertStatus(200)
        ->assertJson(['message' => 'Circuit order-service reset successfully']);

    expect($cb->getState())->toBe(CircuitState::CLOSED)
        ->and($cb->getFailures())->toBe(0)
        ->and($cb->getLastFailureReason())->toBeNull();
});

it('dispatches CircuitClosed when a reset closes an open circuit', function (): void {
    Event::fake([CircuitClosed::class]);

    $cb = Resilience::circuitBreaker('order-service');
    $cb->trip();

    $this->postJson('/resilience/api/circuits/order-service/reset')->assertOk();

    Event::assertDispatched(
        CircuitClosed::class,
        fn (CircuitClosed $event): bool => $event->circuit === 'order-service'
            && $event->previousState === CircuitState::OPEN,
    );
});

it('keeps a reset circuit listed with its cleared counters', function (): void {
    $cb = Resilience::circuitBreaker('order-service');
    $cb->trip();

    $this->postJson('/resilience/api/circuits/order-service/reset')->assertOk();

    $this->getJson('/resilience/api/circuits')
        ->assertJsonPath('circuits.0.name', 'order-service')
        ->assertJsonPath('circuits.0.state', 'closed');
});

it('returns 404 and does not register unknown circuits on reset', function (): void {
    $this->postJson('/resilience/api/circuits/ghost-service/reset')
        ->assertNotFound()
        ->assertJson(['message' => 'Circuit ghost-service not found']);

    expect(Resilience::store()->circuits())->toBe([]);
});

it('resets circuits whose names contain special characters', function (string $name): void {
    $cb = Resilience::circuitBreaker($name);
    $cb->trip();

    $this->postJson('/resilience/api/circuits/'.rawurlencode($name).'/reset')
        ->assertOk()
        ->assertJson(['message' => "Circuit {$name} reset successfully"]);

    expect($cb->getState())->toBe(CircuitState::CLOSED);
})->with([
    'with slash' => 'payments/stripe',
    'with backslash and colons' => 'App\\Services\\Pay::charge',
    'with space' => 'order service',
    'unicode' => 'cobrança-serviço',
]);

it('does not allow resetting through GET', function (): void {
    Resilience::circuitBreaker('order-service')->getState();

    $this->getJson('/resilience/api/circuits/order-service/reset')->assertStatus(405);
});

/*
|--------------------------------------------------------------------------
| Dashboard (view)
|--------------------------------------------------------------------------
*/

it('renders the dashboard view with the api endpoints', function (): void {
    $this->get('/resilience/dashboard')
        ->assertOk()
        ->assertViewIs('resilience-kit::dashboard.index')
        ->assertViewHas('circuitsUrl', url('/resilience/api/circuits'))
        ->assertSee('Circuit Breakers')
        ->assertSee('csrf-token', false)
        // Embutida no <script> via @json (com barras escapadas).
        ->assertSee(json_encode(url('/resilience/api/circuits')), false)
        ->assertSee('__NAME__', false);
});

it('names the dashboard routes', function (): void {
    expect(route('resilience.dashboard', absolute: false))->toBe('/resilience/dashboard')
        ->and(route('resilience.api.circuits.index', absolute: false))->toBe('/resilience/api/circuits')
        ->and(route('resilience.api.circuits.reset', ['name' => 'x'], false))->toBe('/resilience/api/circuits/x/reset');
});

/*
|--------------------------------------------------------------------------
| Autenticação e autorização
|--------------------------------------------------------------------------
*/

it('denies access when the gate rejects the user', function (): void {
    Gate::define('viewResilienceDashboard', fn ($user = null): bool => false);

    $this->getJson('/resilience/api/circuits')->assertForbidden();
    $this->postJson('/resilience/api/circuits/order-service/reset')->assertForbidden();
    $this->get('/resilience/dashboard')->assertForbidden();
});

it('allows access when the gate approves the user', function (): void {
    Gate::define('viewResilienceDashboard', fn ($user = null): bool => $user?->getAuthIdentifier() === 1);

    $this->getJson('/resilience/api/circuits')->assertOk();
    $this->get('/resilience/dashboard')->assertOk();
});

it('denies access outside local/testing when no gate is defined', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->getJson('/resilience/api/circuits')->assertForbidden();
    $this->get('/resilience/dashboard')->assertForbidden();
});

it('lets a defined gate override the production default', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    Gate::define('viewResilienceDashboard', fn ($user = null): bool => true);

    $this->getJson('/resilience/api/circuits')->assertOk();
});
