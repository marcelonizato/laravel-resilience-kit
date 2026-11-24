<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Drivers;

use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/**
 * Driver em memória, destinado a testes isolados.
 *
 * Os dados vivem apenas no processo corrente. Como o PHP executa de forma
 * single-thread por processo, cada operação é naturalmente atômica; este
 * driver NÃO oferece garantias entre processos.
 */
final class ArrayCircuitStore implements CircuitStoreInterface
{
    /** @var array<string, CircuitState> */
    private array $states = [];

    /** @var array<string, int> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $halfOpenSuccesses = [];

    /** @var array<string, int> */
    private array $lastTrippedAt = [];

    /** @var array<string, string> */
    private array $lastFailureReason = [];

    /** @var array<string, int> */
    private array $failureThresholds = [];

    /** @var array<string, int> */
    private array $recoveryTimeouts = [];

    /** @var array<string, int> */
    private array $halfOpenTrials = [];

    /** @var array<string, true> */
    private array $registered = [];

    /**
     * {@inheritDoc}
     */
    public function register(string $circuit): void
    {
        $this->registered[$circuit] = true;
    }

    /**
     * {@inheritDoc}
     */
    public function circuits(): array
    {
        $names = array_map(strval(...), array_keys($this->registered));
        sort($names);

        return $names;
    }

    /**
     * {@inheritDoc}
     */
    public function getState(string $circuit): CircuitState
    {
        return $this->states[$circuit] ?? CircuitState::CLOSED;
    }

    /**
     * {@inheritDoc}
     */
    public function transitionState(string $circuit, CircuitState $from, CircuitState $to): bool
    {
        if ($this->getState($circuit) !== $from) {
            return false;
        }

        $this->forceState($circuit, $to);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function forceState(string $circuit, CircuitState $state): void
    {
        $this->register($circuit);
        $this->states[$circuit] = $state;

        match ($state) {
            CircuitState::OPEN => $this->onOpen($circuit),
            CircuitState::HALF_OPEN => $this->halfOpenSuccesses[$circuit] = 0,
            CircuitState::CLOSED => $this->onClose($circuit),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function incrementFailures(string $circuit, ?string $reason = null): int
    {
        $this->register($circuit);

        if ($reason !== null && $reason !== '') {
            $this->lastFailureReason[$circuit] = $reason;
        }

        return $this->failures[$circuit] = ($this->failures[$circuit] ?? 0) + 1;
    }

    /**
     * {@inheritDoc}
     */
    public function getFailures(string $circuit): int
    {
        return $this->failures[$circuit] ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function incrementHalfOpenSuccesses(string $circuit): int
    {
        $this->register($circuit);

        return $this->halfOpenSuccesses[$circuit] = ($this->halfOpenSuccesses[$circuit] ?? 0) + 1;
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenSuccesses(string $circuit): int
    {
        return $this->halfOpenSuccesses[$circuit] ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastTrippedAt(string $circuit): ?int
    {
        return $this->lastTrippedAt[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastFailureReason(string $circuit): ?string
    {
        return $this->lastFailureReason[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $circuit): void
    {
        unset(
            $this->states[$circuit],
            $this->failures[$circuit],
            $this->halfOpenSuccesses[$circuit],
            $this->lastTrippedAt[$circuit],
            $this->lastFailureReason[$circuit],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $circuit): void
    {
        unset(
            $this->registered[$circuit],
            $this->states[$circuit],
            $this->failures[$circuit],
            $this->halfOpenSuccesses[$circuit],
            $this->lastTrippedAt[$circuit],
            $this->lastFailureReason[$circuit],
            $this->failureThresholds[$circuit],
            $this->recoveryTimeouts[$circuit],
            $this->halfOpenTrials[$circuit],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setFailureThreshold(string $circuit, int $threshold): void
    {
        $this->register($circuit);
        $this->failureThresholds[$circuit] = $threshold;
    }

    /**
     * {@inheritDoc}
     */
    public function getFailureThreshold(string $circuit): ?int
    {
        return $this->failureThresholds[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function setRecoveryTimeout(string $circuit, int $seconds): void
    {
        $this->register($circuit);
        $this->recoveryTimeouts[$circuit] = $seconds;
    }

    /**
     * {@inheritDoc}
     */
    public function getRecoveryTimeout(string $circuit): ?int
    {
        return $this->recoveryTimeouts[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function setHalfOpenTrials(string $circuit, int $trials): void
    {
        $this->register($circuit);
        $this->halfOpenTrials[$circuit] = $trials;
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenTrials(string $circuit): ?int
    {
        return $this->halfOpenTrials[$circuit] ?? null;
    }

    /**
     * Efeitos colaterais da transição para OPEN.
     */
    private function onOpen(string $circuit): void
    {
        $this->lastTrippedAt[$circuit] = Carbon::now()->getTimestamp();
        $this->halfOpenSuccesses[$circuit] = 0;
    }

    /**
     * Efeitos colaterais da transição para CLOSED.
     */
    private function onClose(string $circuit): void
    {
        unset($this->failures[$circuit], $this->halfOpenSuccesses[$circuit]);
    }
}
