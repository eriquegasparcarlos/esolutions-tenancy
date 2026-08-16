# esolutions/tenancy

Multi-tenancy **single-database** para Laravel: aislamiento row-level *fail-closed*, resolución por
dominio y scoping de sesión.

Todos los tenants comparten las mismas tablas y el dato se separa por `tenant_id`. **No hay una base
por tenant, no hay migraciones ×N y dar de alta una empresa es insertar una fila.**

## Por qué existe

Los paquetes de tenancy más difundidos están diseñados para **multi-database**. Su modo single-DB
suele traer un scope **fail-open**:

```php
if (! tenancy()->initialized) {
    return;   // devuelve filas de TODOS los tenants
}
```

En multi-DB eso significa "estoy en el contexto central" y es correcto. En **single-DB es el
escenario de fuga**: un job en cola, un comando o un listener sin contexto lee todas las empresas,
en silencio y sin error.

Acá el scope es **fail-closed**: sin tenant en contexto, la query **lanza excepción**.

Además, el motivo por el que se elige single-DB: con una base por tenant, el número de tablas del
motor crece con el número de clientes (`empresas × tablas`). MySQL dimensiona
`table_definition_cache` / `open_files_limit` por **tablas abiertas**, no por filas — al superarlo
aparecen errores `1615 Prepared statement needs to be re-prepared`. Con single-DB el número de
tablas es **fijo**.

## Requisitos

- PHP >= 8.2
- Laravel 11, 12 o 13

## Instalación

```bash
composer require esolutions/tenancy
php artisan vendor:publish --tag=tenancy-migrations
php artisan vendor:publish --tag=tenancy-config   # opcional
php artisan migrate
```

<details>
<summary>Instalar desde el repositorio (sin Packagist)</summary>

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/eriquegasparcarlos/esolutions-tenancy" }
    ]
}
```
</details>

## Configuración

El paquete aporta el **mecanismo**; la aplicación aporta la **política**. La app declara sus modelos
concretos:

```php
// config/tenancy.php
'tenant_model'    => \App\Models\System\Tenant::class,
'domain_model'    => \App\Models\System\Domain::class,
'central_domains' => ['miapp.com', 'admin.miapp.com'],
```

```php
// app/Models/System/Tenant.php
class Tenant extends \Esolutions\Tenancy\Models\Tenant
{
    // política propia de la app
    public function modules(): HasMany { return $this->hasMany(TenantModule::class); }
    public function hasModule(string $v): bool { return $this->modules()->where('value', $v)->exists(); }
}
```

Si preferís no extender los modelos base, alcanza con implementar
`Esolutions\Tenancy\Contracts\Tenant` (`getTenantKey()` e `isTenantActive()`).

### Opciones de `config/tenancy.php`

| Clave | Default | Para qué |
|---|---|---|
| `tenant_model` / `domain_model` | `App\Models\System\*` | modelos concretos de la app |
| `column` | `tenant_id` | columna discriminadora en las tablas de negocio |
| `slug_column` | `slug` | columna del tenant usada al resolver por subdominio |
| `resolution_cache_ttl` | `300` | segundos de cache de `host → tenant` (`0` = sin cache) |
| `base_domain` | `localhost` | dominio base de los subdominios (`acme.<base>`) |
| `central_domains` | `localhost,127.0.0.1` | dominios del panel del proveedor |
| `middleware_group` | `tenant` | nombre del grupo de middleware que registra el paquete |

## Uso

### 1. Modelos de negocio

```php
use Esolutions\Tenancy\Concerns\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant;   // filtra al leer, setea tenant_id al crear
}
```

Los modelos **globales** (catálogos) y el **control-plane** (`Tenant`, `Domain`) NO lo usan.

### 2. Rutas

```php
Route::middleware(['web', 'tenant'])->group(base_path('routes/tenant.php'));
```

El grupo `tenant` se registra solo y aplica, **en este orden**:

| Middleware | Qué hace |
|---|---|
| `PreventAccessFromCentralDomain` | las rutas de empresa no responden en el dominio del proveedor |
| `ResolveTenant` | host → tenant → contexto (busca dominio exacto, luego subdominio) |
| `ScopeSessionToTenant` | ata la sesión al tenant |

> **`ScopeSessionToTenant` no es opcional.** Las cookies se comparten entre subdominios de un mismo
> dominio padre: sin esto, una sesión abierta en `acme.miapp.com` seguiría siendo válida en
> `globex.miapp.com` y el usuario quedaría autenticado en otra empresa.

### 3. Cron / tareas programadas

En single-DB una tarea corre **una vez** para todas las empresas, así que hay que iterar:

```bash
php artisan tenants:run inventory:recalc
php artisan tenants:run "invoices:remind --days=3"
php artisan tenants:run report:build --tenants=1,5,9
```

```php
// routes/console.php
Schedule::command('tenants:run invoices:remind')->dailyAt('08:00');

// o con un closure
Schedule::call(fn () => Tenancy::each(fn ($t) => Invoice::sendReminders()))->hourly();
```

Un tenant que falla **no aborta** la corrida de los demás (salvo `--stop-on-error`).

### 4. Fuera de HTTP (jobs, comandos, seeders)

```php
use Esolutions\Tenancy\TenantContext;

app(TenantContext::class)->runAs($tenant, function () {
    Invoice::create([...]);   // tenant_id automático
});

