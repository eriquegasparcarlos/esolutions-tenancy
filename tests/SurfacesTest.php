<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Tenancy;
use Esolutions\Tenancy\Tests\Fixtures\Post;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Resto de superficies compartidas: storage, validación, broadcasting y el helper de cron.
 */
class SurfacesTest extends TestCase
{
    /**
     * NO se usa `Storage::fake()`: inyecta un disco directo en el manager y saltea el aislamiento,
     * así que no probaría nada. Se apunta el disco real a un directorio temporal.
     */
    private function tempDisk(): string
    {
        $root = sys_get_temp_dir().'/tenancy-disk-'.uniqid();
        config()->set('filesystems.disks.local.root', $root);

        return $root;
    }

    public function test_los_archivos_no_se_comparten_entre_tenants(): void
    {
        $root = $this->tempDisk();

        $this->context()->runAs($this->acme, fn () => Storage::disk('local')->put('logo.png', 'acme'));
        $this->context()->runAs($this->globex, fn () => Storage::disk('local')->put('logo.png', 'globex'));

        $this->assertSame('acme', $this->context()->runAs($this->acme, fn () => Storage::disk('local')->get('logo.png')));
        $this->assertSame('globex', $this->context()->runAs($this->globex, fn () => Storage::disk('local')->get('logo.png')));

        // Carpetas nombradas por subdominio.
        $this->assertFileExists("{$root}/tenants/acme/logo.png");
        $this->assertFileExists("{$root}/tenants/globex/logo.png");
    }

    public function test_un_tenant_no_ve_los_archivos_de_otro(): void
    {
        $this->tempDisk();

        $this->context()->runAs($this->acme, fn () => Storage::disk('local')->put('secreto.txt', 'x'));

        $this->assertFalse(
            $this->context()->runAs($this->globex, fn () => Storage::disk('local')->exists('secreto.txt')),
            'FUGA: Globex ve un archivo de Acme.'
        );
    }

    public function test_la_regla_unique_se_acota_al_tenant(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'repetido']));

        // Las reglas nativas consultan la BASE directamente y no pasan por el scope: sin
        // Tenancy::unique(), el slug de otra empresa haría fallar la validación.
        $valido = $this->context()->runAs($this->globex, fn () => Validator::make(
            ['slug' => 'repetido'],
            ['slug' => [Tenancy::unique('posts', 'slug')]]
        )->passes());

        $this->assertTrue($valido, 'La regla unique miró fuera del tenant.');

        $invalido = $this->context()->runAs($this->acme, fn () => Validator::make(
            ['slug' => 'repetido'],
            ['slug' => [Tenancy::unique('posts', 'slug')]]
        )->passes());

        $this->assertFalse($invalido, 'La regla unique no detectó el duplicado dentro del tenant.');
    }

    public function test_los_canales_de_broadcasting_llevan_el_tenant(): void
    {
        $a = $this->context()->runAs($this->acme, fn () => Tenancy::channel('orders'));
        $g = $this->context()->runAs($this->globex, fn () => Tenancy::channel('orders'));

        $this->assertNotSame($a, $g);
        $this->assertSame("tenant.{$this->acme->id}.orders", $a);
    }

    public function test_each_recorre_cada_tenant_con_su_contexto(): void
    {
        Tenancy::each(function ($tenant) {
            Post::create(['title' => 'para '.$tenant->slug, 'slug' => 'cron']);
        });

        $this->assertSame('para acme', $this->context()->runAs($this->acme, fn () => Post::first()->title));
        $this->assertSame('para globex', $this->context()->runAs($this->globex, fn () => Post::first()->title));
    }

    public function test_each_no_aborta_cuando_un_tenant_falla(): void
    {
        $fallidos = [];

        Tenancy::each(
            function ($tenant) {
                if ($tenant->slug === 'acme') {
                    throw new \RuntimeException('boom');
                }
                Post::create(['title' => 'ok', 'slug' => 'ok']);
            },
            null,
            function ($tenant) use (&$fallidos) {
                $fallidos[] = $tenant->slug;
            }
        );

        $this->assertSame(['acme'], $fallidos);
        $this->assertSame(1, $this->context()->runAs($this->globex, fn () => Post::count()), 'El resto no se procesó.');
    }

    public function test_each_ignora_tenants_inactivos(): void
    {
        $this->globex->update(['is_active' => false]);

        $vistos = [];
        Tenancy::each(function ($tenant) use (&$vistos) {
            $vistos[] = $tenant->slug;
        });

        $this->assertSame(['acme'], $vistos);
    }
}
