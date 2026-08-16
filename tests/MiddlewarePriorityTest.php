<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Http\Middleware\PreventAccessFromCentralDomain;
use Esolutions\Tenancy\Http\Middleware\ResolveTenant;
use Esolutions\Tenancy\Http\Middleware\ScopeSessionToTenant;
use Illuminate\Contracts\Http\Kernel;

/**
 * Regresión del fix 0.4.0: el trío del grupo 'tenant' debe correr DESPUÉS de StartSession y
 * ANTES de `auth` y de SubstituteBindings. Sin esa prioridad, el route-model-binding y la carga
 * del usuario autenticado consultan la BD con el contexto del request ANTERIOR (tests, Octane)
 * y pueden resolver filas de otro tenant (IDOR silencioso).
 */
class MiddlewarePriorityTest extends TestCase
{
    public function test_el_trio_tenant_corre_entre_start_session_y_auth_bindings(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $priority = (fn () => $this->middlewarePriority)->call($kernel);

        $pos = fn (string $class) => array_search($class, $priority);

        foreach ([PreventAccessFromCentralDomain::class, ResolveTenant::class, ScopeSessionToTenant::class] as $mw) {
            $this->assertNotFalse($pos($mw), "{$mw} no está en la lista de prioridad.");
            $this->assertGreaterThan($pos(\Illuminate\Session\Middleware\StartSession::class), $pos($mw));
            $this->assertLessThan($pos(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class), $pos($mw));
            $this->assertLessThan($pos(\Illuminate\Routing\Middleware\SubstituteBindings::class), $pos($mw));
        }

        // El orden interno del trío se preserva (resolver antes de atar la sesión).
        $this->assertLessThan($pos(ResolveTenant::class), $pos(PreventAccessFromCentralDomain::class));
        $this->assertLessThan($pos(ScopeSessionToTenant::class), $pos(ResolveTenant::class));
    }
}
