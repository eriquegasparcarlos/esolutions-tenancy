<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Database\TenantDatabaseManager;
use Esolutions\Tenancy\Models\Domain;
use Esolutions\Tenancy\Models\Tenant;
use Esolutions\Tenancy\TenancyServiceProvider;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base para los tests con DOS bases reales: la central (`DB_DATABASE`) y la propia de un tenant
 * (`DB_DATABASE_SECONDARY`).
 *
 * No usa RefreshDatabase a propósito: ese trait envuelve cada test en una transacción sobre la
 * conexión por defecto, y el conmutador se niega —con razón— a cambiar de base con una transacción
 * abierta. Tampoco usa DatabaseMigrations: corre `migrate:fresh` sobre la carpeta de migraciones
 * de la app esqueleto de Testbench, que está vacía, y no conoce las nuestras. Acá las dos bases se
 * migran en limpio, explícitamente, en cada setUp.
 *
 * Tenants: `acme` vive en la central (database = NULL); `initech` tiene base propia.
 *
 * Como acá nada corre dentro de una transacción, lo que se escribe en la central QUEDA. Las clases
 * que vienen después usan RefreshDatabase, que migra una sola vez por proceso y luego confía en que
 * la base está vacía: si encontraran `acme` ya insertado, su setUp reventaría por el UNIQUE del slug
 * y dejaría conexiones con transacciones abiertas colgadas, que a su vez bloquean el `drop table` del
 * siguiente `migrate:fresh` (lock de metadatos sobre `tenants`). Por eso tearDown vacía la central.
 */
abstract class MultiDatabaseTestCase extends Orchestra
{
    protected Tenant $acme;

    protected Tenant $initech;

    protected string $secondary;

    protected function setUp(): void
    {
        parent::setUp();

        // Central en limpio, con las migraciones del paquete y las de prueba.
        Artisan::call('migrate:fresh', [
            '--database' => 'testing',
            '--path' => $this->migrationPaths(),
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->secondary = (string) env('DB_DATABASE_SECONDARY', 'esolutions_tenancy_test_b');

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->initech = Tenant::create(['name' => 'Initech', 'slug' => 'initech', 'database' => $this->secondary]);

        Domain::create(['tenant_id' => $this->acme->id, 'domain' => 'acme.test', 'is_primary' => true]);
        Domain::create(['tenant_id' => $this->initech->id, 'domain' => 'initech.test', 'is_primary' => true]);

        // La base del tenant se levanta en limpio con las mismas migraciones que la central.
        $manager = $this->app->make(TenantDatabaseManager::class);
        if (! $manager->exists($this->secondary)) {
            $manager->create($this->secondary);
        }
        $manager->migrate($this->initech, $this->migrationPaths(), fresh: true);
        $manager->mirror($this->initech);
    }

    protected function tearDown(): void
    {
        $this->context()->forget();
        $this->vaciarCentral();

        parent::tearDown();
    }

    /** Deja la central migrada pero sin filas, que es lo que RefreshDatabase espera encontrar. */
    private function vaciarCentral(): void
    {
        $conexion = DB::connection('testing');
        $tablas = array_map(
            fn ($fila) => array_values((array) $fila)[0],
            $conexion->select('show tables')
        );

        $conexion->statement('set foreign_key_checks = 0');
        try {
            foreach ($tablas as $tabla) {
                if ($tabla !== 'migrations') {
                    $conexion->table($tabla)->delete();
                }
            }
        } finally {
            $conexion->statement('set foreign_key_checks = 1');
        }
    }

    /** @return array<int, string> */
    protected function migrationPaths(): array
    {
        return [
            realpath(__DIR__.'/../database/migrations'),
            realpath(__DIR__.'/database/migrations'),
        ];
    }

    protected function context(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'esolutions_tenancy_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ]);

        $app['config']->set('tenancy.tenant_model', Tenant::class);
        $app['config']->set('tenancy.domain_model', Domain::class);
        $app['config']->set('tenancy.central_domains', ['central.test']);
        $app['config']->set('tenancy.resolution_cache_ttl', 0);
        $app['config']->set('tenancy.database.central_connection', 'testing');

        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        // La cola queda clavada a la central: si siguiera la conexión por defecto, un job
        // despachado desde un tenant con base propia se encolaría en SU base y el worker, que
        // escucha la central, nunca lo vería.
        $app['config']->set('queue.default', 'testing');
        $app['config']->set('queue.connections.testing', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]);
    }

}
