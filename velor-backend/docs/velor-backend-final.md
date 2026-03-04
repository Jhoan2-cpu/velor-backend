# Velor Backend - Documento Final

Compilado desde migraciones y contratos funcionales vigentes.

## Fuente: docs/database-schema.md

# Esquema de Base de Datos - Velor Backend

Estado segun migraciones actuales:
- `0001_01_01_000000` a `0001_01_01_000002`
- `2025_01_01_000010` a `2025_01_01_000028`

**Motor:** PostgreSQL 16  
**ORM:** Laravel Eloquent  
**Dominio IDs:** `bigint` auto-increment (`$table->id()`)

---

## Diagrama de relaciones (dominio)

```mermaid
erDiagram
    USERS ||--|| USER_SETTINGS : has
    USERS ||--o{ USER_OAUTH_IDENTITIES : links
    USERS ||--o{ FOCUS_TASKS : owns
    USERS ||--o{ FOCUS_TIME_ENTRIES : writes
    USERS ||--o{ IDLE_TIME_ENTRIES : writes
    FOCUS_TASKS o|--o{ FOCUS_TIME_ENTRIES : source_task_nullable

    USERS {
        bigint id PK
        string display_name
        string email UK
        string password_hash
        string locale
        string remember_token
        timestamp email_verified_at
        timestamp created_at
        timestamp updated_at
    }
    USER_SETTINGS {
        bigint id PK
        bigint user_id FK_UK
        string locale
        string time_zone_name
        boolean ui_sounds_enabled
        boolean background_music_enabled
        smallint background_music_volume_percent
        boolean confirm_task_switch_enabled
        boolean sign_out_confirmation_enabled
        timestamp created_at
        timestamp updated_at
    }
    USER_OAUTH_IDENTITIES {
        bigint id PK
        bigint user_id FK
        string provider
        string provider_user_id
        string provider_email
        text avatar_url
        text access_token
        text refresh_token
        timestamp token_expires_at
        timestamp created_at
        timestamp updated_at
    }
    FOCUS_TASKS {
        bigint id PK
        bigint user_id FK
        string name
        string icon_tag
        string color_tag
        string alarm_time_local
        int timer_initial_seconds
        int timer_remaining_seconds
        timestamp timer_started_at_utc
        timestamp timer_ended_at_utc
        int stopwatch_elapsed_seconds
        timestamp stopwatch_started_at_utc
        timestamp stopwatch_ended_at_utc
        int total_tracked_seconds
        string active_mode
        string state
        int version
        timestamp created_at
        timestamp updated_at
    }
    FOCUS_TIME_ENTRIES {
        bigint id PK
        bigint user_id FK
        bigint focus_task_id_nullable FK
        string task_title_snapshot
        string task_icon_snapshot
        string task_color_snapshot
        int timer_target_snapshot_seconds
        string mode_snapshot
        timestamp started_at_utc
        timestamp ended_at_utc
        int elapsed_seconds
        string stop_reason
        timestamp created_at
        timestamp updated_at
    }
    IDLE_TIME_ENTRIES {
        bigint id PK
        bigint user_id FK
        timestamp started_at_utc
        timestamp ended_at_utc
        int elapsed_seconds
        string reason
        timestamp created_at
        timestamp updated_at
    }
```

---

## Tablas de dominio

### `users`

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `display_name` | varchar(255) | no | - | |
| `email` | varchar(255) | no | - | UNIQUE |
| `password_hash` | varchar(255) | si | null | null para usuarios OAuth |
| `locale` | varchar(10) | no | `'es'` | CHECK: `es` \| `en`; copia denormalizada de `user_settings.locale` |
| `email_verified_at` | timestamp | si | null | |
| `remember_token` | varchar(100) | si | null | token "remember me" de Laravel |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**Constraints**
- `users_email_unique` (UNIQUE `email`)
- `chk_users_locale` (`locale IN ('es','en')`)

---

### `user_settings`

