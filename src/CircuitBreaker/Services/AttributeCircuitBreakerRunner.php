<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Services;

use InvalidArgumentException;
use LaravelResilienceKit\CircuitBreaker\Attributes\WithCircuitBreaker;
use LaravelResilienceKit\CircuitBreaker\CircuitBreakerManager;
use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Executa métodos aplicando o atributo {@see WithCircuitBreaker}.
 *
 * Métodos sem o atributo são repassados diretamente, sem nenhuma proteção.
 */
class AttributeCircuitBreakerRunner
{
    /** @var array<string, WithCircuitBreaker|null> Cache de atributos por `Classe::método`. */
    private array $attributes = [];

    public function __construct(private readonly CircuitBreakerManager $manager) {}

    /**
     * Invoca `$method` em `$target`, protegido pelo circuito declarado no atributo.
     *
     * @param  object  $target  Objeto cujo método será executado.
     * @param  string  $method  Nome do método.
     * @param  array<int|string, mixed>  $arguments  Argumentos (posicionais ou nomeados).
     *
     * @throws InvalidArgumentException Se o fallback declarado não existir.
     */
    public function call(object $target, string $method, array $arguments = []): mixed
    {
        $attribute = $this->attributeFor($target::class, $method);

        if ($attribute === null) {
            return $target->{$method}(...$arguments);
        }

        $circuit = $this->manager->circuitBreaker($attribute->name ?? $target::class.'::'.$method);

        if ($attribute->failureThreshold !== null) {
            $circuit->failureThreshold($attribute->failureThreshold);
        }

        if ($attribute->recoveryTimeout !== null) {
            $circuit->recoveryTimeout($attribute->recoveryTimeout);
        }

        if ($attribute->halfOpenTrials !== null) {
            $circuit->halfOpenTrials($attribute->halfOpenTrials);
        }

        if ($attribute->fallback !== null) {
            $circuit->fallback($this->resolveFallback($target, $attribute->fallback, $arguments));
        }

        return $circuit->run(fn (): mixed => $target->{$method}(...$arguments));
    }

    /**
     * Lê (com cache) o atributo do método, ou `null` se ausente.
     *
     * @param  class-string  $class
     */
    private function attributeFor(string $class, string $method): ?WithCircuitBreaker
    {
        $key = $class.'::'.$method;

        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        try {
            $attributes = (new ReflectionMethod($class, $method))->getAttributes(WithCircuitBreaker::class);
        } catch (ReflectionException) {
            // Método inexistente na classe (ex.: resolvido via __call do alvo).
            return $this->attributes[$key] = null;
        }

        return $this->attributes[$key] = $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Converte o `fallback` do atributo em algo aceito por `CircuitBreakerInterface::fallback()`.
     *
     * @param  array<int|string, mixed>  $arguments  Argumentos da chamada original.
     * @return \Closure(Throwable): mixed|class-string<FallbackHandlerInterface>
     */
    private function resolveFallback(object $target, string $fallback, array $arguments): \Closure|string
    {
        if (is_a($fallback, FallbackHandlerInterface::class, true)) {
            return $fallback;
        }

        try {
            $reflection = new ReflectionMethod($target, $fallback);
        } catch (ReflectionException) {
            throw new InvalidArgumentException(sprintf(
                'The fallback [%s] declared in #[WithCircuitBreaker] is neither a method of %s nor a %s class.',
                $fallback,
                $target::class,
                FallbackHandlerInterface::class,
            ));
        }

        return function (Throwable $exception) use ($reflection, $target, $arguments): mixed {
            $parameters = $reflection->getParameters();
            $last = $parameters === [] ? null : $parameters[array_key_last($parameters)]->getName();

            // Argumentos nomeados não aceitam um posicional depois: usa o nome do último parâmetro.
            if (! array_is_list($arguments) && $last !== null) {
                $arguments[$last] = $exception;
            } else {
                $arguments[] = $exception;
            }

            return $reflection->invokeArgs($target, $arguments);
        };
    }
}
