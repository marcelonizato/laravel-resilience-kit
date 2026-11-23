<?php

declare(strict_types=1);

use LaravelResilienceKit\ResilienceServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(ResilienceServiceProvider::class))->not->toBeEmpty();
});

it('merges the default package configuration', function (): void {
    expect(config('resilience-kit.circuit_breaker.failure_threshold'))->toBe(5)
        ->and(config('resilience-kit.circuit_breaker.recovery_timeout'))->toBe(30)
        ->and(config('resilience-kit.circuit_breaker.half_open_trials'))->toBe(3)
        ->and(config('resilience-kit.circuit_breaker.prefix'))->toBe('resilience:cb:')
        ->and(config('resilience-kit.circuits'))->toBe([])
        ->and(config('resilience-kit.dashboard.middleware'))->toBe(['web', 'auth']);
});

it('uses the array driver in the test environment', function (): void {
    expect(config('resilience-kit.default_driver'))->toBe('array');
});
