<?php

namespace Esolutions\Tenancy\Console;

use Esolutions\Tenancy\Database\TenantDatabaseManager;
use Esolutions\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Migra las bases PROPIAS de los tenants, una por una.
 *
 * Los tenants sin base propia (database = NULL) viven en la central y se migran con `migrate` a
 * secas; este comando los salta. Con `--create`, además crea la base del tenant si no existe y
 * deja adentro su fila espejo (alta completa).
 *
 * ```bash
 * php artisan tenants:migrate                 # todas las bases propias existentes
 * php artisan tenants:migrate --create        # creando las que falten
 * php artisan tenants:migrate --tenants=3,8   # solo esos
 * ```
 */
class TenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate
        {--tenants= : Lista de ids separados por coma (por defecto, todos los activos con base propia)}
        {--create : Crea la base del tenant si no existe y espeja su fila}
        {--stop-on-error : Aborta en el primer tenant que falle (por defecto continúa)}';

    protected $description = 'Migra la base propia de cada tenant (crea con --create).';

    public function handle(TenantDatabaseManager $manager): int
    {
        $only = $this->option('tenants')
            ? array_filter(array_map('trim', explode(',', (string) $this->option('tenants'))))
            : null;

        $query = Tenancy::query()->whereNotNull(config('tenancy.database.column', 'database'));

        if ($only !== null) {
            $query->whereIn((new (config('tenancy.tenant_model')))->getKeyName(), $only);
        }

        $ok = 0;
        $failed = 0;

        foreach ($query->cursor() as $tenant) {
            $base = $tenant->getTenantDatabase();
            $this->line("→ [{$tenant->getTenantKey()}] {$tenant->name}  ({$base})");

            try {
                if ($this->option('create')) {
                    $manager->provision($tenant);
                } elseif (! $manager->exists($base)) {
                    $this->warn("  la base [{$base}] no existe — usá --create");
                    $failed++;

                    continue;
                } else {
                    $codigo = $manager->migrate($tenant);
                    if ($codigo !== 0) {
                        throw new \RuntimeException("migrate devolvió {$codigo}");
                    }
                }

                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("  {$e->getMessage()}");

                if ($this->option('stop-on-error')) {
                    break;
                }
            }
        }

        $this->newLine();
        $this->info("Completado en {$ok} tenant(s).".($failed ? " Fallaron: {$failed}." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
