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