Relacion **1:1** con `users` (`user_id` es UNIQUE).

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `user_id` | bigint | no | - | FK -> `users.id`, cascadeOnDelete, UNIQUE |
| `locale` | varchar(10) | no | `'es'` | CHECK: `es` \| `en`; **fuente de verdad** |
| `time_zone_name` | varchar(255) | no | `'America/Lima'` | timezone IANA |
| `ui_sounds_enabled` | boolean | no | `true` | |
| `background_music_enabled` | boolean | no | `false` | |
| `background_music_volume_percent` | smallint | no | `50` | CHECK: 0..100 |
| `confirm_task_switch_enabled` | boolean | no | `true` | |
| `sign_out_confirmation_enabled` | boolean | no | `true` | |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**Constraints**
- `user_settings_user_id_unique` (UNIQUE `user_id`)
- `chk_user_settings_locale` (`locale IN ('es','en')`)
- `chk_user_settings_music_volume_range` (`background_music_volume_percent BETWEEN 0 AND 100`)

---

### `user_oauth_identities`

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `user_id` | bigint | no | - | FK -> `users.id`, cascadeOnDelete |
| `provider` | varchar(50) | no | - | ej. `google` |
| `provider_user_id` | varchar(255) | no | - | id externo del proveedor |
| `provider_email` | varchar(255) | si | null | |
| `avatar_url` | text | si | null | |
| `access_token` | text | si | null | cifrado en reposo (cast `encrypted`) |
| `refresh_token` | text | si | null | cifrado en reposo (cast `encrypted`) |
| `token_expires_at` | timestamp | si | null | |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**Constraints**
- UNIQUE (`provider`, `provider_user_id`)

---

### `focus_tasks`

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `user_id` | bigint | no | - | FK -> `users.id`, cascadeOnDelete |
| `name` | varchar(255) | no | - | |
| `icon_tag` | varchar(255) | si | null | |
| `color_tag` | varchar(255) | si | null | |
| `alarm_time_local` | varchar(255) | si | null | formato `HH:MM` 24h (CHECK) |
| `timer_initial_seconds` | integer | si | null | CHECK: >= 0 |
| `timer_remaining_seconds` | integer | si | null | CHECK: >= 0 |
| `timer_started_at_utc` | timestamp | si | null | |
| `timer_ended_at_utc` | timestamp | si | null | |
| `stopwatch_elapsed_seconds` | integer | no | `0` | CHECK: >= 0 |
| `stopwatch_started_at_utc` | timestamp | si | null | |
| `stopwatch_ended_at_utc` | timestamp | si | null | |
| `total_tracked_seconds` | integer | no | `0` | CHECK: >= 0 |
| `active_mode` | varchar(30) | no | `'stopwatch'` | CHECK: `timer` \| `stopwatch` |
| `state` | varchar(30) | no | `'idle'` | CHECK: `idle` \| `working` \| `paused` \| `stopped` |
| `version` | integer | no | `1` | optimistic locking, CHECK: >= 1 |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**Constraints**
- `chk_focus_tasks_active_mode`
- `chk_focus_tasks_state`
- `chk_focus_tasks_timer_initial_seconds_non_negative`
- `chk_focus_tasks_timer_remaining_seconds_non_negative`
- `chk_focus_tasks_stopwatch_elapsed_seconds_non_negative`
- `chk_focus_tasks_total_tracked_seconds_non_negative`
- `chk_focus_tasks_version_min_1`
- `chk_focus_tasks_alarm_time_local_hhmm`

**Indices**
- `(user_id, state)` (asc)
- `(user_id, created_at)` (asc)
- `idx_focus_tasks_user_updated`: `(user_id, updated_at DESC)`

---

### `focus_time_entries`

Registra sesiones de trabajo activo.

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `user_id` | bigint | no | - | FK -> `users.id`, cascadeOnDelete |
| `focus_task_id_nullable` | bigint | si | null | FK -> `focus_tasks.id`, nullOnDelete |
| `task_title_snapshot` | varchar(255) | si | null | |
| `task_icon_snapshot` | varchar(255) | si | null | |
| `task_color_snapshot` | varchar(255) | si | null | |
| `timer_target_snapshot_seconds` | integer | si | null | null si `mode_snapshot = stopwatch`, CHECK: >= 0 |
| `mode_snapshot` | varchar(30) | no | - | CHECK: `timer` \| `stopwatch` |
| `started_at_utc` | timestamp | no | - | |
| `ended_at_utc` | timestamp | si | null | null si sesion activa |
| `elapsed_seconds` | integer | si | null | nullable mientras la sesion esta activa |
| `stop_reason` | varchar(50) | si | null | CHECK: valores permitidos |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**`stop_reason` permitidos**
- `manual`
- `timer_completed`
- `task_switch`
- `session_end`
- `idle_detected`

