<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modelo single-DB (row-level, tenant_id)
    |--------------------------------------------------------------------------
    | Todos los tenants comparten las mismas tablas; el dato se separa por `tenant_id`.
    | No hay base por tenant, no hay migraciones ×N.
    */

    // Modelos concretos de la app (deben extender los del paquete o implementar su contrato).
    'tenant_model' => \App\Models\System\Tenant::class,
    'domain_model' => \App\Models\System\Domain::class,

    // Columna discriminadora en las tablas de negocio.
    'column' => 'tenant_id',

    // Columna del tenant usada para resolver por subdominio.
    'slug_column' => 'slug',

    /*
    | Columna que filtra tenants activos en Tenancy::query()/each()/tenants:run (optimización SQL).
    | Con null no se filtra en SQL y decide únicamente el contrato isTenantActive(), que each()
    | re-verifica siempre de todos modos.
    */
    'active_column' => 'is_active',

    // Segundos de cache para resolver host -> tenant (0 = sin cache).
    'resolution_cache_ttl' => env('TENANCY_RESOLUTION_CACHE_TTL', 300),

    // Dominio base para los subdominios de tenant (acme.<base>).
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'localhost'),

    /*
    | Dominios CENTRALES: donde vive el panel del proveedor. Las rutas de tenant NO responden acá.
    */
    'central_domains' => array_filter(explode(',', (string) env(
        'TENANCY_CENTRAL_DOMAINS',
        'localhost,127.0.0.1'
    ))),

    /*
    | Nombre del middleware group que registra el paquete. Aplicalo a las rutas de tenant.
    */
    'middleware_group' => 'tenant',

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | `isolate` envuelve TODOS los cache stores para prefijar las claves con el tenant.
    | Aplica a cualquier driver (file, redis, memcached, database, array).
    |
    | ⚠️ Desactivarlo significa que dos empresas comparten las mismas claves de cache: si A guarda
    | `dashboard_stats`, B lee los datos de A. El TenantScope NO puede detener esa fuga porque no
    | pasa por la base de datos. Desactivar solo si el aislamiento se maneja de otra forma.
    */
    'cache' => [
        'isolate' => env('TENANCY_CACHE_ISOLATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Colas
    |--------------------------------------------------------------------------
    | `propagate` estampa el tenant en el payload del job al encolar y lo restaura al procesar.
    | Necesario para redis/database/sqs: el worker es un proceso de larga vida sin request HTTP.
    |
    | ⚠️ Desactivarlo hace que los jobs corran sin contexto (con el scope fail-closed, revientan) o
    | peor, heredando el tenant del job anterior del mismo worker.
    */
    'queue' => [
        'propagate' => env('TENANCY_QUEUE_PROPAGATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem
    |--------------------------------------------------------------------------
    | Discos que se montan bajo un prefijo por tenant (driver `scoped` de Laravel).
    |
    | ⚠️ Sin esto, `Storage::put('logo.png')` desde dos empresas escribe el MISMO archivo: la
    | segunda pisa a la primera y cualquiera lee el de la otra.
    |
    | Listá solo los discos con contenido DE TENANT. NO incluyas discos de assets compartidos.
    |
    | El prefijo admite `{slug}` (subdominio, carpetas legibles — igual que en qpospe) o `{id}`
    | (clave primaria, inmutable). Con `{slug}`, si algún día se renombra el subdominio de una
    | empresa hay que mover su carpeta.
    */
    'filesystem' => [
        'scoped_disks' => ['local', 'public'],
        'path_prefix' => 'tenants/{slug}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Base por tenant (opcional, convive con el modelo single-DB)
    |--------------------------------------------------------------------------
    | Un tenant con valor en `tenants.database` vive en ESA base; con NULL vive en la central,
    | separado por `tenant_id` como siempre. `tenant_id` se conserva en los dos casos: el código de
    | negocio no distingue en qué base está, y una base puede tener una empresa o varias.
    |
    | Al entrar un tenant con base propia se registra una conexión (`connection_name`) clonada de
    | `template_connection` con su nombre de base, y pasa a ser la conexión POR DEFECTO. La
    | central no se toca ni se purga: las transacciones abiertas sobre ella siguen vivas, y los
    | modelos —que usan la conexión por defecto— pasan solos a la base del tenant.
    |
    | Los modelos de tenant y dominio leen SIEMPRE de `central_connection`: resolver «quién es
    | este tenant» desde la base de otro tenant sería leer una copia espejo, nunca la verdad.
    */
    'database' => [
        'enabled' => env('TENANCY_DATABASE_ENABLED', true),

        // Columna del modelo de tenant con el nombre de su base (NULL = base compartida).
        'column' => 'database',

        // Nombre de la conexión que el paquete registra para la base del tenant en contexto.
        'connection_name' => 'tenant_db',

        // Alias registrados con `DB::extend()` que deben SEGUIR a la conexión por defecto (p. ej.
        // un `tenant` heredado que resuelve a `DB::connection()`): se olvidan del cache del manager
        // en cada conmutación para que vuelvan a resolverse.
        'alias_connections' => [],

        // Conexión fija a la base central. NULL = la conexión por defecto original de la app.
        'central_connection' => env('TENANCY_CENTRAL_CONNECTION'),

        // Conexión cuya configuración se clona para la del tenant. NULL = la central.
        'template_connection' => null,

        // Dump de esquema con el que `tenants:migrate` levanta una base nueva. NULL = el de Laravel
        // para la conexión central (database/schema/<conexión>-schema.sql) si existe.
        'schema_path' => null,

        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',

        // Prefijo para nombrar bases nuevas: <prefix><slug>.
        'name_prefix' => env('TENANCY_DATABASE_PREFIX', 'tenant_'),
    ],

];
