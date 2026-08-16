<?php

namespace Esolutions\Tenancy\Tests\Fixtures;

use Esolutions\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de negocio de prueba: representa a cualquier tabla con `tenant_id` de la app consumidora.
 */
class Post extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
