<?php

namespace Esolutions\Tenancy\Cache;

use Esolutions\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * Decora cualquier cache store y prefija las claves con el tenant en contexto.
 *
 * SIN ESTO HAY FUGA DE DATOS: las claves de cache son globales. Si el tenant A guarda
 * `dashboard_stats` y el tenant B lee la misma clave, B ve datos de A. Es una fuga silenciosa que
 * el TenantScope no puede detener (no pasa por la base de datos).
 *
 * Se decora el STORE (10 métodos) y no el Repository, y se prefija la CLAVE en vez de usar
 * `setPrefix()` del store o tags — porque no todos los drivers los soportan (FileStore no tiene
 * prefijo; el driver `file` no soporta tags).
 *
 * Sin tenant en contexto (panel central, comandos de framework, boot) NO se prefija: esas claves
 * son globales a propósito.
 */
class TenantAwareStore implements Store
{
    public function __construct(
        private readonly Store $store,
        private readonly TenantContext $context,
    ) {}

    /**
     * Prefijo de la clave para el tenant actual (vacío si no hay tenant en contexto).
     *
     * Lo arma el contexto: con base por tenant los ids se repiten entre bases, y `t{id}:` solo
     * dejaría de aislar — dos empresas con id 1 en bases distintas compartirían claves.
     */
    private function tenantPrefix(): string
    {
        return $this->context->cachePrefix();
    }

    private function key(string $key): string
    {
        return $this->tenantPrefix().$key;
    }

    /** @param  array<int, string>  $keys */
    private function keys(array $keys): array
    {
        return array_map(fn ($k) => $this->key($k), $keys);
    }

    public function get($key)
    {
        return $this->store->get($this->key($key));
    }

    public function many(array $keys)
    {
        $prefixed = $this->store->many($this->keys($keys));

        // Devolver el arreglo con las claves ORIGINALES (sin prefijo), como espera el llamador.
        $out = [];
        $i = 0;
        foreach ($prefixed as $value) {
            $out[$keys[$i++]] = $value;
        }

        return $out;
    }

    public function put($key, $value, $seconds)
    {
        return $this->store->put($this->key($key), $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        $prefixed = [];
        foreach ($values as $k => $v) {
            $prefixed[$this->key((string) $k)] = $v;
        }

        return $this->store->putMany($prefixed, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->store->increment($this->key($key), $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->store->decrement($this->key($key), $value);
    }

    public function forever($key, $value)
    {
        return $this->store->forever($this->key($key), $value);
    }

    public function forget($key)
    {
        return $this->store->forget($this->key($key));
    }

    public function touch($key, $seconds)
    {
        return $this->store->touch($this->key($key), $seconds);
    }

    /**
     * FAIL-CLOSED: vaciar el cache dentro del contexto de un tenant borraría también el de TODOS
     * los demás (los drivers no permiten flush selectivo por prefijo de forma portable).
     */
    public function flush()
    {
        if ($this->context->has()) {
            throw new RuntimeException(
                'Cache::flush() dentro del contexto de un tenant borraría el cache de TODOS los '
                .'tenants. Usá forget() por clave, o hacelo desde runAsSystem() si es intencional.'
            );
        }

        return $this->store->flush();
    }

    public function getPrefix()
    {
        return $this->store->getPrefix().$this->tenantPrefix();
    }

    /**
     * Soporte de tags (Redis/Memcached/Array): se prefijan los NOMBRES de los tags con el tenant,
     * de modo que el hash de tags resultante es distinto por empresa → quedan aislados.
     * Los drivers sin tags (file/database) lanzan BadMethodCallException, igual que en Laravel.
     */
    public function tags($names)
    {
        if (! method_exists($this->store, 'tags')) {
            throw new \BadMethodCallException('This cache store does not support tagging.');
        }

        $prefix = $this->tenantPrefix();
        $names = array_map(fn ($n) => $prefix.$n, (array) $names);

        return $this->store->tags($names);
    }

    /** Acceso al store real (p. ej. para features específicas del driver). */
    public function getInnerStore(): Store
    {
        return $this->store;
    }
}
