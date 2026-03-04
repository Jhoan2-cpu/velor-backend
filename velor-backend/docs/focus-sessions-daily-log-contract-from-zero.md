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
