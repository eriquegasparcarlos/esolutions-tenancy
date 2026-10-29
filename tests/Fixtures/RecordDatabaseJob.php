<?php

namespace Esolutions\Tenancy\Tests\Fixtures;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Job de prueba: deja registrado, en un archivo, el tenant que ve Y la base a la que está conectada
 * la conexión por defecto al ejecutarse. Formato: `<id>|<base>`.
 */
class RecordDatabaseJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $path) {}

    public function handle(): void
    {
        @mkdir(dirname($this->path), 0777, true);
        file_put_contents(
            $this->path,
            app(TenantContext::class)->id().'|'.DB::connection()->getDatabaseName()
        );
    }
}
