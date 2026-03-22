# Auth & WebSocket Production Fixes

> Fix cascading 401 errors on `auth/me`, `broadcasting/auth`, and WebSocket subscription failures after registration on riserpos.app.

## Problem Statement

After a user registers on the production deployment (riserpos.app), the following errors appear in the console:

1. `api/v1/auth/me` → 401
2. `broadcasting/auth` → 401
3. WebSocket subscription errors (Import channel)

Root causes identified:

- **AuthProvider race condition**: `clearAllAppState()` fires on the initial `auth/me` 401 (before any user exists), which calls `queryClient.clear()` and `logout()`. This can race with `setAuth()` after registration, clearing the just-stored token.
- **Stale React Query cache**: The registration mutation does not invalidate the `['auth', 'me']` query. After `setAuth()` and navigation, the AuthProvider's query stays in error state — it never refetches with the new token.
- **Echo singleton with stale token**: `createEchoInstance()` captures `useAuthStore.getState().token` once at creation time. The singleton is never recreated when the token changes. If Echo is created before authentication, all private channel subscriptions send `broadcasting/auth` without an Authorization header.
- **Premature Echo creation**: `<ImportProgressSubscriber />` is rendered at the App.tsx top level (outside `RequireAuth`), triggering Echo instantiation on public pages like `/register`.
- **X-Forwarded-Proto mismatch**: The web nginx proxy sets `X-Forwarded-Proto $scheme`, but `$scheme` is `http` (Traefik terminates TLS). The API receives `X-Forwarded-Proto: http`, causing incorrect cookie Secure flags and URL generation.
- **Missing mail env vars**: `MAIL_MAILER`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` are configured locally but not in the Dokploy production environment.

## Design

### Fix 1: AuthProvider — guard clearAllAppState against initial load

**File**: `apps/web/src/features/auth/AuthProvider.tsx`

Replace the unconditional `clearAllAppState()` call with a guarded version that only fires when transitioning from authenticated → unauthenticated (session expiry), not on initial page load when there was never a session.

**Approach**: Use a `useRef` to track whether the user was previously authenticated. Only call `clearAllAppState` when `wasAuthenticated.current === true && isError === true`.

```typescript
const wasAuthenticated = useRef(false)

useEffect(() => {
  if (isLoading) {
    setLoading(true)
  } else if (data) {
    wasAuthenticated.current = true
    // ... set user
  } else if (isError) {
    if (wasAuthenticated.current) {
      clearAllAppState(queryClient)
      wasAuthenticated.current = false
    }
    setLoading(false)
  }
}, [data, isLoading, isError, setUser, setLoading, queryClient])
```

**Why not just remove clearAllAppState?** It serves a real purpose: when a session expires mid-use, stale data from the previous session must be purged. The fix is to only invoke it when there was a session to expire.

**Important: `wasAuthenticated` is set from the React Query success path only** (when `data` is truthy), not from the Zustand store. This ensures that if `invalidateQueries` (Fix 2) triggers a refetch that fails due to a transient network error, `clearAllAppState` is NOT called — because `wasAuthenticated` was never set to true by the query layer.

**Test plan** (Vitest, component test):
- Test: AuthProvider does NOT call clearAllAppState on initial 401 (no prior auth)
- Test: AuthProvider DOES call clearAllAppState when auth/me fails after previously succeeding
- Test: AuthProvider updates user state when auth/me succeeds after token is set
- Test: AuthProvider sets isLoading to false on initial 401 (prevents infinite spinner)
- Test: Transient auth/me failure after registration (wasAuthenticated never set by query) does NOT trigger clearAllAppState

### Fix 2: Invalidate auth/me query after registration

**File**: `apps/web/src/features/auth/RegisterPage.tsx`

In the registration mutation's `onSuccess`, invalidate the `['auth', 'me']` query so AuthProvider refetches with the new Bearer token. Note: `RegisterPage.tsx` does not currently use `useQueryClient()` — it must be added.

```typescript
const queryClient = useQueryClient()  // add import + hook

