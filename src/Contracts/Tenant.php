<?php

namespace Esolutions\Tenancy\Contracts;

/**
 * Contrato mínimo que el paquete necesita de un tenant. La aplicación provee el modelo concreto
 * (con sus módulos, plan, facturación, etc.) y lo declara en `config('tenancy.tenant_model')`.
 *
 * El paquete aporta el MECANISMO (aislamiento, resolución, sesión); la app aporta la POLÍTICA.
 */
interface Tenant
{
    /** Valor que se guarda en la columna `tenant_id` de las tablas de negocio. */
    public function getTenantKey(): int|string;

    /** Si es false, el middleware de resolución rechaza la request. */
    public function isTenantActive(): bool;

    /**
     * Nombre de la base propia del tenant, o null si vive en la base compartida (separado solo
     * por `tenant_id`). Decide a qué base se conecta el contexto al entrar el tenant.
     */
    public function getTenantDatabase(): ?string;
}