**Constraints**
- `chk_focus_time_entries_mode_snapshot`
- `chk_focus_time_entries_stop_reason`
- `chk_fte_timer_target_snapshot_seconds_non_negative`
- `chk_fte_elapsed_nullness_matches_end`
- `chk_fte_elapsed_non_negative_when_closed`
- `chk_fte_end_after_start`
- `idx_fte_one_active_per_user` (UNIQUE parcial: `user_id` where `ended_at_utc IS NULL`)

**Indices**
- `(user_id, started_at_utc)` (asc, indice original de create)
- `(focus_task_id_nullable)` (asc)
- `idx_focus_time_entries_user_started`: `(user_id, started_at_utc DESC)`
- `idx_focus_time_entries_user_ended_closed`: `(user_id, ended_at_utc DESC) WHERE ended_at_utc IS NOT NULL`

---

### `idle_time_entries`

Registra periodos de inactividad/descanso.

| Columna | Tipo | Nullable | Default | Notas |
|---|---|---|---|---|
| `id` | bigint | no | auto | PK |
| `user_id` | bigint | no | - | FK -> `users.id`, cascadeOnDelete |
| `started_at_utc` | timestamp | no | - | |
| `ended_at_utc` | timestamp | si | null | null si idle activo |
| `elapsed_seconds` | integer | si | null | nullable mientras idle esta activo |
| `reason` | varchar(100) | si | null | CHECK: valores permitidos |
| `created_at` | timestamp | si | null | |
| `updated_at` | timestamp | si | null | |

**`reason` permitidos**
- `user_idle`
- `break`
- `task_switch`
- `session_end`

**Constraints**
- `chk_idle_time_entries_reason`
- `chk_ite_elapsed_nullness_matches_end`
- `chk_ite_elapsed_non_negative_when_closed`
- `chk_ite_end_after_start`
- `idx_ite_one_active_per_user` (UNIQUE parcial: `user_id` where `ended_at_utc IS NULL`)

**Indices**
- `(user_id, started_at_utc)` (asc, indice original de create)
- `idx_idle_time_entries_user_started`: `(user_id, started_at_utc DESC)`
- `idx_idle_time_entries_user_ended_closed`: `(user_id, ended_at_utc DESC) WHERE ended_at_utc IS NOT NULL`

**Triggers de integridad cruzada (FTE <-> ITE)**
- `trg_fte_prevent_cross_active` -> `enforce_no_cross_active_fte()`
- `trg_ite_prevent_cross_active` -> `enforce_no_cross_active_ite()`

**Nota de concurrencia**
- Los triggers/indices son red de seguridad.
- En capa de servicio (start/pause/resume/stop) mantener `DB::transaction` + lock por usuario para evitar carreras.

---

## Tablas de sistema (Laravel)

| Tabla | Proposito |
|---|---|
| `migrations` | control de historial de migraciones |
| `password_reset_tokens` | recuperacion de password |
| `sessions` | almacenamiento de sesiones (si `SESSION_DRIVER=database`) |
| `cache` | cache table store |
| `cache_locks` | locks de cache |
| `jobs` | cola de trabajos |
| `job_batches` | lotes de jobs |
| `failed_jobs` | jobs fallidos |

Notas de configuracion actual (`.env`):
- `QUEUE_CONNECTION=database`
- `CACHE_STORE=file` (la tabla `cache` existe pero puede estar inactiva segun driver)
- `SESSION_DRIVER=cookie` (la tabla `sessions` existe por migracion base, pero no es usada por defecto con cookie)

---

## Convenciones observadas

