<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Services;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitClosed;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitHalfOpened;
use LaravelResilienceKit\CircuitBreaker\Events\CircuitOpened;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Máquina de estados do Circuit Breaker (docs/circuit-breaker-spec.md, seção 1).
 *
 *  - CLOSED: executa a ação; falhas incrementam o contador e, ao atingir
 *    `failure_threshold`, o circuito abre.
 *  - OPEN: não executa a ação; lança {@see CircuitBreakerOpenException} ou usa
 *    o fallback. Após `recovery_timeout`, a próxima requisição move o circuito
 *    para HALF-OPEN.
 *  - HALF-OPEN: as requisições são provas de recuperação. `half_open_trials`
 *    sucessos fecham o circuito; qualquer falha o reabre imediatamente.
 *
 * Toda mudança de estado é um compare-and-set atômico no
 * {@see CircuitStoreInterface}: com requisições concorrentes, apenas o
 * vencedor da transição dispara o evento correspondente.
 *
 * O contador de falhas é cumulativo desde o último fechamento/reset, como
 * definido na especificação.
 *
 * O fallback é acionado quando o circuito está OPEN e também quando a própria
 * ação falha (a falha é sempre contabilizada antes). Sem fallback, a exceção
 * original da ação é relançada.
 *
 * `failureThreshold()`, `recoveryTimeout()` e `halfOpenTrials()` alteram só
 * esta instância. Uma chamada nova do mesmo circuito, sem esses métodos,
 * volta ao padrão recebido no construtor (`circuits.{name}` ou
 * `circuit_breaker.*`). O dashboard lê esse padrão, não o último override.
 */
class CircuitBreakerService implements CircuitBreakerInterface
{
    /** @var Closure(Throwable): mixed|FallbackHandlerInterface|class-string<FallbackHandlerInterface>|null */
    private Closure|FallbackHandlerInterface|string|null $fallback = null;

    private int $failureThreshold;

    private int $recoveryTimeout;

    private int $halfOpenTrials;

