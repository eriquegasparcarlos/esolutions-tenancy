<?php

namespace Esolutions\Tenancy\Models;

use Esolutions\Tenancy\Contracts\Tenant as TenantContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo base de tenant. La app lo EXTIENDE para agregar su política (módulos contratados, plan,
 * facturación, etc.) y lo declara en `config('tenancy.tenant_model')`.
 *
 * NO lleva tenant_id ni TenantScope: es la tabla raíz. Vive en la MISMA base que las tablas de
 * negocio para que `negocio.tenant_id -> tenants.id` pueda ser FK real (MySQL no soporta FK entre
 * esquemas).
 */
class Tenant extends Model implements TenantContract
{
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'is_active', 'data', 'database'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'data' => 'array',
        ];
    }

    public function getTenantKey(): int|string
    {
        return $this->getKey();
    }

    public function isTenantActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function getTenantDatabase(): ?string
    {
        $columna = config('tenancy.database.column', 'database');

        return $this->{$columna} ?: null;
    }

    /**
     * El tenant se lee SIEMPRE de la base central, aunque la conexión por defecto esté apuntando a
     * la base de un tenant. Resolver «quién es este tenant» desde la base de otro sería leer su
     * copia espejo (la fila que cada base propia lleva para que las FK de negocio cierren), no la
     * verdad del plano de control.
     */
    public function getConnectionName()
    {
        return app(\Esolutions\Tenancy\Database\TenantDatabaseSwitcher::class)->centralConnection();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(config('tenancy.domain_model'));
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(config('tenancy.domain_model'))->where('is_primary', true);
    }
}
