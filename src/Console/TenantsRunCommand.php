<?php

namespace Esolutions\Tenancy\Console;

use Esolutions\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Corre un comando artisan UNA VEZ POR CADA TENANT, con su contexto establecido.
 *
 * En single-DB no existe "migrar todos los tenants" (el esquema es uno solo), pero sí tareas de
 * negocio que deben correr por empresa: recalcular stock, enviar recordatorios, cerrar caja, etc.
 *
 * ```bash
 * php artisan tenants:run inventory:recalc
 * php artisan tenants:run "invoices:remind --days=3"
 * php artisan tenants:run report:build --tenants=1,5,9
 * ```
 *
 * En el scheduler:
 * ```php
 * Schedule::command('tenants:run invoices:remind')->dailyAt('08:00');
 * ```
 */
class TenantsRunCommand extends Command
{
    protected $signature = 'tenants:run
        {cmd : Comando artisan a ejecutar (entre comillas si lleva opciones)}
        {--tenants= : Lista de ids separados por coma (por defecto, todos los activos)}
        {--stop-on-error : Aborta en el primer tenant que falle (por defecto continúa)}';

    protected $description = 'Ejecuta un comando artisan una vez por cada tenant.';

    public function handle(): int
    {
        $cmd = $this->argument('cmd');

        $only = $this->option('tenants')
            ? array_filter(array_map('trim', explode(',', $this->option('tenants'))))
            : null;

        $stopOnError = (bool) $this->option('stop-on-error');

        $ok = 0;
        $failed = 0;

        $onError = $stopOnError ? null : function ($tenant, Throwable $e) use (&$failed) {
            $failed++;
            $this->error("  [{$tenant->getTenantKey()}] {$tenant->name}: {$e->getMessage()}");
        };

        Tenancy::each(function ($tenant) use ($cmd, &$ok) {
            $this->line("→ [{$tenant->getTenantKey()}] {$tenant->name}");
            Artisan::call($cmd, [], $this->getOutput());
            $ok++;
        }, $only, $onError);

        $this->newLine();
        $this->info("Completado en {$ok} tenant(s).".($failed ? " Fallaron: {$failed}." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
