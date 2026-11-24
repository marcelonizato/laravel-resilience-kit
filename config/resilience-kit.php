<?php

declare(strict_types=1);

/**
 * Configuração do Laravel Resilience Kit.
 *
 * Publique com: php artisan vendor:publish --tag="resilience-kit-config"
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Driver padrão de armazenamento
    |--------------------------------------------------------------------------
    |
    | Define onde o estado dos circuitos é persistido.
    | Valores suportados: "redis" (atômico, recomendado), "cache" e "array"
    | (em memória, indicado para testes isolados).
    |
    */
    'default_driver' => env('RESILIENCE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | failure_threshold : falhas acumuladas (desde o último fechamento/reset) para abrir o circuito (CLOSED -> OPEN).
    | recovery_timeout  : segundos em OPEN antes de permitir HALF-OPEN.
    | half_open_trials  : requisições de teste bem-sucedidas para fechar o circuito.
    | cache_store       : store do Laravel usado pelo driver "cache".
    | redis_connection  : conexão de config/database.php usada pelo driver "redis".
    | prefix            : prefixo das chaves (`{prefix}{circuit}:state`, `:failures`, ...).
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => (int) env('RESILIENCE_CB_FAILURE_THRESHOLD', 5),
        'recovery_timeout' => (int) env('RESILIENCE_CB_RECOVERY_TIMEOUT', 30),
        'half_open_trials' => (int) env('RESILIENCE_CB_HALF_OPEN_TRIALS', 3),
        'cache_store' => env('RESILIENCE_CB_CACHE_STORE', 'redis'),
        'redis_connection' => env('RESILIENCE_CB_REDIS_CONNECTION', 'default'),
        'prefix' => 'resilience:cb:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuitos nomeados
    |--------------------------------------------------------------------------
    |
    | Limites por nome de circuito. Chaves omitidas herdam o default global
    | de `circuit_breaker` acima.
    |
    | Precedência ao criar um circuito:
    |   1. defaults de `circuit_breaker.*`
    |   2. entrada em `circuits.{name}` (se existir)
    |
    | API fluente e #[WithCircuitBreaker] alteram só aquela instância.
    | A chamada seguinte, sem esses parâmetros, volta a esta configuração.
    |
    */
    'circuits' => [
        // 'payment-gateway' => [
        //     'failure_threshold' => 3,
        //     'recovery_timeout' => 60,
        //     'half_open_trials' => 2,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | path     : rota da view principal do painel.
    | api_path : prefixo da API JSON (listagem e reset manual de circuitos).
    |
    */
    'dashboard' => [
        'enabled' => (bool) env('RESILIENCE_DASHBOARD_ENABLED', true),
        'path' => env('RESILIENCE_DASHBOARD_PATH', 'resilience/dashboard'),
        'api_path' => env('RESILIENCE_DASHBOARD_API_PATH', 'resilience/api'),
        'middleware' => ['web', 'auth'],
    ],

];
