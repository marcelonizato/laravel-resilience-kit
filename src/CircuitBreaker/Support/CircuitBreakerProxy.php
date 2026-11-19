<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Support;

use LaravelResilienceKit\CircuitBreaker\Services\AttributeCircuitBreakerRunner;

/**
 * Proxy que aplica `#[WithCircuitBreaker]` às chamadas de método do alvo.
 *
 * Obtido via `Resilience::protect($service)`. Toda chamada é repassada ao
 * objeto original; os métodos anotados passam pelo Circuit Breaker.
 *
 * Atenção: o proxy NÃO é uma instância da classe do alvo (não satisfaz
 * type hints). Use {@see self::__unwrap()} para obter o objeto original.
 *
 * @template TTarget of object
 *
 * @mixin TTarget
 */
final readonly class CircuitBreakerProxy
{
    /**
     * @param  TTarget  $target  Objeto protegido.
     */
    public function __construct(
        private object $target,
        private AttributeCircuitBreakerRunner $runner,
    ) {}

    /**
     * Repassa a chamada ao alvo, protegida quando o método tem o atributo.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->runner->call($this->target, $method, $arguments);
    }

    /**
     * Retorna o objeto original, sem proteção.
     *
     * @return TTarget
     */
    public function __unwrap(): object
    {
        return $this->target;
    }
}
