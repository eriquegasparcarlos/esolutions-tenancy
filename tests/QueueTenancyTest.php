<?php

namespace Esolutions\Tenancy\Tests;

use Esolutions\Tenancy\Tests\Fixtures\RecordTenantJob;
use Illuminate\Queue\WorkerOptions;

/**
 * Las colas son el punto más delicado: el worker es un proceso de larga vida sin request HTTP que
 * procesa jobs de MUCHAS empresas, uno atrás de otro.
 */
class QueueTenancyTest extends TestCase
{
    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = sys_get_temp_dir().'/tenancy-probe-'.getmypid().'.txt';
        @unlink($this->probe);
    }

    protected function tearDown(): void
    {
        @unlink($this->probe);

        parent::tearDown();
    }

    /**
     * Procesa un job con el Worker directo, no con `queue:work`: el comando instala manejadores de
     * error/señales propios que PHPUnit marca como riesgo, y acá solo interesa el ciclo del worker
     * (que es donde se restaura el tenant).
     */
    private function work(): void
    {
        $this->app->make('queue.worker')->runNextJob('testing', 'default', new WorkerOptions);
    }

    private function recorded(): string
    {
        return is_file($this->probe) ? trim(file_get_contents($this->probe)) : '';
    }

    public function test_un_job_encolado_conserva_el_tenant_que_lo_despacho(): void
    {
        $this->context()->runAs($this->acme, function () {
            RecordTenantJob::dispatch($this->probe);
        });

        // El worker corre SIN contexto HTTP.
        $this->context()->forget();
        $this->work();

        $this->assertSame((string) $this->acme->id, $this->recorded());
    }

    public function test_el_job_no_hereda_el_tenant_del_worker(): void
    {
        $this->context()->runAs($this->acme, function () {
            RecordTenantJob::dispatch($this->probe);
        });

        // El worker está "posicionado" en Globex: el job igual debe correr como Acme.
        $this->context()->runAs($this->globex, fn () => $this->work());

        $this->assertSame(
            (string) $this->acme->id,
            $this->recorded(),
            'El job corrió con el tenant del worker, no con el suyo.'
        );
    }

    public function test_el_worker_no_contamina_entre_jobs(): void
    {
        // 1) job de Acme
        $this->context()->runAs($this->acme, function () {
            RecordTenantJob::dispatch($this->probe);
        });
        $this->context()->forget();
        $this->work();
        $this->assertSame((string) $this->acme->id, $this->recorded());

        // 2) job SIN tenant, en el mismo worker: no debe arrastrar a Acme
        $this->context()->forget();
        RecordTenantJob::dispatch($this->probe);
        $this->work();

        $this->assertSame(
            '',
            $this->recorded(),
            'CONTAMINACIÓN: el job sin tenant heredó el tenant del job anterior.'
        );
    }

    public function test_dispatch_con_arrow_function_tambien_conserva_el_tenant(): void
    {
        /*
         * `Job::dispatch()` devuelve un PendingDispatch que encola al destruirse. Si el callback lo
         * RETORNA (arrow function), el objeto moriría fuera del contexto y el job iría sin tenant.
         * runAs() fuerza su encolado dentro del contexto.
         */
        $this->context()->runAs($this->acme, fn () => RecordTenantJob::dispatch($this->probe));

        $this->context()->forget();
        $this->work();

        $this->assertSame((string) $this->acme->id, $this->recorded());
    }

    public function test_un_job_de_un_tenant_borrado_falla_con_mensaje_claro(): void
    {
        $this->context()->runAs($this->globex, function () {
            RecordTenantJob::dispatch($this->probe);
        });

        $this->globex->forceDelete();

        $this->context()->forget();
        $this->work();

        // No se ejecutó (job huérfano) en vez de correr sin contexto.
        $this->assertSame('', $this->recorded(), 'El job de un tenant borrado se ejecutó igual.');
    }
}
