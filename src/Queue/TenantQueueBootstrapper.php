<?php

namespace Esolutions\Tenancy\Queue;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;

/**
 * Hace que las colas sean tenant-aware AUTOMÁTICAMENTE, sin traits ni cambios en los jobs.
 *
 * El problema: un worker es un proceso de larga vida sin request HTTP. Un job despachado por la
 * empresa A se procesa sin contexto → con el scope fail-closed reventaría, y si el worker venía de
 * procesar un job de B, podría correr **con el tenant equivocado** (peor: escribe datos cruzados).
 *
 * Solución: al ENCOLAR se estampa el `tenant_id` en el payload del job; al PROCESAR se restaura ese
 * tenant en el contexto y al terminar se limpia. Funciona con cualquier driver (redis, database,
 * sqs, sync) porque viaja en el payload del propio job.
 *
 * Se desactiva con `config('tenancy.queue.propagate' => false)`.
 */
class TenantQueueBootstrapper
{
    public const PAYLOAD_KEY = 'tenant_id';

    public function __construct(private readonly Container $app) {}

    public function bootstrap(): void
    {
        $this->stampPayload();
        $this->restoreOnProcessing();
    }

    /**
     * Al encolar: agrega el tenant actual al payload de todo job.
     *
     * Se usa la clase concreta (no la facade Queue) a propósito: la facade resuelve el
     * QueueManager y reenviaría la llamada a una conexión, forzando a conectarse al backend de
     * colas durante el boot.
     */
    private function stampPayload(): void
    {
        Queue::createPayloadUsing(function () {
            $id = $this->context()->id();

            return $id === null ? [] : [self::PAYLOAD_KEY => $id];
        });
    }

    /** Al procesar: restaura el tenant del payload; al terminar, limpia. */
    private function restoreOnProcessing(): void
    {
        $events = $this->app->make('events');

        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            $payload = $event->job->payload();
            $id = $payload[self::PAYLOAD_KEY] ?? null;

            if ($id === null) {
                // Job sin tenant (comando del sistema): se corre en modo system explícito para no
                // reventar con el scope fail-closed, y sobre todo para NO heredar el tenant del
                // job anterior que procesó este mismo worker.
                $this->context()->forget();

                return;
            }

            $tenant = $this->resolveTenant($id);

            $this->context()->set($tenant);
        });

        $clear = fn () => $this->context()->forget();

        $events->listen(JobProcessed::class, $clear);
        $events->listen(JobFailed::class, $clear);
        $events->listen(Looping::class, $clear); // entre jobs, el worker queda sin contexto
    }

    private function resolveTenant(int|string $id): mixed
    {
        $model = config('tenancy.tenant_model');

        return $model::find($id);
    }

    private function context(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }
}
