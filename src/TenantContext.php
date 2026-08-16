<?php

namespace Esolutions\Tenancy;

use Esolutions\Tenancy\Contracts\Tenant;
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
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /** true = operación deliberadamente cross-tenant (panel central, migrador, mantenimiento). */
    private bool $systemMode = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
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
        $this->tenant = null;
    }

    /**
     * Ejecuta un callback con OTRO tenant en contexto y restaura el anterior.
     * Uso: jobs, comandos, migrador, seeders.
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $previousSystem = $this->systemMode;
        $this->tenant = $tenant;
        $this->systemMode = false;

        try {
            return $this->settle($callback($tenant));
        } finally {
            $this->tenant = $previous;
            $this->systemMode = $previousSystem;
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
     * Ejecuta un callback SIN filtro de tenant (cross-tenant deliberado).
     * Uso EXCLUSIVO: panel central, reportes globales del proveedor, migrador, mantenimiento.
     * Nunca en código servido a un tenant.
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->tenant;
        $previousSystem = $this->systemMode;
        $this->tenant = null;
        $this->systemMode = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->systemMode = $previousSystem;
        }
    }
}
