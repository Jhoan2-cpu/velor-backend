# Frontend Handoff - TaskCards CRUD + Realtime

Fecha de verificacion backend: 2026-03-04.

Este documento resume lo que el backend **ya implementa** y lo que frontend debe cumplir para evitar errores `422` y `403`.

---

## 1) Base URL y credenciales

- Backend local: `http://localhost:8001`
- Frontend local: `http://localhost:5173`
- Todas las requests con sesion deben usar:
  - `credentials: 'include'`
  - `Accept: application/json`
  - `Content-Type: application/json` (en mutaciones JSON)

Antes de login/register/logout y `broadcasting/auth`:
- `GET /sanctum/csrf-cookie`

---

## 2) API TaskCards implementada

Namespace soportado:
- Canonico: `/api/v1/focus/tasks`
- Alias compatible: `/api/v1/tasks`

Endpoints:
- `GET /api/v1/focus/tasks`
- `POST /api/v1/focus/tasks`
- `PATCH /api/v1/focus/tasks/{taskId}`
- `DELETE /api/v1/focus/tasks/{taskId}`

### 2.1 Crear task (`POST`)

Payload recomendado:

```json
{
  "name": "Deep Work: API",
  "icon_tag": "briefcase",
  "color_tag": "#1A73E8",
  "alarm_time_local": null
}
```

Tambien se aceptan aliases:
- `title` -> `name`
- `icon` -> `icon_tag`
- `color` -> `color_tag`
- `alarmTimeLocal` -> `alarm_time_local`

Tambien se acepta wrapper:
- `apiPayload`, `payload`, `task`, `data`
- como objeto JSON
- o string JSON dentro de form body

Campos runtime prohibidos en create:
- `state`, `active_mode`, `timer_*`, `stopwatch_*`, `total_tracked_seconds`, `version`

Si se envian campos prohibidos:
- `422` con `errors.{campo}`

### 2.2 Actualizar task (`PATCH`)

Requiere version optimista:
- `if_version` o `version` (min 1)

Campos editables:
- `name`, `icon_tag`, `color_tag`, `alarm_time_local`, `timer_initial_seconds`

Alias aceptados:
- `title`, `icon`, `color`, `alarmTimeLocal`, `timerInitialSeconds`, `ifVersion`

### 2.3 Eliminar task (`DELETE`)

Version requerida por uno de estos medios:
- header `If-Match: <version>`
- query `?if_version=<version>`
- body `version`

---

## 3) Realtime implementado (Broadcasting)

Canales privados autorizados:
- `private-user.{userId}.focus.tasks`
- `private-user.{userId}.taskcards`

Eventos emitidos por backend:
- `taskcard.created`
- `taskcard.updated`
- `taskcard.deleted`

Payload emitido:

```json
{
  "type": "taskcard.updated",
  "meta": {
    "user_id": "12",
    "event_id": "evt_...",
    "emitted_at_utc": "2026-03-04T20:00:00Z",
    "origin_device_id": "web-abc"
  },
  "data": {
    "task": {
      "...": "..."
    }
  }
}
```

Nota:
- El backend actualmente emite a ambos canales por compatibilidad.
- Frontend puede suscribirse a uno canonico y mantener fallback temporal.

---

## 4) `/broadcasting/auth` y error 403

Si aparece `POST /broadcasting/auth 403`, normalmente es problema de integracion frontend (no del canal en backend).

Checklist frontend:
1. Usuario autenticado con cookie de sesion vigente.
2. `credentials: 'include'` en authorizer de Echo.
3. `X-XSRF-TOKEN` enviado (tomado de cookie `XSRF-TOKEN`).
4. `channel_name` coincide con el usuario real (`private-user.{id}.focus.tasks`).
5. No mezclar host/puerto distintos entre login y broadcasting auth.

Backend ya valida canales con comparacion segura:
- `(string)$user->id === (string)$userId`
- guards `web` y `sanctum`

---

## 5) Config recomendada en frontend

## 5.1 fetch helper

```ts
export async function apiFetch(path: string, init: RequestInit = {}) {
  return fetch(`http://localhost:8001${path}`, {
    credentials: "include",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
    ...init,
  });
}
```

## 5.2 createTask (evitar 422 por payload)

```ts
await apiFetch("/api/v1/focus/tasks", {
  method: "POST",
  body: JSON.stringify({
    name,
    icon_tag,
    color_tag,
    alarm_time_local,
  }),
});
```

No enviar:
- `body: apiPayload` sin `JSON.stringify`
- `apiPayload=[object Object]`

## 5.3 Echo authorizer

```ts
authorizer: (channel) => ({
  authorize: async (socketId, callback) => {
    try {
      const res = await fetch("http://localhost:8001/broadcasting/auth", {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": readXsrfTokenFromCookie(),
          "Accept": "application/json",
        },
        body: new URLSearchParams({
          socket_id: socketId,
          channel_name: channel.name,
        }),
      });

      if (!res.ok) throw new Error(`broadcast auth ${res.status}`);
      callback(false, await res.json());
    } catch (error) {
      callback(true, error);
    }
  },
}),
```

---

## 6) Diagnostico rapido

Si ves `422 The name field is required`:
- revisar body real en Network tab.
- si llega `apiPayload: "[object Object]"`, faltó serializacion JSON.

Si ves `403 /broadcasting/auth`:
- revisar cookies/sesion/csrf.
- confirmar `channel_name` correcto para el `userId` autenticado.

Si ves `401` en tasks:
- sesion expirada o no autenticado.

