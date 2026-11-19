<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use LaravelResilienceKit\Http\Controllers\CircuitApiController;
use LaravelResilienceKit\Http\Controllers\DashboardController;
use LaravelResilienceKit\Http\Middleware\AuthorizeDashboard;

/*
|--------------------------------------------------------------------------
| Rotas do Dashboard
|--------------------------------------------------------------------------
|
| Carregadas pelo ResilienceServiceProvider quando
| `resilience-kit.dashboard.enabled` é true. Os caminhos e middlewares vêm de
| `config/resilience-kit.php`; a autorização é sempre aplicada por último.
|
|  GET  {path}                         view principal do painel
|  GET  {api_path}/circuits            lista JSON dos circuitos
|  POST {api_path}/circuits/{name}/reset   reset manual de um circuito
|
*/

$middleware = array_values(array_filter(
    Arr::wrap(config('resilience-kit.dashboard.middleware', ['web', 'auth'])),
    is_string(...),
));
$middleware[] = AuthorizeDashboard::class;

$path = trim((string) config('resilience-kit.dashboard.path', 'resilience/dashboard'), '/');
$apiPath = trim((string) config('resilience-kit.dashboard.api_path', 'resilience/api'), '/');

Route::middleware($middleware)->group(function () use ($path, $apiPath): void {
    Route::get($path, [DashboardController::class, 'index'])->name('resilience.dashboard');

    Route::get($apiPath.'/circuits', [CircuitApiController::class, 'index'])
        ->name('resilience.api.circuits.index');

    // `.+` permite nomes de circuito com "/" (ex.: "payments/stripe").
    Route::post($apiPath.'/circuits/{name}/reset', [CircuitApiController::class, 'reset'])
        ->where('name', '.+')
        ->name('resilience.api.circuits.reset');
});
