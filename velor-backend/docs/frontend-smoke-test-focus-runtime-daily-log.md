# Frontend Smoke Test - Taskcards + Focus Runtime + Daily Log

Fecha: 2026-03-05  
Estado: listo para ejecución por frontend

## 1) Objetivo

Validar en pocos minutos que:
- Taskcards CRUD funciona end-to-end.
- Runtime focus (`start/pause/resume/stop/reset/switch-task/heartbeat`) responde contrato.
- Daily log retorna shape correcto.
- Realtime sincroniza entre 2 clientes sin errores de canal/CORS.

## 2) Prerrequisitos

- Backend levantado en `http://localhost:8001` (o el puerto configurado).
- Frontend levantado en `http://localhost:5173`.
- Usuario autenticado en frontend (cookie Sanctum activa).
- CSRF inicial obtenido (`GET /sanctum/csrf-cookie`) antes de mutaciones.
- Reverb/broadcast corriendo (si usan proceso separado).

## 3) Checklist de rutas (backend vivo)

Debe existir todo esto:
- `GET /api/v1/app/bootstrap`
- `GET /api/v1/focus/tasks`
- `POST /api/v1/focus/tasks`
- `PATCH /api/v1/focus/tasks/{taskId}`
- `DELETE /api/v1/focus/tasks/{taskId}`
- `GET /api/v1/focus-sessions/active`
- `POST /api/v1/focus-sessions/start`
- `POST /api/v1/focus-sessions/pause`
- `POST /api/v1/focus-sessions/resume`
- `POST /api/v1/focus-sessions/stop`
- `POST /api/v1/focus-sessions/reset`
- `POST /api/v1/focus-sessions/switch-task`
- `POST /api/v1/focus-sessions/heartbeat`
- `GET /api/v1/focus/daily-log`
- `POST /broadcasting/auth`

## 4) Prueba 0 - Bootstrap

Request:
- `GET /api/v1/app/bootstrap?include=tasks,preferences,daily_log,dashboard_stats,active_focus_session`

Esperado:
- `200`
- `data.user.id` existe y es `string`
- `data.tasks` es array
- `data.daily_log.entries` es array
- `data.active_focus_session` es `object|null`
- `data.server_now_utc` existe

No permitido:
- `500`
- `daily_log.entries` null/no iterable

## 5) Prueba 1 - Taskcards CRUD

## 5.1 Crear

Request:
```json
{
  "name": "Smoke Task A",
  "icon_tag": "briefcase",
  "color_tag": "#1A73E8",
  "alarm_time_local": null
}
```

Esperado:
- `POST /api/v1/focus/tasks` => `201`
- `data.id` string
- guardar `taskId` y `version`

## 5.2 Editar con versión correcta

Request:
```json
{
  "if_version": 1,
  "name": "Smoke Task A v2"
}
```

Esperado:
- `PATCH /api/v1/focus/tasks/{taskId}` => `200`
- `version` incrementa

## 5.3 Editar con versión vieja (conflicto)

Request:
```json
{
  "if_version": 1,
  "name": "Should fail"
}
```

Esperado:
- `409`
- `code = "TASK_VERSION_CONFLICT"`

## 5.4 Delete con `If-Match`

Headers:
- `If-Match: <current_version>`

Esperado:
- `DELETE /api/v1/focus/tasks/{taskId}` => `204`

## 6) Prueba 2 - Runtime Focus

Primero crea 2 tasks para switch/reset:
- `taskA`
- `taskB`

## 6.1 Active inicial

- `GET /api/v1/focus-sessions/active`
- Esperado: `200` con `active_focus_session = null`

## 6.2 Start

Request:
```json
{
  "task_id": "<taskA>",
  "timer_mode": "stopwatch"
}
```

Esperado:
- `200` (o `201` si su cliente lo interpreta igual)
- `active_focus_session.task_id = taskA`
- `session_state = running`

## 6.3 Start otra vez (conflicto activo)

Request:
```json
{
  "task_id": "<taskB>",
  "timer_mode": "stopwatch"
}
```

Esperado:
- `409`
- `code = "ACTIVE_SESSION_CONFLICT"`

## 6.4 Pause / Resume

Requests:
```json
{ "expected_version": <version_actual> }
```

Esperado:
- `pause` => `200`, `session_state = paused`
- `resume` => `200`, `session_state = running`

## 6.5 Heartbeat

Request:
```json
{ "expected_version": <version_actual> }
```

Esperado:
- `200`
- sin errores de contrato

## 6.6 Stop

Request:
```json
{
  "expected_version": <version_actual>,
  "stop_reason": "manual"
}
```

Esperado:
- `200`
- `active_focus_session = null`
- `stopped_session_summary.stop_reason = "manual"`

## 6.7 Reset determinístico

Request:
```json
{
  "task_id": "<taskA>",
  "expected_version": <version_taskA>
}
```

Esperado:
- `200`
- task queda en `idle`

## 6.8 Switch task

Precondición:
- iniciar runtime en `taskA`

Request:
```json
{
  "task_id": "<taskB>",
  "expected_version": <version_taskA_runtime>,
  "timer_mode": "stopwatch"
}
```

Esperado:
- `200`
- sesión activa ahora en `taskB`

## 7) Prueba 3 - Daily Log

Request:
- `GET /api/v1/focus/daily-log?date=2026-03-05&time_zone_name=America/Lima`

Esperado:
- `200`
- `data.entries` array
- `data.focus_time_entries` array
- `data.idle_time_entries` array
- IDs únicos en `entries` (prefijos `focus:` y `idle:`)

## 8) Prueba 4 - Realtime (dos pestañas)

Escenario:
1. Abrir cliente A y cliente B con el mismo usuario.
2. En A ejecutar `start` de runtime.
3. Verificar en B actualización inmediata (estado running).
4. En A ejecutar `pause` y luego `stop`.
5. Verificar en B transición a paused y luego stopped/sin sesión activa.

Esperado:
- No `403` en `/broadcasting/auth`.
- No `404` en endpoints runtime/bootstrap.
- No `CORS` errors.
- Eventos esperados:
  - `taskcard.updated`
  - `focus_session.updated`
  - `focus_session.stopped`

## 9) Criterio de aprobación

Se considera aprobado si:
1. No aparecen `500` en flujo normal de uso.
2. No quedan errores de consola relacionados a contrato (`404`, `403`, shape inválido).
3. Runtime y daily log se sincronizan en dos clientes.
4. Los conflictos (`409`) aparecen con códigos correctos.
5. Las respuestas devuelven IDs como `string`.

## 10) Diagnóstico rápido

| Síntoma | Causa probable |
|---|---|
| `POST /focus-sessions/start 404` | ruta no desplegada o servidor viejo |
| `POST /broadcasting/auth 403` | sesión/csrf inválida o canal no autorizado |
| `bootstrap ... entries is not iterable` | backend devolviendo shape viejo |
| `TASK_VERSION_CONFLICT` en edición | frontend usa versión stale |
| `ACTIVE_SESSION_CONFLICT` al start | ya hay focus activo en ese usuario |

