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
