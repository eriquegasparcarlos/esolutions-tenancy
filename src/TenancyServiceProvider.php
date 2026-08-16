<?php

namespace Esolutions\Tenancy;

use Esolutions\Tenancy\Cache\TenantAwareCacheManager;
use Esolutions\Tenancy\Console\TenantsRunCommand;
use Esolutions\Tenancy\Filesystem\TenantFilesystemManager;
use Esolutions\Tenancy\Http\Middleware\PreventAccessFromCentralDomain;
use Esolutions\Tenancy\Http\Middleware\ResolveTenant;
use Esolutions\Tenancy\Http\Middleware\ScopeSessionToTenant;
use Esolutions\Tenancy\Queue\TenantQueueBootstrapper;
use Esolutions\Tenancy\Tenancy;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        $this->app->singleton(TenantContext::class);

        $this->registerCacheManager();
        $this->registerFilesystemManager();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        $this->registerMiddlewareGroup();
        $this->registerQueueTenancy();
        $this->registerResolutionCacheInvalidation();

        if ($this->app->runningInConsole()) {
            $this->commands([TenantsRunCommand::class]);
        }
    }

    /**
     * Reemplaza el CacheManager para que TODO store (file, redis, memcached, database…) quede
     * aislado por tenant. Sin esto, dos empresas comparten las mismas claves de cache — fuga
     * silenciosa que el TenantScope no puede detener porque no pasa por la base de datos.
     */
    private function registerCacheManager(): void
    {
        if (! config('tenancy.cache.isolate', true)) {
            return;
        }

        $this->app->extend('cache', fn ($manager, $app) => new TenantAwareCacheManager($app));
    }

    /**
     * Reemplaza el FilesystemManager para montar los discos de tenant bajo `tenants/{id}/`.
     * Sin esto, dos empresas escriben y leen los mismos archivos.
     */
    private function registerFilesystemManager(): void
    {
        if (empty(config('tenancy.filesystem.scoped_disks', []))) {
            return;
        }

        $this->app->extend('filesystem', fn ($manager, $app) => new TenantFilesystemManager($app));
    }

    /**
     * Propaga el tenant a las colas: se estampa en el payload al encolar y se restaura al
     * procesar. Necesario para redis/database/sqs, donde el worker es un proceso de larga vida
     * sin request HTTP.
     */
    private function registerQueueTenancy(): void
    {
        if (! config('tenancy.queue.propagate', true)) {
            return;
        }

        (new TenantQueueBootstrapper($this->app))->bootstrap();
    }

    /**
     * Invalida el cache host→tenant cuando cambian los datos que lo alimentan (dominios, slug).
     * Sin esto, re-puntear un dominio tardaría hasta el TTL en aplicar. (Desactivar un tenant NO
     * depende de esto: el estado se lee fresco en cada request — solo se cachea el ID.)
     */
    private function registerResolutionCacheInvalidation(): void
    {
        $tenantModel = config('tenancy.tenant_model');
        $domainModel = config('tenancy.domain_model');

        if (class_exists($tenantModel)) {
            $flush = fn ($tenant) => Tenancy::flushResolutionCache($tenant);
            $tenantModel::saved($flush);
            $tenantModel::deleted($flush);
        }

        if (class_exists($domainModel)) {
            $flushHosts = function ($domain) {
                Tenancy::flushHost($domain->domain);

                // Si el dominio fue RENOMBRADO, la clave vieja también debe morir.
                $original = $domain->getOriginal('domain');
                if ($original && $original !== $domain->domain) {
                    Tenancy::flushHost($original);
                }
            };
            $domainModel::saved($flushHosts);
            $domainModel::deleted($flushHosts);
        }
    }

    /**
     * Registra el grupo de middleware (por defecto 'tenant'). EL ORDEN IMPORTA:
     *  1. descarta el dominio central
     *  2. resuelve el tenant desde el host
     *  3. ata la sesión al tenant (requiere StartSession, del grupo 'web')
     *
     * Uso en rutas:  Route::middleware(['web', 'tenant'])->group(...)
     */
    private function registerMiddlewareGroup(): void
    {
        $router = $this->app->make(Router::class);
        $group = config('tenancy.middleware_group', 'tenant');

        $router->middlewareGroup($group, [
            PreventAccessFromCentralDomain::class,
            ResolveTenant::class,
            ScopeSessionToTenant::class,
        ]);
    }
}
