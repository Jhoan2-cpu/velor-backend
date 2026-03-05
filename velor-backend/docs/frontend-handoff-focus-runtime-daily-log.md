# Frontend Handoff - Taskcards + Focus Runtime + Daily Log

Fecha: 2026-03-05  
Estado: contrato operativo para implementacion frontend

## 1) Objetivo y alcance

Este documento define como integrar frontend con:
- Taskcards CRUD + realtime
- Focus runtime (`start/pause/resume/stop/reset/switch-task/heartbeat`)
- Daily log por zona horaria

Fuera de alcance:
- preferencias de usuario
- reportes historicos avanzados

## 2) Matriz de disponibilidad backend (hoy)

| Modulo | Endpoint/Canal | Estado |
|---|---|---|
| Taskcards CRUD | `/api/v1/focus/tasks` | Implementado |
| Taskcards alias legacy | `/api/v1/tasks` | Implementado (compat) |
| Taskcards realtime | `private-user.{userId}.focus.tasks` | Implementado |
| Focus runtime HTTP | `/api/v1/focus-sessions/*` | Implementado |
| Focus runtime realtime | `private-user.{userId}.focus` | Implementado |
| Daily log | `/api/v1/focus/daily-log` | Implementado |

## 3) Convenciones globales

- Auth: Sanctum cookie session.
- IDs en JSON: siempre `string` (DB usa `bigint`).
- Timestamps: ISO 8601 UTC, server-authoritative.
- Optimistic locking:
  - Taskcards: `if_version`/`If-Match` contra `focus_tasks.version`.
  - Runtime: `expected_version` contra `focus_tasks.version`.

## 4) Integracion HTTP

### 4.1 Base URL y cookies

- Base API canonica: `http://localhost:<BACKEND_PORT>/api/v1`
- En todas las llamadas autenticadas usar `credentials: 'include'`.
- Antes de `POST/PATCH/DELETE`, asegurar cookie CSRF: `GET /sanctum/csrf-cookie`.

### 4.2 Taskcards (activo hoy)

#### Listar
- `GET /api/v1/focus/tasks`
- 200: lista de tasks del usuario.
- 401: `Unauthenticated.`

#### Crear
- `POST /api/v1/focus/tasks`
- payload editable: `name`, `icon_tag`, `color_tag`, `alarm_time_local`, `timer_initial_seconds` (opcional).
- 201: task creada.
- 422: validacion.

#### Editar
- `PATCH /api/v1/focus/tasks/{taskId}`
- enviar `if_version`.
- solo metadatos, no runtime.
- 200: actualizado.
- 409: `TASK_VERSION_CONFLICT`.
- 422: validacion / campo runtime prohibido.

#### Eliminar
- `DELETE /api/v1/focus/tasks/{taskId}`
- version requerida via `If-Match` (preferido) o `?if_version=`.
- 204: eliminado.
- 409: `TASK_VERSION_CONFLICT`.
- 404: task no encontrada/no pertenece al usuario.

### 4.3 Runtime (implementado)

Namespace canonico:
- `/api/v1/focus-sessions/*`

Endpoints objetivo:
- `GET /focus-sessions/active`
- `POST /focus-sessions/start`
- `POST /focus-sessions/pause`
- `POST /focus-sessions/resume`
- `POST /focus-sessions/stop`
- `POST /focus-sessions/reset`
- `POST /focus-sessions/switch-task`
- `POST /focus-sessions/heartbeat`

Payloads canonicos:
- `stop_reason` (alias temporal de entrada permitido: `stopped_reason`)
- `timer_mode` + `target_seconds` con precedencia documentada
- en `reset`: `task_id` y `expected_version` obligatorios

### 4.4 Daily log (implementado)

- `GET /api/v1/focus/daily-log?date=YYYY-MM-DD&time_zone_name=America/Lima`
- corte diario por fecha local + timezone IANA (respetar DST).

## 5) Realtime

### 5.1 Naming canonico de eventos

Taskcards:
- `taskcard.created`
- `taskcard.updated`
- `taskcard.deleted`

Focus runtime:
- `focus_session.updated`
- `focus_session.stopped`

### 5.2 Regla Echo

- escuchar con punto:
  - `listen('.taskcard.updated')`
  - `listen('.focus_session.updated')`
- en payload `type` sin punto:
  - `"type": "taskcard.updated"`
  - `"type": "focus_session.updated"`

### 5.3 Canales privados

Frontend suscribe:
- `private-user.{userId}.focus.tasks`
- `private-user.{userId}.focus`

