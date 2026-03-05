# Velor Backend - Contrato Unificado (Single File)

Estado del documento: consolidado para handoff backend.
Fecha: 2026-03-05.

## 1) Scope

Este archivo unifica en un solo lugar:
- Taskcards CRUD + Realtime
- Focus Runtime (start/pause/resume/stop/reset/switch-task/heartbeat)
- Daily Log realtime

Fuera de alcance:
- preferencias de usuario
- reportes avanzados

## 2) Convenciones globales

- API namespace canonico:
  - Taskcards: `/api/v1/focus/tasks`
  - Taskcards legacy/compat backend: `/api/v1/tasks` (alias temporal)
  - Runtime: `/api/v1/focus-sessions/*`
  - Daily log: `/api/v1/focus/daily-log`
- Auth: Sanctum cookie session
- IDs:
  - DB: `bigint`
  - JSON: `string`
- Timestamps: ISO 8601 UTC, server-authoritative
- Optimistic locking: `focus_tasks.version`

## 3) Realtime naming (regla unica)

Para evitar inconsistencias, el naming canonico queda asi:

### 3.1 Taskcards events

- `taskcard.created`
- `taskcard.updated`
- `taskcard.deleted`

### 3.2 Focus session events

- `focus_session.updated`
- `focus_session.stopped`

### 3.3 Echo listen vs payload type

- En frontend Echo se escucha con punto:
  - `listen('.taskcard.updated')`
  - `listen('.focus_session.updated')`
- En payload JSON, `type` NO lleva punto:
  - `"type": "taskcard.updated"`
  - `"type": "focus_session.updated"`

### 3.4 Canal privado

- Frontend suscribe: `private-user.{userId}.focus` y `private-user.{userId}.focus.tasks`
- Backend (`routes/channels.php`) registra sin prefijo `private-`:
  - `user.{userId}.focus`
  - `user.{userId}.focus.tasks`

## 4) Taskcards CRUD basico

## 4.1 Modelo API (resumen)

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

Campos runtime no editables en CRUD basico:
- `state`
- `active_mode`
- `timer_*`
- `stopwatch_*`
- `total_tracked_seconds`

## 4.2 Endpoints

### GET `/api/v1/focus/tasks`

- 200: lista de taskcards del usuario
- 401: `Unauthenticated.`
- nota de compatibilidad: backend tambien expone alias legacy `/api/v1/tasks`

### POST `/api/v1/focus/tasks`

Request:
```json
{
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#1A73E8",
  "alarm_time_local": "09:00"
}
```

- 201: task creada
- 422: validacion

### PATCH `/api/v1/focus/tasks/{taskId}`

Request:
```json
{
  "if_version": 3,
  "name": "Deep Work: API v2",
  "icon_tag": "code",
  "color_tag": "#0F9D58",
  "alarm_time_local": "10:00"
}
```

- 200: task actualizada
- 409: `TASK_VERSION_CONFLICT`
- 422: campo invalido o runtime prohibido

### DELETE `/api/v1/focus/tasks/{taskId}`

Requiere version por:
- header `If-Match`, o
- query `if_version`

- 204: eliminado
- 409: `TASK_VERSION_CONFLICT`
- 404: no existe/no pertenece al usuario

## 4.3 Realtime de taskcards

Canal:
- `private-user.{userId}.focus.tasks`

Payload ejemplo:
```json
{
  "type": "taskcard.updated",
  "meta": {
    "user_id": "12",
    "event_id": "evt_01HT...",
    "origin_device_id": "web-7f3b91",
    "emitted_at_utc": "2026-03-05T14:20:00Z"
  },
  "data": {
    "task": {
      "id": "101",
      "user_id": "12",
      "name": "Deep Work: API v2",
      "icon_tag": "code",
      "color_tag": "#0F9D58",
      "alarm_time_local": "10:00",
      "version": 4,
      "updated_at": "2026-03-05T14:20:00Z"
    }
  }
}
```

Reglas cliente:
- ignorar evento si `incoming.version < local.version`
- deduplicar por `event_id`
- aplicar idempotencia por `origin_device_id` cuando corresponda

## 5) Focus runtime contract

## 5.1 Estados y transiciones

- `idle --play--> working`
- `working --pause--> paused`
- `paused --resume--> working`
- `working --stop--> stopped`
- `paused --stop--> stopped`
- `stopped --reset--> idle`
- `paused --reset--> idle`

## 5.2 Reglas de modo al start

Precedencia de `timer_mode`:
- si request trae `timer_mode` valido, ese valor manda
- si request NO trae `timer_mode`, default:
  - `timer_initial_seconds > 0` => `timer`
  - `timer_initial_seconds` null/0 => `stopwatch`

Precedencia de `target_seconds` (solo timer):
- request `target_seconds` valido (`>= 1`) manda
- si no viene, usar `focus_tasks.timer_initial_seconds`
- si no hay valor resoluble, responder 422
- en `stopwatch`, ignorar `target_seconds`

## 5.3 Invariantes DB/runtime

- max 1 `focus_time_entries` activo por usuario
- max 1 `idle_time_entries` activo por usuario
- no activos simultaneos en ambas tablas para el mismo usuario
- `ended_at_utc IS NULL => elapsed_seconds IS NULL`
- `ended_at_utc IS NOT NULL => elapsed_seconds >= 0`
- `ended_at_utc >= started_at_utc`