    /**
     * @param  string  $name  Nome único do circuito.
     * @param  CircuitStoreInterface  $store  Persistência atômica do estado.
     * @param  Dispatcher  $events  Dispatcher dos eventos de transição.
     * @param  Container  $container  Container, usado para resolver handlers de fallback por classe.
     * @param  int  $failureThreshold  Falhas para abrir o circuito.
     * @param  int  $recoveryTimeout  Segundos em OPEN antes de testar a recuperação.
     * @param  int  $halfOpenTrials  Sucessos em HALF-OPEN para fechar o circuito.
     */
    public function __construct(
        private readonly string $name,
        private readonly CircuitStoreInterface $store,
        private readonly Dispatcher $events,
        private readonly Container $container,
        int $failureThreshold = 5,
        int $recoveryTimeout = 30,
        int $halfOpenTrials = 3,
    ) {
        $this->applyFailureThreshold($failureThreshold);
        $this->applyRecoveryTimeout($recoveryTimeout);
        $this->applyHalfOpenTrials($halfOpenTrials);
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function failureThreshold(int $failures): static
    {
        $this->applyFailureThreshold($failures);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function recoveryTimeout(int $seconds): static
    {
        $this->applyRecoveryTimeout($seconds);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function halfOpenTrials(int $trials): static
    {
        $this->applyHalfOpenTrials($trials);

        return $this;
    }

    /**
     * Define o fallback, acionado quando o circuito está aberto ou a ação falha.
     *
     * A string é validada em runtime (o PHPDoc não é imposto pelo PHP).
     *
     * @param  Closure(Throwable): mixed|FallbackHandlerInterface|string  $fallback  Closure, handler ou nome de classe de um handler.
     *
     * @throws InvalidArgumentException Se a classe informada não implementar {@see FallbackHandlerInterface}.
     */
    public function fallback(Closure|FallbackHandlerInterface|string $fallback): static
    {
        if (is_string($fallback) && ! is_a($fallback, FallbackHandlerInterface::class, true)) {
            throw new InvalidArgumentException(
                sprintf('The fallback class [%s] must implement %s.', $fallback, FallbackHandlerInterface::class)
            );
        }

        $this->fallback = $fallback;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getFailureThreshold(): int
    {
        return $this->failureThreshold;
    }

    /**
     * {@inheritDoc}
     */
    public function getRecoveryTimeout(): int
    {
        return $this->recoveryTimeout;
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenTrials(): int
    {
        return $this->halfOpenTrials;
    }

    /**
     * {@inheritDoc}
     */
    public function getState(): CircuitState
    {
        return $this->store->getState($this->name);
    }

    /**
     * {@inheritDoc}
     */
    public function getFailures(): int
    {
        return $this->store->getFailures($this->name);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastTrippedAt(): ?int
    {
        return $this->store->getLastTrippedAt($this->name);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastFailureReason(): ?string
    {
        return $this->store->getLastFailureReason($this->name);
    }

    /**
     * {@inheritDoc}
     *
     * @throws CircuitBreakerOpenException Se o circuito está OPEN e não há fallback.
     * @throws Throwable Exceção original da ação, se falhar e não houver fallback.
     */
    public function run(Closure $action): mixed
    {
        if ($this->resolveState() === CircuitState::OPEN) {
            return $this->handleFailure($this->openException());
        }

        try {
            $result = $action();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            return $this->handleFailure($exception);
        }

        $this->recordSuccess();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function trip(): void
    {
        $previous = $this->getState();

        $this->store->forceState($this->name, CircuitState::OPEN);

        if ($previous !== CircuitState::OPEN) {
            $this->events->dispatch(new CircuitOpened(
                $this->name,
                $previous,
                $this->getFailures(),
                $this->getLastFailureReason(),
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): void
    {
        $previous = $this->getState();

        $this->store->reset($this->name);

        if ($previous !== CircuitState::CLOSED) {
            $this->events->dispatch(new CircuitClosed($this->name, $previous));
        }
    }

    /**
     * {@inheritDoc}
     *
     * O compare-and-set garante que apenas um chamador concorrente efetue a
     * transição e dispare {@see CircuitHalfOpened}; os demais apenas releem o
     * estado resultante.
     */
    public function resolveState(): CircuitState
    {
        $state = $this->store->getState($this->name);

        if ($state !== CircuitState::OPEN || ! $this->recoveryTimeoutElapsed()) {
            return $state;
        }

        if ($this->store->transitionState($this->name, CircuitState::OPEN, CircuitState::HALF_OPEN)) {
            $this->events->dispatch(new CircuitHalfOpened($this->name));
        }

        return $this->store->getState($this->name);
    }

    /**
     * Contabiliza uma falha e abre o circuito quando aplicável.
     *
     *  - CLOSED: abre ao atingir `failure_threshold`.
     *  - HALF-OPEN: qualquer falha reabre imediatamente (reiniciando o timer).
     *  - OPEN: apenas contabiliza (outro processo já abriu o circuito).
     */
    private function recordFailure(Throwable $exception): void
    {
        $reason = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;

        $failures = $this->store->incrementFailures($this->name, $reason);

        $state = $this->store->getState($this->name);

        $shouldOpen = match ($state) {
            CircuitState::CLOSED => $failures >= $this->failureThreshold,
            CircuitState::HALF_OPEN => true,
            CircuitState::OPEN => false,
        };

        if ($shouldOpen && $this->store->transitionState($this->name, $state, CircuitState::OPEN)) {
            $this->events->dispatch(new CircuitOpened($this->name, $state, $failures, $reason));
        }
    }

    /**
     * Contabiliza um sucesso; em HALF-OPEN, fecha o circuito ao atingir `half_open_trials`.
     */
    private function recordSuccess(): void
    {
        if ($this->store->getState($this->name) !== CircuitState::HALF_OPEN) {
            return;
        }

        $successes = $this->store->incrementHalfOpenSuccesses($this->name);

        if ($successes >= $this->halfOpenTrials
            && $this->store->transitionState($this->name, CircuitState::HALF_OPEN, CircuitState::CLOSED)) {
            $this->events->dispatch(new CircuitClosed($this->name, CircuitState::HALF_OPEN));
        }
    }

    /**
     * Executa o fallback com a exceção, ou a relança quando não há fallback.
     *
     * @throws Throwable
     */
    private function handleFailure(Throwable $exception): mixed
    {
        $fallback = $this->fallback;

        if ($fallback === null) {
            throw $exception;
        }

        if ($fallback instanceof Closure) {
            return $fallback($exception);
        }

        $handler = is_string($fallback) ? $this->container->make($fallback) : $fallback;

        if (! $handler instanceof FallbackHandlerInterface) {
            throw new UnexpectedValueException(
                sprintf('The fallback handler must implement %s.', FallbackHandlerInterface::class)
            );
        }

        return $handler->handle($exception);
    }

    /**
     * Constrói a exceção de circuito aberto com o tempo restante de espera.
     */
    private function openException(): CircuitBreakerOpenException
    {
        $trippedAt = $this->store->getLastTrippedAt($this->name);
        $retryAfter = $trippedAt === null
            ? 0
            : max(0, $trippedAt + $this->recoveryTimeout - Carbon::now()->getTimestamp());

        return new CircuitBreakerOpenException(
            $this->name,
            $retryAfter,
            $this->store->getLastFailureReason($this->name),
        );
    }

    /**
     * Valida e aplica o limite de falhas na instância, sem persistir.
     */
    private function applyFailureThreshold(int $failures): void
    {
        if ($failures < 1) {
            throw new InvalidArgumentException('The failure threshold must be at least 1.');
        }

        $this->failureThreshold = $failures;
    }

    /**
     * Valida e aplica o timeout de recuperação na instância, sem persistir.
     */
    private function applyRecoveryTimeout(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('The recovery timeout cannot be negative.');
        }

        $this->recoveryTimeout = $seconds;
    }

    /**
     * Valida e aplica a quantidade de trials na instância, sem persistir.
     */
    private function applyHalfOpenTrials(int $trials): void
    {
        if ($trials < 1) {
            throw new InvalidArgumentException('The number of half-open trials must be at least 1.');
        }

        $this->halfOpenTrials = $trials;
    }

    /**
     * Indica se o `recovery_timeout` desde a última abertura já expirou.
     *
     * Um circuito OPEN sem `last_tripped_at` (dado ausente/corrompido) é
     * considerado elegível à recuperação, para nunca ficar preso em OPEN.
     */
    private function recoveryTimeoutElapsed(): bool
    {
        $trippedAt = $this->store->getLastTrippedAt($this->name);

        return $trippedAt === null
            || Carbon::now()->getTimestamp() >= $trippedAt + $this->recoveryTimeout;
    }
}
