<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use LaravelResilienceKit\CircuitBreaker\CircuitBreakerManager;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/**
 * API JSON do dashboard: listagem dos circuitos e reset manual.
 *
 * A amostra exibida (`calls_count`, `failures_count`, `failure_rate`) é a
 * janela deslizante. Os limites (`sliding_window_size`, `minimum_calls`,
 * `failure_rate_threshold`, `recovery_timeout`, `half_open_trials`) seguem o
 * último valor persistido pela API fluente ou pelo atributo. O que não foi
 * definido usa `circuits.{name}` ou os defaults de `circuit_breaker`.
 */
class CircuitApiController extends Controller
{
    public function __construct(private readonly CircuitBreakerManager $manager) {}

    /**
     * Lista todos os circuitos registrados com seu estado e métricas.
     */
    public function index(): JsonResponse
    {
        $circuits = array_map(
            fn (string $name): array => $this->present($this->manager->circuitBreaker($name)),
            $this->manager->store()->circuits(),
        );

        return response()->json(['circuits' => $circuits]);
    }

    /**
     * Executa o reset do circuito informado (estado CLOSED e contadores zerados).
     */
    public function reset(string $name): JsonResponse
    {
        if (! in_array($name, $this->manager->store()->circuits(), true)) {
            return response()->json(['message' => "Circuit {$name} not found"], 404);
        }

        $this->manager->circuitBreaker($name)->reset();

        return response()->json(['message' => "Circuit {$name} reset successfully"]);
    }

    /**
     * Remove o circuito do índice e apaga estado, contadores e limites.
     *
     * Uma chamada posterior com o mesmo nome registra o circuito de novo.
     */
    public function destroy(string $name): JsonResponse
    {
        if (! in_array($name, $this->manager->store()->circuits(), true)) {
            return response()->json(['message' => "Circuit {$name} not found"], 404);
        }

        $this->manager->forget($name);

        return response()->json(['message' => "Circuit {$name} removed successfully"]);
    }

    /**
     * Serializa um circuito para o formato da API.
     *
     * Usa {@see CircuitBreakerInterface::resolveState()} para que, após o
     * `recovery_timeout`, o dashboard reflita `HALF-OPEN` (e dispare o evento)
     * sem depender de uma nova requisição de negócio.
     *
     * @return array<string, mixed>
     */
    private function present(CircuitBreakerInterface $circuit): array
    {
        $state = $circuit->resolveState();
        $trippedAt = $circuit->getLastTrippedAt();

        $retryAfter = $state === CircuitState::OPEN && $trippedAt !== null
            ? max(0, $trippedAt + $circuit->getRecoveryTimeout() - Carbon::now()->getTimestamp())
            : null;

        $window = $circuit->getWindow();

        return [
            'name' => $circuit->name(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'calls_count' => $window->calls,
            'failures_count' => $window->failures,
            'failure_rate' => $window->failureRate(),
            'sliding_window_size' => $circuit->getSlidingWindowSize(),
            'minimum_calls' => $circuit->getMinimumCalls(),
            'effective_minimum_calls' => $circuit->getEffectiveMinimumCalls(),
            'failure_rate_threshold' => $circuit->getFailureRateThreshold(),
            'half_open_successes' => $circuit->getHalfOpenSuccesses(),
            'half_open_trials' => $circuit->getHalfOpenTrials(),
            'recovery_timeout' => $circuit->getRecoveryTimeout(),
            'last_tripped_at' => $trippedAt === null ? null : Carbon::createFromTimestamp($trippedAt)->toIso8601String(),
            'last_failure_reason' => $circuit->getLastFailureReason(),
            'retry_after' => $retryAfter,
        ];
    }
}