- En dominio, los timestamps de eventos usan sufijo `_utc`.
- No hay soft deletes en tablas de dominio.
- `focus_tasks.version` default DB = `1`.
- En `focus_time_entries` se guardan snapshots de tarea para trazabilidad historica.
- En API, los IDs `bigint` se serializan como `string` para evitar perdida de precision en JS.

---

## Observaciones criticas (revision)

### 1) `users.password_hash` y autenticacion Laravel

**Estado:** cubierto en codigo actual.  
El modelo `User` sobrescribe `getAuthPassword()` para que Auth/Sanctum use `password_hash` en lugar de `password`.

Referencia de implementacion:
- `app/Models/User.php` -> `getAuthPassword(): string`

Riesgo residual:
- Si se elimina ese override en el futuro, el login por credenciales puede fallar.

### 2) `unsigned` en PostgreSQL

**Estado:** resuelto en DB (migracion `2025_01_01_000026_enforce_runtime_invariants`).

- PostgreSQL no tiene tipos `unsigned` nativos.
- Se mantienen tipos `integer/smallint` y ahora estan reforzados con `CHECK` de no-negatividad/minimos en las columnas de dominio relevantes.

### 3) Invariante global de sesion activa (FTE vs ITE)

**Estado:** resuelto en DB (migracion `2025_01_01_000026_enforce_runtime_invariants`).

Actualmente existen:
- UNIQUE parcial en `focus_time_entries` (`idx_fte_one_active_per_user`)
- UNIQUE parcial en `idle_time_entries` (`idx_ite_one_active_per_user`)
- Triggers cruzados:
  - `trg_fte_prevent_cross_active` / `enforce_no_cross_active_fte()`
  - `trg_ite_prevent_cross_active` / `enforce_no_cross_active_ite()`

Con esto se bloquea que exista simultaneamente una fila activa en cada tabla para el mismo usuario.

### 4) Consistencia temporal (`started_at_utc`, `ended_at_utc`, `elapsed_seconds`)

**Estado:** resuelto en DB (migracion `2025_01_01_000026_enforce_runtime_invariants`).

Checks aplicados en `focus_time_entries` e `idle_time_entries`:
- `ended_at_utc IS NULL` => `elapsed_seconds IS NULL`
- `ended_at_utc IS NOT NULL` => `elapsed_seconds >= 0`
- `ended_at_utc >= started_at_utc`

### 5) Tokens OAuth en reposo

**Estado:** resuelto en codigo y datos (migracion `2025_01_01_000027_encrypt_oauth_tokens_at_rest`).

- `UserOauthIdentity` usa cast `encrypted` en `access_token` y `refresh_token`.
- La migracion cifra tokens historicos para evitar lectura en texto plano.

### 6) Formato de `alarm_time_local`

**Estado:** resuelto en DB (migracion `2025_01_01_000028_harden_focus_alarm_and_growth_indexes`).

- Constraint `chk_focus_tasks_alarm_time_local_hhmm`:
  - permite `NULL`
  - permite solo formato `HH:MM` 24h.

### 7) Serializacion de IDs `bigint` en API

**Estado:** parcialmente verificado en codigo actual.

- Implementado y verificado en Auth (`UserResource`): `id` se devuelve como `string`.
- Para endpoints de focus/taskcards/realtime (incluyendo errores `409` y eventos) el contrato ya exige IDs string, pero su verificacion final depende de implementar esos endpoints en backend.

### 8) `locale` duplicado (`users` vs `user_settings`)

**Estado:** definido a nivel de contrato.

- `user_settings.locale` es la fuente de verdad.
- `users.locale` queda como copia denormalizada para lecturas rapidas.
- Las escrituras deben mantener ambos campos sincronizados dentro de la misma transaccion.

---

## Fuente: docs/taskcards-realtime-contract-from-zero.md

# Taskcards Realtime Contract (Desde Cero) - Frontend <-> Backend

## Scope

Contrato funcional para la capa de taskcards y su sincronizacion realtime.
Este documento esta alineado con el esquema de DB vigente (`focus_tasks`).

---

## Identificadores y tipos base

