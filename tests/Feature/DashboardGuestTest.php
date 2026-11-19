<?php

declare(strict_types=1);

use LaravelResilienceKit\Facades\Resilience;

/*
|--------------------------------------------------------------------------
| Visitantes (sem autenticação)
|--------------------------------------------------------------------------
|
| Garante que o middleware `auth` do config padrão protege a API do dashboard.
|
*/

it('rejects unauthenticated requests to the api', function (): void {
    Resilience::circuitBreaker('order-service')->trip();

    $this->getJson('/resilience/api/circuits')->assertUnauthorized();
    $this->postJson('/resilience/api/circuits/order-service/reset')->assertUnauthorized();
});

it('does not reset the circuit for an unauthenticated request', function (): void {
    $circuit = Resilience::circuitBreaker('order-service');
    $circuit->trip();

    $this->postJson('/resilience/api/circuits/order-service/reset');

    expect($circuit->getState()->isOpen())->toBeTrue();
});
