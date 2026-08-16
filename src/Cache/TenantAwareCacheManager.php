<?php

namespace Esolutions\Tenancy\Cache;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Store;

/**
 * CacheManager que envuelve CADA store resuelto en un {@see TenantAwareStore}, de modo que todo el
 * cache de la app (incluido `Cache::store('redis')`, rate limiter, etc.) queda aislado por tenant
 * sin que el código de la app tenga que acordarse de nada.
 *
 * Se desactiva con `config('tenancy.cache.isolate' => false)`.
 */
class TenantAwareCacheManager extends CacheManager
{
    public function repository(Store $store, array $config = [])
    {
        if (config('tenancy.cache.isolate', true) && ! $store instanceof TenantAwareStore) {
            $store = new TenantAwareStore($store, $this->app->make(TenantContext::class));
        }

        return parent::repository($store, $config);
    }
}
