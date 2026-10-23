<?php

namespace Esolutions\Tenancy\Http\Middleware;

use Closure;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ata la sesión al tenant que la originó.
 *
 * Sin esto, una cookie de sesión emitida en `acme.midominio.com` seguiría siendo válida en
 * `globex.midominio.com` (las cookies se comparten entre subdominios de un mismo dominio padre):
 * un usuario autenticado en una empresa quedaría autenticado en otra.
 *
 * Debe correr DESPUÉS de StartSession y de ResolveTenant.
 */
class ScopeSessionToTenant
{
    public const KEY = '_tenant_id';

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return $next($request);
        }

        // Request sin sesión (API stateless con Bearer): no hay cookie que pueda viajar a otro
        // subdominio, así que no hay nada que atar. Sin esto, el grupo `tenant` no se puede usar
        // en rutas `api` — reventaría con "Session store not set on request".
        if (! $request->hasSession()) {
            return $next($request);
        }

        $sessionTenant = $request->session()->get(self::KEY);

        if ($sessionTenant === null) {
            $request->session()->put(self::KEY, $tenantId);

            return $next($request);
        }

        if ((string) $sessionTenant !== (string) $tenantId) {
            // La sesión viene de OTRA empresa: se descarta por completo.
            //
            // El logout solo aplica a los guards de sesión. `Auth::guard()` devuelve el guard por
            // DEFECTO, y ese no siempre es uno: el middleware `auth:web,api` deja como default al
            // que autenticó, así que en una app con Bearer puede ser un TokenGuard, que no tiene
            // `logout()` y revienta con BadMethodCallException. Lo que de verdad corta la sesión
            // es el flush + regenerate de abajo; el logout es para el usuario ya resuelto en
            // memoria.
            $guard = Auth::guard();

            if ($guard instanceof StatefulGuard) {
                $guard->logout();
            }

            $request->session()->flush();
            $request->session()->regenerate(true);
            $request->session()->put(self::KEY, $tenantId);
        }

        return $next($request);
    }
}
