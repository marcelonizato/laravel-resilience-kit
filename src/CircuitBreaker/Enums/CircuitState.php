<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Enums;

/**
 * Estados da máquina de estados do Circuit Breaker.
 *
 * O valor de cada case é exatamente o valor persistido na chave
 * `resilience:cb:{circuit}:state` (ver docs/circuit-breaker-spec.md, seção 2).
 */
enum CircuitState: string
{
    /** Operação normal: as requisições chegam ao serviço protegido. */
    case CLOSED = 'closed';

    /** Curto-circuito ativo: as requisições falham rápido ou usam o fallback. */
    case OPEN = 'open';

    /** Teste de recuperação: aceita um número limitado de requisições de prova. */
    case HALF_OPEN = 'half_open';

    /**
     * Rótulo legível usado pelo dashboard (`CLOSED`, `OPEN`, `HALF-OPEN`).
     */
    public function label(): string
    {
        return match ($this) {
            self::CLOSED => 'CLOSED',
            self::OPEN => 'OPEN',
            self::HALF_OPEN => 'HALF-OPEN',
        };
    }

    /**
     * Indica se, neste estado, as requisições devem ser curto-circuitadas.
     */
    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Resolve o estado persistido, assumindo CLOSED quando ausente ou inválido.
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::CLOSED) : self::CLOSED;
    }
}