- `id` de task: `bigint` en DB, **string en JSON**
- `user_id`: `bigint` en DB, **string en JSON**
- timestamps: ISO 8601 UTC (`2026-03-04T15:20:30Z`)

No usar UUID en este modulo.  
Decision de contrato: IDs serializados como string para evitar perdida de precision en clientes JS.

---

## Modelo TaskCard (source of truth)

```json
{
  "id": "101",
  "user_id": "12",
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#FF5733",
  "alarm_time_local": "08:30",
  "timer_initial_seconds": 1500,
  "timer_remaining_seconds": 1200,
  "timer_started_at_utc": "2026-03-04T15:00:00Z",
  "timer_ended_at_utc": null,
  "stopwatch_elapsed_seconds": 0,
  "stopwatch_started_at_utc": null,
  "stopwatch_ended_at_utc": null,
  "total_tracked_seconds": 5400,
  "active_mode": "timer",
  "state": "working",
  "version": 3,
  "created_at": "2026-03-03T10:00:00Z",
  "updated_at": "2026-03-04T15:05:00Z"
}
```

### Enums vigentes

- `active_mode`: `timer` | `stopwatch`
- `state`: `idle` | `working` | `paused` | `stopped`

---

## Endpoints HTTP

Namespace canonico:
- usar solo `/api/v1/focus/tasks` en frontend/backend/documentacion.

### Listar taskcards

`GET /api/v1/focus/tasks`

Response `200`:
```json
{
  "data": [
    {
      "id": "101",
      "user_id": "12",
      "name": "Deep Work: API",
      "state": "idle",
      "active_mode": "stopwatch",
      "version": 1,
      "updated_at": "2026-03-04T12:00:00Z"
    }
  ]
}
```

### Crear taskcard

`POST /api/v1/focus/tasks`

Request:
```json
{
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#FF5733",
  "alarm_time_local": "08:30",
  "active_mode": "stopwatch"
}
```

Response `201`: `TaskCard` completo.

### Actualizar taskcard

`PATCH /api/v1/focus/tasks/{taskId}`

- `taskId`: `bigint` (path param), serializado como string en payloads
- usar `if_version` para optimistic locking
- Este endpoint es solo para metadatos de tarjeta (no runtime de sesion).

Request:
```json
{
  "if_version": 3,
  "name": "Deep Work: API v2",
  "icon_tag": "brain",
  "color_tag": "#1A73E8",
  "alarm_time_local": "09:00"
}
```

Campos prohibidos en `PATCH` (runtime):
- `state`
- `active_mode`
- `timer_started_at_utc`, `timer_ended_at_utc`
- `stopwatch_started_at_utc`, `stopwatch_ended_at_utc`
- `timer_remaining_seconds`, `stopwatch_elapsed_seconds`, `total_tracked_seconds`

Cambios de runtime permitidos solo por endpoints de foco (`start/pause/resume/stop`).

Responses:
- `200` actualizado
- `409` conflicto de version
- `422` validacion (enum invalido, tipo invalido, campo runtime no permitido, etc.)

Response sugerido para `409`:
```json
{
  "message": "Version conflict.",
  "code": "VERSION_CONFLICT",
  "data": {
    "current": {
      "id": "101",
      "version": 4,
      "updated_at": "2026-03-04T15:10:00Z"
    }
  }
}
```

### Eliminar taskcard

`DELETE /api/v1/focus/tasks/{taskId}`

- `taskId`: `bigint` (path param), serializado como string en payloads
- requiere `if_version` para optimistic locking via header `If-Match`

Header requerido:
```http
If-Match: 4
```

Fallback opcional si el cliente no soporta `If-Match`:
- query param `?if_version=4`

Responses:
- `204` eliminado
- `409` conflicto de version
- `404` no encontrado

Response sugerido para `409`:
```json
{
  "message": "Version conflict.",
  "code": "VERSION_CONFLICT",
  "data": {
    "current": {
      "id": "101",
      "version": 5,
      "updated_at": "2026-03-04T15:20:00Z"
    }
  }
}
```

---

## Realtime (broadcast)

Canal sugerido por usuario:
- `private-user.{userId}.focus.tasks`

