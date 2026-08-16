<?php

namespace Esolutions\Tenancy\Concerns;

use Esolutions\Tenancy\Scopes\TenantScope;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Todo modelo de NEGOCIO usa este trait. Efecto:
 *  - al leer: filtra por el tenant en contexto (TenantScope, fail-closed)
 *  - al crear: setea `tenant_id` solo (nunca se toma del request)
 *
 * NO lo usan los modelos globales (catálogos) ni los del control-plane (Tenant, Domain).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $column = $model->getTenantColumn();

            if (empty($model->{$column})) {
                $model->{$column} = app(TenantContext::class)->idOrFail();
            }
        });
    }

    public function getTenantColumn(): string
    {
        return config('tenancy.column', 'tenant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'), $this->getTenantColumn());
    }

    /**
     * Escapa del filtro por tenant. USO RESTRINGIDO: panel central, migrador, mantenimiento.
     * Nunca en código servido a un tenant.
     */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
