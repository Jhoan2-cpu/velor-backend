# Auth Contract (Desde Cero) - Frontend <-> Laravel

## Scope

Backend objetivo:
- Laravel + Sanctum (cookie session)
- Google OAuth (redirect/callback)
- Reverb se usa despues de auth, no en este modulo

Frontend objetivo:
- Registrar
- Iniciar sesion
- Cerrar sesion
- Obtener sesion actual
- Login/Register con Google

---

## Regla de estandarizacion (idioma y zona horaria)

### Idioma (`locale`)
- Fuente: navegador (`navigator.language` / `navigator.languages`)
- Estandar del navegador: BCP 47
- Normalizacion frontend obligatoria:
  - si empieza con `es` -> enviar `es`
  - si empieza con `en` -> enviar `en`
  - cualquier otro -> fallback `es`
- Backend solo acepta: `es`, `en`

### Zona horaria (`time_zone_name`)
- Fuente: navegador (`Intl.DateTimeFormat().resolvedOptions().timeZone`)
- Debe enviarse en formato IANA (ejemplo: `America/Lima`)
- Backend valida con regla Laravel `timezone`

---

## Contrato final de register (source of truth)

El frontend enviara al backend:  
`POST /api/v1/auth/register`

body:
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

validaciones backend (Laravel):
- `display_name`: `required|string|min:2|max:120`
- `email`: `required|email|max:255|unique:users,email`
- `password`: `required|string|min:8|confirmed`
- `locale`: `required|in:es,en`
- `time_zone_name`: `required|timezone`

efectos de negocio obligatorios:
- crear usuario
- crear `user_preferences` iniciales con `locale` y `time_zone_name`
- iniciar sesion (cookie auth activa)
- todo en transaccion DB

respuestas esperadas:

si todo esta correcto enviara (`201`):
```json
{
  "data": {
    "user": {
      "id": "usr_123",
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

sino (`422` validacion):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "El correo ya esta en uso."
    ],
    "time_zone_name": [
      "La zona horaria no es valida."
    ]
  }
}
```

sino (`500` error interno):
```json
{
  "message": "Register failed."
}
```

---

## Login

El frontend enviara al backend:  
`POST /api/v1/auth/login`

body:
```json
{
  "email": "anton@velor.app",
  "password": "secret12345"
}
```

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "user": {
      "id": "usr_123",
      "display_name": "Anton Rivera",
      "email": "anton@velor.app",
      "locale": "es"
    }
  }
}
```

sino (`401` credenciales invalidas):
```json
{
  "message": "Credenciales invalidas."
}
```

---

## Me (sesion actual)

El frontend enviara al backend:  
`GET /api/v1/auth/me`

si todo esta correcto enviara (`200`):
```json
{
  "data": {
    "id": "usr_123",
    "display_name": "Anton Rivera",
    "email": "anton@velor.app",
    "locale": "es"
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

## Logout

El frontend enviara al backend:  
`POST /api/v1/auth/logout`

si todo esta correcto enviara (`200`):
```json
{
  "message": "Logged out."
}
```

sino (`401`):
```json
{
  "message": "Unauthenticated."
}
```

---

## Google Auth

### Redirect

El frontend enviara al backend:  
`GET /api/v1/auth/google/redirect?intent=login`  
o  
`GET /api/v1/auth/google/redirect?intent=register`

resultado esperado:
- `302` hacia Google

### Callback

Google enviara al backend:  
`GET /api/v1/auth/google/callback`

resultado esperado:
- success: `302` -> `FRONTEND_URL/app`
- error: `302` -> `FRONTEND_URL/login?auth_error=google`

---

## Configuracion adicional obligatoria

### Frontend

- usar `credentials: 'include'` en requests
- antes de `POST /register`, `POST /login`, `POST /logout` llamar:
  - `GET /sanctum/csrf-cookie`
- en registro:
  - `locale` desde navegador normalizado a `es|en`
  - `time_zone_name` autodetectada y editable por usuario

### Backend Laravel

- modelo simple por usuario personal (sin multi-workspace)

`.env` minimo:
```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

SESSION_DRIVER=cookie
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost:5173

GOOGLE_CLIENT_ID=xxxx
GOOGLE_CLIENT_SECRET=xxxx
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/auth/google/callback
```

`config/cors.php`:
- permitir `http://localhost:5173`
- `supports_credentials = true`
