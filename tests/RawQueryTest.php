<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Tenancy;
use Esolutions\Tenancy\Tests\Fixtures\Post;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `Tenancy::table()` — el query builder crudo acotado al tenant.
 *
 * El TenantScope solo cubre Eloquent. Una query armada con `DB::table()` lo esquiva sin avisar, y
 * en single-DB eso significa leer las filas de todas las empresas. Estos tests fijan que el helper
 * cierra ese agujero y que falla cerrado.
 */
class RawQueryTest extends TestCase
{
    private function sembrar(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'De Acme', 'slug' => 'a']));
        $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'De Globex', 'slug' => 'g']));
    }

    public function test_la_query_cruda_sin_el_helper_ve_todas_las_empresas(): void
    {
        $this->sembrar();

        // Este test documenta el PELIGRO, no un comportamiento deseado: `DB::table()` no pasa por
        // el scope. Si algún día empezara a filtrar solo, este test avisa para revisar el helper.
        $todas = $this->context()->runAs($this->acme, fn () => DB::table('posts')->count());

        $this->assertSame(2, $todas, 'DB::table() dejó de ver todo: revisar si el helper sigue haciendo falta.');
    }

    public function test_el_helper_acota_la_lectura_al_tenant_en_contexto(): void
    {
        $this->sembrar();

        $this->assertSame(
            ['De Acme'],
            $this->context()->runAs($this->acme, fn () => Tenancy::table('posts')->pluck('title')->all())
        );

        $this->assertSame(
            ['De Globex'],
            $this->context()->runAs($this->globex, fn () => Tenancy::table('posts')->pluck('title')->all())
        );
    }

    public function test_el_helper_no_deja_borrar_filas_de_otra_empresa(): void
    {
        $this->sembrar();

        $borradas = $this->context()->runAs($this->acme, fn () => Tenancy::table('posts')->delete());

        $this->assertSame(1, $borradas, 'El delete se pasó de empresa.');
        $this->assertSame(1, DB::table('posts')->count());
    }

    public function test_sin_tenant_en_contexto_falla_en_vez_de_devolver_todo(): void
    {
        $this->sembrar();
        $this->context()->forget();

        $this->expectException(RuntimeException::class);

        Tenancy::table('posts')->get();
    }

    public function test_la_columna_va_calificada_para_no_romper_los_joins(): void
    {
        // Sin calificar, un join contra otra tabla con `tenant_id` daría "Column 'tenant_id' in
        // where clause is ambiguous".
        $sql = $this->context()->runAs($this->acme, fn () => Tenancy::table('posts')->toSql());

        $this->assertStringContainsString('"posts"."tenant_id"', str_replace('`', '"', $sql));
    }
}
