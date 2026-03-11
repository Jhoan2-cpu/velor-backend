# Handoff Frontend - Login Correcto (Sanctum Cookie Session)

Fecha: 2026-03-10  
Alcance: implementacion de login por email/password y Google OAuth contra backend Laravel en `http://localhost:8001`.

## 1) Modelo de autenticacion (obligatorio)

Este backend usa **Sanctum con cookie de sesion**, no JWT bearer.

Reglas:
1. Todas las requests de auth deben usar cookies (`withCredentials: true` en axios o `credentials: 'include'` en fetch).
2. No enviar `Authorization: Bearer ...` para este flujo.
3. Si existen tokens viejos en localStorage, limpiarlos:
   - `localStorage.removeItem('velor_auth_tokens')`

## 2) Base URL y origenes

1. Backend: `http://localhost:8001`
2. Frontend: `http://localhost:5173`
3. No mezclar `127.0.0.1` con `localhost`.

## 3) Endpoints de auth

1. `GET /sanctum/csrf-cookie`
2. `POST /api/v1/auth/register`
3. `POST /api/v1/auth/login`
4. `POST /api/v1/auth/logout` (auth requerida)
5. `GET /api/v1/auth/me` (auth requerida)
6. `GET /api/v1/auth/google/redirect?intent=login`
7. `GET /api/v1/auth/google/callback` (lo consume backend; frontend no lo llama directo)

## 4) Flujo login por email/password

Orden exacto:
1. `GET /sanctum/csrf-cookie`
2. `POST /api/v1/auth/login` con body:

```json
{
  "email": "anton@velor.app",
  "password": "secret12345"
}
```

3. Si `200`, llamar `GET /api/v1/auth/me`.
4. Si `me` responde `200`, considerar sesion activa y navegar a `/app`.
5. Si `me` responde `401`, mantener `/login` y mostrar error de sesion.

Respuesta esperada `POST /login` (`200`):

```json
{
  "data": {
    "user": {
      "id": "1",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    }
  }
}
```

## 5) Flujo login con Google

Orden exacto:
1. Redirigir navegador a:
   - `window.location.href = "http://localhost:8001/api/v1/auth/google/redirect?intent=login"`
2. Backend procesa callback y redirige:
   - exito: `http://localhost:5173/app`
   - error: `http://localhost:5173/login?auth_error=google&stage=callback&status=failed&code=<ERROR_CODE>`
3. Cuando frontend monte en `/app`, ejecutar `GET /api/v1/auth/me` con cookies.
4. Si `me=200`, mantener `/app`.
5. Si `me=401`, enviar a `/login`.

## 6) Contrato de errores a manejar

1. `401` en `login`: credenciales invalidas.
2. `409` en `login` con `code = "SESSION_ALREADY_ACTIVE"`: existe sesion activa previa.
3. `422` validacion:
   - login: `email`, `password`
   - register: `display_name`, `email`, `password`, `password_confirmation`, `locale`, `time_zone_name`
4. `401` en `me/logout`: sesion no valida o expirada.

Ejemplo `409`:

```json
{
  "message": "An active session already exists for this user.",
  "code": "SESSION_ALREADY_ACTIVE",
  "data": {
    "active_session_expires_at": "2026-03-10T20:45:00.000000Z"
  }
}
```

## 7) Register (si aplica en pantalla de login)

Request:

```json
{
  "display_name": "Anton Rivera",
  "email": "anton@velor.app",
  "password": "secret12345",
  "password_confirmation": "secret12345",
  "locale": "es",
  "time_zone_name": "America/Lima"
}
```

Respuesta `201`:

```json
{
  "data": {
    "user": {
      "id": "1",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    },
    "settings": {
      "locale": "es",
      "time_zone_name": "America/Lima"
    }
  }
}
```

## 8) Implementacion recomendada (axios)

```ts
import axios from "axios";

export const api = axios.create({
  baseURL: "http://localhost:8001",
  withCredentials: true,
  headers: { Accept: "application/json" },
});

export async function ensureCsrfCookie() {
  await api.get("/sanctum/csrf-cookie");
}

export async function loginEmail(email: string, password: string) {
  await ensureCsrfCookie();
  await api.post("/api/v1/auth/login", { email, password });
  return api.get("/api/v1/auth/me");
}

export function loginGoogle() {
  window.location.href =
    "http://localhost:8001/api/v1/auth/google/redirect?intent=login";
}
```

## 9) Checklist de verificacion en DevTools

1. En request a `/api/v1/auth/me`:
   - `Cookie` header presente con `velor-backend-session`.
2. En request de mutacion (`login/register/logout`):
   - `X-XSRF-TOKEN` presente despues de llamar `/sanctum/csrf-cookie`.
3. En Application > Cookies (`http://localhost:8001`):
   - existe `XSRF-TOKEN`
   - existe `velor-backend-session`
4. Si hay rebote `/app -> /login`, revisar primero `status` de `/api/v1/auth/me`.

## 10) Criterio de aceptacion

1. Login email funciona y mantiene sesion al recargar `/app`.
2. Login Google funciona y mantiene sesion al volver del callback.
3. `GET /api/v1/auth/me` responde `200` en sesion activa y `401` sin sesion.
4. Frontend no depende de bearer token para autenticar.
