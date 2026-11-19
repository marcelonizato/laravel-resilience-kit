<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Tests\Support\Fixtures;

use LaravelResilienceKit\CircuitBreaker\Attributes\WithCircuitBreaker;
use LaravelResilienceKit\Tests\Support\StubFallbackHandler;
use RuntimeException;
use Throwable;

/**
 * Serviço de exemplo usado para testar `#[WithCircuitBreaker]`.
 */
class PaymentService
{
    /** Quantas vezes o corpo de um método protegido foi realmente executado. */
    public int $calls = 0;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    #[WithCircuitBreaker(name: 'payment-gateway', fallback: 'paymentFallback', failureThreshold: 2, recoveryTimeout: 30)]
    public function processPayment(array $payload): array
    {
        $this->calls++;

        if ($payload['fail'] ?? false) {
            throw new RuntimeException('gateway down');
        }

        return ['status' => 'ok'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function paymentFallback(array $payload, Throwable $e): array
    {
        return ['status' => 'queued_for_retry', 'reason' => $e->getMessage(), 'payload' => $payload];
    }

    #[WithCircuitBreaker]
    public function unnamed(): string
    {
        return 'unnamed-result';
    }

    #[WithCircuitBreaker(name: 'handler-based', fallback: StubFallbackHandler::class)]
    public function withHandlerFallback(): never
    {
        throw new RuntimeException('handler failure');
    }

    #[WithCircuitBreaker(name: 'broken-fallback', fallback: 'doesNotExist')]
    public function withMissingFallback(): string
    {
        return 'never used';
    }

    #[WithCircuitBreaker(name: 'named-args', fallback: 'namedFallback')]
    public function named(string $label, int $amount): never
    {
        throw new RuntimeException("failed {$label}:{$amount}");
    }

    public function namedFallback(string $label, int $amount, Throwable $error): string
    {
        return "fallback {$label}:{$amount}:{$error->getMessage()}";
    }

    #[WithCircuitBreaker(name: 'private-fallback', fallback: 'hidden')]
    public function withPrivateFallback(): never
    {
        throw new RuntimeException('private failure');
    }

    private function hidden(Throwable $e): string
    {
        return 'private:'.$e->getMessage();
    }

    #[WithCircuitBreaker(name: 'no-fallback', failureThreshold: 1)]
    public function withoutFallback(): never
    {
        throw new RuntimeException('no fallback failure');
    }

    #[WithCircuitBreaker(name: 'single-trial', fallback: 'singleTrialFallback', failureThreshold: 1, recoveryTimeout: 5, halfOpenTrials: 1)]
    public function singleTrial(bool $fail): string
    {
        if ($fail) {
            throw new RuntimeException('single trial failure');
        }

        return 'recovered';
    }

    public function singleTrialFallback(bool $fail, Throwable $e): string
    {
        return 'fallback';
    }

    /** Método sem atributo: nenhuma proteção. */
    public function plain(): never
    {
        throw new RuntimeException('plain failure');
    }
}
