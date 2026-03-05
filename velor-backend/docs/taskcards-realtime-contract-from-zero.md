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
- alias legacy temporal en backend: `/api/v1/tasks` (no usar para integraciones nuevas).

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
  "alarm_time_local": "08:30"
}
```

Response `201`: `TaskCard` completo.
Notas:
- Este endpoint de taskcards CRUD no acepta campos runtime (`state`, `active_mode`, `timer_*`, `stopwatch_*`, `total_tracked_seconds`).

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
  "message": "Task version conflict.",
  "code": "TASK_VERSION_CONFLICT",
  "data": {
    "current": {
      "id": "101",
      "version": 4,
      "updated_at": "2026-03-04T15:10:00Z"
    }
  }
}
```

Response sugerido para `422` por campo runtime prohibido:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "state": [
      "The state field is not allowed in this endpoint."
    ]
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
  "message": "Task version conflict.",
  "code": "TASK_VERSION_CONFLICT",
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
- `taskcard.created`
- `taskcard.updated`
- `taskcard.deleted`

Payload minimo:
```json
{
  "event_id": "evt_01HTZKX6A34N9Q7M5FQ8K3JY2R",
  "origin_device_id": "web-7f3b91",
  "occurred_at_utc": "2026-03-04T15:10:00Z",
  "event": "taskcard.updated",
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
