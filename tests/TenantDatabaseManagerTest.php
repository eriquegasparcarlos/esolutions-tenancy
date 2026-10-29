<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Database\TenantDatabaseManager;
use Esolutions\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ciclo de vida de la base propia: crear, verificar, migrar, espejar, borrar.
 */
class TenantDatabaseManagerTest extends MultiDatabaseTestCase
{
    private string $extra = 'esolutions_tenancy_test_extra';

    protected function tearDown(): void
    {
        $this->manager()->drop($this->extra);

        parent::tearDown();
    }

    private function manager(): TenantDatabaseManager
    {
        return $this->app->make(TenantDatabaseManager::class);
    }

    public function test_crea_verifica_y_borra_una_base(): void
    {
        $m = $this->manager();

        $this->assertFalse($m->exists($this->extra));
        $m->create($this->extra);
        $this->assertTrue($m->exists($this->extra));
        $m->drop($this->extra);
        $this->assertFalse($m->exists($this->extra));
    }

    public function test_rechaza_nombres_que_no_sean_identificadores(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->create('mala; drop database x');
    }

    public function test_se_niega_a_borrar_la_central(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/central/');

        $this->manager()->drop((string) env('DB_DATABASE', 'esolutions_tenancy_test'));
    }

    public function test_provision_crea_migra_y_deja_la_fila_espejo(): void
    {
        $tenant = Tenant::create(['name' => 'Extra', 'slug' => 'extra', 'database' => $this->extra]);

        $this->manager()->provision($tenant, $this->migrationPaths());

        $this->assertTrue($this->manager()->exists($this->extra));

        // El esquema está (las migraciones corrieron) y la fila espejo tiene la MISMA clave.
        $this->context()->runAs($tenant, function () use ($tenant) {
            $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('posts'));
            $espejo = DB::connection()->table('tenants')->where('id', $tenant->id)->first();
            $this->assertNotNull($espejo, 'Sin la fila espejo, la FK tenant_id de las tablas de negocio no cierra.');
            $this->assertSame('extra', $espejo->slug);
        });

        // Y la conexión volvió a la central.
        $this->assertSame('testing', DB::getDefaultConnection());
    }

    public function test_migrate_sobre_un_tenant_sin_base_propia_falla_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/database es null/');

        $this->manager()->migrate($this->acme);
    }

    public function test_name_for_sanea_el_slug(): void
    {
        $tenant = new Tenant(['name' => 'X', 'slug' => 'Mi Empresa-S.A.C.']);

        $this->assertSame('tenant_mi_empresa_s_a_c', $this->manager()->nameFor($tenant));
    }
}
