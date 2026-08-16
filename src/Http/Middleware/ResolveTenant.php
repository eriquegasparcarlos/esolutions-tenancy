<?php

namespace Esolutions\Tenancy\Http\Middleware;

use Closure;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant a partir del HOST de la request y lo pone en contexto.
 *
 * REGLA DE ORO: el tenant sale del dominio, NUNCA de un parámetro del cliente
 * (?tenant_id=, header, body). Eso es lo que hace que el aislamiento no se pueda burlar.
 *
 * Busca primero el host completo (dominio propio del cliente: `facturacion.acme.com`) y si no,
 * el subdominio contra la columna slug del modelo de tenant (`acme.miapp.com`).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $tenant = $this->resolve($host);

        abort_if($tenant === null, 404, 'Dominio no registrado.');
        abort_if(! $tenant->isTenantActive(), 403, 'Esta cuenta está inactiva.');

        app(TenantContext::class)->set($tenant);

        return $next($request);
    }

    private function resolve(string $host): mixed
    {
        $ttl = (int) config('tenancy.resolution_cache_ttl', 300);

        $resolver = function () use ($host) {
            $domainModel = config('tenancy.domain_model');
            $tenantModel = config('tenancy.tenant_model');

            $domain = $domainModel::with('tenant')->where('domain', $host)->first();

            if ($domain?->tenant) {
                return $domain->tenant;
            }

            $subdomain = explode('.', $host)[0] ?? null;
            $slugColumn = config('tenancy.slug_column', 'slug');

            return $subdomain ? $tenantModel::where($slugColumn, $subdomain)->first() : null;
        };

        if ($ttl <= 0) {
            return $resolver();
        }

        return cache()->remember("tenancy:host:{$host}", $ttl, $resolver);
    }
}
