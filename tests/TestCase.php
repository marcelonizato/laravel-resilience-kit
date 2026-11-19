<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaravelResilienceKit\ResilienceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Caso de teste base do pacote, executado sobre o Orchestra Testbench.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Configura o ambiente de teste.
     *
     * O grupo de middlewares `web` (usado pelo dashboard) criptografa cookies
     * e exige uma APP_KEY.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /**
     * Registra os service providers do pacote no ambiente de teste.
     *
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ResilienceServiceProvider::class,
        ];
    }
}
