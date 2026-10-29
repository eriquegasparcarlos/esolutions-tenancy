<?php

namespace Esolutions\Tenancy;

use Esolutions\Tenancy\Contracts\Tenant;
use Esolutions\Tenancy\Database\TenantDatabaseSwitcher;
use RuntimeException;

/**
 * Contexto del tenant actual (singleton).
 *
 * REGLA DE ORO: el tenant SIEMPRE se resuelve desde el dominio (o desde el login), NUNCA desde un
 * parámetro que manda el cliente.
 *
 * Fuera de una request HTTP (jobs, comandos, tests) hay que establecerlo explícitamente con
 * `runAs()`, o las queries de modelos con BelongsToTenant fallarán (fail-closed) en vez de
 * devolver datos de todos los tenants.
 *
 * BASE POR TENANT: cada vez que cambia el tenant en contexto —set(), runAs(), runAsSystem(),
 * forget()— se le pide al conmutador que conecte a la base de ese tenant (o vuelva a la central si
 * no tiene base propia). Es un solo gancho, acá, y no en el middleware, el runAs y el worker por
 * separado: los tres pasan por este contexto.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /** true = operación deliberadamente cross-tenant (panel central, migrador, mantenimiento). */
    private bool $systemMode = false;

    public function __construct(private readonly ?TenantDatabaseSwitcher $switcher = null) {}

    /**
     * Si la conmutación de base falla, el contexto vuelve al tenant anterior: dejar el tenant nuevo
     * con la base vieja conectada haría que las consultas siguientes filtren por un `tenant_id`
     * contra la base equivocada.
     */
    public function set(?Tenant $tenant): void
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            $this->applyDatabase();
        } catch (\Throwable $e) {
            $this->tenant = $previous;

            throw $e;
        }
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): int|string|null
    {
        return $this->tenant?->getTenantKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function isSystemMode(): bool
    {
        return $this->systemMode;
    }

    /** Id del tenant o excepción — para código que NO debe correr sin contexto. */
    public function idOrFail(): int|string
    {
        return $this->id() ?? throw new RuntimeException(
            'No hay tenant en contexto. Usá TenantContext::runAs() en jobs/comandos, o runAsSystem() '
            .'si la operación es deliberadamente cross-tenant.'
        );
    }

    public function forget(): void
    {
        $previous = $this->tenant;
        $this->tenant = null;

        try {
            $this->applyDatabase();
        } catch (\Throwable $e) {
            $this->tenant = $previous;

            throw $e;
        }
    }

    /**
     * Prefijo con el que el cache aísla al tenant actual (vacío sin tenant).
     *
     * Con base por tenant los ids se repiten entre bases: `t1:` sería la misma clave para la
     * empresa 1 de la base A y la empresa 1 de la base B. Por eso el prefijo lleva la base.
     */
    public function cachePrefix(): string
    {
        $id = $this->id();

        if ($id === null) {
            return '';
        }

        $base = $this->tenant?->getTenantDatabase();

        return $base ? "d{$base}:t{$id}:" : "t{$id}:";
    }

    /**
     * Ejecuta un callback con OTRO tenant en contexto y restaura el anterior.
     * Uso: jobs, comandos, migrador, seeders.
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $previousSystem = $this->systemMode;

        // La asignación y la conmutación van DENTRO del try: si conectar a la base del tenant
        // falla, el finally restaura el contexto anterior en vez de dejarlo a medias.
        try {
            $this->tenant = $tenant;
            $this->systemMode = false;
            $this->applyDatabase();

            return $this->settle($callback($tenant));
        } finally {
            $this->tenant = $previous;
            $this->systemMode = $previousSystem;
            $this->applyDatabase();
        }
    }

    /**
     * `Job::dispatch()` devuelve un PendingDispatch que encola recién en su __destruct(). Si el
     * callback lo RETORNA (típico con arrow functions: `fn () => MiJob::dispatch()`), el objeto se
     * destruye afuera de este método — es decir, DESPUÉS de restaurar el contexto — y el job se
     * encolaría sin tenant (o con el equivocado).
     *
     * Acá se fuerza su destrucción todavía dentro del contexto correcto.
     */
    private function settle(mixed $result): mixed
    {
        if ($result instanceof \Illuminate\Foundation\Bus\PendingDispatch) {
            unset($result); // dispara __destruct() con el tenant aún en contexto

            return null;
        }

        return $result;
    }

    /**
     * Ejecuta un callback SIN filtro de tenant (cross-tenant deliberado), conectado a la central.
     * Uso EXCLUSIVO: panel central, reportes globales del proveedor, migrador, mantenimiento.
     * Nunca en código servido a un tenant.
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->tenant;
        $previousSystem = $this->systemMode;

        try {
            $this->tenant = null;
            $this->systemMode = true;
            $this->applyDatabase();

            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->systemMode = $previousSystem;
            $this->applyDatabase();
        }
    }

    /** La base sigue al tenant: la suya si tiene, la central si no (o si no hay tenant). */
    private function applyDatabase(): void
    {
        $this->switcher?->connectTo($this->tenant?->getTenantDatabase());
    }
}
