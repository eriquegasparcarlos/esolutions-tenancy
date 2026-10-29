<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Database\TenantDatabaseSwitcher;
use Esolutions\Tenancy\Models\Tenant;
use Esolutions\Tenancy\Tests\Fixtures\Post;
use Esolutions\Tenancy\Tests\Fixtures\RecordDatabaseJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base por tenant conviviendo con la base compartida: `acme` vive en la central, `initech` en la
 * suya. Lo que se prueba es que la conexión SIGA al tenant en los tres puntos de entrada —runAs,
 * request HTTP y job de cola— y que siempre vuelva a la central.
 */
class MultiDatabaseTest extends MultiDatabaseTestCase
{
    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = sys_get_temp_dir().'/tenancy-db-probe-'.getmypid().'.txt';
        @unlink($this->probe);
    }

    protected function tearDown(): void
    {
        @unlink($this->probe);

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'tenant'])->get('/posts-count', fn () => [
            'count' => Post::count(),
            'database' => DB::connection()->getDatabaseName(),
        ]);
    }

    private function switcher(): TenantDatabaseSwitcher
    {
        return $this->app->make(TenantDatabaseSwitcher::class);
    }

    private function central(): string
    {
        return (string) env('DB_DATABASE', 'esolutions_tenancy_test');
    }

    // ── El conmutador ──────────────────────────────────────────────────────────

    public function test_un_tenant_sin_base_propia_se_queda_en_la_central(): void
    {
        $this->context()->runAs($this->acme, function () {
            $this->assertNull($this->switcher()->current());
            $this->assertSame('testing', DB::getDefaultConnection());
            $this->assertSame($this->central(), DB::connection()->getDatabaseName());
        });
    }

    public function test_run_as_conecta_a_la_base_del_tenant_y_vuelve_a_la_central(): void
    {
        $this->assertSame('testing', DB::getDefaultConnection());

        $this->context()->runAs($this->initech, function () {
            $this->assertSame($this->secondary, $this->switcher()->current());
            $this->assertSame('tenant_db', DB::getDefaultConnection());
            $this->assertSame($this->secondary, DB::connection()->getDatabaseName());
        });

        $this->assertNull($this->switcher()->current());
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertArrayNotHasKey('tenant_db', DB::getConnections(), 'La conexión del tenant tiene que cerrarse al salir.');
    }

    public function test_los_modelos_leen_y_escriben_en_la_base_del_tenant(): void
    {
        $this->context()->runAs($this->initech, function () {
            Post::create(['title' => 'En la base propia', 'slug' => 'propia']);
        });

        // Está en la base del tenant y NO en la central…
        $this->assertSame(1, (int) $this->enSecundaria('select count(*) from posts'));
        $this->assertSame(0, DB::connection('testing')->table('posts')->count());

        // …y otro tenant no la ve.
        $this->context()->runAs($this->acme, fn () => $this->assertSame(0, Post::count()));
        $this->context()->runAs($this->initech, fn () => $this->assertSame(1, Post::count()));
    }

    public function test_run_as_anidado_restaura_la_base_anterior(): void
    {
        $this->context()->runAs($this->initech, function () {
            $this->context()->runAs($this->acme, function () {
                $this->assertNull($this->switcher()->current());
            });

            $this->assertSame($this->secondary, $this->switcher()->current(), 'Al salir del anidado hay que volver a la base de initech, no a la central.');
            $this->assertSame($this->secondary, DB::connection()->getDatabaseName());
        });

        $this->assertNull($this->switcher()->current());
    }

    public function test_el_tenant_y_el_dominio_se_leen_siempre_de_la_central(): void
    {
        $this->context()->runAs($this->initech, function () {
            $this->assertSame('testing', (new Tenant)->getConnectionName());
            $this->assertSame('testing', Tenant::query()->getConnection()->getName());

            // Aunque la base del tenant tenga su fila espejo, la verdad sale de la central.
            $this->assertSame('Initech', Tenant::find($this->initech->id)->name);
        });
    }

    public function test_no_se_cambia_de_base_con_una_transaccion_abierta(): void
    {
        DB::connection('testing')->beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/transacción abierta/');

            $this->context()->runAs($this->initech, fn () => null);
        } finally {
            DB::connection('testing')->rollBack();
            $this->context()->forget();
        }
    }

    public function test_el_prefijo_de_cache_incluye_la_base(): void
    {
        $this->context()->runAs($this->acme, function () {
            $this->assertSame("t{$this->acme->id}:", $this->context()->cachePrefix());
        });

        $this->context()->runAs($this->initech, function () {
            $this->assertSame("d{$this->secondary}:t{$this->initech->id}:", $this->context()->cachePrefix());
        });

        // Dos tenants con el MISMO id en bases distintas no comparten claves.
        $fantasma = new Tenant(['name' => 'Fantasma', 'slug' => 'fantasma', 'database' => $this->secondary]);
        $fantasma->id = $this->acme->id;

        $this->context()->runAs($this->acme, fn () => Cache::put('k', 'de acme', 60));
        $this->context()->set($fantasma);
        $this->assertNull(Cache::get('k'));
        $this->context()->forget();
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────

    public function test_la_request_al_dominio_del_tenant_llega_a_su_base(): void
    {
        $this->context()->runAs($this->initech, fn () => Post::create(['title' => 'x', 'slug' => 'x']));
        $this->context()->forget();

        $this->get('http://initech.test/posts-count')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('database', $this->secondary);

        $this->get('http://acme.test/posts-count')->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('database', $this->central());
    }

    // ── Colas ──────────────────────────────────────────────────────────────────

    private function work(): void
    {
        $this->app->make('queue.worker')->runNextJob('testing', 'default', new WorkerOptions);
    }

    public function test_un_job_del_tenant_corre_contra_su_base_y_el_worker_vuelve_a_la_central(): void
    {
        $this->context()->runAs($this->initech, function () {
            RecordDatabaseJob::dispatch($this->probe);
        });

        // El job quedó encolado en la CENTRAL aunque se despachó desde la base del tenant.
        $this->assertSame(1, DB::connection('testing')->table('jobs')->count());

        $this->context()->forget();
        $this->work();

        $this->assertSame("{$this->initech->id}|{$this->secondary}", trim(file_get_contents($this->probe)));
        $this->assertNull($this->switcher()->current(), 'Después del job, el worker tiene que estar en la central.');
        $this->assertSame('testing', DB::getDefaultConnection());
    }

    private function enSecundaria(string $sql): mixed
    {
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), $this->secondary),
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', '')
        );

        return $pdo->query($sql)->fetchColumn();
    }
}
