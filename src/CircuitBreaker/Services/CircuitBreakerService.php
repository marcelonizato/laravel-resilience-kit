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
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;
use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Máquina de estados do Circuit Breaker (docs/circuit-breaker-spec.md, seção 1).
 *
 *  - CLOSED: executa a ação e grava o resultado na janela das últimas N
 *    chamadas. O circuito abre quando a janela tem pelo menos `minimum_calls`
 *    amostras e a taxa de falha atinge `failure_rate_threshold`. Um sucesso
 *    entra na amostra; não zera as falhas que ainda estão na janela. A janela
 *    também pode abrir num sucesso, quando essa chamada completa o volume
 *    mínimo e a taxa já está no limite.
 *  - OPEN: não executa a ação; lança {@see CircuitBreakerOpenException} ou usa
 *    o fallback. Após `recovery_timeout`, a próxima requisição move o circuito
 *    para HALF-OPEN.
 *  - HALF-OPEN: as requisições são provas de recuperação e não entram na
 *    janela. `half_open_trials` sucessos fecham o circuito e apagam a amostra;
 *    qualquer falha o reabre imediatamente.
 *
 * Toda mudança de estado é um compare-and-set atômico no
 * {@see CircuitStoreInterface}: com requisições concorrentes, apenas o
 * vencedor da transição dispara o evento correspondente.
 *
 * O fallback é acionado quando o circuito está OPEN e também quando a própria
 * ação falha (a falha é sempre contabilizada antes). Sem fallback, a exceção
 * original da ação é relançada.
 *
 * Os métodos fluentes gravam o limite no store. O dashboard e uma instância
 * nova do mesmo nome mostram esses valores. Em `run()`, o limite que não foi
 * definido nessa chamada é apagado do store e volta ao padrão do construtor
 * (`circuits.{name}` ou `circuit_breaker.*`).
 */
class CircuitBreakerService implements CircuitBreakerInterface
{
    /** @var Closure(Throwable): mixed|FallbackHandlerInterface|class-string<FallbackHandlerInterface>|null */
    private Closure|FallbackHandlerInterface|string|null $fallback = null;

    private int $slidingWindowSize;

    private int $minimumCalls;

    private int $failureRateThreshold;

    private int $recoveryTimeout;

    private int $halfOpenTrials;

    private readonly int $configuredSlidingWindowSize;

    private readonly int $configuredMinimumCalls;

    private readonly int $configuredFailureRateThreshold;

    private readonly int $configuredRecoveryTimeout;

    private readonly int $configuredHalfOpenTrials;

    private bool $slidingWindowSizeExplicit = false;

    private bool $minimumCallsExplicit = false;

    private bool $failureRateThresholdExplicit = false;

    private bool $recoveryTimeoutExplicit = false;

    private bool $halfOpenTrialsExplicit = false;

