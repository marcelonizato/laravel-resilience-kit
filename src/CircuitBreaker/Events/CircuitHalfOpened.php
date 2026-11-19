<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Events;

/**
 * Disparado quando o `recovery_timeout` expira e o circuito passa de OPEN
 * para HALF-OPEN, iniciando o teste de recuperação.
 */
final readonly class CircuitHalfOpened
{
    /**
     * @param  string  $circuit  Nome do circuito.
     */
    public function __construct(public string $circuit) {}
}
