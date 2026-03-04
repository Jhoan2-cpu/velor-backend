# TaskCards CRUD Basico + Realtime (Frontend <-> Backend)

## Alcance

Este documento cubre solo:
- crear taskcard
- listar taskcards
- editar taskcard
- eliminar taskcard
- sincronizacion realtime de esos cambios

No cubre:
- start/pause/resume/stop de foco
- sesiones (`focus_time_entries`, `idle_time_entries`)
- log diario

---

## Convenciones base

- auth: Laravel Sanctum (cookie session)
- namespace canonico: `/api/v1/focus/tasks`
- IDs en API: `string` (aunque en DB sean `bigint`)
- timestamps: ISO 8601 UTC
- concurrencia optimista: `version`

---

## Modelo TaskCard (CRUD basico)

```json
{
  "id": "101",
  "user_id": "12",
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#1A73E8",
  "alarm_time_local": "09:00",
  "version": 3,
  "created_at": "2026-03-04T15:00:00Z",
  "updated_at": "2026-03-04T15:10:00Z"
}
```

Campos editables en CRUD basico:
- `name`
- `icon_tag`
- `color_tag`
- `alarm_time_local`

Campos no editables por CRUD basico (runtime):
- `state`
- `active_mode`
- `timer_*`
- `stopwatch_*`
- `total_tracked_seconds`

---

## 1) Listar taskcards

El frontend enviara al backend:  
`GET /api/v1/focus/tasks`

si todo esta correcto enviara (`200`):
```json
{
  "data": [
    {
      "id": "101",
      "user_id": "12",
      "name": "Deep Work: API",
      "icon_tag": "brain",
      "color_tag": "#1A73E8",
      "alarm_time_local": "09:00",
      "version": 3,
      "created_at": "2026-03-04T15:00:00Z",
      "updated_at": "2026-03-04T15:10:00Z"
    }
  ]
}
```

sino (`401`):
```json
{
  "message": "Unauthenticated."
}
```

---

## 2) Crear taskcard

El frontend enviara al backend:  
`POST /api/v1/focus/tasks`

body:
```json
{
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#1A73E8",
  "alarm_time_local": "09:00"
}
```

si todo esta correcto enviara (`201`):
```json
{
  "data": {
    "id": "102",
    "user_id": "12",
    "name": "Deep Work: API",
    "icon_tag": "brain",
    "color_tag": "#1A73E8",
    "alarm_time_local": "09:00",
    "version": 1,
    "created_at": "2026-03-04T15:20:00Z",
    "updated_at": "2026-03-04T15:20:00Z"
  }
}
```

sino (`422`):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

---

## 3) Editar taskcard

El frontend enviara al backend:  
`PATCH /api/v1/focus/tasks/{taskId}`

body:
```json
{
  "if_version": 3,
  "name": "Deep Work: API v2",
  "icon_tag": "code",
  "color_tag": "#0F9D58",
  "alarm_time_local": "10:00"
}
```

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "id": "101",
    "user_id": "12",
    "name": "Deep Work: API v2",
    "icon_tag": "code",
    "color_tag": "#0F9D58",
    "alarm_time_local": "10:00",
    "version": 4,
    "created_at": "2026-03-04T15:00:00Z",
    "updated_at": "2026-03-04T15:30:00Z"
  }
}
```

sino (`409` conflicto de version):
```json
{
  "message": "Version conflict.",
  "code": "VERSION_CONFLICT",
  "data": {
    "current": {
      "id": "101",
      "version": 4,
      "updated_at": "2026-03-04T15:30:00Z"
    }
  }
}
```

sino (`422` campo no permitido en CRUD basico):
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

---

## 4) Eliminar taskcard

El frontend enviara al backend:  
`DELETE /api/v1/focus/tasks/{taskId}`

header requerido:
```http
If-Match: 4
```

fallback opcional:
- query param `?if_version=4`

si todo esta correcto enviara (`204`):
```json
{}
```

sino (`409` conflicto de version):
```json
{
  "message": "Version conflict.",
  "code": "VERSION_CONFLICT",
  "data": {
    "current": {
      "id": "101",
      "version": 5,
      "updated_at": "2026-03-04T15:40:00Z"
    }
  }
}
```

sino (`404`):
```json
{
  "message": "Task not found."
}
```

---

## Realtime (CRUD)

Canal privado:
- `private-user.{userId}.focus.tasks`

Eventos:
- `focus.task.created`
- `focus.task.updated`
- `focus.task.deleted`

payload base:
```json
{
  "event_id": "evt_01HTZKX6A34N9Q7M5FQ8K3JY2R",
  "origin_device_id": "web-7f3b91",
  "occurred_at_utc": "2026-03-04T15:30:00Z",
  "event": "focus.task.updated",
  "task": {
    "id": "101",
    "user_id": "12",
    "name": "Deep Work: API v2",
    "icon_tag": "code",
    "color_tag": "#0F9D58",
    "alarm_time_local": "10:00",
    "version": 4,
    "updated_at": "2026-03-04T15:30:00Z"
  }
}
```

payload deleted:
```json
{
  "event_id": "evt_01HTZKX6A34N9Q7M5FQ8K3JY2S",
  "origin_device_id": "web-7f3b91",
  "occurred_at_utc": "2026-03-04T15:40:00Z",
  "event": "focus.task.deleted",
  "task": {
    "id": "101",
    "user_id": "12",
    "version": 5
  }
}
```

Reglas frontend:
- ignorar evento si `task.version` < version local
- si `task.version` == local, deduplicar por `event_id`
- ante desincronizacion, ejecutar `GET /api/v1/focus/tasks` para resync completo

---

## Validaciones backend (Laravel)

- `name`: `required|string|min:1|max:120`
- `icon_tag`: `nullable|string|max:255`
- `color_tag`: `nullable|string|max:255`
- `alarm_time_local`: `nullable|regex:/^([01]\d|2[0-3]):([0-5]\d)$/`
- `if_version` (update): `required|integer|min:1`
- `if_version` (delete): requerido via `If-Match` o query `if_version`

Reglas de seguridad:
- siempre filtrar por `auth()->id()`
- `404` si task no existe o no pertenece al usuario
- create/update/delete en transaccion DB
- update/delete con lock + check de version
- emitir eventos realtime solo despues de commit

