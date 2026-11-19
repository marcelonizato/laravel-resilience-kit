<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Events;

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/**
 * Disparado quando o circuito volta a CLOSED, seja por recuperação bem-sucedida
 * (HALF-OPEN -> CLOSED) ou por reset manual.
 */
final readonly class CircuitClosed
{
    /**
     * @param  string  $circuit  Nome do circuito.
     * @param  CircuitState  $previousState  Estado anterior ao fechamento.
     */
    public function __construct(
        public string $circuit,
        public CircuitState $previousState,
    ) {}
}
