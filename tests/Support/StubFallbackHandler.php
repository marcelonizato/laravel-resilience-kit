<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Tests\Support;

use LaravelResilienceKit\Contracts\FallbackHandlerInterface;
use Throwable;

/**
 * Handler de fallback de teste: devolve a classe da exceção recebida.
 */
final class StubFallbackHandler implements FallbackHandlerInterface
{
    public function handle(Throwable $exception): mixed
    {
        return 'handled:'.class_basename($exception);
    }
}
