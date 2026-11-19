<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Contracts;

use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use Throwable;

/**
 * Handler de fallback dedicado, alternativo ao uso de uma Closure.
 *
 * Pode ser informado como instância ou como nome de classe (resolvida pelo
 * container do Laravel, permitindo injeção de dependências).
 */
interface FallbackHandlerInterface
{
    /**
     * Produz o valor substituto da ação protegida.
     *
     * @param  Throwable  $exception  Motivo do fallback: {@see CircuitBreakerOpenException}
     *                                quando o circuito está aberto, ou a exceção lançada pela ação.
     */
    public function handle(Throwable $exception): mixed;
}