Eventos sugeridos:
- `focus.task.created`
- `focus.task.updated`
- `focus.task.deleted`

Payload minimo:
```json
{
  "event_id": "evt_01HTZKX6A34N9Q7M5FQ8K3JY2R",
  "origin_device_id": "web-7f3b91",
  "occurred_at_utc": "2026-03-04T15:10:00Z",
  "event": "focus.task.updated",
  "task": {
    "id": "101",
    "user_id": "12",
    "state": "working",
    "active_mode": "timer",
    "version": 4,
    "updated_at": "2026-03-04T15:10:00Z"
  }
}
```

---

## Reglas de compatibilidad frontend

- Tratar IDs como `string` y no como `number`.
- No enviar estados fuera de: `idle|working|paused|stopped`.
- No enviar `active_mode` fuera de: `timer|stopwatch`.
- Si llega `409` por `if_version`, refrescar task y reintentar.
- Realtime idempotente:
  - ignorar evento si `task.version` es menor al local.
  - si `task.version` es igual, usar `event_id` para deduplicar.
  - aplicar evento solo una vez por `event_id`.

---

## Fuente: docs/focus-sessions-daily-log-contract-from-zero.md

# Focus Sessions + Daily Log Contract (Desde Cero) - Frontend <-> Backend

## Scope

Contrato funcional para sesiones de foco (`focus_time_entries`) e inactividad (`idle_time_entries`),
alineado al esquema de DB vigente.

---

## Identificadores y tipos base

- `focus_time_entries.id`: `bigint` en DB, **string en JSON**
- `idle_time_entries.id`: `bigint` en DB, **string en JSON**
- `user_id`: `bigint` en DB, **string en JSON**
- timestamps: ISO 8601 UTC

No usar UUID en este modulo.  
Decision de contrato: IDs serializados como string para evitar perdida de precision en clientes JS.

---

## Enums vigentes (source of truth)

### `focus_time_entries.mode_snapshot`
- `timer`
- `stopwatch`

### `focus_time_entries.stop_reason`
- `manual`
- `timer_completed`
- `task_switch`
- `session_end`
- `idle_detected`

### `idle_time_entries.reason`
- `user_idle`
- `break`
- `task_switch`
- `session_end`

---

## Invariantes de integridad (backend)

- Maximo 1 sesion activa en `focus_time_entries` por usuario (`ended_at_utc IS NULL`).
- Maximo 1 sesion activa en `idle_time_entries` por usuario (`ended_at_utc IS NULL`).
- No puede existir simultaneamente una sesion activa en ambas tablas para el mismo usuario.
- Reglas temporales:
  - `ended_at_utc IS NULL` => `elapsed_seconds IS NULL`
  - `ended_at_utc IS NOT NULL` => `elapsed_seconds >= 0`
  - `ended_at_utc >= started_at_utc`
- Todas las transiciones `start/resume/pause/stop` deben ejecutarse en transaccion y lock por usuario.
- `started_at_utc` y `ended_at_utc` son **server-authoritative** (`now()` backend), no input del cliente.

---

## DTOs

### FocusTimeEntry

```json
{
  "id": "9001",
  "user_id": "12",
  "focus_task_id_nullable": "101",
  "task_title_snapshot": "Deep Work: API",
  "task_icon_snapshot": "brain",
  "task_color_snapshot": "#FF5733",
  "timer_target_snapshot_seconds": 1500,
  "mode_snapshot": "timer",
  "started_at_utc": "2026-03-04T15:00:00Z",
  "ended_at_utc": null,
  "elapsed_seconds": null,
  "stop_reason": null,
  "created_at": "2026-03-04T15:00:00Z",
  "updated_at": "2026-03-04T15:00:00Z"
}
```

### IdleTimeEntry

```json
{
  "id": "7001",
  "user_id": "12",
  "started_at_utc": "2026-03-04T16:00:00Z",
  "ended_at_utc": null,
  "elapsed_seconds": null,
  "reason": "break",
  "created_at": "2026-03-04T16:00:00Z",
  "updated_at": "2026-03-04T16:00:00Z"
}
```

---

## Endpoints HTTP

### Iniciar sesion de foco

