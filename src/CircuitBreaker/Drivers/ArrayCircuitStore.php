<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Drivers;

use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;

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

    /** @var array<string, list<int>> Resultados mais recentes primeiro (`1` falha, `0` sucesso). */
    private array $windows = [];

    /** @var array<string, int> */
    private array $halfOpenSuccesses = [];

    /** @var array<string, int> */
    private array $lastTrippedAt = [];

    /** @var array<string, string> */
    private array $lastFailureReason = [];

    /** @var array<string, int> */
    private array $slidingWindowSizes = [];

    /** @var array<string, int> */
    private array $minimumCalls = [];

    /** @var array<string, int> */
    private array $failureRateThresholds = [];

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
    public function recordOutcome(string $circuit, bool $failure, int $windowSize, ?string $reason = null): CallWindow
    {
        $this->register($circuit);

        if ($failure && $reason !== null && $reason !== '') {
            $this->lastFailureReason[$circuit] = $reason;
        }

        $outcomes = $this->windows[$circuit] ?? [];
        array_unshift($outcomes, $failure ? 1 : 0);
        $outcomes = array_slice($outcomes, 0, $this->normalizeWindowSize($windowSize));
        $this->windows[$circuit] = $outcomes;

        return CallWindow::fromOutcomes($outcomes);
    }

    /**
     * {@inheritDoc}
     */
    public function getWindow(string $circuit, int $windowSize): CallWindow
    {
        $outcomes = array_slice($this->windows[$circuit] ?? [], 0, $this->normalizeWindowSize($windowSize));

        if ($outcomes === []) {
            unset($this->windows[$circuit]);
        } else {
            $this->windows[$circuit] = $outcomes;
        }

        return CallWindow::fromOutcomes($outcomes);
    }

    /**
     * {@inheritDoc}
     */
    public function rememberFailureReason(string $circuit, string $reason): void
    {
        $this->register($circuit);

        if ($reason !== '') {
            $this->lastFailureReason[$circuit] = $reason;
        }
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
            $this->windows[$circuit],
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
            $this->windows[$circuit],
            $this->halfOpenSuccesses[$circuit],
            $this->lastTrippedAt[$circuit],
            $this->lastFailureReason[$circuit],
            $this->slidingWindowSizes[$circuit],
            $this->minimumCalls[$circuit],
            $this->failureRateThresholds[$circuit],
            $this->recoveryTimeouts[$circuit],
            $this->halfOpenTrials[$circuit],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setSlidingWindowSize(string $circuit, int $size): void
    {
        $this->register($circuit);
        $this->slidingWindowSizes[$circuit] = $size;
    }

    /**
     * {@inheritDoc}
     */
    public function getSlidingWindowSize(string $circuit): ?int
    {
        return $this->slidingWindowSizes[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetSlidingWindowSize(string $circuit): void
    {
        unset($this->slidingWindowSizes[$circuit]);
    }

    /**
     * {@inheritDoc}
     */
    public function setMinimumCalls(string $circuit, int $calls): void
    {
        $this->register($circuit);
        $this->minimumCalls[$circuit] = $calls;
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimumCalls(string $circuit): ?int
    {
        return $this->minimumCalls[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetMinimumCalls(string $circuit): void
    {
        unset($this->minimumCalls[$circuit]);
    }

    /**
     * {@inheritDoc}
     */
    public function setFailureRateThreshold(string $circuit, int $percentage): void
    {
        $this->register($circuit);
        $this->failureRateThresholds[$circuit] = $percentage;
    }

    /**
     * {@inheritDoc}
     */
    public function getFailureRateThreshold(string $circuit): ?int
    {
        return $this->failureRateThresholds[$circuit] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetFailureRateThreshold(string $circuit): void
    {
        unset($this->failureRateThresholds[$circuit]);
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
    public function forgetRecoveryTimeout(string $circuit): void
    {
        unset($this->recoveryTimeouts[$circuit]);
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
     * {@inheritDoc}
     */
    public function forgetHalfOpenTrials(string $circuit): void
    {
        unset($this->halfOpenTrials[$circuit]);
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
        unset($this->windows[$circuit], $this->halfOpenSuccesses[$circuit]);
    }

    /**
     * Garante um tamanho de janela utilizável.
     */
    private function normalizeWindowSize(int $windowSize): int
    {
        return max(1, $windowSize);
    }
}
