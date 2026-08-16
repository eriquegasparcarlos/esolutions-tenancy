# Changelog

## 0.1.0 — 2026-08-16

Versión inicial, extraída de qpospev3 antes de que el proyecto creciera (adopción ~0, costo de
extracción mínimo).

### Aislamiento de datos
- `TenantScope` — filtro automático **fail-closed**: sin tenant en contexto lanza excepción, en vez
  de devolver filas de todos los tenants (que es lo que hacen los paquetes pensados para multi-DB).
- `BelongsToTenant` — trait para modelos de negocio (filtra al leer, setea `tenant_id` al crear,
  `allTenants()` como escape explícito).
- `TenantContext` — contexto singleton con `runAs()` / `runAsSystem()`.

### Aislamiento de superficies compartidas de Laravel
- **Cache**: `TenantAwareStore` prefija las claves en cualquier driver (file, redis, memcached,
  database, array) + tags prefijados donde el driver los soporta. `Cache::flush()` queda bloqueado
  dentro del contexto de un tenant (borraría el de todos).
- **Colas**: el tenant se estampa en el payload al encolar y se restaura al procesar; el worker
  queda sin contexto entre jobs para no heredar el tenant anterior. Cubre también listeners
  encolados, notifications y mails en cola.
- **Storage**: los discos configurados se montan bajo `tenants/{slug}/` (driver `scoped`), con
  cache de disco por tenant. Sin esto dos empresas escriben el mismo archivo.
- **Sesiones**: `ScopeSessionToTenant` ata la sesión al tenant (las cookies se comparten entre
  subdominios de un mismo dominio padre).
- **Validación**: `Tenancy::unique()` / `Tenancy::exists()` — las reglas nativas consultan la base
  directamente y **no** pasan por el scope.
- **Broadcasting**: `Tenancy::channel()` prefija el nombre del canal.
- **Cron**: comando `tenants:run` y helper `Tenancy::each()`; un tenant que falla no aborta el resto.

### HTTP
- Middleware group `tenant`: `PreventAccessFromCentralDomain` → `ResolveTenant` →
  `ScopeSessionToTenant`.
- Resolución por dominio completo, con fallback a subdominio, cacheada.

### Otros
- Modelos base `Tenant` / `Domain` + contrato `Contracts\Tenant` (la app provee el modelo concreto).
- Migración publicable del control-plane (`tenants`, `domains`).
- `runAs()` fuerza el encolado de un `PendingDispatch` retornado, para que los jobs despachados con
  arrow functions no queden sin tenant.

> 0.x: la API puede cambiar mientras se estabiliza con el primer consumidor real (qpospev3).

## 0.3.0 — 2026-08-16

Cierra los dos hallazgos restantes de la revisión externa:

- **Frescura de resolución con cache**: se cachea solo el mapeo host→id; el modelo del tenant se
  lee fresco en cada request. Desactivar/borrar una cuenta aplica DE INMEDIATO (antes, hasta 5 min
  con el TTL por defecto). Observers sobre tenant/dominios invalidan las claves al re-puntear o
  crear dominios (incluye el sentinel de 404 cacheado). `Tenancy::flushResolutionCache()` /
  `flushHost()` disponibles para casos manuales.
- **`Tenancy::query()` ya no hardcodea `is_active`**: la columna sale de `tenancy.active_column`
  (null = sin filtro SQL), y `each()` re-verifica SIEMPRE con el contrato `isTenantActive()` — la
  columna es la optimización, el contrato es la verdad.
- Suite: 42 tests (nueva `ResolutionFreshnessTest`).

## 0.4.0 — 2026-08-16

- **Fix de seguridad — prioridad de middleware**: el grupo `tenant` ahora se inserta en la lista de
  prioridad del kernel HTTP, DESPUÉS de `StartSession` y ANTES de `auth`
  (`AuthenticatesRequests`) y de `SubstituteBindings`. Sin esto, el route-model-binding y la carga
  del usuario autenticado consultaban la BD **antes** de que `ResolveTenant` fijara el contexto:
  con el singleton "sucio" del request anterior (tests, Octane) resolvían filas de OTRO tenant —
  IDOR silencioso. Detectado por el test IDOR del módulo Company de qpospev3.
- Efecto visible: una sesión estampada con otro tenant ahora se purga (y desloguea) **antes** del
  chequeo de `auth` — el request cruzado da `401` en vez de autenticarse y purgarse tarde.
- Suite: 43 tests (nueva `MiddlewarePriorityTest`, regresión del orden en el kernel).
