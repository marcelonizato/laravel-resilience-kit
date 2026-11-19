<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Contracts;

use Closure;

/**
 * Contrato comum a todos os recursos de resiliência do pacote.
 *
 * Circuit Breaker, e futuramente Bulkheads, Rate Limiters e Timeouts, são
 * módulos independentes que protegem a execução de uma ação e implementam
 * este contrato, permitindo composição e extensão sem breaking changes.
 */
interface ResilienceFeatureInterface
{
    /**
     * Nome identificador da instância do recurso (ex.: nome do circuito).
     */
    public function name(): string;

    /**
     * Executa a ação sob a proteção do recurso de resiliência.
     *
     * @param  Closure(): mixed  $action  Ação protegida.
     * @return mixed Resultado da ação ou, quando aplicável, do fallback.
     */
    public function run(Closure $action): mixed;
}
