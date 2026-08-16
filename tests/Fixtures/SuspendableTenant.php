<?php

namespace Esolutions\Tenancy\Tests\Fixtures;

use Esolutions\Tenancy\Models\Tenant;

/**
 * Tenant con noción PROPIA de "activo": además de la columna, mira un flag de suspensión en data.
 * Sirve para probar que each()/query() respetan el CONTRATO (isTenantActive) y no solo la columna.
 */
class SuspendableTenant extends Tenant
{
    protected $table = 'tenants';

    public function isTenantActive(): bool
    {
        return parent::isTenantActive() && ! ($this->data['suspended'] ?? false);
    }
}
