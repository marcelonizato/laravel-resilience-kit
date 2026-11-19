<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Attributes;

use Attribute;

/**
 * Protege um método com um Circuit Breaker.
 *
 * O atributo é apenas declarativo: ele é aplicado quando o método é invocado
 * através de `Resilience::protect($objeto)` ou `Resilience::call(...)`.
 *
 * O fallback (opcional) pode ser:
 *  - o nome de um método da mesma classe, invocado com os mesmos argumentos da
 *    chamada original seguidos da exceção (`Throwable`);
 *  - o nome de uma classe que implemente `FallbackHandlerInterface`.
 *
 * Os limites não informados usam os padrões de `config/resilience-kit.php`.
 *
 * ```php
 * #[WithCircuitBreaker(name: 'payment-gateway', fallback: 'paymentFallback', failureThreshold: 3)]
 * public function processPayment(array $payload): array { ... }
 *
 * public function paymentFallback(array $payload, \Throwable $e): array { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WithCircuitBreaker
{
    /**
     * @param  string|null  $name  Nome do circuito; padrão: `Classe::método`.
     * @param  string|null  $fallback  Método da classe ou classe de FallbackHandlerInterface.
     * @param  int|null  $failureThreshold  Falhas para abrir o circuito.
     * @param  int|null  $recoveryTimeout  Segundos em OPEN antes do HALF-OPEN.
     * @param  int|null  $halfOpenTrials  Sucessos em HALF-OPEN para fechar o circuito.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $fallback = null,
        public ?int $failureThreshold = null,
        public ?int $recoveryTimeout = null,
        public ?int $halfOpenTrials = null,
    ) {}
}
