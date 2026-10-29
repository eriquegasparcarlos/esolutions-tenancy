<?php

namespace Esolutions\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dominio/subdominio que resuelve a un tenant (`acme.miapp.com`, o dominio propio del cliente).
 * Un tenant puede tener varios; uno marcado is_primary.
 */
class Domain extends Model
{
    protected $fillable = ['tenant_id', 'domain', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'));
    }

    /** Igual que el tenant: el dominio es plano de control y se lee siempre de la central. */
    public function getConnectionName()
    {
        return app(\Esolutions\Tenancy\Database\TenantDatabaseSwitcher::class)->centralConnection();
    }
}
