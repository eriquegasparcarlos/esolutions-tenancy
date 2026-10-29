<?php

namespace Esolutions\Tenancy\Database;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Conecta la aplicación a la base propia de un tenant, y la devuelve a la central.
 *
 * CÓMO: registra una conexión aparte (`tenancy.database.connection_name`) clonada de la central con
 * el nombre de base del tenant, y la pone como conexión POR DEFECTO. Los modelos de negocio usan
 * la conexión por defecto, así que pasan solos a la base del tenant. La central NO se toca: sus
 * transacciones abiertas siguen vivas y sus consultas explícitas (`DB::connection('central')`)
 * siguen yendo a donde iban.
 *
 * POR QUÉ ASÍ y no mutando la central y purgándola: purgar cierra el PDO, y con él cualquier
 * transacción abierta — y hay código que abre transacciones sobre una conexión con nombre mientras
 * los modelos escriben por la de por defecto. Cambiar cuál es la de por defecto deja todo eso en
 * pie.
 *
 * Es el ÚNICO lugar que decide la base. Lo llama TenantContext cada vez que cambia el tenant
 * (request HTTP, runAs, job de cola), así que no hay tres sitios que mantener sincronizados.
 */
class TenantDatabaseSwitcher
{
    /** Nombre de la conexión por defecto ORIGINAL de la app (la central), capturado al primer uso. */
    private ?string $central = null;

    /** Base de tenant conectada ahora mismo; null = estamos en la central. */
    private ?string $current = null;

    public function __construct(private readonly Application $app) {}

    /** Base de tenant conectada ahora, o null si la conexión por defecto es la central. */
    public function current(): ?string
    {
        return $this->current;
    }

    /** Nombre de la conexión fija a la base central. */
    public function centralConnection(): string
    {
        $configurada = $this->app['config']->get('tenancy.database.central_connection');

        if ($configurada) {
            return $configurada;
        }

        // Se captura UNA vez: después de conmutar, `database.default` ya no es la central.
        return $this->central ??= (string) $this->app['config']->get('database.default');
    }

    /** Nombre de la conexión que el paquete registra para la base del tenant en contexto. */
    public function tenantConnection(): string
    {
        return (string) $this->app['config']->get('tenancy.database.connection_name', 'tenant_db');
    }

    /** Nombre de la base de la conexión central. */
    public function centralDatabase(): ?string
    {
        return $this->app['config']->get('database.connections.'.$this->centralConnection().'.database');
    }

    /**
     * Conecta a la base indicada. null (o el nombre de la central) = volver a la central.
     * Idempotente: pedir la base que ya está conectada no hace nada.
     */
    public function connectTo(?string $database): void
    {
        if (! $this->enabled()) {
            return;
        }

        // Capturar la central ANTES de cualquier conmutación, aunque el primer tenant sea compartido.
        $this->centralConnection();

        if ($database === null || $database === $this->centralDatabase()) {
            $this->reconnectToCentral();

            return;
        }

        self::assertValidName($database);

        if ($this->current === $database) {
            return;
        }

        $this->assertNoOpenTransaction();

        $nombre = $this->tenantConnection();
        $plantilla = $this->app['config']->get('tenancy.database.template_connection') ?: $this->centralConnection();
        $config = $this->app['config']->get("database.connections.{$plantilla}");

        if (! is_array($config)) {
            throw new RuntimeException("No existe la conexión plantilla [{$plantilla}] para clonar la del tenant.");
        }

        $config['database'] = $database;
        $this->app['config']->set("database.connections.{$nombre}", $config);

        $db = $this->app['db'];
        // Si la conexión del tenant ya existía apuntando a OTRA base, hay que cerrarla: reusar el
        // PDO viejo seguiría hablando con la base anterior.
        $db->purge($nombre);
        $db->setDefaultConnection($nombre);
        $this->forgetAliases($db);

        $this->current = $database;
    }

    /** Vuelve a la central y cierra la conexión del tenant (un worker no debe quedarse con un PDO abierto a una base ajena). */
    public function reconnectToCentral(): void
    {
        if ($this->current === null) {
            return;
        }

        $this->assertNoOpenTransaction();

        $db = $this->app['db'];
        $db->setDefaultConnection($this->centralConnection());
        $db->purge($this->tenantConnection());
        $this->forgetAliases($db);

        $this->current = null;
    }

    /**
     * Alias que deben SEGUIR a la conexión por defecto (`tenancy.database.alias_connections`).
     *
     * Caso típico: código heredado que escribe `DB::connection('tenant')` a mano, con `tenant`
     * registrado vía `DB::extend('tenant', fn () => DB::connection())`. El manager cachea lo que
     * devuelve ese resolver, así que después de conmutar el alias seguiría entregando la conexión
     * de ANTES. Acá se olvida la entrada cacheada para que la próxima llamada vuelva a resolverse.
     *
     * No se usa `purge()`: purge desconecta el PDO, y como el alias es la MISMA instancia que la
     * conexión por defecto, le cerraría la conexión a esta (y a su transacción, si tuviera). Solo
     * se suelta la referencia; la conexión real sigue viva bajo su propio nombre.
     */
    private function forgetAliases($db): void
    {
        $aliases = (array) $this->app['config']->get('tenancy.database.alias_connections', []);

        if (! $aliases) {
            return;
        }

        (function () use ($aliases) {
            foreach ($aliases as $alias) {
                unset($this->connections[$alias]);
            }
        })->call($db);
    }

    /**
     * Cambiar de base con una transacción abierta en la conexión actual es el peor error posible:
     * lo que se escriba del otro lado NO está cubierto por esa transacción, y un rollback lo deja
     * confirmado. Se corta acá, con un mensaje que diga qué pasó.
     *
     * Solo se pregunta a conexiones ya instanciadas: abrir un PDO para preguntarle si tiene una
     * transacción sería absurdo.
     */
    private function assertNoOpenTransaction(): void
    {
        $db = $this->app['db'];
        $actual = $db->getDefaultConnection();

        if (! isset($db->getConnections()[$actual])) {
            return;
        }

        if ($db->connection($actual)->transactionLevel() > 0) {
            throw new RuntimeException(
                "No se puede cambiar de base con una transacción abierta en [{$actual}]: lo que se "
                .'escriba en la otra base quedaría fuera de ella. Cerrá la transacción antes de '
                .'cambiar de tenant, o abrila adentro del runAs().'
            );
        }
    }

    private function enabled(): bool
    {
        return (bool) $this->app['config']->get('tenancy.database.enabled', true);
    }

    /** El nombre va a una sentencia DDL sin parámetros posibles: se valida estrictamente. */
    public static function assertValidName(string $database): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new RuntimeException("Nombre de base inválido: [{$database}]. Solo letras, números y guión bajo.");
        }
    }
}
