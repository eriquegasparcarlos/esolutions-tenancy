<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Models\Domain;
use Esolutions\Tenancy\Models\Tenant;
use Esolutions\Tenancy\TenancyServiceProvider;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        Domain::create(['tenant_id' => $this->acme->id, 'domain' => 'acme.test', 'is_primary' => true]);
        Domain::create(['tenant_id' => $this->globex->id, 'domain' => 'globex.test', 'is_primary' => true]);
    }

    protected function tearDown(): void
    {
        $this->context()->forget();

        parent::tearDown();
    }

    protected function context(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class];
    }

    /**
     * El entorno de este repo no tiene el driver sqlite, así que los tests corren contra MySQL real
     * (base `esolutions_tenancy_test`). Se puede apuntar a otra con las env DB_*.
     */
    protected function defineEnvironment($app): void
    {
        // Requerida por la sesión y las cookies (tests HTTP).
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

        // La app consumidora provee sus modelos; en los tests usamos los del propio paquete.
        $app['config']->set('tenancy.tenant_model', Tenant::class);
        $app['config']->set('tenancy.domain_model', Domain::class);
        $app['config']->set('tenancy.central_domains', ['central.test']);
        $app['config']->set('tenancy.resolution_cache_ttl', 0);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'testing');
        $app['config']->set('queue.connections.testing', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Control-plane del paquete (tenants, domains).
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Tabla de negocio de prueba + tablas de cola (jobs) que trae testbench.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

    }
}