`POST /api/v1/focus/sessions/start`

Request:
```json
{
  "focus_task_id_nullable": "101",
  "mode_snapshot": "timer",
  "timer_target_snapshot_seconds": 1500
}
```

Responses:
- `201` con `FocusTimeEntry` activo (`ended_at_utc = null`, `elapsed_seconds = null`)
- `409` si ya existe sesion activa (focus o idle)
- `422` validacion

Response sugerido para `409`:
```json
{
  "message": "Active session conflict.",
  "code": "ACTIVE_SESSION_CONFLICT"
}
```

### Cerrar sesion de foco

`POST /api/v1/focus/sessions/{entryId}/stop`

- `entryId`: `bigint` (path param), serializado como string en payloads

Request:
```json
{
  "stop_reason": "manual"
}
```

Responses:
- `200` con `FocusTimeEntry` cerrado
- `409` si el entry no esta activo
- `422` si `stop_reason` o temporalidad es invalida

### Iniciar idle

`POST /api/v1/focus/idle/start`

Request:
```json
{
  "reason": "break"
}
```

Responses:
- `201` con `IdleTimeEntry` activo
- `409` si ya existe sesion activa (focus o idle)
- `422` validacion

### Cerrar idle

`POST /api/v1/focus/idle/{entryId}/stop`

- `entryId`: `bigint` (path param), serializado como string en payloads

Request:
```json
{}
```

Responses:
- `200` con `IdleTimeEntry` cerrado
- `409` si el entry no esta activo
- `422` si temporalidad invalida

### Daily log

`GET /api/v1/focus/daily-log?date=2026-03-04&time_zone_name=America/Lima`

Regla de calculo:
- La seleccion diaria se calcula por dia local del usuario (`time_zone_name`) y se proyecta a rangos UTC.
- Debe respetar cambios DST del timezone (inicio/fin de horario de verano).

Response `200`:
```json
{
  "data": {
    "date": "2026-03-04",
    "time_zone_name": "America/Lima",
    "focus_time_entries": [
      {
        "id": "9001",
        "mode_snapshot": "timer",
        "started_at_utc": "2026-03-04T15:00:00Z",
        "ended_at_utc": "2026-03-04T15:25:00Z",
        "elapsed_seconds": 1500,
        "stop_reason": "timer_completed"
      }
    ],
    "idle_time_entries": [
      {
        "id": "7001",
        "started_at_utc": "2026-03-04T16:00:00Z",
        "ended_at_utc": "2026-03-04T16:10:00Z",
        "elapsed_seconds": 600,
        "reason": "break"
      }
    ]
  }
}
```

---

## Errores de validacion frecuentes (422)

- `stop_reason` fuera de enum permitido.
- `reason` de idle fuera de enum permitido.
- `ended_at_utc` menor a `started_at_utc`.
- `elapsed_seconds` no compatible con `ended_at_utc`.

---

## Compatibilidad frontend

- Reemplazar valores antiguos (`user_stop`, `after_pause`, `after_stop`, etc.).
- Usar `timer_completed` como termino canonico para timer completado.
- Usar solo enums vigentes definidos en este documento.
- Tratar IDs como `string` (no `number` ni UUID string).

---

## Fuente: docs/auth-contract-from-zero.md

# Auth Contract (Desde Cero) - Frontend <-> Laravel

## Scope

Backend objetivo:
- Laravel + Sanctum (cookie session)
- Google OAuth (redirect/callback)
- Reverb se usa despues de auth, no en este modulo

Convencion de IDs en API:
- DB usa `bigint`
- JSON expone IDs como `string` (ejemplo: `"12"`) para evitar perdida de precision en JS

Frontend objetivo:
- Registrar
- Iniciar sesion
- Cerrar sesion
- Obtener sesion actual
- Login/Register con Google

---

## Regla de estandarizacion (idioma y zona horaria)

### Idioma (`locale`)
- Fuente: navegador (`navigator.language` / `navigator.languages`)
- Estandar del navegador: BCP 47
- Normalizacion frontend obligatoria:
  - si empieza con `es` -> enviar `es`
  - si empieza con `en` -> enviar `en`
  - cualquier otro -> fallback `es`
