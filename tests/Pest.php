<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitBreakerInterface;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;
use LaravelResilienceKit\Tests\Support\StoreFactory;
use LaravelResilienceKit\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuração global do Pest
|--------------------------------------------------------------------------
|
| Todos os testes de Unit/ e Feature/ estendem o TestCase baseado em
| Orchestra Testbench. Os testes de Unit/ pertencem ao grupo "unit"
| (`./vendor/bin/sail pest --group=unit`).
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->group('unit')->in('Unit');
pest()->group('feature')->in('Feature');

pest()->afterEach(fn () => StoreFactory::cleanup())->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Cria um driver de store isolado, ignorando o teste se ele não estiver
 * disponível no ambiente (ex.: Redis fora do ar).
 *
 * @param  'array'|'cache'|'redis'  $driver
 */
function circuitStore(string $driver): CircuitStoreInterface
{
    if (! StoreFactory::isAvailable($driver)) {
        test()->markTestSkipped("Driver [{$driver}] indisponível neste ambiente (Redis/phpredis).");
    }

    return StoreFactory::make($driver);
}

/**
 * Ação protegida que sempre falha com RuntimeException.
 *
 * @return Closure(): never
 */
function failingAction(string $message = 'Service error'): Closure
{
    return fn () => throw new RuntimeException($message);
}

/**
 * Executa a ação no circuito ignorando qualquer exceção resultante.
 */
function attempt(CircuitBreakerInterface $circuit, Closure $action): void
{
    try {
        $circuit->run($action);
    } catch (Throwable) {
        // A exceção é irrelevante: apenas o efeito sobre o circuito importa.
    }
}

/**
 * Ignora o teste corrente se não houver Redis acessível via phpredis.
 */
function skipUnlessRedis(): void
{
    if (! StoreFactory::redisAvailable()) {
        test()->markTestSkipped('Redis/phpredis indisponível neste ambiente.');
    }
}
