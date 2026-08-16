<?php

namespace Esolutions\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impide que las rutas de TENANT respondan en un dominio central (el del panel del proveedor).
 * Los dominios centrales se listan en config/tenancy.php.
 */
class PreventAccessFromCentralDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            in_array($request->getHost(), config('tenancy.central_domains', []), true),
            404,
            'Esta ruta no está disponible en el dominio central.'
        );

        return $next($request);
    }
}