Backend registra:
- `user.{userId}.focus.tasks`
- `user.{userId}.focus`

### 5.4 Idempotencia y orden

- deduplicar por `event_id`.
- ignorar evento si `incoming.version < local.version`.
- si `incoming.version === local.version`, aplicar solo si no existe `event_id`.
- usar `origin_device_id` para evitar re-aplicar eco local.

## 6) Estrategia de estado frontend recomendada

Store sugerido:
- `taskcardsById: Record<string, Taskcard>`
- `orderedTaskIds: string[]`
- `activeSession: FocusSession | null`
- `dailyLogCacheByDateTz: Record<string, DailyLog>`
- `lastEventIds: Set<string>`

Reglas:
- Taskcards HTTP como source primario para CRUD.
- Realtime como sincronizacion cross-device.
- Runtime HTTP como source autoritativo de estado de sesion.
- Daily log: refetch tras eventos de apertura/cierre FTE/ITE o aplicar delta.

## 7) Reglas de negocio criticas (frontend debe respetar)

- No editar runtime de task desde PATCH taskcards (`state`, `active_mode`, `timer_*`, `stopwatch_*`, `total_tracked_seconds`).
- `start`:
  - si backend responde `ACTIVE_SESSION_CONFLICT`, no forzar localmente.
- `reset`:
  - siempre enviar `task_id` + `expected_version`.
- `stop`:
  - usar solo `stop_reason` canonico.

## 8) Manejo de errores y UX

401:
- limpiar estado de sesion y redirigir a login.

404:
- eliminar entidad local y refrescar lista.

409 (`TASK_VERSION_CONFLICT`):
- refrescar task desde backend y mostrar aviso de conflicto.

409 (`FOCUS_RUNTIME_CONFLICT` / `ACTIVE_SESSION_CONFLICT`):
- hacer `GET /focus-sessions/active` y rehidratar runtime.

422:
- mapear `errors` por campo y mostrar validaciones inline.

## 9) QA checklist frontend (paso a paso)

1. Login + CSRF + `GET /focus/tasks` exitoso.
2. Crear task y verificar render + IDs string.
3. Editar con `if_version` correcto (200).
4. Editar con `if_version` viejo (409 `TASK_VERSION_CONFLICT`).
5. DELETE con `If-Match` correcto (204).
6. DELETE con version vieja (409 `TASK_VERSION_CONFLICT`).
7. Realtime taskcards:
   - recibe `taskcard.created/updated/deleted`
   - dedupe por `event_id`
   - no downgrade por version menor.
8. Runtime:
   - start/pause/resume/stop/reset/switch-task/heartbeat contra contratos.
9. Daily log:
   - valida corte por timezone y casos de DST.

## 10) Tabla de verdad canonica

Rutas:
- Taskcards: `/api/v1/focus/tasks` (canonico), `/api/v1/tasks` (legacy)
- Runtime: `/api/v1/focus-sessions/*`
- Daily log: `/api/v1/focus/daily-log`

Eventos:
- Taskcards: `taskcard.created|updated|deleted`
- Runtime: `focus_session.updated|stopped`

Codigos de conflicto:
- Taskcards: `TASK_VERSION_CONFLICT`
- Runtime: `FOCUS_RUNTIME_CONFLICT`
- Start con focus activo: `ACTIVE_SESSION_CONFLICT`

## 11) Protocolo Console-Zero (obligatorio)

Actualizado: **2026-03-05**.

Objetivo:
- Evitar errores repetitivos en consola frontend (`500`, `404`, keys duplicadas, shape invalido de bootstrap).
- Definir reglas backend que deben cumplirse siempre en ambientes `local`, `dev` y `staging`.

Reglas obligatorias:
1. Backend no debe responder `500` por reglas de negocio esperadas.
2. Backend debe usar solo codigos de dominio:
   - `200/201/204` exito
   - `401` no autenticado
   - `404` recurso/ruta no existe
   - `409` conflicto de estado/version
   - `422` validacion
3. Cualquier error inesperado (`500`) debe ir con:
   - `message` trazable
   - `error_id` correlacionable en logs
4. Frontend puede tener fallback temporal, pero backend sigue siendo responsable de eliminar `500` y contratos incompletos.

## 12) Shape estricto de `app/bootstrap` (source of truth)

Endpoint:
- `GET /api/v1/app/bootstrap?include=tasks,preferences,daily_log,dashboard_stats,active_focus_session`