    /**
     * @param  string  $name  Nome único do circuito.
     * @param  CircuitStoreInterface  $store  Persistência atômica do estado.
     * @param  Dispatcher  $events  Dispatcher dos eventos de transição.
     * @param  Container  $container  Container, usado para resolver handlers de fallback por classe.
     * @param  int  $slidingWindowSize  Chamadas recentes que entram na amostra.
     * @param  int  $minimumCalls  Chamadas exigidas antes de avaliar a taxa.
     * @param  int  $failureRateThreshold  Porcentagem de falhas que abre o circuito.
     * @param  int  $recoveryTimeout  Segundos em OPEN antes de testar a recuperação.
     * @param  int  $halfOpenTrials  Sucessos em HALF-OPEN para fechar o circuito.
     */
    public function __construct(
        private readonly string $name,
        private readonly CircuitStoreInterface $store,
        private readonly Dispatcher $events,
        private readonly Container $container,
        int $slidingWindowSize = 10,
        int $minimumCalls = 10,
        int $failureRateThreshold = 50,
        int $recoveryTimeout = 30,
        int $halfOpenTrials = 3,
    ) {
        $this->configuredSlidingWindowSize = $slidingWindowSize;
        $this->configuredMinimumCalls = $minimumCalls;
        $this->configuredFailureRateThreshold = $failureRateThreshold;
        $this->configuredRecoveryTimeout = $recoveryTimeout;
        $this->configuredHalfOpenTrials = $halfOpenTrials;

        $this->applySlidingWindowSize($this->resolveLimit(
            $store->getSlidingWindowSize($name),
            $slidingWindowSize,
            1,
        ));
        $this->applyMinimumCalls($this->resolveLimit(
            $store->getMinimumCalls($name),
            $minimumCalls,
            1,
        ));
        $this->applyFailureRateThreshold($this->resolveLimit(
            $store->getFailureRateThreshold($name),
            $failureRateThreshold,
            1,
            100,
        ));
        $this->applyRecoveryTimeout($this->resolveLimit(
            $store->getRecoveryTimeout($name),
            $recoveryTimeout,
            0,
        ));
        $this->applyHalfOpenTrials($this->resolveLimit(
            $store->getHalfOpenTrials($name),
            $halfOpenTrials,
            1,
        ));
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
    public function slidingWindowSize(int $size): static
    {
        $this->applySlidingWindowSize($size);
        $this->slidingWindowSizeExplicit = true;
        $this->store->setSlidingWindowSize($this->name, $size);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function minimumCalls(int $calls): static
    {
        $this->applyMinimumCalls($calls);
        $this->minimumCallsExplicit = true;
        $this->store->setMinimumCalls($this->name, $calls);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function failureRateThreshold(int $percentage): static
    {
        $this->applyFailureRateThreshold($percentage);
        $this->failureRateThresholdExplicit = true;
        $this->store->setFailureRateThreshold($this->name, $percentage);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function recoveryTimeout(int $seconds): static
    {
        $this->applyRecoveryTimeout($seconds);
        $this->recoveryTimeoutExplicit = true;
        $this->store->setRecoveryTimeout($this->name, $seconds);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function halfOpenTrials(int $trials): static
    {
        $this->applyHalfOpenTrials($trials);
        $this->halfOpenTrialsExplicit = true;
        $this->store->setHalfOpenTrials($this->name, $trials);

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
    public function getSlidingWindowSize(): int
    {
        return $this->slidingWindowSize;
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimumCalls(): int
    {
        return $this->minimumCalls;
    }

    /**
     * {@inheritDoc}
     */
    public function getEffectiveMinimumCalls(): int
    {
        return min($this->minimumCalls, $this->slidingWindowSize);
    }

    /**
     * {@inheritDoc}
     */
    public function getFailureRateThreshold(): int
    {
        return $this->failureRateThreshold;
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
    public function getWindow(): CallWindow
    {
        return $this->store->getWindow($this->name, $this->slidingWindowSize);
    }

    /**
     * {@inheritDoc}
     */
    public function getFailures(): int
    {
        return $this->getWindow()->failures;
    }

    /**
     * {@inheritDoc}
     */
    public function getHalfOpenSuccesses(): int
    {
        return $this->store->getHalfOpenSuccesses($this->name);
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
        $this->commitLimits();

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
            $this->dispatchOpened($previous, $this->getWindow(), $this->getLastFailureReason());
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
     *  - CLOSED: a falha entra na janela; abre se a taxa e o volume mínimo forem atingidos.
     *  - HALF-OPEN: qualquer falha reabre imediatamente, sem entrar na janela.
     *  - OPEN: a falha entra na janela (chamada que já estava em curso) e não reabre.
     */
    private function recordFailure(Throwable $exception): void
    {
        $reason = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
        $state = $this->store->getState($this->name);

        if ($state === CircuitState::HALF_OPEN) {
            $this->store->rememberFailureReason($this->name, $reason);

            if ($this->store->transitionState($this->name, CircuitState::HALF_OPEN, CircuitState::OPEN)) {
                $this->dispatchOpened($state, $this->getWindow(), $reason);
            }

            return;
        }

        $window = $this->store->recordOutcome($this->name, true, $this->slidingWindowSize, $reason);
        $state = $this->store->getState($this->name);

        if ($state === CircuitState::CLOSED && $this->windowShouldOpen($window)
            && $this->store->transitionState($this->name, CircuitState::CLOSED, CircuitState::OPEN)) {
            $this->dispatchOpened(CircuitState::CLOSED, $window, $reason);
        }
    }

    /**
     * Contabiliza um sucesso.
     *
     * Em CLOSED o sucesso entra na janela e pode abrir o circuito se completar
     * o volume mínimo com a taxa já no limite. Em HALF-OPEN, fecha o circuito
     * ao atingir `half_open_trials`.
     */
    private function recordSuccess(): void
    {
        $state = $this->store->getState($this->name);

        if ($state === CircuitState::CLOSED) {
            $window = $this->store->recordOutcome($this->name, false, $this->slidingWindowSize);

            if ($this->windowShouldOpen($window)
                && $this->store->getState($this->name) === CircuitState::CLOSED
                && $this->store->transitionState($this->name, CircuitState::CLOSED, CircuitState::OPEN)) {
                $this->dispatchOpened(CircuitState::CLOSED, $window, $this->getLastFailureReason());
            }

            return;
        }

        if ($state !== CircuitState::HALF_OPEN) {
            return;
        }

        $successes = $this->store->incrementHalfOpenSuccesses($this->name);

        if ($successes >= $this->halfOpenTrials
            && $this->store->transitionState($this->name, CircuitState::HALF_OPEN, CircuitState::CLOSED)) {
            $this->events->dispatch(new CircuitClosed($this->name, CircuitState::HALF_OPEN));
        }
    }

    /**
     * Indica se a amostra corrente justifica abrir o circuito.
     */
    private function windowShouldOpen(CallWindow $window): bool
    {
        return $window->shouldOpen($this->getEffectiveMinimumCalls(), $this->failureRateThreshold);
    }

    /**
     * Dispara {@see CircuitOpened} com a amostra que motivou a abertura.
     */
    private function dispatchOpened(CircuitState $previous, CallWindow $window, ?string $reason): void
    {
        $this->events->dispatch(new CircuitOpened(
            $this->name,
            $previous,
            $window->failures,
            $window->calls,
            $window->failureRate(),
            $reason,
        ));
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
     * Valida e aplica o tamanho da janela na instância, sem persistir.
     */
    private function applySlidingWindowSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The sliding window size must be at least 1.');
        }

        $this->slidingWindowSize = $size;
    }

    /**
     * Valida e aplica o volume mínimo na instância, sem persistir.
     */
    private function applyMinimumCalls(int $calls): void
    {
        if ($calls < 1) {
            throw new InvalidArgumentException('The minimum number of calls must be at least 1.');
        }

        $this->minimumCalls = $calls;
    }

    /**
     * Valida e aplica a taxa de falha na instância, sem persistir.
     */
    private function applyFailureRateThreshold(int $percentage): void
    {
        if ($percentage < 1 || $percentage > 100) {
            throw new InvalidArgumentException('The failure rate threshold must be between 1 and 100.');
        }

        $this->failureRateThreshold = $percentage;
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
     * Grava os limites definidos nesta chamada e apaga os que foram omitidos.
     *
     * O dashboard passa a mostrar só o que esta execução informou; o restante
     * volta ao config (`circuits.{name}` ou `circuit_breaker.*`).
     */
    private function commitLimits(): void
    {
        if ($this->slidingWindowSizeExplicit) {
            $this->store->setSlidingWindowSize($this->name, $this->slidingWindowSize);
        } else {
            $this->applySlidingWindowSize($this->configuredSlidingWindowSize);
            $this->store->forgetSlidingWindowSize($this->name);
        }

        if ($this->minimumCallsExplicit) {
            $this->store->setMinimumCalls($this->name, $this->minimumCalls);
        } else {
            $this->applyMinimumCalls($this->configuredMinimumCalls);
            $this->store->forgetMinimumCalls($this->name);
        }

        if ($this->failureRateThresholdExplicit) {
            $this->store->setFailureRateThreshold($this->name, $this->failureRateThreshold);
        } else {
            $this->applyFailureRateThreshold($this->configuredFailureRateThreshold);
            $this->store->forgetFailureRateThreshold($this->name);
        }

        if ($this->recoveryTimeoutExplicit) {
            $this->store->setRecoveryTimeout($this->name, $this->recoveryTimeout);
        } else {
            $this->applyRecoveryTimeout($this->configuredRecoveryTimeout);
            $this->store->forgetRecoveryTimeout($this->name);
        }

        if ($this->halfOpenTrialsExplicit) {
            $this->store->setHalfOpenTrials($this->name, $this->halfOpenTrials);
        } else {
            $this->applyHalfOpenTrials($this->configuredHalfOpenTrials);
            $this->store->forgetHalfOpenTrials($this->name);
        }
    }

    /**
     * Prefere o valor persistido quando ele respeita os limites; senão, o padrão.
     */
    private function resolveLimit(?int $stored, int $fallback, int $minimum, ?int $maximum = null): int
    {
        if ($stored === null || $stored < $minimum || ($maximum !== null && $stored > $maximum)) {
            return $fallback;
        }

        return $stored;
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
