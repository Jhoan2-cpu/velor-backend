# Auth API — Referencia para Frontend (React)

**Base URL:** `http://localhost:8000`  
**Versión:** v1  
**Autenticación:** Cookie-session via Laravel Sanctum

---

## Configuración inicial obligatoria

### 1. Instalar un cliente HTTP con soporte de cookies

Usa `axios` con `withCredentials` o `fetch` con `credentials: 'include'`.

```ts
// axios (recomendado)
import axios from 'axios'

const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,           // ← OBLIGATORIO para que las cookies funcionen
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})
```

### 2. Obtener el CSRF cookie antes de cualquier mutación

Antes de llamar a `register`, `login` o `logout` **siempre** debes obtener primero el CSRF token:

```ts
await api.get('/sanctum/csrf-cookie')
// Después de esto, axios enviará el X-XSRF-TOKEN automáticamente
```

> Llama a este endpoint una sola vez al iniciar la app (o cuando una petición falle con 419).

---

## Endpoints

### `POST /api/v1/auth/register`

Registra un nuevo usuario e inicia sesión automáticamente.

**Request body:**
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

**Cómo obtener `locale` y `time_zone_name` del navegador:**
```ts
// locale: detectar del navegador y normalizar a "es" | "en"
const raw = navigator.language || navigator.languages?.[0] || 'es'
const locale = raw.startsWith('es') ? 'es' : raw.startsWith('en') ? 'en' : 'es'

// time_zone_name: IANA automático
const time_zone_name = Intl.DateTimeFormat().resolvedOptions().timeZone
// Ejemplo: "America/Lima" | "America/New_York" | "Europe/Madrid"
```

**Validaciones backend:**
| Campo | Regla |
|---|---|
| `display_name` | requerido, 2–120 caracteres |
| `email` | requerido, email válido, único |
| `password` | requerido, mín. 8 caracteres, debe coincidir con `password_confirmation` |
| `locale` | requerido, solo `es` o `en` |
| `time_zone_name` | requerido, timezone IANA válida |

**Respuestas:**

✅ `201 Created`
```json
{
  "data": {
    "user": {
      "id": 1,
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    },
    "preferences": {
      "locale": "es",
      "time_zone_name": "America/Lima"
    }
  }
}
```

❌ `422 Unprocessable Entity` (validación)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["El correo ya está en uso."],
    "time_zone_name": ["La zona horaria no es válida."]
  }
}
```

❌ `500 Internal Server Error`
```json
{ "message": "Register failed." }
```

---

### `POST /api/v1/auth/login`

Inicia sesión con email y contraseña.

**Request body:**
```json
{
  "email": "anton@velor.app",
  "password": "secret12345"
}
```

**Respuestas:**

✅ `200 OK`
```json
{
  "data": {
    "user": {
      "id": 1,
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    }
  }
}
```

❌ `401 Unauthorized`
```json
{ "message": "Credenciales inválidas." }
```

❌ `422 Unprocessable Entity`
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["El campo email es obligatorio."],
    "password": ["El campo password es obligatorio."]
  }
}
```

---

### `GET /api/v1/auth/me`

Retorna el usuario autenticado actualmente. Usar para verificar si hay sesión activa.

**Headers:** ninguno adicional (la cookie de sesión se envía automáticamente)

**Respuestas:**

✅ `200 OK`
```json
{
  "data": {
    "id": 1,
    "display_name": "Anton Rivera",
    "email": "anton@velor.app",
    "locale": "es"
  }
}
```

❌ `401 Unauthorized`
```json
{ "message": "Unauthenticated." }
```

> **Patrón recomendado:** llama a `/me` al montar tu AuthProvider para determinar si el usuario ya tiene sesión activa.

---

### `POST /api/v1/auth/logout`

Cierra la sesión del usuario actual.

**Request body:** vacío

**Respuestas:**

✅ `200 OK`
```json
{ "message": "Logged out." }
```

❌ `401 Unauthorized`
```json
{ "message": "Unauthenticated." }
```

---

### `GET /api/v1/auth/google/redirect`

Inicia el flujo OAuth con Google. **No llamar con fetch/axios** — redirigir el browser directamente.

```ts
// ✅ Correcto: redirigir el browser
window.location.href = 'http://localhost:8000/api/v1/auth/google/redirect'

// ❌ Incorrecto: no uses fetch/axios para este endpoint
```

**Respuesta:** `302` redirect hacia Google → el usuario completa el login → Google redirige al backend → el backend redirige al frontend.

**Resultados del callback (el backend redirige al frontend):**
- ✅ Éxito → `http://localhost:5173/app`
- ❌ Error → `http://localhost:5173/login?auth_error=google`

```ts
// En tu página /login, detectar el error:
const params = new URLSearchParams(window.location.search)
if (params.get('auth_error') === 'google') {
  // mostrar mensaje: "Error al iniciar sesión con Google"
}
```

---

## Flujo completo sugerido

```
App mount
  └─ GET /sanctum/csrf-cookie
  └─ GET /api/v1/auth/me
        ├─ 200 → usuario autenticado → ir a /app
        └─ 401 → usuario no autenticado → mostrar /login

Login / Register
  └─ GET /sanctum/csrf-cookie  (si no se hizo al inicio)
  └─ POST /api/v1/auth/login  o  POST /api/v1/auth/register
        ├─ 200/201 → guardar user en estado → ir a /app
        └─ 401/422 → mostrar errores

Logout
  └─ POST /api/v1/auth/logout
        └─ 200 → limpiar estado → ir a /login

Google OAuth
  └─ window.location.href = '/api/v1/auth/google/redirect'
        └─ (flujo externo en el browser)
        └─ Backend redirige a /app o /login?auth_error=google
```

---

## Errores comunes

| Síntoma | Causa | Fix |
|---|---|---|
| `419 CSRF token mismatch` | No se llamó a `/sanctum/csrf-cookie` | Llamar antes de cualquier POST |
| `401` en `/me` tras login | `withCredentials: true` no está en el cliente | Activarlo en axios/fetch |
| CORS error | El origin del frontend no está en `SANCTUM_STATEFUL_DOMAINS` | Verificar `.env` del backend |
| Google redirect no funciona | Llamar con fetch en vez de `window.location.href` | Usar redirección del browser |
