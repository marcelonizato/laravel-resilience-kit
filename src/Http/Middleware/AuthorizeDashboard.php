<?php

declare(strict_types=1);

namespace LaravelResilienceKit\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza o acesso ao dashboard e à sua API.
 *
 * Regras (mesmo modelo de Horizon/Telescope):
 *  - se a aplicação definiu o gate `viewResilienceDashboard`, ele decide;
 *  - caso contrário, o acesso só é liberado nos ambientes `local` e `testing`.
 *
 * Exemplo (AppServiceProvider):
 *
 * ```php
 * Gate::define('viewResilienceDashboard', fn ($user) => $user->isAdmin());
 * ```
 *
 * A autenticação em si é responsabilidade dos middlewares configurados em
 * `resilience-kit.dashboard.middleware` (padrão: `web`, `auth`).
 */
class AuthorizeDashboard
{
    /** Nome do gate que a aplicação pode definir para liberar o acesso. */
    public const string GATE = 'viewResilienceDashboard';

    public function __construct(private readonly Gate $gate) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->gate->has(self::GATE)
            ? $this->gate->allows(self::GATE)
            : app()->environment('local', 'testing');

        abort_unless($allowed, Response::HTTP_FORBIDDEN, 'Access to the Resilience dashboard is not authorized.');

        return $next($request);
    }
}