- Backend solo acepta: `es`, `en`

Fuente de verdad de `locale`:
- `user_settings.locale` es canonical/source of truth.
- `users.locale` se mantiene como copia denormalizada para lecturas rapidas y compatibilidad.
- Toda escritura de locale debe actualizar ambos campos en una sola transaccion.

### Zona horaria (`time_zone_name`)
- Fuente: navegador (`Intl.DateTimeFormat().resolvedOptions().timeZone`)
- Debe enviarse en formato IANA (ejemplo: `America/Lima`)
- Backend valida con regla Laravel `timezone`

---

## Contrato final de register (source of truth)

El frontend enviara al backend:  
`POST /api/v1/auth/register`

body:
```json
{
  "display_name": "Anton Rivera",
  "email": "anton@velor.app",
  "password": "secret12345",
  "password_confirmation": "secret12345",
  "locale": "es",
  "time_zone_name": "America/Lima"
}
```

validaciones backend (Laravel):
- `display_name`: `required|string|min:2|max:120`
- `email`: `required|email|max:255|unique:users,email`
- `password`: `required|string|min:8|confirmed`
- `locale`: `required|in:es,en`
- `time_zone_name`: `required|timezone`

efectos de negocio obligatorios:
- crear usuario
- crear `user_settings` iniciales con `locale` y `time_zone_name`
- iniciar sesion (cookie auth activa)
- todo en transaccion DB

respuestas esperadas:

si todo esta correcto enviara (`201`):
```json
{
  "data": {
    "user": {
      "id": "12",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    },
    "settings": {
      "locale": "es",
      "time_zone_name": "America/Lima"
    }
  }
}
```

sino (`422` validacion):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "El correo ya esta en uso."
    ],
    "time_zone_name": [
      "La zona horaria no es valida."
    ]
  }
}
```

sino (`500` error interno):
```json
{
  "message": "Register failed."
}
```

---

## Login

El frontend enviara al backend:  
`POST /api/v1/auth/login`

body:
```json
{
  "email": "anton@velor.app",
  "password": "secret12345"
}
```

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "user": {
      "id": "12",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    }
  }
}
```

sino (`401` credenciales invalidas):
```json
{
  "message": "Credenciales invalidas."
}
```

---

## Me (sesion actual)

El frontend enviara al backend:  
`GET /api/v1/auth/me`

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "id": "12",
    "display_name": "Anton Rivera",
    "email": "anton@velor.app",
    "locale": "es"
  }
}
```

sino (`401`):
```json
{
  "message": "Unauthenticated."
}
```

---

## Logout

El frontend enviara al backend:  
`POST /api/v1/auth/logout`

si todo esta correcto enviara (`200`):
```json
{
  "message": "Logged out."
}
```

sino (`401`):
```json
{
  "message": "Unauthenticated."
}
```

---

## Google Auth

### Redirect

El frontend enviara al backend:  
`GET /api/v1/auth/google/redirect?intent=login`  
o  
`GET /api/v1/auth/google/redirect?intent=register`

resultado esperado:
- `302` hacia Google

### Callback

Google enviara al backend:  
`GET /api/v1/auth/google/callback`

resultado esperado:
- success: `302` -> `FRONTEND_URL/app`
- error: `302` -> `FRONTEND_URL/login?auth_error=google`

---

## Configuracion adicional obligatoria

### Frontend

- usar `credentials: 'include'` en requests
- antes de `POST /register`, `POST /login`, `POST /logout` llamar:
  - `GET /sanctum/csrf-cookie`
- en registro:
  - `locale` desde navegador normalizado a `es|en`
  - `time_zone_name` autodetectada y editable por usuario

### Backend Laravel

- modelo simple por usuario personal (sin multi-workspace)

`.env` minimo:
```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

SESSION_DRIVER=cookie
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost:5173

GOOGLE_CLIENT_ID=xxxx
GOOGLE_CLIENT_SECRET=xxxx
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/auth/google/callback
```

`config/cors.php`:
- permitir `http://localhost:5173`
- `supports_credentials = true`

---