onSuccess: (data) => {
  const user = { /* ... existing mapping ... */ }
  setAuth(user, data.token)
  queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
  void navigate('/', { replace: true })
}
```

`invalidateQueries` is asynchronous but navigation proceeds immediately via `void navigate(...)`. This is intentional — the Zustand store (`isAuthenticated: true` from `setAuth`) is the source of truth for the `RequireAuth` gate, not the React Query cache. The query refetch happens in the background and AuthProvider picks up the result when it resolves.

Also apply the same fix to `LoginPage.tsx` for consistency.

**Test plan** (Vitest, component test):
- Test: Registration success invalidates the auth/me query
- Test: Login success invalidates the auth/me query

### Fix 3: Echo lifecycle — recreate on token change

**File**: `apps/web/src/lib/echo.ts`

Add a Zustand store subscription that watches for token changes. When the token changes, disconnect the existing Echo instance and nullify `window.Echo`. The next call to `getEcho()` creates a fresh instance with the current token.

```typescript
// Subscribe to auth store — recreate Echo when token changes
// Initialize from current store state to avoid a spurious disconnectEcho on hydration
let previousToken: string | null = useAuthStore.getState().token
useAuthStore.subscribe((state) => {
  const currentToken = state.token
  if (currentToken !== previousToken) {
    previousToken = currentToken
    if (window.Echo) {
      disconnectEcho()
    }
  }
})
```

This is a module-level subscription (runs once when the module is imported). It ensures:
- After login/registration: old null-token Echo is destroyed, next access creates authenticated Echo
- After logout: authenticated Echo is destroyed, preventing stale subscriptions

**Test plan** (Vitest, unit test):
- Test: Token change from null to string triggers disconnectEcho
- Test: Token change from string to null triggers disconnectEcho
- Test: Same token value does not trigger disconnectEcho
- Test: getEcho() after token change creates new instance with correct token

### Fix 4: Move ImportProgressSubscriber inside DashboardLayout

**Files**: `apps/web/src/App.tsx`, `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx`

Move `<ImportProgressSubscriber />` (and its `<GlobalImportProgress />` rendering) from the top-level `App` component into `DashboardLayout`. This component already lives inside `RequireAuth > Layout` in the route tree (`routes/index.tsx:404`) and contains `WebSocketReconnectProvider`, making it the natural home for WebSocket-dependent components.

**Current** (App.tsx):
```tsx
<AuthProvider>
  <CompanyProvider>
    <CompanyConfigProvider>
      <LocationProvider>
        <AppRoutes />
        <ImportProgressSubscriber />  {/* runs on ALL pages */}
      </LocationProvider>
    </CompanyConfigProvider>
  </CompanyProvider>
