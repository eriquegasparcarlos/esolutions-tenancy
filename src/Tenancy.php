<?php

namespace Esolutions\Tenancy;

use Esolutions\Tenancy\Contracts\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Throwable;

/**
 * Helper para trabajo por-tenant fuera de HTTP: cron/scheduler, comandos, mantenimiento.
 *
 * En single-DB una tarea programada corre UNA vez para todas las empresas, así que hay que iterar
 * explícitamente. Este helper garantiza que cada iteración tenga su contexto y que un fallo en una
 * empresa no aborte al resto.
 */
class Tenancy
{
    /** Query de tenants activos (el modelo de tenant no lleva TenantScope). */
    public static function query(): Builder
    {
        $model = config('tenancy.tenant_model');

        return $model::query()->where('is_active', true);
    }

    /**
     * Regla `unique` acotada al tenant actual.
     *
     * OBLIGATORIO en vez de `Rule::unique()`: las reglas de validación consultan la BASE DE DATOS
     * directamente (query builder), NO el modelo → **no pasan por el TenantScope**. Con
     * `Rule::unique('users','email')` el email de OTRA empresa haría fallar la validación (y de
     * paso revela que ese email existe en el sistema).
     *
     * ```php
     * 'email' => ['required', Tenancy::unique('users', 'email')->ignore($user)],
     * ```
     */
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)
            ->where(config('tenancy.column', 'tenant_id'), app(TenantContext::class)->idOrFail());
    }

    /** Regla `exists` acotada al tenant actual. Mismo motivo que {@see unique()}. */
    public static function exists(string $table, string $column = 'NULL'): Exists
    {
        return Rule::exists($table, $column)
            ->where(config('tenancy.column', 'tenant_id'), app(TenantContext::class)->idOrFail());
    }

    /**
     * Prefija el nombre de un canal de broadcasting con el tenant.
     *
     * Sin esto, dos empresas comparten el canal `orders.1` (mismo id en ambas) y un evento de una
     * llegaría a los suscriptores de la otra.
     *
     * ```php
     * broadcast(new OrderShipped)->toOthers();      // en el evento:
     * public function broadcastOn(): Channel { return new PrivateChannel(Tenancy::channel('orders')); }
     * ```
     */
    public static function channel(string $name): string
    {
        return 'tenant.'.app(TenantContext::class)->idOrFail().'.'.$name;
    }

    /**
     * Ejecuta el callback una vez por cada tenant, con su contexto establecido.
     *
     * ```php
     * Schedule::call(fn () => Tenancy::each(fn ($t) => Invoice::sendReminders()))->dailyAt('08:00');
     * ```
     *
     * @param  callable(Tenant): void  $callback
     * @param  array<int, int|string>|null  $only  ids de tenant a procesar (null = todos los activos)
     * @param  callable(Tenant, Throwable): void|null  $onError  si es null, la excepción se propaga
     */
    public static function each(callable $callback, ?array $only = null, ?callable $onError = null): void
    {
        $context = app(TenantContext::class);

        $query = static::query();

        if ($only !== null) {
            $query->whereIn((new (config('tenancy.tenant_model')))->getKeyName(), $only);
        }

        $query->chunkById(100, function ($tenants) use ($context, $callback, $onError) {
            foreach ($tenants as $tenant) {
                try {
                    $context->runAs($tenant, $callback);
                } catch (Throwable $e) {
                    if ($onError === null) {
                        throw $e;
                    }

                    // Un tenant que falla NO debe abortar la corrida de los demás.
                    $onError($tenant, $e);
                }
            }
        });
    }
}
