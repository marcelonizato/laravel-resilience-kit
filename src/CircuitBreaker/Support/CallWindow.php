<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Support;

/**
 * Amostra das chamadas mais recentes de um circuito.
 *
 * A janela é contada (as últimas N chamadas), não um contador cumulativo.
 * Um sucesso entra na amostra e empurra o resultado mais antigo para fora;
 * não zera as falhas que ainda estão dentro da janela.
 */
final readonly class CallWindow
{
    /**
     * @param  int  $calls  Chamadas presentes na janela.
     * @param  int  $failures  Falhas dentre essas chamadas.
     */
    public function __construct(
        public int $calls,
        public int $failures,
    ) {}

    /**
     * Janela vazia: nenhuma chamada amostrada.
     */
    public static function empty(): self
    {
        return new self(0, 0);
    }

    /**
     * Resume uma lista de resultados, do mais recente para o mais antigo.
     *
     * Cada item é `1` (falha) ou `0` (sucesso).
     *
     * @param  list<int>  $outcomes
     */
    public static function fromOutcomes(array $outcomes): self
    {
        $failures = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome === 1) {
                $failures++;
            }
        }

        return new self(count($outcomes), $failures);
    }

    /**
     * Sucessos ainda dentro da janela.
     */
    public function successes(): int
    {
        return max(0, $this->calls - $this->failures);
    }

    /**
     * Taxa de falha em porcentagem inteira (0–100), arredondada para baixo.
     *
     * A decisão de abrir o circuito não usa este arredondamento: ela compara
     * `failures * 100` com `threshold * calls`, para não perder o caso exato
     * (1 falha em 2 chamadas com limite de 50%).
     */
    public function failureRate(): int
    {
        if ($this->calls === 0) {
            return 0;
        }

        return intdiv($this->failures * 100, $this->calls);
    }

    /**
     * Indica se a amostra já autoriza a abertura do circuito.
     *
     * As duas condições são independentes, no mesmo modelo do Resilience4j:
     * há pelo menos `$minimumCalls` chamadas na janela, e a taxa de falha
     * atingiu `$failureRateThreshold` (1–100).
     */
    public function shouldOpen(int $minimumCalls, int $failureRateThreshold): bool
    {
        if ($this->calls < $minimumCalls) {
            return false;
        }

        return $this->failures * 100 >= $failureRateThreshold * $this->calls;
    }
}
