<?php

namespace Esolutions\Tenancy\Filesystem;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Filesystem\FilesystemManager;

/**
 * Aísla por tenant los discos listados en `config('tenancy.filesystem.scoped_disks')`.
 *
 * SIN ESTO HAY FUGA (y pisada) DE ARCHIVOS: `Storage::put('logo.png', ...)` desde dos empresas
 * escribe el MISMO archivo. La segunda pisa a la primera, y cualquiera puede leer el archivo de la
 * otra. El TenantScope no puede detenerlo porque no pasa por la base de datos.
 *
 * Implementación: se usa el driver `scoped` de Laravel para montar el disco bajo un prefijo por
 * tenant, de modo que el código de la app no cambia.
 *
 * El prefijo se arma con `config('tenancy.filesystem.path_prefix')`, que admite dos placeholders:
 *   {slug} → subdominio del tenant (por defecto; carpetas legibles, igual que en qpospe)
 *   {id}   → clave primaria del tenant (inmutable)
 *
 * Sin tenant en contexto no se prefija (panel central, comandos del sistema).
 */
class TenantFilesystemManager extends FilesystemManager
{
    /**
     * FilesystemManager cachea los discos resueltos por NOMBRE. Sin esto, el primer tenant que
     * pide `local` deja su disco (ya prefijado) en cache y el siguiente recibe **el del otro**.
     * Se cachea por nombre + tenant.
     */
    public function disk($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();
        $tenant = $this->app->make(TenantContext::class)->get();

        if ($tenant === null || ! $this->shouldScope($name)) {
            return parent::disk($name);
        }

        $key = $name.'@'.$tenant->getTenantKey();

        return $this->disks[$key] ??= $this->resolve($name);
    }

    protected function resolve($name, $config = null)
    {
        $tenant = $this->app->make(TenantContext::class)->get();

        if ($tenant !== null && $this->shouldScope($name)) {
            return $this->createScopedDriver([
                'disk' => $name,
                'prefix' => $this->prefixFor($tenant),
            ]);
        }

        return parent::resolve($name, $config);
    }

    private function prefixFor(mixed $tenant): string
    {
        $pattern = config('tenancy.filesystem.path_prefix', 'tenants/{slug}');
        $slugColumn = config('tenancy.slug_column', 'slug');

        return strtr($pattern, [
            '{slug}' => (string) ($tenant->{$slugColumn} ?? $tenant->getTenantKey()),
            '{id}' => (string) $tenant->getTenantKey(),
            // alias retrocompatible
            '{tenant}' => (string) ($tenant->{$slugColumn} ?? $tenant->getTenantKey()),
        ]);
    }

    private function shouldScope(string $name): bool
    {
        return in_array($name, (array) config('tenancy.filesystem.scoped_disks', []), true);
    }
}
