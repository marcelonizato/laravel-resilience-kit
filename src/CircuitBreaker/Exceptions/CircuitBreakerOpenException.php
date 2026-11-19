<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Exceptions;

use RuntimeException;

/**
 * Lançada quando uma requisição é curto-circuitada por um circuito OPEN e
 * nenhum fallback foi configurado.
 */
class CircuitBreakerOpenException extends RuntimeException
{
    /**
     * @param  string  $circuit  Nome do circuito aberto.
     * @param  int  $retryAfter  Segundos restantes até o circuito aceitar uma requisição de teste.
     * @param  string|null  $lastFailureReason  Mensagem da última falha que contribuiu para a abertura.
     */
    public function __construct(
        public readonly string $circuit,
        public readonly int $retryAfter = 0,
        public readonly ?string $lastFailureReason = null,
    ) {
        parent::__construct(sprintf(
            'Circuit [%s] is open; requests are short-circuited. Retry in %d second(s).',
            $circuit,
            $retryAfter,
        ));
    }
}
