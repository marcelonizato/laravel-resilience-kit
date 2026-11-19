<?php

declare(strict_types=1);

namespace LaravelResilienceKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaravelResilienceKit\CircuitBreaker\CircuitBreakerManager;
use LaravelResilienceKit\CircuitBreaker\Contracts\CircuitStoreInterface;

/**
 * Service Provider do Laravel Resilience Kit.
 *
 * Registra a configuração e os bindings do módulo de Circuit Breaker e, no
 * boot, carrega as views e as rotas do dashboard (quando habilitado).
 */
class ResilienceServiceProvider extends ServiceProvider
{
    /**
     * Registra a configuração e os bindings do pacote.
     *
     * Bindings:
     *  - {@see CircuitBreakerManager} (alias `resilience`): singleton usado pela Facade `Resilience`;
     *  - {@see CircuitStoreInterface}: store do driver padrão, para injeção de dependências.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resilience-kit.php', 'resilience-kit');

        $this->app->singleton(
            CircuitBreakerManager::class,
            static fn (Application $app): CircuitBreakerManager => new CircuitBreakerManager($app),
        );

        $this->app->alias(CircuitBreakerManager::class, 'resilience');

        $this->app->bind(
            CircuitStoreInterface::class,
            static fn (Application $app): CircuitStoreInterface => $app->make(CircuitBreakerManager::class)->store(),
        );
    }

    /**
     * Publica os recursos do pacote para a aplicação hospedeira.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resilience-kit');

        if (config('resilience-kit.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/resilience-kit.php' => config_path('resilience-kit.php'),
            ], 'resilience-kit-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/resilience-kit'),
            ], 'resilience-kit-views');
        }
    }
}
