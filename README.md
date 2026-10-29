# esolutions/tenancy

Multi-tenancy para Laravel con aislamiento row-level *fail-closed*, resolución por dominio y
scoping de sesión. **Single-database por defecto, con base por tenant opcional.**

Todos los tenants comparten las mismas tablas y el dato se separa por `tenant_id`: dar de alta una
empresa es insertar una fila. Si un cliente necesita su propia base, se le asigna una en
`tenants.database` y el paquete conecta a ella al entrar el tenant — **conservando `tenant_id`**,
así que el código de negocio es el mismo en los dos casos (ver [Base por tenant](#6-base-por-tenant-opcional)).

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

Cuando igual hace falta una base aparte (un cliente que exige sus datos físicamente separados, un
respaldo propio, una migración de un sistema que ya venía con una base por empresa), el paquete la
soporta **sin cambiar el modelo**: el tenant sigue teniendo `tenant_id`, el scope sigue siendo
fail-closed, y lo único que cambia es a qué base apunta la conexión por defecto mientras ese tenant
está en contexto. Una base puede tener una empresa o varias.

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
| `database.enabled` | `true` | habilita la base por tenant (con `false` se ignora `tenants.database`) |
| `database.column` | `database` | columna del tenant con el nombre de su base (`NULL` = base compartida) |
| `database.connection_name` | `tenant_db` | conexión que el paquete registra para la base del tenant en contexto |
| `database.central_connection` | `null` | conexión fija a la central (`null` = la conexión por defecto original) |
| `database.template_connection` | `null` | conexión cuya config se clona para la del tenant (`null` = la central) |
| `database.schema_path` | `null` | dump con el que `tenants:migrate` levanta una base nueva (`null` = el de Laravel para la central) |
| `database.name_prefix` | `tenant_` | prefijo al nombrar bases nuevas: `<prefix><slug>` |

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

El paquete además declara la **prioridad** del trío en el kernel HTTP: corre después de
`StartSession` y **antes de `auth` y de `SubstituteBindings`**. Esto garantiza que el contexto de
tenant ya está fijado cuando el route-model-binding y el guard de autenticación tocan la base — sin
esa prioridad, ambos usarían el contexto del request anterior (tests, Octane) y podrían resolver
filas de otro tenant. No hay que configurar nada: basta `Route::middleware(['web', 'tenant'])`.

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

### 6. Base por tenant (opcional)

Un tenant con valor en `tenants.database` vive en ESA base; con `NULL` vive en la central, separado
por `tenant_id` como siempre. Los dos modelos conviven en la misma instalación.

**Cómo funciona.** Cada vez que cambia el tenant en contexto —`ResolveTenant`, `runAs()`,
`runAsSystem()`, el worker de colas, `forget()`— el contexto le pide a `TenantDatabaseSwitcher` que
conecte a la base del tenant. El conmutador registra una conexión (`tenant_db`) clonada de la
central con el nombre de base del tenant y la vuelve **conexión por defecto**. La central no se toca
ni se purga, así que una transacción abierta sobre ella sigue viva. Al salir del tenant se vuelve a
la central y se cierra la conexión del tenant. Es un solo gancho, en `TenantContext`, y no uno por
punto de entrada.

**Reglas que el paquete hace cumplir:**

- `Tenant` y `Domain` se leen **siempre** de la central (`database.central_connection`). Resolver
  «quién es este tenant» desde la base de otro sería leer una copia espejo, nunca la verdad.
- **No se cambia de base con una transacción abierta** sobre la conexión que se abandona: lanza
  `RuntimeException`. Si no, la transacción quedaría colgada en una conexión que ya nadie mira.
- El prefijo de cache pasa a ser `d{base}:t{id}:`: con base por tenant los ids se repiten entre
  bases, y `t1:` sería la misma clave para la empresa 1 de dos bases distintas.
- Cada base propia lleva una **fila espejo** del tenant en su tabla `tenants`, para que la FK
  `tenant_id → tenants.id` de las tablas de negocio cierre. La escribe `mirror()` / `provision()`.

**Alta y migración de una base propia:**

```php
use Esolutions\Tenancy\Database\TenantDatabaseManager;

$tenant = Tenant::create(['name' => 'Initech', 'slug' => 'initech', 'database' => 'tenant_initech']);

// crea la base si no existe → migra (dump de esquema + migraciones) → fila espejo
app(TenantDatabaseManager::class)->provision($tenant);
```

```bash
php artisan tenants:migrate                        # migra la base de cada tenant que tenga una
php artisan tenants:migrate --tenants=3,7 --create # crea las que falten antes de migrar
```

`migrate()` delega en `php artisan migrate --database=tenant_db`, así que una base vacía arranca del
dump de esquema igual que la central. Ojo: Laravel busca el dump **por nombre de conexión**
(`database/schema/<conexión>-schema.sql`); si el de la app lleva el nombre de la central, indicarlo
en `database.schema_path`.

**Lo que queda del lado de la app:**

- **Colas**: clavar la conexión del driver `database` a la central (`DB_QUEUE_CONNECTION`). Si
  siguiera la conexión por defecto, un job despachado desde un tenant con base propia se encolaría
  en SU base y el worker, que escucha la central, nunca lo vería.
- **Tablas del plano de control** (planes, módulos contratados, menú compartido): leerlas por la
  conexión central explícita, no por la por defecto.
- **Catálogos globales** que las tablas de negocio referencian por FK: replicarlos en cada base al
  darla de alta.

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

## Suite de tests

El paquete es la **frontera de aislamiento** de los sistemas que lo usan, así que trae su propia
suite (orchestra/testbench) para que un refactor futuro no rompa el aislamiento en silencio:

```bash
composer install
mysql -e "CREATE DATABASE esolutions_tenancy_test"
mysql -e "CREATE DATABASE esolutions_tenancy_test_b"   # base propia de un tenant, para la parte multi-base
vendor/bin/phpunit          # 65 tests
```

| Archivo | Qué garantiza |
|---|---|
| `IsolationTest` | A no ve/edita/borra lo de B · IDOR → null · `tenant_id` automático · fail-closed sin contexto · `runAsSystem`/`allTenants` · UNIQUE compuesto · `runAs` anidado restaura |
| `CacheIsolationTest` | claves por tenant · `forget` no cruza · `flush()` bloqueado en tenant y permitido fuera · `remember` aislado |
| `QueueTenancyTest` | el job conserva su tenant · no hereda el del worker · **el worker no contamina entre jobs** · dispatch con arrow function · job de tenant borrado no corre |
| `HttpTenancyTest` | resolución por dominio y subdominio · 404 si no existe · 403 si inactivo · dominio central bloqueado · **sesión cruzada descartada** |
| `SurfacesTest` | storage aislado y nombrado por subdominio · `Tenancy::unique()` por tenant · canales de broadcasting · `Tenancy::each()` (contexto, tolerancia a fallos, ignora inactivos) |
| `RawQueryTest` | `Tenancy::table()` acota el builder crudo al tenant · califica con el alias · fail-closed sin contexto |
| `MultiDatabaseTest` | un tenant sin base se queda en la central · `runAs` conecta a la base propia y **vuelve** · los modelos leen/escriben en la base del tenant y otro tenant no las ve · anidado restaura · `Tenant`/`Domain` siempre de la central · se niega a cambiar con transacción abierta · prefijo de cache con base · request HTTP y job de cola llegan a su base |
| `TenantDatabaseManagerTest` | crear/verificar/borrar · rechaza nombres no identificadores · se niega a borrar la central · `provision` migra y deja la fila espejo · `migrate` sin base propia falla claro |

Las clases multi-base no usan `RefreshDatabase` (el conmutador se niega a cambiar de base con una
transacción abierta): migran las dos bases en limpio en cada `setUp` y **vacían la central en
`tearDown`**, porque las clases que vienen después sí usan `RefreshDatabase`, que migra una sola vez
por proceso y confía en que la base está vacía.

Los tests corren contra **MySQL** (no sqlite) porque el aislamiento de storage y colas necesita el
comportamiento real del motor.

## Reglas de esquema (responsabilidad de la app)

1. Toda tabla de negocio: `tenant_id` con FK a `tenants` + `cascadeOnDelete`.
2. **Todo UNIQUE es compuesto con `tenant_id`.** Ej.: `unique(['tenant_id','series','number'])`.
   Un UNIQUE global impediría que dos empresas usen la misma serie.
3. Índices de acceso encabezados por `tenant_id`.
4. **Queries crudas** (`DB::table`, `DB::raw`) NO pasan por el scope → agregar
   `where('tenant_id', ...)` a mano. Es la fuga más probable; auditar con grep.
5. La tabla `tenants` debe vivir en la **misma base** que las de negocio: MySQL no soporta FK entre
   esquemas, así que un control-plane en otra base impediría la FK `tenant_id → tenants.id`. Con
   base por tenant eso se resuelve con la fila espejo que deja `provision()` / `mirror()`.
6. Tests de aislamiento por módulo (tenant A no ve a B).

## Regla de oro

> El `tenant_id` **siempre** sale del dominio o del login, **nunca** de un parámetro del request.

Efecto lateral: protege contra IDOR gratis — si cambian el ID en la URL por uno de otra empresa,
`findOrFail` no lo encuentra → 404, no fuga.

## Qué NO hace este paquete

- No decide qué tenant lleva base propia: eso es política de la app (plan contratado, pedido del
  cliente). El paquete solo conecta a la que diga `tenants.database`.
- No replica catálogos ni datos compartidos entre bases: la app los siembra al dar de alta.
- No gestiona planes, facturación del SaaS ni módulos contratados — eso es política de la app.
- No provee panel de administración.

## Licencia

Proprietary — © Carlos Erique Gaspar.