</AuthProvider>
```

**After** (App.tsx): Remove `<ImportProgressSubscriber />` and its import.

**After** (DashboardLayout.tsx): Add `<ImportProgressSubscriber />` as a child of `WebSocketReconnectProvider` (so it benefits from reconnection logic), before the `<main>` element.

**Dependency with Fix 3**: The stale Echo reference in `useWebSocketConnection` (used by `useImportProgress`) is resolved by the component unmount/remount lifecycle. When the user logs out, `RequireAuth` unmounts `DashboardLayout` (and `ImportProgressSubscriber`). When they log back in, the component remounts, calling `getEcho()` which creates a fresh Echo instance with the current token (Fix 3 ensures the old one was destroyed). This is why Fix 3 and Fix 4 work together — Fix 3 destroys the stale singleton, Fix 4 ensures the component lifecycle triggers a fresh creation.

**Test plan** (Vitest, component test with `MemoryRouter`):
- Test: ImportProgressSubscriber is not rendered when route is `/login` (unauthenticated)
- Test: ImportProgressSubscriber is rendered inside DashboardLayout on authenticated routes

### Fix 5: Web nginx X-Forwarded-Proto fix

**File**: `apps/web/docker/entrypoint.sh`

In all proxy locations (`/api/`, `/sanctum/`, `/broadcasting/`), change:
```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```
to:
```nginx
proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
```

This passes through the original protocol from Traefik (which sets `X-Forwarded-Proto: https` after TLS termination) instead of using the internal HTTP scheme.

**Fallback**: Use a `map` directive to default to `https` when the header is absent (e.g., direct access without Traefik):

```nginx
map $http_x_forwarded_proto $forwarded_proto {
    default $http_x_forwarded_proto;
    ''      'https';
}
```

The `map` directive must be placed **outside** the `server {}` block (nginx does not allow `map` inside `server`). In the generated heredoc in `entrypoint.sh`, add it before the `server {` line. Then use `$forwarded_proto` in all four proxy locations (`/api/`, `/sanctum/`, `/app/`, `/broadcasting/`). This is safer than relying on implicit behavior from `TRUSTED_PROXIES` + `APP_URL`.

**Test plan**: Manual verification — after deployment, check that `$request->secure()` returns true in Laravel and that cookies have the Secure flag set.

### Fix 6: Add mail env vars to Dokploy production

**Service**: ERP Production API (`toYham9mvlkPMRcAHzx7D`)

Add the following environment variables via Dokploy MCP:
- `MAIL_MAILER=resend`
- `RESEND_API_KEY=<from local .env>`
- `MAIL_FROM_ADDRESS=noreply@riserpos.app`
- `MAIL_FROM_NAME=RiserPOS`

No code change required. This enables email sending in production for when email verification is re-enabled.

**Test plan**: After adding env vars and redeploying, verify by triggering a test email (e.g., password reset) or checking Laravel logs for mail dispatch.

## Files Changed

| File | Change |
|------|--------|
| `apps/web/src/features/auth/AuthProvider.tsx` | Guard clearAllAppState with wasAuthenticated ref; setLoading(false) on initial error |
| `apps/web/src/features/auth/RegisterPage.tsx` | Add queryClient.invalidateQueries after setAuth |
| `apps/web/src/features/auth/LoginPage.tsx` | Add queryClient.invalidateQueries after setAuth |
| `apps/web/src/lib/echo.ts` | Add store subscription to recreate Echo on token change |
| `apps/web/src/App.tsx` | Remove ImportProgressSubscriber from top level |
| `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx` | Add ImportProgressSubscriber inside authenticated layout |
| `apps/web/docker/entrypoint.sh` | Fix X-Forwarded-Proto to pass through original value |
| Dokploy API env | Add MAIL_MAILER, RESEND_API_KEY, MAIL_FROM_ADDRESS, MAIL_FROM_NAME |

## Test Files

| Test File | Coverage |
|-----------|----------|
| `apps/web/src/features/auth/__tests__/AuthProvider.test.tsx` | Fix 1: clearAllAppState guarding |
| `apps/web/src/features/auth/__tests__/RegisterPage.test.tsx` | Fix 2: query invalidation on registration |
| `apps/web/src/features/auth/__tests__/LoginPage.test.tsx` | Fix 2: query invalidation on login |
| `apps/web/src/lib/__tests__/echo.test.ts` | Fix 3: Echo lifecycle on token change |
| `apps/web/src/__tests__/App.test.tsx` | Fix 4: ImportProgressSubscriber placement |

## Out of Scope

- Email verification gate re-enablement (separate task, depends on Resend domain verification)
- Full AuthProvider/EchoProvider structural refactor (Approach B — future improvement)
- Sanctum stateful domain behavior audit (works correctly with Bearer token fallback)

## Deployment Order

1. Deploy code changes (Fixes 1-5) — single commit
2. Add mail env vars via Dokploy MCP (Fix 6)
3. Redeploy API service to pick up new env vars
4. Manual smoke test: register → dashboard → verify WebSocket subscriptions work
