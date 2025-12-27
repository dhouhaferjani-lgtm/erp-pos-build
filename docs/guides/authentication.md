# Authentication Guide

> Complete documentation for AutoERP's authentication system using Laravel Sanctum with httpOnly cookie-based SPA authentication.

---

## Overview

AutoERP uses **Laravel Sanctum** for authentication with **session-based cookie authentication** for the SPA frontend. This approach provides:

- **Security**: Authentication tokens stored in httpOnly cookies (not accessible to JavaScript)
- **CSRF Protection**: Automatic CSRF token handling via `XSRF-TOKEN` cookie
- **Session Management**: Server-side session with configurable lifetime
- **No localStorage Tokens**: Eliminates XSS token theft risk

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        FRONTEND (React SPA)                         │
│                                                                     │
│  1. Fetch CSRF cookie from /sanctum/csrf-cookie                     │
│  2. Send credentials to /api/v1/auth/login                          │
│  3. Subsequent requests include cookies automatically               │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        BACKEND (Laravel)                            │
│                                                                     │
│  Middleware Stack (in order):                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 1. EnsureFrontendRequestsAreStateful (Sanctum)              │   │
│  │    - Checks Origin/Referer header against stateful domains  │   │
│  │    - Applies session middleware for matching requests       │   │
│  ├─────────────────────────────────────────────────────────────┤   │
│  │ 2. EncryptCookies                                           │   │
│  ├─────────────────────────────────────────────────────────────┤   │
│  │ 3. AddQueuedCookiesToResponse                               │   │
│  ├─────────────────────────────────────────────────────────────┤   │
│  │ 4. StartSession                                             │   │
│  ├─────────────────────────────────────────────────────────────┤   │
│  │ 5. ValidateCsrfToken                                        │   │
│  ├─────────────────────────────────────────────────────────────┤   │
│  │ 6. auth:sanctum (authenticates via session OR token)        │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Authentication Flow

### 1. Login Flow (SPA)

```
Browser                           API Server
   │                                   │
   │  GET /sanctum/csrf-cookie         │
   │──────────────────────────────────▶│
   │                                   │  Sets XSRF-TOKEN cookie
   │  Set-Cookie: XSRF-TOKEN=...       │  (readable by JS)
   │◀──────────────────────────────────│
   │                                   │
   │  POST /api/v1/auth/login          │
   │  Headers:                         │
   │    X-XSRF-TOKEN: <from cookie>    │
   │    Origin: http://localhost:5173  │
   │  Body: {email, password}          │
   │──────────────────────────────────▶│
   │                                   │  1. Auth::attempt() validates
   │                                   │  2. Session created & regenerated
   │                                   │  3. Token created (for mobile)
   │  Set-Cookie: autoerp-session=...  │
   │  {user, token, ...}               │
   │◀──────────────────────────────────│
   │                                   │
```

### 2. Authenticated Request Flow

```
Browser                           API Server
   │                                   │
   │  GET /api/v1/partners             │
   │  Headers:                         │
   │    Origin: http://localhost:5173  │
   │  Cookies:                         │
   │    autoerp-session=...            │
   │    XSRF-TOKEN=...                 │
   │──────────────────────────────────▶│
   │                                   │  1. EnsureFrontendRequestsAreStateful
   │                                   │     checks Origin matches stateful domain
   │                                   │  2. Session middleware applies
   │                                   │  3. auth:sanctum authenticates via session
   │                                   │
   │  {data: [...partners]}            │
   │◀──────────────────────────────────│
```

### 3. Logout Flow

```
Browser                           API Server
   │                                   │
   │  POST /api/v1/auth/logout         │
   │  Cookies: autoerp-session=...     │
   │──────────────────────────────────▶│
   │                                   │  1. Delete current token
   │                                   │  2. Auth::guard('web')->logout()
   │                                   │  3. Session invalidated & regenerated
   │  Set-Cookie: autoerp-session=""   │
   │◀──────────────────────────────────│
```

---

## Backend Configuration

### Sanctum Configuration (`config/sanctum.php`)

```php
return [
    // Domains that receive stateful cookie authentication
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,127.0.0.1:5173,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    // Guards to check for authentication
    'guard' => ['web'],

    // Token expiration (30 days in minutes) - for mobile/API clients
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 43200),

    // Token prefix for leak detection
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'aerp_'),
];
```

### CORS Configuration (`config/cors.php`)

```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173')),

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'Accept',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Company-Id',
        'X-Location-Id',
    ],

    'supports_credentials' => true,  // Required for cookies
];
```

### Session Configuration (`config/session.php`)

```php
return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),  // 2 hours
    'cookie' => env('SESSION_COOKIE', 'autoerp-session'),
    'http_only' => env('SESSION_HTTP_ONLY', true),  // Not accessible to JS
    'same_site' => env('SESSION_SAME_SITE', 'lax'),  // Allow cross-site with navigation
];
```

### Middleware Configuration (`bootstrap/app.php`)

```php
$middleware->prependToGroup('api', [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);

$middleware->appendToGroup('api', [
    SecurityHeaders::class,
    SetLocale::class,
    CompanyContextMiddleware::class,
]);
```

### Route Configuration (`app/Modules/Identity/routes.php`)

**Critical**: Auth routes MUST include the `api` middleware group:

```php
// Public auth routes
Route::prefix('api/v1/auth')->middleware('api')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
    // ...
});

// Protected routes
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    Route::get('user/companies', [UserController::class, 'companies']);
    // ...
});
```

---

## Frontend Implementation

### API Client (`lib/api.ts`)

