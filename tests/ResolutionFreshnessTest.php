<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Models\Domain;
use Esolutions\Tenancy\Tenancy;
use Esolutions\Tenancy\TenantContext;
use Esolutions\Tenancy\Tests\Fixtures\SuspendableTenant;
use Illuminate\Support\Facades\Route;

/**
 * Frescura de la resolución host→tenant con cache ACTIVADO.
 *
 * El hallazgo que motiva esta suite: cachear el modelo completo congelaba is_active — una cuenta
 * suspendida seguía operando hasta el TTL (5 min). Ahora se cachea solo el ID y el estado se lee
 * fresco en cada request.
 */
class ResolutionFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.resolution_cache_ttl', 300);   // cache REAL, el caso de producción

        Route::middleware(['web', 'tenant'])->get('/ping', fn () => 'ok');
    }

    public function test_desactivar_un_tenant_aplica_de_inmediato_aunque_este_cacheado(): void
    {
        $this->get('http://acme.test/ping')->assertOk();      // deja el mapeo en cache

        $this->acme->update(['is_active' => false]);

        $this->get('http://acme.test/ping')->assertForbidden();
    }

    public function test_borrar_un_tenant_aplica_de_inmediato_aunque_este_cacheado(): void
    {
        $this->get('http://acme.test/ping')->assertOk();

        $this->acme->forceDelete();

        $this->get('http://acme.test/ping')->assertNotFound();
    }

    public function test_repuntar_un_dominio_aplica_de_inmediato(): void
    {
        $this->get('http://acme.test/ping')->assertOk();

        // El dominio de Acme pasa a apuntar a Globex (el observer invalida la clave cacheada).
        Domain::where('domain', 'acme.test')->first()->update(['tenant_id' => $this->globex->id]);

        $response = $this->get('http://acme.test/ping')->assertOk();

        $tenant = app(TenantContext::class)->get();
        $this->assertSame('globex', $tenant->slug, 'El host siguió resolviendo al tenant viejo.');
    }

    public function test_un_dominio_nuevo_aplica_de_inmediato_aunque_el_404_este_cacheado(): void
    {
        $this->get('http://nuevo.test/ping')->assertNotFound();   // cachea el miss

        Domain::create(['tenant_id' => $this->acme->id, 'domain' => 'nuevo.test']);

        $this->get('http://nuevo.test/ping')->assertOk();
    }

    public function test_each_respeta_el_contrato_por_encima_de_la_columna(): void
    {
        config()->set('tenancy.tenant_model', SuspendableTenant::class);

        // Globex queda suspendido por CONTRATO (la columna is_active sigue en true).
        SuspendableTenant::where('slug', 'globex')->first()->update(['data' => ['suspended' => true]]);

        $vistos = [];
        Tenancy::each(function ($tenant) use (&$vistos) {
            $vistos[] = $tenant->slug;
        });

        $this->assertSame(['acme'], $vistos, 'each() ignoró isTenantActive() del modelo.');
    }
}
