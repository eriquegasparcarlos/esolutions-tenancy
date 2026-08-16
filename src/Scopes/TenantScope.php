<?php

namespace Esolutions\Tenancy\Scopes;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Filtra TODA query de un modelo de negocio por el tenant en contexto.
 *
 * FAIL-CLOSED (diferencia clave con otros paquetes): si no hay tenant en contexto y no estamos en
 * modo system, lanza excepción en vez de devolver filas de todos los tenants. Es lo que evita que
 * un job/comando al que se le olvidó establecer el contexto filtre datos entre empresas.
 *
 * Para operaciones cross-tenant deliberadas: TenantContext::runAsSystem() o Model::allTenants().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            $builder->where(
                $model->getTable().'.'.$model->getTenantColumn(),
                $context->id()
            );

            return;
        }

        if ($context->isSystemMode()) {
            return; // cross-tenant deliberado
        }

        throw new RuntimeException(sprintf(
            'Query sobre [%s] sin tenant en contexto. Establecé el contexto (TenantContext::runAs) '
            .'o usá runAsSystem()/allTenants() si es deliberadamente cross-tenant.',
            $model::class
        ));
    }
}
