<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Events;

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/**
 * Disparado por quem efetivamente abriu o circuito (CLOSED -> OPEN, ou
 * HALF-OPEN -> OPEN após falha em requisição de teste).
 */
final readonly class CircuitOpened
{
    /**
     * @param  string  $circuit  Nome do circuito.
     * @param  CircuitState  $previousState  Estado anterior (CLOSED ou HALF_OPEN).
     * @param  int  $failures  Contador de falhas no momento da abertura.
     * @param  string|null  $reason  Mensagem da última falha registrada.
     */
    public function __construct(
        public string $circuit,
        public CircuitState $previousState,
        public int $failures,
        public ?string $reason = null,
    ) {}
}
