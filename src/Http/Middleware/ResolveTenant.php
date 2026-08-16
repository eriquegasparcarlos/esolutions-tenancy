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
 *
 * CACHE: se cachea SOLO el mapeo host → id (que casi nunca cambia); el modelo del tenant se lee
 * fresco en cada request (un find() por PK). Así, desactivar o borrar un tenant aplica DE
 * INMEDIATO — con el modelo cacheado, una cuenta suspendida seguía operando hasta que venciera
 * el TTL. Además, las claves se invalidan al cambiar dominios/slug (ver TenancyServiceProvider).
 */
class ResolveTenant
{
    /** Sentinela para cachear también los hosts desconocidos (evita martillar la BD con 404s). */
    private const MISS = '__none__';

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
        $tenantModel = config('tenancy.tenant_model');

        if ($ttl <= 0) {
            $id = $this->resolveId($host);

            return $id === null ? null : $tenantModel::find($id);
        }

        $id = cache()->remember(
            "tenancy:host:{$host}",
            $ttl,
            fn () => $this->resolveId($host) ?? self::MISS,
        );

        // El modelo SIEMPRE se lee fresco: is_active/soft-delete aplican al instante.
        return $id === self::MISS ? null : $tenantModel::find($id);
    }

    private function resolveId(string $host): int|string|null
    {
        $domainModel = config('tenancy.domain_model');
        $tenantModel = config('tenancy.tenant_model');

        $domain = $domainModel::where('domain', $host)->first();

        if ($domain !== null) {
            return $domain->tenant_id;
        }

        $subdomain = explode('.', $host)[0] ?? null;
        $slugColumn = config('tenancy.slug_column', 'slug');

        return $subdomain ? $tenantModel::where($slugColumn, $subdomain)->value('id') : null;
    }
}
