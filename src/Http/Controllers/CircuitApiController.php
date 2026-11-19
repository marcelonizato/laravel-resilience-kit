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
 * Os limites exibidos (`failure_threshold`, `recovery_timeout`,
 * `half_open_trials`) são os padrões de `config/resilience-kit.php`: ajustes
 * feitos por circuito via API fluente ou atributo não são persistidos no store.
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
     * Serializa um circuito para o formato da API.
     *
     * @return array<string, mixed>
     */
    private function present(CircuitBreakerInterface $circuit): array
    {
        $state = $circuit->getState();
        $trippedAt = $circuit->getLastTrippedAt();

        $retryAfter = $state === CircuitState::OPEN && $trippedAt !== null
            ? max(0, $trippedAt + $circuit->getRecoveryTimeout() - Carbon::now()->getTimestamp())
            : null;

        return [
            'name' => $circuit->name(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'failures_count' => $circuit->getFailures(),
            'failure_threshold' => $circuit->getFailureThreshold(),
            'recovery_timeout' => $circuit->getRecoveryTimeout(),
            'half_open_trials' => $circuit->getHalfOpenTrials(),
            'last_tripped_at' => $trippedAt === null ? null : Carbon::createFromTimestamp($trippedAt)->toIso8601String(),
            'last_failure_reason' => $circuit->getLastFailureReason(),
            'retry_after' => $retryAfter,
        ];
    }
}