```typescript
import axios from 'axios'

// Create API client with cookie-based auth
const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,  // Required for cookies
  xsrfCookieName: 'XSRF-TOKEN',  // CSRF cookie name
  xsrfHeaderName: 'X-XSRF-TOKEN',  // CSRF header name
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Fetch CSRF cookie before auth requests
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', {
    withCredentials: true,
  })
}
```

### Auth Store (`stores/authStore.ts`)

```typescript
// SECURITY: Token is NOT stored - authentication relies on httpOnly cookies
// Only user info is persisted for UI display
export const useAuthStore = create<AuthStore>()(
  persist(
    (set) => ({
      user: null,
      isAuthenticated: false,
      // ...
    }),
    {
      name: 'autoerp-auth',
      // Only persist user info - NOT authentication state
      partialize: (state) => ({ user: state.user }),
    }
  )
)
```

### Login Hook Example

```typescript
export function useLogin() {
  const { setAuth } = useAuthStore()

  return useMutation({
    mutationFn: async (credentials: LoginCredentials) => {
      // 1. Fetch CSRF cookie first
      await ensureCsrfCookie()

      // 2. Login - session cookie set automatically
      const response = await api.post('/auth/login', credentials)
      return response.data.data
    },
    onSuccess: (data) => {
      setAuth(data.user)
    },
  })
}
```

---

## API Endpoints

### Public Endpoints

| Method | Endpoint | Description | Rate Limit |
|--------|----------|-------------|------------|
| POST | `/api/v1/auth/login` | Authenticate user | 5/min |
| POST | `/api/v1/auth/register` | Register new user | 5/15min |
| POST | `/api/v1/auth/verify-email` | Verify email token | Standard |

### Protected Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/auth/me` | Get current user |
| POST | `/api/v1/auth/logout` | Logout current session |
| POST | `/api/v1/auth/logout-all` | Logout all devices |
| POST | `/api/v1/auth/resend-verification` | Resend verification email |
| GET | `/api/v1/user/companies` | Get user's companies |

---

## Cookies

| Cookie Name | Purpose | HttpOnly | SameSite |
|-------------|---------|----------|----------|
| `autoerp-session` | Session identifier | Yes | Lax |
| `XSRF-TOKEN` | CSRF protection | No (JS must read) | Lax |

---

## Troubleshooting

### Common Issues

#### 1. "Session store not set on request"

**Cause**: Routes not using `api` middleware group.

**Solution**: Ensure auth routes include `middleware('api')`:
```php
Route::prefix('api/v1/auth')->middleware('api')->group(function () {
    // ...
});
```

#### 2. 401 Unauthorized after successful login

**Cause**: Session not established because `Auth::attempt()` not called.

**Solution**: Use `Auth::attempt()` in login controller:
```php
if (! Auth::attempt(['email' => $email, 'password' => $password])) {
    throw ValidationException::withMessages([...]);
}
$request->session()->regenerate();
```

#### 3. CSRF Token Mismatch (419)

**Cause**: Missing or expired CSRF token.

**Solution**: Fetch CSRF cookie before login:
```typescript
await ensureCsrfCookie()
await api.post('/auth/login', credentials)
```

#### 4. Session not persisting in development

**Cause**: Origin header not matching stateful domains.

**Solution**: Ensure `SANCTUM_STATEFUL_DOMAINS` includes your dev URL:
```env
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
```

#### 5. CORS errors

**Cause**: Frontend URL not in allowed origins.

**Solution**: Update `CORS_ALLOWED_ORIGINS`:
```env
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

---

## Security Considerations

### Token Storage

- **Session Cookie**: httpOnly, not accessible to JavaScript
- **CSRF Token**: Accessible to JavaScript (required for CSRF protection)
- **No localStorage**: Tokens never stored in localStorage

### CSRF Protection

- All mutating requests (POST, PATCH, DELETE) require `X-XSRF-TOKEN` header
- Token automatically extracted from `XSRF-TOKEN` cookie by axios

### Session Fixation

- Session ID regenerated after login (`$request->session()->regenerate()`)
- Session invalidated and regenerated on logout

### Rate Limiting

- Login: 5 attempts per minute per email
- Registration: 5 attempts per 15 minutes per IP
- Email verification: Throttled per user

---

## Testing Authentication

### Manual Testing with curl

```bash
#!/bin/bash
# 1. Get CSRF cookie
curl -c cookies.txt -b cookies.txt http://127.0.0.1:8000/sanctum/csrf-cookie

# 2. Extract XSRF token
XSRF=$(grep XSRF-TOKEN cookies.txt | awk '{print $7}')
XSRF_DECODED=$(python3 -c "import urllib.parse; print(urllib.parse.unquote('$XSRF'))")

# 3. Login
curl -c cookies.txt -b cookies.txt \
  -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_DECODED" \
  -H "Origin: http://localhost:5173" \
  -d '{"email":"test@example.com","password":"password"}'

# 4. Access protected endpoint
curl -c cookies.txt -b cookies.txt \
  http://127.0.0.1:8000/api/v1/auth/me \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_DECODED" \
  -H "Origin: http://localhost:5173"
```

---

## Related Files

| File | Purpose |
|------|---------|
| `app/Modules/Identity/Presentation/Controllers/AuthController.php` | Auth endpoints |
| `app/Modules/Identity/routes.php` | Auth route definitions |
| `config/sanctum.php` | Sanctum configuration |
| `config/cors.php` | CORS configuration |
| `config/session.php` | Session configuration |
| `bootstrap/app.php` | Middleware configuration |
| `apps/web/src/lib/api.ts` | Frontend API client |
| `apps/web/src/stores/authStore.ts` | Frontend auth state |

---

*Last Updated: December 2025*
