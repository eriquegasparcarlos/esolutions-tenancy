<?php

namespace Esolutions\Tenancy\Tests\Fixtures;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job de prueba: deja registrado, en un archivo, el tenant que ve al ejecutarse.
 *
 * Se escribe a archivo a propósito (y no a cache) porque el cache está aislado por tenant y
 * enmascararía justamente lo que se quiere medir.
 */
class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $path) {}

    public function handle(): void
    {
        @mkdir(dirname($this->path), 0777, true);

        file_put_contents($this->path, (string) app(TenantContext::class)->id());
    }
}
