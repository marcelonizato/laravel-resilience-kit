<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Contracts;

use Closure;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;
use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use LaravelResilienceKit\Contracts\ResilienceFeatureInterface;

/**
 * Contrato de um Circuit Breaker nomeado, com API fluente de configuração.
 *
 * `slidingWindowSize()`, `minimumCalls()`, `failureRateThreshold()`,
 * `recoveryTimeout()` e `halfOpenTrials()` persistem o limite e passam a
 * aparecer no dashboard. Uma instância nova reidrata esses valores. Em
 * `run()`, cada limite omitido na chamada é descartado e volta ao config
 * (`circuits.{name}` ou `circuit_breaker.*`).
 */
interface CircuitBreakerInterface extends ResilienceFeatureInterface
{
    /**
     * Define quantas chamadas recentes entram na amostra. Mínimo: 1.
     */
    public function slidingWindowSize(int $size): static;

    /**
     * Define quantas chamadas a janela precisa ter antes de avaliar a taxa. Mínimo: 1.
     *
     * Um valor maior que a janela é limitado ao tamanho da janela na hora de decidir.
     */
    public function minimumCalls(int $calls): static;

    /**
     * Define a taxa de falha, em porcentagem de 1 a 100, que abre o circuito.
     */
    public function failureRateThreshold(int $percentage): static;

    /**
     * Define por quantos segundos o circuito permanece OPEN antes do HALF-OPEN. Mínimo: 0.
     */
    public function recoveryTimeout(int $seconds): static;

    /**
     * Define quantos sucessos em HALF-OPEN são necessários para fechar o circuito. Mínimo: 1.
     */
    public function halfOpenTrials(int $trials): static;

    /**
     * Define o fallback, acionado quando o circuito está aberto ou a ação falha.
     *
     * @param  Closure(\Throwable): mixed|FallbackHandlerInterface|class-string<FallbackHandlerInterface>  $fallback
     */
    public function fallback(Closure|FallbackHandlerInterface|string $fallback): static;

    /**
     * Estado atual do circuito (leitura pura, sem efeitos colaterais).
     *
     * Não aplica a transição `OPEN -> HALF-OPEN` quando o `recovery_timeout`
     * já expirou; use {@see self::resolveState()} para isso.
     */
    public function getState(): CircuitState;

    /**
     * Lê o estado e, se o `recovery_timeout` expirou, move `OPEN -> HALF-OPEN`.
     *
     * A transição é atômica: apenas um chamador concorrente efetiva a mudança
     * e dispara `CircuitHalfOpened`. Usado por `run()` e pelo dashboard.
     */
    public function resolveState(): CircuitState;

    /**
     * Amostra das chamadas mais recentes, já aparada ao tamanho da janela.
     */
    public function getWindow(): CallWindow;

    /**
     * Falhas dentro da janela atual.
     */
    public function getFailures(): int;

    /**
     * Sucessos de prova já obtidos no HALF-OPEN atual.
     */
    public function getHalfOpenSuccesses(): int;

    /**
     * Timestamp UNIX da última abertura, ou `null` se nunca abriu.
     */
    public function getLastTrippedAt(): ?int;

    /**
     * Mensagem da última falha registrada, ou `null`.
     */
    public function getLastFailureReason(): ?string;

    /**
     * Tamanho da janela configurado.
     */
    public function getSlidingWindowSize(): int;

    /**
     * Volume mínimo de chamadas configurado, antes de aplicar o teto da janela.
     */
    public function getMinimumCalls(): int;

    /**
     * Volume mínimo efetivo: nunca maior que o tamanho da janela.
     */
    public function getEffectiveMinimumCalls(): int;

    /**
     * Taxa de falha configurada, em porcentagem.
     */
    public function getFailureRateThreshold(): int;

    /**
     * Timeout de recuperação configurado, em segundos.
     */
    public function getRecoveryTimeout(): int;

    /**
     * Quantidade de sucessos em HALF-OPEN configurada.
     */
    public function getHalfOpenTrials(): int;

    /**
     * Abre o circuito manualmente.
     */
    public function trip(): void;

    /**
     * Fecha o circuito manualmente e zera a janela e os contadores.
     */
    public function reset(): void;
}
