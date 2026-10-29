<?php

namespace Esolutions\Tenancy\Database;

use Esolutions\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida de la base propia de un tenant: existe, crear, migrar, espejar, borrar.
 *
 * `provision()` es el alta completa: crea la base, la levanta desde el dump de esquema y deja
 * adentro la fila espejo del tenant. Esa fila espejo es necesaria porque el esquema es IDÉNTICO en
 * todas las bases y las tablas de negocio tienen FK real `tenant_id -> tenants.id`: sin la fila,
 * el primer insert de negocio falla por la FK. La verdad del tenant sigue en la central; el espejo
 * solo cierra la integridad referencial.
 */
class TenantDatabaseManager
{
    public function __construct(
        private readonly Application $app,
        private readonly TenantDatabaseSwitcher $switcher,
    ) {}

    /** Nombre sugerido para la base de un tenant: <prefix><slug>, saneado para MySQL. */
    public function nameFor(Tenant $tenant): string
    {
        $prefijo = (string) $this->app['config']->get('tenancy.database.name_prefix', 'tenant_');
        $slug = $tenant->{$this->app['config']->get('tenancy.slug_column', 'slug')} ?? $tenant->getTenantKey();

        $nombre = $prefijo.Str::of((string) $slug)->ascii()->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_');

        return substr((string) $nombre, 0, 64);
    }

    public function exists(string $database): bool
    {
        TenantDatabaseSwitcher::assertValidName($database);

        return (bool) $this->central()
            ->table('information_schema.schemata')
            ->where('schema_name', $database)
            ->exists();
    }

    public function create(string $database): void
    {
        TenantDatabaseSwitcher::assertValidName($database);

        $charset = $this->app['config']->get('tenancy.database.charset', 'utf8mb4');
        $collation = $this->app['config']->get('tenancy.database.collation', 'utf8mb4_unicode_ci');

        // charset/collation vienen de config, no del usuario; igual se restringen por si acaso.
        foreach ([$charset, $collation] as $v) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $v)) {
                throw new RuntimeException("Charset/collation inválido: [{$v}].");
            }
        }

        $this->central()->statement("CREATE DATABASE `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
    }

    public function drop(string $database): void
    {
        TenantDatabaseSwitcher::assertValidName($database);

        if ($database === $this->switcher->centralDatabase()) {
            throw new RuntimeException('Negado: esa es la base central.');
        }

        $this->central()->statement("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * Levanta o actualiza el esquema de la base del tenant con `migrate`.
     *
     * Sobre una base vacía, Laravel carga primero el dump de esquema; pero lo busca POR NOMBRE DE
     * CONEXIÓN (`<conexión>-schema.sql`) y la conexión del tenant es dinámica, así que se le pasa
     * `--schema-path` explícito: el de config, o el de la conexión central si existe.
     *
     * @param  array<int, string>  $paths  rutas de migraciones (absolutas); vacío = las de la app
     * @return int código de salida de artisan
     */
    public function migrate(Tenant $tenant, array $paths = [], bool $fresh = false): int
    {
        $database = $tenant->getTenantDatabase()
            ?? throw new RuntimeException('El tenant no tiene base propia (database es null).');

        return $this->onDatabase($database, function () use ($paths, $fresh) {
            $opciones = [
                '--database' => $this->switcher->tenantConnection(),
                '--force' => true,
                '--no-interaction' => true,
            ];

            if ($paths) {
                $opciones['--path'] = $paths;
                $opciones['--realpath'] = true;
            }

            if (($schema = $this->schemaPath()) && ! $fresh) {
                $opciones['--schema-path'] = $schema;
            }

            if ($fresh) {
                // migrate:fresh no acepta --schema-path; se limpia y se vuelve a migrar.
                Artisan::call('db:wipe', ['--database' => $opciones['--database'], '--force' => true]);
                if ($schema = $this->schemaPath()) {
                    $opciones['--schema-path'] = $schema;
                }
            }

            return Artisan::call('migrate', $opciones);
        });
    }

    /**
     * Copia la fila del tenant (misma clave primaria) a su propia base, para que las FK de negocio
     * cierren. Idempotente.
     */
    public function mirror(Tenant $tenant): void
    {
        $database = $tenant->getTenantDatabase()
            ?? throw new RuntimeException('El tenant no tiene base propia (database es null).');

        $tabla = $tenant->getTable();
        $clave = $tenant->getKeyName();

        $fila = (array) $this->central()->table($tabla)->where($clave, $tenant->getTenantKey())->first();

        if (! $fila) {
            throw new RuntimeException("El tenant [{$tenant->getTenantKey()}] no existe en la central.");
        }

        $this->onDatabase($database, function () use ($tabla, $clave, $fila) {
            $this->app['db']->connection($this->switcher->tenantConnection())
                ->table($tabla)
                ->updateOrInsert([$clave => $fila[$clave]], $fila);
        });
    }

    /** Alta completa: crear si no existe, migrar, espejar. */
    public function provision(Tenant $tenant, array $paths = []): void
    {
        $database = $tenant->getTenantDatabase()
            ?? throw new RuntimeException('El tenant no tiene base propia (database es null).');

        if (! $this->exists($database)) {
            $this->create($database);
        }

        $codigo = $this->migrate($tenant, $paths);

        if ($codigo !== 0) {
            throw new RuntimeException("migrate devolvió {$codigo} sobre la base [{$database}].");
        }

        $this->mirror($tenant);
    }

    /**
     * Ejecuta el callback conectado a la base indicada y vuelve a donde estaba — sea la central o
     * la base de otro tenant.
     */
    private function onDatabase(string $database, callable $callback): mixed
    {
        $anterior = $this->switcher->current();
        $this->switcher->connectTo($database);

        try {
            return $callback();
        } finally {
            $this->switcher->connectTo($anterior);
        }
    }

    private function schemaPath(): ?string
    {
        $configurado = $this->app['config']->get('tenancy.database.schema_path');

        if ($configurado) {
            return $configurado;
        }

        $central = $this->switcher->centralConnection();
        $ruta = $this->app->databasePath("schema/{$central}-schema.sql");

        return is_file($ruta) ? $ruta : null;
    }

    private function central()
    {
        return $this->app['db']->connection($this->switcher->centralConnection());
    }
}
