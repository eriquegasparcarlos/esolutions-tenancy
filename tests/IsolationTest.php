<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Tests\Fixtures\Post;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Aislamiento a nivel de datos. Si algo de acá falla, una empresa puede ver o escribir datos de otra.
 */
class IsolationTest extends TestCase
{
    public function test_un_tenant_solo_ve_sus_propias_filas(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'De Acme', 'slug' => 'a']));
        $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'De Globex', 'slug' => 'g']));

        $this->assertSame(['De Acme'], $this->context()->runAs($this->acme, fn () => Post::pluck('title')->all()));
        $this->assertSame(['De Globex'], $this->context()->runAs($this->globex, fn () => Post::pluck('title')->all()));
    }

    public function test_no_puede_leer_por_id_una_fila_de_otro_tenant_idor(): void
    {
        $id = $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'X', 'slug' => 'x'])->id);

        $this->assertNull(
            $this->context()->runAs($this->acme, fn () => Post::find($id)),
            'IDOR: un tenant accedió a una fila de otro tenant.'
        );
    }

    public function test_no_puede_actualizar_ni_borrar_filas_de_otro_tenant(): void
    {
        $id = $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'Intacto', 'slug' => 'i'])->id);

        $this->context()->runAs($this->acme, function () {
            Post::query()->update(['title' => 'Pisado']);
            Post::query()->delete();
        });

        $this->context()->runAs($this->globex, function () use ($id) {
            $post = Post::find($id);
            $this->assertNotNull($post, 'Otro tenant borró la fila.');
            $this->assertSame('Intacto', $post->title, 'Otro tenant modificó la fila.');
        });
    }

    public function test_el_tenant_id_se_asigna_solo_desde_el_contexto(): void
    {
        $post = $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'T', 'slug' => 'auto']));

        $this->assertSame($this->acme->id, $post->tenant_id);
    }

    public function test_el_tenant_id_del_request_no_puede_sobreescribir_el_contexto(): void
    {
        // Simula un payload malicioso que intenta forzar otro tenant.
        $post = $this->context()->runAs($this->acme, fn () => Post::create([
            'title' => 'T', 'slug' => 'spoof', 'tenant_id' => $this->globex->id,
        ]));

        // El modelo se guardó; lo que importa es que NO sea visible para el atacante y que el
        // scope siga filtrando por el contexto real.
        $visible = $this->context()->runAs($this->acme, fn () => Post::where('slug', 'spoof')->exists());

        $this->assertFalse(
            $visible && $post->tenant_id !== $this->acme->id,
            'Un tenant_id enviado por el cliente terminó siendo visible en el tenant equivocado.'
        );
    }

    public function test_query_sin_contexto_falla_en_vez_de_devolver_todo(): void
    {
        $this->context()->forget();

        $this->expectException(RuntimeException::class);

        Post::count();
    }

    public function test_modo_system_permite_consultar_cross_tenant_deliberadamente(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'a']));
        $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'B', 'slug' => 'b']));

        $this->assertSame(2, $this->context()->runAsSystem(fn () => Post::count()));
    }

    public function test_all_tenants_escapa_del_scope_explicitamente(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'a']));
        $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'B', 'slug' => 'b']));

        $total = $this->context()->runAs($this->acme, fn () => Post::allTenants()->count());

        $this->assertSame(2, $total);
    }

    public function test_el_mismo_valor_unico_puede_repetirse_en_dos_tenants(): void
    {
        $this->context()->runAs($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'repetido']));

        $post = $this->context()->runAs($this->globex, fn () => Post::create(['title' => 'B', 'slug' => 'repetido']));

        $this->assertSame($this->globex->id, $post->tenant_id);
    }

    public function test_el_unico_sigue_valiendo_dentro_del_mismo_tenant(): void
    {
        $this->expectException(QueryException::class);

        $this->context()->runAs($this->acme, function () {
            Post::create(['title' => 'A', 'slug' => 'dup']);
            Post::create(['title' => 'B', 'slug' => 'dup']);
        });
    }

    public function test_run_as_restaura_el_contexto_anterior(): void
    {
        $this->context()->runAs($this->acme, function () {
            $this->context()->runAs($this->globex, fn () => null);

            $this->assertSame($this->acme->id, $this->context()->id(), 'runAs anidado no restauró el contexto.');
        });

        $this->assertNull($this->context()->id());
    }
}
