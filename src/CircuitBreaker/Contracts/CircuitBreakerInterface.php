<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Contracts;

use Closure;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use LaravelResilienceKit\Contracts\ResilienceFeatureInterface;

/**
 * Contrato de um Circuit Breaker nomeado, com API fluente de configuração.
 *
 * Os métodos de configuração alteram a instância corrente e retornam `$this`.
 */
interface CircuitBreakerInterface extends ResilienceFeatureInterface
{
    /**
     * Define quantas falhas abrem o circuito (CLOSED -> OPEN). Mínimo: 1.
     */
    public function failureThreshold(int $failures): static;

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
     * Número de falhas acumuladas desde o último fechamento/reset.
     */
    public function getFailures(): int;

    /**
     * Timestamp UNIX da última abertura, ou `null` se nunca abriu.
     */
    public function getLastTrippedAt(): ?int;

    /**
     * Mensagem da última falha registrada, ou `null`.
     */
    public function getLastFailureReason(): ?string;

    /**
     * Limite de falhas configurado.
     */
    public function getFailureThreshold(): int;

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
     * Fecha o circuito manualmente e zera todos os contadores.
     */
    public function reset(): void;
}