Respuesta `200` debe incluir (siempre presentes):
- `data.user`
- `data.preferences`
- `data.tasks` (array, puede ser vacio)
- `data.daily_log` (objeto consistente)
- `data.dashboard_stats`
- `data.active_focus_session` (objeto o `null`)
- `data.server_now_utc`

Contrato minimo recomendado:
```json
{
  "data": {
    "server_now_utc": "2026-03-05T12:00:00Z",
    "user": {
      "id": "1",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    },
    "preferences": {
      "locale": "es",
      "time_zone_name": "America/Lima",
      "time_zone_auto_detect": true,
      "ui_sounds_enabled": true,
      "background_music_enabled": false,
      "background_music_volume_percent": 50,
      "confirm_task_switch_enabled": true,
      "sign_out_confirmation_enabled": true
    },
    "tasks": [],
    "daily_log": {
      "date_local": "2026-03-05",
      "tracked_seconds": 0,
      "untracked_seconds": 0,
      "entries": []
    },
    "dashboard_stats": {
      "tracked_seconds_today": 0,
      "untracked_seconds_today": 0,
      "tracked_sessions_count_today": 0,
      "focus_time_total_seconds": 0
    },
    "active_focus_session": null
  }
}
```

No permitido:
- `user` faltante o `user.id` faltante
- `daily_log.entries` no-array
- `tasks` no-array

## 13) Contrato de IDs unicos en listas (evita React key warnings)

Problema observado:
- `Encountered two children with the same key` por IDs duplicados en daily log.

Regla backend:
1. `daily_log.entries[*].id` debe ser unico dentro del arreglo.
2. Si se mezclan fuentes (`focus_time_entries` + `idle_time_entries`), no reutilizar IDs crudos cruzados.
3. Serializacion canonical:
   - `focus_time_entries`: `id = "focus:<id_db>"`
   - `idle_time_entries`: `id = "idle:<id_db>"`

No permitido:
- IDs tipo `"1"` repetidos en el mismo payload.

## 14) Runtime start/active: respuesta esperada y errores validos

### 14.1 Start

Endpoint:
- `POST /api/v1/focus-sessions/start`

Payload:
```json
{
  "task_id": "2",
  "timer_mode": "stopwatch",
  "target_seconds": null
}
```

Respuestas validas:
- `200` (o `201`) con `active_focus_session`
- `409` (`ACTIVE_SESSION_CONFLICT`)
- `422` validacion
- `401` unauthenticated

No valido:
- `500 Internal Server Error`

### 14.2 Active

Endpoint:
- `GET /api/v1/focus-sessions/active`

Respuestas validas:
- `200` con `active_focus_session` (objeto o `null`)
- `401`

No valido:
- `404` si el endpoint forma parte del contrato habilitado en el entorno.

## 15) Realtime: eventos de control vs ticking

Para evitar carga innecesaria:
- Realtime debe priorizar transiciones de control:
  - `pause`, `resume`, `stop`, `reset`, `switch-task`
- El ticking por segundo se calcula localmente en frontend.

Backend:
- emitir `focus_session.updated` al cambiar estado/version
- evitar ruido de eventos por cada segundo si no cambia estado/version

## 16) Checklist de salida para backend (antes de handoff)

Checklist minimo:
1. `route:list` contiene:
   - `/api/v1/app/bootstrap`
   - `/api/v1/focus-sessions/active`
   - `/api/v1/focus-sessions/start|pause|resume|stop|reset|switch-task|heartbeat`
2. `POST /focus-sessions/start` no devuelve `500`.
3. `GET /app/bootstrap` devuelve `user` completo y `daily_log.entries` array.
4. IDs de daily log son unicos por respuesta.
5. `/broadcasting/auth` responde `200` cuando sesion/csrf son validos.
6. Canal `private-user.{userId}.focus` autoriza correctamente para el usuario autenticado.
7. Test de integracion cubre:
   - start OK
   - start conflicto (409)
   - payload invalido (422)
   - bootstrap shape estable

## 17) Tabla de diagnostico rapido (frontend + backend)

| Error en consola | Causa probable | Responsable primario |
|---|---|---|
| `POST /focus-sessions/start 500` | exception backend en service/controller | Backend |
| `bootstrap.daily_log.entries is not iterable` | shape invalido de `daily_log` | Backend |
| `Cannot read ... user.id` | `user` incompleto en bootstrap | Backend |
| `Encountered two children with the same key` | IDs duplicados en entries | Backend |
| `POST /broadcasting/auth 403` | sesion/csrf/canal auth desalineado | Backend + Integracion FE |
