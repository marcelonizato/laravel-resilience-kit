<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Support;

/**
 * Gerador das chaves de armazenamento (Redis / Cache) de um circuito.
 *
 * Layout definido em docs/circuit-breaker-spec.md (seção 2):
 *
 *  - `{prefix}{circuit}:state`
 *  - `{prefix}{circuit}:window` (lista das últimas chamadas: `1` falha, `0` sucesso)
 *  - `{prefix}{circuit}:half_open_successes`
 *  - `{prefix}{circuit}:last_tripped_at`
 *  - `{prefix}{circuit}:last_failure_reason`
 *  - `{prefix}{circuit}:sliding_window_size`
 *  - `{prefix}{circuit}:minimum_calls`
 *  - `{prefix}{circuit}:failure_rate_threshold`
 *  - `{prefix}{circuit}:recovery_timeout`
 *  - `{prefix}{circuit}:half_open_trials`
 *
 * As cinco últimas são configuração do circuito e não entram em {@see self::all()}:
 * o reset apaga estado e a janela, mas preserva os limites.
 */
final readonly class CircuitKeys
{
    /**
     * @param  string  $prefix  Prefixo das chaves (ex.: `resilience:cb:`).
     */
    public function __construct(private string $prefix = 'resilience:cb:') {}

    /** Chave do estado (`closed` | `open` | `half_open`). */
    public function state(string $circuit): string
    {
        return $this->key($circuit, 'state');
    }

    /** Chave da janela deslizante (lista, mais recente primeiro). */
    public function window(string $circuit): string
    {
        return $this->key($circuit, 'window');
    }

    /** Chave do contador atômico de sucessos em half-open. */
    public function halfOpenSuccesses(string $circuit): string
    {
        return $this->key($circuit, 'half_open_successes');
    }

    /** Chave do timestamp UNIX da última abertura. */
    public function lastTrippedAt(string $circuit): string
    {
        return $this->key($circuit, 'last_tripped_at');
    }

    /** Chave da mensagem da última falha. */
    public function lastFailureReason(string $circuit): string
    {
        return $this->key($circuit, 'last_failure_reason');
    }

    /** Chave do tamanho da janela deslizante configurado para o circuito. */
    public function slidingWindowSize(string $circuit): string
    {
        return $this->key($circuit, 'sliding_window_size');
    }

    /** Chave do volume mínimo de chamadas configurado para o circuito. */
    public function minimumCalls(string $circuit): string
    {
        return $this->key($circuit, 'minimum_calls');
    }

    /** Chave da taxa de falha (porcentagem) configurada para o circuito. */
    public function failureRateThreshold(string $circuit): string
    {
        return $this->key($circuit, 'failure_rate_threshold');
    }

    /** Chave do timeout de recuperação configurado, em segundos. */
    public function recoveryTimeout(string $circuit): string
    {
        return $this->key($circuit, 'recovery_timeout');
    }

    /** Chave da quantidade de trials em HALF-OPEN configurada. */
    public function halfOpenTrials(string $circuit): string
    {
        return $this->key($circuit, 'half_open_trials');
    }

    /**
     * Chaves de configuração do circuito (preservadas no reset).
     *
     * @return list<string>
     */
    public function configuration(string $circuit): array
    {
        return [
            $this->slidingWindowSize($circuit),
            $this->minimumCalls($circuit),
            $this->failureRateThreshold($circuit),
            $this->recoveryTimeout($circuit),
            $this->halfOpenTrials($circuit),
        ];
    }

    /** Chave do lock distribuído por circuito (usado pelo driver de cache). */
    public function lock(string $circuit): string
    {
        return $this->key($circuit, 'lock');
    }

    /** Chave do índice de circuitos registrados. */
    public function index(): string
    {
        return $this->prefix.'circuits';
    }

    /** Chave do lock do índice de circuitos (usado pelo driver de cache). */
    public function indexLock(): string
    {
        return $this->prefix.'circuits:lock';
    }

    /**
     * Chaves de estado e contadores de um circuito (exclui configuração, locks e índice).
     *
     * @return list<string>
     */
    public function all(string $circuit): array
    {
        return [
            $this->state($circuit),
            $this->window($circuit),
            $this->halfOpenSuccesses($circuit),
            $this->lastTrippedAt($circuit),
            $this->lastFailureReason($circuit),
        ];
    }

    private function key(string $circuit, string $suffix): string
    {
        return $this->prefix.$circuit.':'.$suffix;
    }
}
