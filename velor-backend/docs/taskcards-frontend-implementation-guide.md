# TaskCards Frontend Implementation Guide

Guia especifica para implementar en frontend el modulo **TaskCards CRUD + Realtime** ya disponible en backend.

## Objetivo del modulo

Permitir al usuario:
- listar taskcards
- crear taskcards
- editar metadatos de taskcards
- eliminar taskcards
- sincronizar cambios en tiempo real entre dispositivos/pestanas

Fuera de alcance en este modulo:
- start/pause/resume/stop de foco
- sesiones (`focus_time_entries`, `idle_time_entries`)
- daily log

---

## Reglas de contrato (obligatorias)

- Namespace canonico: `/api/v1/focus/tasks`
- IDs en API: `string` (aunque en DB sean `bigint`)
- Concurrencia optimista:
  - `PATCH` requiere `if_version` (body)
  - `DELETE` requiere `If-Match` (header), fallback `?if_version=`
- Campos runtime **prohibidos** en `POST/PATCH`:
  - `state`, `active_mode`, `timer_*`, `stopwatch_*`, `total_tracked_seconds`, `version`
  - si se envian: `422` con `errors.{campo}`

---

## Modelo de datos en frontend

```ts
export type TaskCard = {
  id: string;
  user_id: string;
  name: string;
  icon_tag: string | null;
  color_tag: string | null;
  alarm_time_local: string | null; // HH:MM 24h
  version: number;
  created_at: string; // ISO UTC
  updated_at: string; // ISO UTC
};
```

Validaciones recomendadas de UI antes de enviar:
- `name`: requerido, 1..120
- `alarm_time_local`: `HH:MM` 24h (`/^([01]\d|2[0-3]):([0-5]\d)$/`)

---

## Endpoints a consumir

### 1) Listar

`GET /api/v1/focus/tasks`

Response `200`:
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

### 2) Crear

`POST /api/v1/focus/tasks`

Body permitido:
```json
{
  "name": "Deep Work: API",
  "icon_tag": "brain",
  "color_tag": "#1A73E8",
  "alarm_time_local": "09:00"
}
```

Response `201`: `{ data: TaskCard }`

### 3) Editar metadatos

`PATCH /api/v1/focus/tasks/{taskId}`

Body:
```json
{
  "if_version": 3,
  "name": "Deep Work: API v2",
  "icon_tag": "code",
  "color_tag": "#0F9D58",
  "alarm_time_local": "10:00"
}
```

Responses clave:
- `200`: actualizado
- `409 VERSION_CONFLICT`: version local vieja
- `422`: validacion / campo runtime prohibido

Ejemplo `409`:
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

Ejemplo `422` por campo prohibido:
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

### 4) Eliminar

`DELETE /api/v1/focus/tasks/{taskId}`

Header requerido:
```http
If-Match: 4
```

Fallback opcional:
- `DELETE /api/v1/focus/tasks/{taskId}?if_version=4`

Responses clave:
- `204`: eliminado
- `409 VERSION_CONFLICT`
- `404 Task not found.`

---

## Realtime

Canal privado:
- `private-user.{userId}.focus.tasks`

Eventos:
- `focus.task.created`
- `focus.task.updated`
- `focus.task.deleted`

Payload base:
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

En delete, `task` llega minimo con:
- `id`
- `user_id`
- `version`

Regla de idempotencia recomendada:
1. Si `incoming.version < local.version`: ignorar.
2. Si `incoming.version === local.version`: deduplicar por `event_id`.
3. Si `incoming.version > local.version`: aplicar.
4. Ante duda de desync: ejecutar `GET /api/v1/focus/tasks`.

---

## Recomendacion de cliente HTTP (Sanctum cookie session)

- usar `credentials: 'include'`
- antes de llamadas mutables (`POST/PATCH/DELETE`), asegurar CSRF:
  - `GET /sanctum/csrf-cookie`
- enviar `X-Origin-Device-Id` en mutaciones para trazabilidad realtime

Ejemplo helper:

```ts
export async function apiFetch(path: string, init: RequestInit = {}) {
  return fetch(path, {
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(init.headers ?? {}),
    },
    ...init,
  });
}
```

---

## Flujo sugerido de UI

1. Cargar vista -> `GET /api/v1/focus/tasks`.
2. Suscribirse al canal realtime del usuario.
3. En crear/editar/eliminar:
   - ejecutar request
   - actualizar estado local con response
   - si llega evento propio duplicado, deduplicar por `event_id`.
4. Si `409` en update/delete:
   - mostrar aviso "cambios en otro dispositivo"
   - refrescar lista (`GET /api/v1/focus/tasks`)
   - reintentar con nueva version si aplica.
5. Si `422`:
   - mapear `errors` por campo en el formulario.

---

## Checklist de integracion frontend

- [ ] Tipos TS con `id`/`user_id` como `string`
- [ ] Formularios de create/edit solo con campos permitidos
- [ ] PATCH con `if_version`
- [ ] DELETE con `If-Match` (fallback query)
- [ ] Manejo de `409 VERSION_CONFLICT`
- [ ] Manejo de `422` por campo
- [ ] Suscripcion realtime + deduplicacion por `event_id`
- [ ] Resync completo con `GET /api/v1/focus/tasks` ante desincronizacion

