# TaskCards Realtime Contract (Desde Cero) - Frontend <-> Laravel + Reverb

## Scope

Backend objetivo:
- CRUD de TaskCards por usuario autenticado
- Sincronizacion realtime entre sesiones/dispositivos del mismo usuario
- Control de concurrencia para evitar sobreescrituras (version)

Frontend objetivo:
- Crear, editar y eliminar TaskCards
- Reflejar cambios inmediatamente en todas las sesiones abiertas
- Resolver conflictos de version sin perder datos

---

## Tabla objetivo (source of truth)

Tabla recomendada: `FOCUS_TASKS`  
(si tu migracion actual usa `TASKS`, el contrato aplica igual)

Columnas clave para realtime:
- `id` (uuid)
- `user_id` (fk)
- `name`
- `icon_tag`
- `color_tag`
- `alarm_time_local`
- `timer_initial_seconds`
- `timer_remaining_seconds`
- `stopwatch_elapsed_seconds`
- `total_tracked_seconds`
- `active_mode` (`timer|stopwatch`)
- `state` (`working|paused|stopped`)
- `version` (int, inicia en 1)
- `updated_at`

Regla:
- cualquier cambio en la tarjeta debe incrementar `version` en `+1`

---

## Canal realtime (Reverb)

Canal privado:
- wire-level: `private-user.{userId}.taskcards`
- frontend Echo: `echo.private('user.{userId}.taskcards')`

Autorizacion de canal:
- `auth()->id() === {userId}`

Eventos:
- `.taskcard.created`
- `.taskcard.updated`
- `.taskcard.deleted`
- `.taskcard.state.changed`
- `.taskcard.conflict` (opcional)

---

## 0) Bootstrap de TaskCards al entrar a /app

El frontend enviara al backend:  
`GET /api/v1/tasks`

