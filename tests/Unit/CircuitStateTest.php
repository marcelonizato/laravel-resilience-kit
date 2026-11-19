<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

it('uses the persisted values defined in the spec', function (): void {
    expect(CircuitState::CLOSED->value)->toBe('closed')
        ->and(CircuitState::OPEN->value)->toBe('open')
        ->and(CircuitState::HALF_OPEN->value)->toBe('half_open');
});

it('exposes dashboard labels', function (): void {
    expect(CircuitState::CLOSED->label())->toBe('CLOSED')
        ->and(CircuitState::OPEN->label())->toBe('OPEN')
        ->and(CircuitState::HALF_OPEN->label())->toBe('HALF-OPEN');
});

it('knows whether it short-circuits requests', function (): void {
    expect(CircuitState::OPEN->isOpen())->toBeTrue()
        ->and(CircuitState::CLOSED->isOpen())->toBeFalse()
        ->and(CircuitState::HALF_OPEN->isOpen())->toBeFalse();
});

it('resolves stored values falling back to CLOSED', function (mixed $stored, CircuitState $expected): void {
    expect(CircuitState::fromStored($stored))->toBe($expected);
})->with([
    'open' => ['open', CircuitState::OPEN],
    'half_open' => ['half_open', CircuitState::HALF_OPEN],
    'closed' => ['closed', CircuitState::CLOSED],
    'missing (null)' => [null, CircuitState::CLOSED],
    'missing (false)' => [false, CircuitState::CLOSED],
    'invalid' => ['garbage', CircuitState::CLOSED],
]);
