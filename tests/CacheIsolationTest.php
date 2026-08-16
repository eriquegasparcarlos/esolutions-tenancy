<?php

namespace Esolutions\Tenancy\Tests;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * El cache es una fuga que el TenantScope NO puede detener: no pasa por la base de datos.
 * Si el tenant A guarda `dashboard_stats` y el B lee la misma clave, ve datos ajenos.
 */
class CacheIsolationTest extends TestCase
{
    public function test_el_cache_no_se_comparte_entre_tenants(): void
    {
        $this->context()->runAs($this->acme, fn () => Cache::put('stats', 'datos-de-acme', 60));

        $this->assertNull(
            $this->context()->runAs($this->globex, fn () => Cache::get('stats')),
            'FUGA: Globex leyó el cache de Acme.'
        );

        $this->assertSame(
            'datos-de-acme',
            $this->context()->runAs($this->acme, fn () => Cache::get('stats'))
        );
    }

    public function test_cada_tenant_tiene_su_propio_valor_para_la_misma_clave(): void
    {
        $this->context()->runAs($this->acme, fn () => Cache::put('contador', 1, 60));
        $this->context()->runAs($this->globex, fn () => Cache::put('contador', 999, 60));

        $this->assertSame(1, $this->context()->runAs($this->acme, fn () => Cache::get('contador')));
        $this->assertSame(999, $this->context()->runAs($this->globex, fn () => Cache::get('contador')));
    }

    public function test_forget_de_un_tenant_no_borra_el_del_otro(): void
    {
        $this->context()->runAs($this->acme, fn () => Cache::put('k', 'a', 60));
        $this->context()->runAs($this->globex, fn () => Cache::put('k', 'g', 60));

        $this->context()->runAs($this->acme, fn () => Cache::forget('k'));

        $this->assertNull($this->context()->runAs($this->acme, fn () => Cache::get('k')));
        $this->assertSame('g', $this->context()->runAs($this->globex, fn () => Cache::get('k')));
    }

    public function test_flush_dentro_de_un_tenant_es_rechazado(): void
    {
        $this->expectException(RuntimeException::class);

        $this->context()->runAs($this->acme, fn () => Cache::flush());
    }

    public function test_flush_sigue_disponible_fuera_de_un_tenant(): void
    {
        $this->context()->runAsSystem(fn () => Cache::flush());

        $this->assertTrue(true); // no lanzó: el panel central sí puede vaciar
    }

    public function test_remember_respeta_el_aislamiento(): void
    {
        $a = $this->context()->runAs($this->acme, fn () => Cache::remember('calc', 60, fn () => 'A'));
        $g = $this->context()->runAs($this->globex, fn () => Cache::remember('calc', 60, fn () => 'G'));

        $this->assertSame('A', $a);
        $this->assertSame('G', $g, 'Globex reutilizó el valor cacheado por Acme.');
    }

    public function test_sin_tenant_las_claves_son_globales(): void
    {
        // El panel central y el framework comparten un espacio de claves global a propósito.
        Cache::put('global', 'x', 60);

        $this->assertSame('x', Cache::get('global'));
    }
}