## 5.4 Comandos runtime (efecto esperado)

### GET `/api/v1/focus-sessions/active`

Snapshot autoritativo de runtime.

### POST `/api/v1/focus-sessions/start`

Request:
```json
{
  "task_id": "101",
  "timer_mode": "timer",
  "target_seconds": 1500
}
```

Politica de conflicto unica:
- si existe `focus_time_entries` activo => `409 ACTIVE_SESSION_CONFLICT`
- si existe solo `idle_time_entries` activo => cerrarlo en la misma transaccion y continuar start
- no auto-switch aqui (usar `switch-task`)

### POST `/api/v1/focus-sessions/pause`

```json
{ "expected_version": 6 }
```

- valida `expected_version` contra `focus_tasks.version`
- NO cierra `focus_time_entries`
- NO abre `idle_time_entries`

### POST `/api/v1/focus-sessions/resume`

```json
{ "expected_version": 7 }
```

- valida `expected_version` contra `focus_tasks.version`
- mantiene misma `focus_time_entries` activa

### POST `/api/v1/focus-sessions/stop`

```json
{
  "expected_version": 8,
  "stop_reason": "manual"
}
```

- valida `expected_version` contra `focus_tasks.version`
- cierra `focus_time_entries` activa con `stop_reason` permitido
- actualiza task (`state`, elapsed/remaining, ended_at, total_tracked_seconds, version)

Apertura de idle en stop:
- abre idle:
  - `manual` => `break`
  - `timer_completed` => `break`
  - `idle_detected` => `user_idle`
  - `session_end` => `session_end`
- NO abre idle:
  - `task_switch`
- no crear duplicados si ya hay idle activo

### POST `/api/v1/focus-sessions/reset`

Request (obligatorio deterministico):
```json
{
  "task_id": "101",
  "expected_version": 9
}
```

Reglas:
- `task_id` obligatorio
- `expected_version` valida `focus_tasks.version` del `task_id`
- si hay focus activo de otro task => `409 FOCUS_RUNTIME_CONFLICT`
- si hay focus activo del mismo task => cerrarlo con `stop_reason = session_end`
- resetea task objetivo a idle
- abrir idle solo si reset cerro una sesion activa

### POST `/api/v1/focus-sessions/switch-task`

```json
{
  "expected_version": 10,
  "task_id": "102",
  "timer_mode": "stopwatch"
}
```

- cierra sesion actual con `stop_reason = task_switch`
- abre sesion nueva en task destino
- atomico
- sin idle intermedio

### POST `/api/v1/focus-sessions/heartbeat`

```json
{ "expected_version": 11 }
```

- sincroniza elapsed/remaining de manera autoritativa
- permite evaluar auto-stop por `timer_completed`

## 6) Daily log realtime

Endpoint:
- `GET /api/v1/focus/daily-log?date=YYYY-MM-DD&time_zone_name=America/Lima`

Regla temporal:
- corte por dia local + timezone IANA
- proyectado a UTC
- respetar DST

Fuente:
- `focus_time_entries`
- `idle_time_entries`

Realtime:
- refrescar cuando se abre/cierra FTE o ITE
- incluir ids de entradas afectadas para delta/refetch

## 7) Concurrencia, seguridad y consistencia

Obligatorio:
- `DB::transaction` en `start/pause/resume/stop/reset/switch-task`
- lock por usuario (`SELECT ... FOR UPDATE`)
- optimistic locking por `expected_version`
- validacion de ownership por `auth()->id()`
- emitir realtime solo post-commit

No permitido:
- usar tiempo de cliente para `started_at_utc` / `ended_at_utc`
- escrituras parciales fuera de transaccion

## 8) Errores canonicos

401:
```json
{ "message": "Unauthenticated." }
```

404:
```json
{ "message": "Task not found." }
```

409 generic runtime:
```json
{
  "message": "Runtime conflict.",
  "code": "FOCUS_RUNTIME_CONFLICT",
  "data": {
    "server_now_utc": "2026-03-05T14:20:00Z",
    "active_focus_session": null
  }
}
```

409 start conflict:
```json
{
  "message": "Active session conflict.",
  "code": "ACTIVE_SESSION_CONFLICT"
}
```

409 version conflict (taskcards):
```json
{
  "message": "Task version conflict.",
  "code": "TASK_VERSION_CONFLICT"
}
```

422:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "timer_mode": ["The selected timer mode is invalid."]
  }
}
```

## 9) Estado de implementacion (alineado a hallazgos)

Segun verificacion 2026-03-05:
- contrato objetivo definido en este archivo
- Taskcards CRUD + realtime base: disponible
- runtime `/api/v1/focus-sessions/*`: pendiente de montar completo en backend
- canal de sesiones `private-user.{userId}.focus`: pendiente de habilitar en backend

## 10) Checklist final backend

- [ ] rutas runtime completas en `/api/v1/focus-sessions/*`
- [ ] machine state aplicada en DB + servicio
- [ ] expected_version validando `focus_tasks.version`
- [ ] reset deterministico con `task_id` obligatorio
- [ ] regla unica de start con idle activo (cerrar idle y continuar)
- [ ] taskcard events unificados a `taskcard.*`
- [ ] focus events con `type` sin punto y `listen()` con punto
- [ ] daily log por timezone + DST
- [ ] transacciones + locks + post-commit broadcast