si todo esta correcto enviara (`200`):
```json
{
  "data": [
    {
      "id": "task_123",
      "name": "Q3 Report Writing",
      "icon_tag": "briefcase",
      "color_tag": "blue",
      "alarm_time_local": "21:30",
      "timer_initial_seconds": 2700,
      "timer_remaining_seconds": 2700,
      "stopwatch_elapsed_seconds": 0,
      "total_tracked_seconds": 0,
      "active_mode": "timer",
      "state": "stopped",
      "version": 1,
      "updated_at": "2026-03-04T15:10:00.000Z"
    }
  ],
  "meta": {
    "server_now_utc": "2026-03-04T15:10:01.000Z"
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

## 1) Crear TaskCard

El frontend enviara al backend:  
`POST /api/v1/tasks`

headers recomendados:
- `X-Device-Id: web-7b9f...` (requerido para dedupe en UI realtime)
- `X-Idempotency-Key: 6f3d...` (opcional, recomendado)

body:
```json
{
  "name": "Study English",
  "icon_tag": "learning",
  "color_tag": "green",
  "alarm_time_local": "20:30",
  "timer_initial_seconds": 1500
}
```

si todo esta correcto enviara (`201`):
```json
{
  "data": {
    "id": "task_456",
    "name": "Study English",
    "icon_tag": "learning",
    "color_tag": "green",
    "alarm_time_local": "20:30",
    "timer_initial_seconds": 1500,
    "timer_remaining_seconds": 1500,
    "stopwatch_elapsed_seconds": 0,
    "total_tracked_seconds": 0,
    "active_mode": "timer",
    "state": "stopped",
    "version": 1,
    "updated_at": "2026-03-04T15:20:00.000Z"
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

## 2) Editar TaskCard (con control de version)

El frontend enviara al backend:  
`PATCH /api/v1/tasks/{taskId}`

headers recomendados:
- `X-Device-Id: web-7b9f...`

body:
```json
{
  "name": "Study English Advanced",
  "color_tag": "violet",
  "alarm_time_local": "21:00",
  "timer_initial_seconds": 1800,
  "version": 4
}
```

Regla:
- `version` enviada por frontend debe coincidir con la version actual en DB
- si coincide: backend actualiza, incrementa version a `5`
- si no coincide: backend rechaza con `409`

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "id": "task_456",
    "name": "Study English Advanced",
    "color_tag": "violet",
    "alarm_time_local": "21:00",
    "timer_initial_seconds": 1800,
    "timer_remaining_seconds": 1800,
    "version": 5,
    "updated_at": "2026-03-04T15:23:10.000Z"
  }
}
```

sino (`409`):
```json
{
  "message": "Task version conflict.",
  "code": "TASK_VERSION_CONFLICT",
  "data": {
    "server_task": {
      "id": "task_456",
      "name": "Study English",
      "version": 5,
      "updated_at": "2026-03-04T15:23:10.000Z"
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

## 3) Eliminar TaskCard (con control de version)

El frontend enviara al backend:  
`DELETE /api/v1/tasks/{taskId}`

body:
```json
{
  "version": 5
}
```

si todo esta correcto enviara (`204`):
```json
{}
```

sino (`409`):
```json
{
  "message": "Task version conflict.",
  "code": "TASK_VERSION_CONFLICT"
}
```

sino (`404`):
```json
{
  "message": "Task not found."
}
```

---

## 4) Cambios de estado de TaskCard por modulo Focus

No se recomienda enviar ticks por segundo al backend.  
El modulo Focus (`/api/v1/focus/*`) actualiza TaskCard solo en transiciones:

- `start timer`
- `start stopwatch`
- `pause`
- `resume`
- `stop`
- `timer_finished`

Cada transicion debe:
1. actualizar columnas runtime de `FOCUS_TASKS`
2. incrementar `version`
3. emitir `.taskcard.state.changed`

Ejemplo de payload realtime:
```json
{
  "type": "taskcard.state.changed",
  "meta": {
    "user_id": "usr_123",
    "event_id": "evt_aa91",
    "emitted_at_utc": "2026-03-04T15:40:10.100Z",
    "origin_device_id": "web-7b9f..."
  },
  "data": {
    "task": {
      "id": "task_456",
      "state": "working",
      "active_mode": "timer",
      "timer_remaining_seconds": 1450,
      "stopwatch_elapsed_seconds": 0,
      "total_tracked_seconds": 3500,
      "version": 9,
      "updated_at": "2026-03-04T15:40:10.090Z"
    }
  }
}
```

---

## Payload base recomendado para todos los eventos realtime

```json
{
  "type": "taskcard.updated",
  "meta": {
    "user_id": "usr_123",
    "event_id": "evt_8f1d",
    "emitted_at_utc": "2026-03-04T15:30:00.000Z",
    "origin_device_id": "web-7b9f..."
  },
  "data": {
    "task": {
      "id": "task_456",
      "version": 6
    }
  }
}
```

Para deleted:
```json
{
  "type": "taskcard.deleted",
  "meta": {
    "user_id": "usr_123",
    "event_id": "evt_8f2a",
    "emitted_at_utc": "2026-03-04T15:33:00.000Z",
    "origin_device_id": "web-7b9f..."
  },
  "data": {
    "task_id": "task_456",
    "deleted_version": 7
  }
}
```

---

## Reglas frontend para aplicar eventos

1. Si llega evento con `version` menor o igual a la local, ignorar.
2. Si llega evento de otra pestaña/dispositivo (`origin_device_id` distinto), aplicar.
3. Si detectas hueco de version o inconsistencia, ejecutar `GET /api/v1/tasks` para resync completo.
4. Si llega `.taskcard.deleted`, remover tarjeta por `task_id`.

---

## Validaciones backend (Laravel)

- `name`: `required|string|min:1|max:120`
- `color_tag`: `required|in:blue,green,amber,rose,pink,violet`
- `icon_tag`: `required|in:briefcase,learning,tools,code,book,pen,cart,game`
- `timer_initial_seconds`: `nullable|integer|min:1|max:86400`
- `alarm_time_local`: `nullable|date_format:H:i`
- `version`: `required|integer|min:1` (en update/delete)

Reglas de seguridad:
- siempre filtrar por `auth()->id()`
- `404` si task no existe o no pertenece al usuario
- transiciones en DB transaction
- `lockForUpdate()` para update/delete
- emitir evento Reverb despues de commit

---

## Configuracion adicional obligatoria (Laravel + Reverb)

- `BROADCAST_CONNECTION=reverb`
- definir canal en `routes/channels.php`:
  - `user.{userId}.taskcards` solo si `auth()->id() === userId`
- eventos con nombre explicito:
  - `broadcastAs(): taskcard.created|updated|deleted|state.changed`
- emitir `origin_device_id` (request header `X-Device-Id`)
- mantener `version` como control de concurrencia optimista

