<?php

namespace Esolutions\Tenancy\Http\Middleware;

use Closure;
use Esolutions\Tenancy\TenantContext;
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

        $sessionTenant = $request->session()->get(self::KEY);

        if ($sessionTenant === null) {
            $request->session()->put(self::KEY, $tenantId);

            return $next($request);
        }

        if ((string) $sessionTenant !== (string) $tenantId) {
            // La sesión viene de OTRA empresa: se descarta por completo.
            Auth::guard()->logout();
            $request->session()->flush();
            $request->session()->regenerate(true);
            $request->session()->put(self::KEY, $tenantId);
        }

        return $next($request);
    }
}
