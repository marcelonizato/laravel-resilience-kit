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
    | sliding_window_size     : quantas chamadas recentes entram na amostra.
    | minimum_calls           : chamadas exigidas na janela antes de avaliar a taxa.
    |                           Acima do tamanho da janela, vale o tamanho da janela.
    | failure_rate_threshold  : porcentagem de falhas (1–100) que abre o circuito.
    | recovery_timeout        : segundos em OPEN antes de permitir HALF-OPEN.
    | half_open_trials        : requisições de teste bem-sucedidas para fechar o circuito.
    | cache_store             : store do Laravel usado pelo driver "cache".
    | redis_connection        : conexão de config/database.php usada pelo driver "redis".
    | prefix                  : prefixo das chaves (`{prefix}{circuit}:state`, `:window`, ...).
    |
    */
    'circuit_breaker' => [
        'sliding_window_size' => (int) env('RESILIENCE_CB_SLIDING_WINDOW_SIZE', 10),
        'minimum_calls' => (int) env('RESILIENCE_CB_MINIMUM_CALLS', 10),
        'failure_rate_threshold' => (int) env('RESILIENCE_CB_FAILURE_RATE_THRESHOLD', 50),
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
    | Precedência ao criar um circuito, para cada limite:
    |   1. valor persistido pela API fluente ou #[WithCircuitBreaker]
    |   2. entrada em `circuits.{name}` (se existir)
    |   3. defaults de `circuit_breaker.*`
    |
    | O dashboard mostra essa precedência. Em run(), o limite omitido na
    | chamada deixa de ser persistido e volta ao config.
    |
    */
    'circuits' => [
        // 'payment-gateway' => [
        //     'sliding_window_size' => 20,
        //     'minimum_calls' => 10,
        //     'failure_rate_threshold' => 50,
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