// Cross-tenant deliberado (panel central, migrador, mantenimiento):
app(TenantContext::class)->runAsSystem(fn () => Invoice::count());
Invoice::allTenants()->get();
```

Sin contexto y sin modo system, cualquier query **lanza excepción** en vez de devolver datos de
todas las empresas.

> **Gotcha resuelto:** `MiJob::dispatch()` devuelve un `PendingDispatch` que encola recién al
> destruirse. Con `fn () => MiJob::dispatch()` (arrow function que lo *retorna*), el objeto moría
> fuera del contexto y el job se encolaba **sin tenant**. `runAs()` fuerza su encolado dentro del
> contexto correcto, así que ambas formas funcionan.

### 5. Testing

`Storage::fake()` **inyecta un disco directamente en el manager y saltea el aislamiento por
tenant**, así que no sirve para probarlo. Para tests de storage, apuntá el disco real a un
directorio temporal:

```php
config()->set('filesystems.disks.local.root', storage_path('framework/testing/disks/'.uniqid()));
```

## Superficies compartidas de Laravel — auditoría completa

En single-DB no alcanza con filtrar la base de datos. Laravel tiene varias superficies **globales**
donde una empresa puede ver (o pisar) datos de otra **sin pasar por SQL**, así que el scope no las
puede detener. Esto es lo que cubre el paquete y lo que queda a cargo de la app:

| Superficie | Riesgo si no se aísla | Estado |
|---|---|---|
| Consultas Eloquent | leer/editar datos de otra empresa | ✅ `TenantScope` (fail-closed) |
| Route model binding | IDOR por URL | ✅ vía scope → 404 |
| **Cache** (todos los drivers) | leer valores cacheados de otra empresa | ✅ claves prefijadas |
| **Cache tags** (redis/memcached) | idem | ✅ nombres de tag prefijados |
| `Cache::flush()` | borrar el cache de TODAS las empresas | ✅ bloqueado dentro de un tenant |
| **Rate limiter** | contador de throttle compartido | ✅ automático (usa cache) |
| **Sesiones** | quedar autenticado en otra empresa | ✅ `ScopeSessionToTenant` |
| **Colas** (redis/database/sqs) | job procesado con el tenant equivocado | ✅ payload + restauración |
| Listeners encolados, Notifications, Mail en cola | idem | ✅ son jobs: mismo mecanismo |
| **Eventos síncronos** y observers | — | ✅ corren en el contexto actual |
| **Scheduler / cron** | la tarea corre sin tenant | ✅ `tenants:run` + `Tenancy::each()` |
| **Storage / archivos** | pisar y leer archivos ajenos | ✅ discos con prefijo por tenant |
| **Broadcasting** | evento enviado al canal de otra empresa | ✅ `Tenancy::channel()` *(manual)* |
| **Validación** `unique` / `exists` | falso duplicado + revela datos ajenos | ✅ `Tenancy::unique()` *(manual)* |
| Queries crudas `DB::table()` | fuga total | ⚠️ **manual**: `where('tenant_id', …)` |
| `Redis::` usado directo | claves globales (no pasa por cache) | ⚠️ **manual**: prefijar la clave |
| Octane / workers de larga vida | el contexto persiste entre requests | ⚠️ ver abajo |

### Storage — convención de carpetas

Los discos listados en `tenancy.filesystem.scoped_disks` se montan bajo un prefijo por tenant, así
que el código de la app **no cambia**:

```php
Storage::disk('public')->put('logo.png', $file);
// acme  → storage/app/public/tenants/acme/logo.png
// globex→ storage/app/public/tenants/globex/logo.png
```

El patrón se configura con `path_prefix` y admite `{slug}` (subdominio — carpetas legibles, el
default) o `{id}` (clave primaria, inmutable). Con `{slug}`, renombrar el subdominio de una empresa
obliga a mover su carpeta.

### Octane y procesos de larga vida

`TenantContext` es un singleton: en Octane/Swoole persiste entre requests. El middleware
`ResolveTenant` lo sobreescribe en cada request, pero si una ruta **no** pasa por el grupo `tenant`
podría heredar el contexto de la request anterior. Si usás Octane, limpiá el contexto entre
requests:

```php
Octane::tick('flush-tenant', fn () => app(TenantContext::class)->forget());
// o en un listener de RequestTerminated
```

En los workers de cola esto ya está resuelto: el bootstrapper limpia el contexto entre jobs.

## Reglas de esquema (responsabilidad de la app)

1. Toda tabla de negocio: `tenant_id` con FK a `tenants` + `cascadeOnDelete`.
2. **Todo UNIQUE es compuesto con `tenant_id`.** Ej.: `unique(['tenant_id','series','number'])`.
   Un UNIQUE global impediría que dos empresas usen la misma serie.
3. Índices de acceso encabezados por `tenant_id`.
4. **Queries crudas** (`DB::table`, `DB::raw`) NO pasan por el scope → agregar
   `where('tenant_id', ...)` a mano. Es la fuga más probable; auditar con grep.
5. La tabla `tenants` debe vivir en la **misma base** que las de negocio: MySQL no soporta FK entre
   esquemas, así que un control-plane en otra base impediría la FK `tenant_id → tenants.id`.
6. Tests de aislamiento por módulo (tenant A no ve a B).

## Regla de oro

> El `tenant_id` **siempre** sale del dominio o del login, **nunca** de un parámetro del request.

Efecto lateral: protege contra IDOR gratis — si cambian el ID en la URL por uno de otra empresa,
`findOrFail` no lo encuentra → 404, no fuga.

## Qué NO hace este paquete

- No crea bases de datos ni corre migraciones por tenant (no hace falta: es single-DB).
- No gestiona planes, facturación del SaaS ni módulos contratados — eso es política de la app.
- No provee panel de administración.

## Licencia

Proprietary — © Carlos Erique Gaspar.
