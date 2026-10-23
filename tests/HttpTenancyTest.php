<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Http\Middleware\ScopeSessionToTenant;
use Illuminate\Support\Facades\Auth;
use Esolutions\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * Resolución por dominio, scoping de sesión y separación del dominio central.
 */
class HttpTenancyTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'tenant'])->get('/whoami', function () {
            return ['tenant' => app(TenantContext::class)->get()?->slug];
        });
    }

    public function test_el_dominio_resuelve_al_tenant_correcto(): void
    {
        $this->get('http://acme.test/whoami')->assertOk()->assertJsonPath('tenant', 'acme');
        $this->get('http://globex.test/whoami')->assertOk()->assertJsonPath('tenant', 'globex');
    }

    public function test_resuelve_por_subdominio_si_no_hay_dominio_registrado(): void
    {
        // 'acme' existe como slug aunque 'acme.otrodominio.com' no esté en `domains`.
        $this->get('http://acme.otrodominio.com/whoami')->assertOk()->assertJsonPath('tenant', 'acme');
    }

    public function test_dominio_no_registrado_da_404(): void
    {
        $this->get('http://desconocido.test/whoami')->assertNotFound();
    }

    public function test_tenant_inactivo_es_rechazado(): void
    {
        $this->acme->update(['is_active' => false]);

        $this->get('http://acme.test/whoami')->assertForbidden();
    }

    public function test_las_rutas_de_tenant_no_responden_en_el_dominio_central(): void
    {
        $this->get('http://central.test/whoami')->assertNotFound();
    }

    public function test_la_sesion_queda_estampada_con_el_tenant(): void
    {
        $this->get('http://acme.test/whoami')->assertOk();

        $this->assertSame($this->acme->id, session(ScopeSessionToTenant::KEY));
    }

    public function test_una_sesion_de_otro_tenant_se_descarta(): void
    {
        // Cookie emitida en Acme, presentada en el dominio de Globex (las cookies se comparten
        // entre subdominios de un mismo dominio padre).
        $this->withSession([
            ScopeSessionToTenant::KEY => $this->acme->id,
            'dato_de_acme' => 'secreto',
        ])->get('http://globex.test/whoami')->assertOk();

        $this->assertSame($this->globex->id, session(ScopeSessionToTenant::KEY));
        $this->assertNull(session('dato_de_acme'), 'La sesión de otro tenant sobrevivió.');
    }

    /**
     * Descartar la sesión ajena no puede depender de que el guard por defecto sea de sesión.
     *
     * `auth:web,api` deja como default al guard que autenticó, así que en una app con Bearer el
     * default termina siendo un TokenGuard — que no tiene `logout()`. Antes eso tumbaba el request
     * entero con BadMethodCallException justo en el momento en que había que proteger la sesión.
     */
    public function test_descarta_la_sesion_ajena_aunque_el_guard_por_defecto_no_sea_de_sesion(): void
    {
        config()->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
            'storage_key' => 'api_token',
            'hash' => false,
        ]);

        Auth::shouldUse('api');

        $this->withSession([
            ScopeSessionToTenant::KEY => $this->acme->id,
            'dato_de_acme' => 'secreto',
        ])->get('http://globex.test/whoami')->assertOk();

        $this->assertSame($this->globex->id, session(ScopeSessionToTenant::KEY));
        $this->assertNull(session('dato_de_acme'), 'La sesión de otro tenant sobrevivió.');
    }
}
