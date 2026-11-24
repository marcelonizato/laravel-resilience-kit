<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Renderiza a view principal do dashboard. Os dados são carregados no
 * navegador a partir da API ({@see CircuitApiController}).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly Factory $views) {}

    /**
     * Exibe o painel de monitoramento dos circuitos.
     */
    public function index(Request $request): View
    {
        return $this->views->make('resilience-kit::dashboard.index', [
            'circuitsUrl' => route('resilience.api.circuits.index'),
            'resetUrlTemplate' => route('resilience.api.circuits.reset', ['name' => '__NAME__']),
            'removeUrlTemplate' => route('resilience.api.circuits.destroy', ['name' => '__NAME__']),
            'csrfToken' => $request->hasSession() ? $request->session()->token() : '',
        ]);
    }
}
