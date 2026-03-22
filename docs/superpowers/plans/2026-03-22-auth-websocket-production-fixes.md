# Auth & WebSocket Production Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix cascading 401 errors on auth/me, broadcasting/auth, and WebSocket subscriptions after registration on riserpos.app.

**Architecture:** Six targeted fixes: guard AuthProvider's clearAllAppState against initial load, invalidate auth/me query after login/registration, recreate Echo on token change, move ImportProgressSubscriber into DashboardLayout, fix nginx X-Forwarded-Proto, add mail env vars to Dokploy.

**Tech Stack:** React 19, TanStack Query 5, Zustand 5, Laravel Echo, Vitest, nginx

**Spec:** `docs/superpowers/specs/2026-03-22-auth-websocket-production-fixes.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `apps/web/src/features/auth/__tests__/AuthProvider.test.tsx` | Create | Tests for clearAllAppState guarding |
| `apps/web/src/features/auth/AuthProvider.tsx` | Modify | Guard clearAllAppState with wasAuthenticated ref |
| `apps/web/src/features/auth/RegisterPage.tsx` | Modify | Invalidate auth/me query after registration |
| `apps/web/src/features/auth/LoginPage.tsx` | Modify | Invalidate auth/me query after login |
| `apps/web/src/lib/__tests__/echo.test.ts` | Create | Tests for Echo lifecycle on token change |
| `apps/web/src/lib/echo.ts` | Modify | Store subscription to recreate Echo on token change |
| `apps/web/src/App.tsx` | Modify | Remove ImportProgressSubscriber |
| `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx` | Modify | Add ImportProgressSubscriber |
| `apps/web/docker/entrypoint.sh` | Modify | Fix X-Forwarded-Proto passthrough |

---

### Task 1: AuthProvider — guard clearAllAppState (test)

**Files:**
- Create: `apps/web/src/features/auth/__tests__/AuthProvider.test.tsx`

- [ ] **Step 1: Write failing tests for AuthProvider clearAllAppState guarding**

Create `apps/web/src/features/auth/__tests__/AuthProvider.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '../../../stores/authStore'

// Mock clearAllAppState — must use vi.hoisted for variables used in vi.mock factory
const { mockClearAllAppState } = vi.hoisted(() => ({
  mockClearAllAppState: vi.fn(),
}))

vi.mock('../../../lib/clearAppState', () => ({
  clearAllAppState: mockClearAllAppState,
}))

// Mock the API
const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('../../../lib/api', () => ({
  api: { get: mockApiGet },
}))

// Import AFTER mocks are set up
import { AuthProvider } from '../AuthProvider'

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
}

function renderWithProviders(ui: React.ReactElement, queryClient?: QueryClient) {
  const qc = queryClient ?? createTestQueryClient()
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>
  )
}

describe('AuthProvider', () => {
  beforeEach(() => {
    useAuthStore.getState().logout()
    vi.clearAllMocks()
  })

  it('does NOT call clearAllAppState on initial 401 (no prior auth)', async () => {
    mockApiGet.mockRejectedValue({ response: { status: 401 } })

    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    // Wait for query to settle
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/auth/me')
    })

    // Give effect time to run
    await waitFor(() => {
      expect(mockClearAllAppState).not.toHaveBeenCalled()
    })
  })

  it('sets isLoading to false on initial 401 (prevents infinite spinner)', async () => {
    mockApiGet.mockRejectedValue({ response: { status: 401 } })

    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    await waitFor(() => {
      expect(useAuthStore.getState().isLoading).toBe(false)
    })
  })

  it('DOES call clearAllAppState when auth/me fails after previously succeeding', async () => {
    const queryClient = createTestQueryClient()

    // First: auth/me succeeds
    mockApiGet.mockResolvedValueOnce({
      data: {
        data: {
          id: 'user-1',
          name: 'Test',
          email: 'test@test.com',
          tenantId: 'tenant-1',
          roles: ['admin'],
          emailVerifiedAt: null,
        },
      },
    })

    // Keep the component mounted — wasAuthenticated ref must persist
    renderWithProviders(
      <AuthProvider><div>child</div></AuthProvider>,
      queryClient,
    )

    // Wait for success — wasAuthenticated.current is now true
    await waitFor(() => {
      expect(useAuthStore.getState().user).not.toBeNull()
    })

    // Now simulate session expiry: auth/me will fail on next refetch
    mockApiGet.mockRejectedValue({ response: { status: 401 } })

    // Trigger a refetch within the same mounted component
    void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })

    await waitFor(() => {
      expect(mockClearAllAppState).toHaveBeenCalledWith(queryClient)
    })
  })

  it('updates user state when auth/me succeeds', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          id: 'user-1',
          name: 'Test User',
          email: 'test@test.com',
          tenantId: 'tenant-1',
          roles: ['admin'],
          emailVerifiedAt: '2026-01-01',
        },
      },
    })

    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    await waitFor(() => {
      const state = useAuthStore.getState()
      expect(state.user?.id).toBe('user-1')
      expect(state.user?.tenant_id).toBe('tenant-1')
      expect(state.isLoading).toBe(false)
    })
  })

  it('does NOT trigger clearAllAppState on transient refetch failure after setAuth', async () => {
    // Simulate: auth/me initially 401, then user registers (setAuth), then invalidateQueries
    // triggers refetch that also fails — clearAllAppState should NOT be called because
    // wasAuthenticated was never set by the query success path
    mockApiGet.mockRejectedValue({ response: { status: 401 } })

    const queryClient = createTestQueryClient()

    renderWithProviders(
      <AuthProvider><div>child</div></AuthProvider>,
      queryClient,
    )

    // Wait for initial 401 to settle
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })

    // Simulate registration setting auth (but query never succeeded)
    useAuthStore.getState().setAuth({
      id: 'user-1',
      name: 'Test',
      email: 'test@test.com',
      tenant_id: 'tenant-1',
      roles: ['admin'],
      email_verified_at: null,
    }, 'fake-token')

    // Trigger refetch (simulating invalidateQueries from RegisterPage)
    void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })

    // The refetch will also 401 (mockApiGet still rejects)
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(2)
    })

    // clearAllAppState should NEVER have been called
    expect(mockClearAllAppState).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/AuthProvider.test.tsx`
Expected: FAIL — AuthProvider currently calls clearAllAppState unconditionally on error.

---

### Task 2: AuthProvider — implement clearAllAppState guard

**Files:**
- Modify: `apps/web/src/features/auth/AuthProvider.tsx:1-74`

- [ ] **Step 3: Implement the wasAuthenticated ref guard**

Replace the entire `AuthProvider.tsx` content. Key changes:
- Add `useRef` import
- Add `wasAuthenticated` ref initialized to `false`
- Set `wasAuthenticated.current = true` only in the `data` success branch
- Only call `clearAllAppState` when `wasAuthenticated.current` is true
- Always call `setLoading(false)` on error (prevents infinite spinner)

```tsx
import { useEffect, useRef, type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { clearAllAppState } from '../../lib/clearAppState'

interface MeResponseUser {
  id: string
  name: string
  email: string
  tenantId: string
  roles: string[]
  emailVerifiedAt: string | null
}

interface MeResponse {
  data: MeResponseUser
}

interface AuthProviderProps {
  children: ReactNode
}

interface RequireAuthProps {
  children: ReactNode
}

/**
 * AuthProvider checks for existing session on mount
 * and maintains auth state throughout the app.
 *
 * Uses Bearer token auth via Authorization header.
 * On initial load with no token, auth/me returns 401 — this is expected
 * and does NOT trigger clearAllAppState. Only a session expiry (auth/me
 * fails after previously succeeding) triggers state cleanup.
 */
export function AuthProvider({ children }: AuthProviderProps) {
  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const setLoading = useAuthStore((state) => state.setLoading)
  const queryClient = useQueryClient()
  const wasAuthenticated = useRef(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const response = await api.get<MeResponse>('/auth/me')
      return response.data.data
    },
    retry: false,
    staleTime: 1000 * 60 * 5, // 5 minutes
    enabled: true,
  })

  useEffect(() => {
    if (isLoading) {
      setLoading(true)
    } else if (data) {
      wasAuthenticated.current = true
      const userData = {
        id: data.id,
        name: data.name,
        email: data.email,
        tenant_id: data.tenantId,
        roles: data.roles,
        email_verified_at: data.emailVerifiedAt,
      }
      setUser(userData)
    } else if (isError) {
      if (wasAuthenticated.current) {
        clearAllAppState(queryClient)
        wasAuthenticated.current = false
      }
      setLoading(false)
    }
  }, [data, isLoading, isError, setUser, setLoading, queryClient])

  if (isLoading && !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          <p className="text-gray-500">Loading...</p>
        </div>
      </div>
    )
  }

  return <>{children}</>
}

/**
 * RequireAuth wraps protected routes and redirects to login
 * if user is not authenticated
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const isLoading = useAuthStore((state) => state.isLoading)
  const location = useLocation()

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          <p className="text-gray-500">Loading...</p>
        </div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  return <>{children}</>
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/AuthProvider.test.tsx`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/auth/__tests__/AuthProvider.test.tsx apps/web/src/features/auth/AuthProvider.tsx
git commit -m "fix(auth): guard clearAllAppState against initial 401 on page load

AuthProvider now tracks whether auth/me previously succeeded via a
wasAuthenticated ref. clearAllAppState only fires on session expiry
(authenticated → unauthenticated), not on initial load when there
was never a session. Also ensures setLoading(false) on initial error
to prevent infinite loading spinner."
```

---

### Task 3: Invalidate auth/me query after login/registration

**Files:**
- Modify: `apps/web/src/features/auth/RegisterPage.tsx:3,39,79-101`
- Modify: `apps/web/src/features/auth/LoginPage.tsx:3,41,51-80`

- [ ] **Step 6: Verify existing auth tests pass as baseline**

Run: `cd apps/web && pnpm vitest run src/features/auth/auth.test.tsx`
Expected: ALL PASS — this confirms existing login/register tests work before our changes.

- [ ] **Step 7: Add useQueryClient and invalidateQueries to RegisterPage**

In `apps/web/src/features/auth/RegisterPage.tsx`:

1. Add `useQueryClient` to the import on line 3:
```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query'
```

2. Add `const queryClient = useQueryClient()` after line 40 (the `setAuth` line):
```typescript
const setAuth = useAuthStore((state) => state.setAuth)
const queryClient = useQueryClient()
```

3. Replace the `onSuccess` handler (lines 86-97) with:
```typescript
    onSuccess: (data) => {
      const user = {
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        tenant_id: data.user.tenantId,
        roles: data.user.roles,
        email_verified_at: null,
      }
      setAuth(user, data.token)
      void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
      void navigate('/', { replace: true })
    },
```

- [ ] **Step 8: Add useQueryClient and invalidateQueries to LoginPage**

In `apps/web/src/features/auth/LoginPage.tsx`:

1. Add `useQueryClient` to the import on line 3:
```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query'
```

2. Add `const queryClient = useQueryClient()` after line 42 (the `setAuth` line):
```typescript
const setAuth = useAuthStore((state) => state.setAuth)
const queryClient = useQueryClient()
```

3. Replace the `onSuccess` handler (lines 59-73) with:
```typescript
    onSuccess: (data) => {
      const user = {
        id: data.user.id,
        name: data.user.name,
        email: data.user.email,
        tenant_id: data.user.tenantId,
        roles: data.user.roles,
        email_verified_at: data.user.emailVerifiedAt,
      }
      setAuth(user, data.token)
      void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
      const locationState = location.state as { from?: { pathname: string } } | null
      const from = locationState?.from?.pathname ?? '/'
      void navigate(from, { replace: true })
    },
```

- [ ] **Step 9: Run existing auth tests to ensure no regressions**

Run: `cd apps/web && pnpm vitest run src/features/auth/`
Expected: ALL PASS

- [ ] **Step 10: Commit**

```bash
git add apps/web/src/features/auth/RegisterPage.tsx apps/web/src/features/auth/LoginPage.tsx
git commit -m "fix(auth): invalidate auth/me query after login and registration

After setAuth stores the token, invalidateQueries triggers AuthProvider
to refetch auth/me with the new Bearer token. Navigation proceeds
immediately via Zustand (source of truth for RequireAuth gate)."
```

---

### Task 4: Echo lifecycle — recreate on token change (test)

**Files:**
- Create: `apps/web/src/lib/__tests__/echo.test.ts`

- [ ] **Step 11: Write failing tests for Echo token lifecycle**

Create `apps/web/src/lib/__tests__/echo.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useAuthStore } from '../../stores/authStore'

// Mock laravel-echo and pusher-js
vi.mock('laravel-echo', () => {
  const MockEcho = vi.fn().mockImplementation(() => ({
    disconnect: vi.fn(),
  }))
  return { default: MockEcho }
})

vi.mock('pusher-js', () => ({
  default: vi.fn(),
}))

// Import after mocks
import { getEcho, disconnectEcho } from '../echo'

describe('Echo lifecycle', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Reset global Echo BEFORE logout to avoid the store subscription
    // calling disconnectEcho on a stale Echo instance from a previous test
    window.Echo = null
    useAuthStore.getState().logout()
  })

  it('creates Echo instance with current token', () => {
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'my-token')

    const echo = getEcho()
    expect(echo).toBeDefined()
    expect(window.Echo).toBe(echo)
  })

  it('disconnects Echo when token changes from null to string', () => {
    // Create Echo with no token
    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    // Simulate login — token changes
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'new-token')

    // The store subscription should have disconnected the old Echo
    expect(window.Echo).toBeNull()
    expect(disconnectSpy).toHaveBeenCalled()
  })

  it('disconnects Echo when token changes from string to null (logout)', () => {
    // Set token first
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'my-token')

    // Create Echo with token
    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    // Simulate logout
    useAuthStore.getState().logout()

    expect(window.Echo).toBeNull()
    expect(disconnectSpy).toHaveBeenCalled()
  })

  it('does NOT disconnect Echo when same token is set', () => {
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'same-token')

    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    // Set same auth again with same token
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'same-token')

    expect(disconnectSpy).not.toHaveBeenCalled()
    expect(window.Echo).toBe(echo)
  })

  it('creates new Echo with correct token after disconnect', () => {
    // Start with no token
    getEcho()

    // Login — triggers disconnect via subscription
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'new-token')

    // Get Echo again — should create a fresh instance
    const newEcho = getEcho()
    expect(newEcho).toBeDefined()
    expect(window.Echo).toBe(newEcho)
  })
})
```

- [ ] **Step 12: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/lib/__tests__/echo.test.ts`
Expected: FAIL — echo.ts has no store subscription yet.

---

### Task 5: Echo lifecycle — implement store subscription

**Files:**
- Modify: `apps/web/src/lib/echo.ts`

- [ ] **Step 13: Add store subscription to echo.ts**

Replace the entire `apps/web/src/lib/echo.ts` content:

```typescript
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '../stores/authStore'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo: Echo<'reverb'> | null
  }
}

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher

/**
 * Laravel Echo configuration for WebSocket connections.
 *
 * Connects through same-origin nginx proxy (/app/ and /broadcasting/auth)
 * so no separate WS host/port env vars are needed in production.
 * Authentication uses Bearer token to work behind reverse proxies.
 */
export function createEchoInstance(): Echo<'reverb'> {
  const token = useAuthStore.getState().token

  return new Echo({
    broadcaster: 'reverb',
    key: import.meta.env['VITE_REVERB_APP_KEY'] || 'local_key',
    wsHost: window.location.hostname,
    wsPort: window.location.port ? parseInt(window.location.port) : 80,
    wssPort: window.location.port ? parseInt(window.location.port) : 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    },
  })
}

/**
 * Get or create the global Echo instance.
 * Lazily initializes on first access.
 */
export function getEcho(): Echo<'reverb'> {
  if (!window.Echo) {
    window.Echo = createEchoInstance()
  }
  return window.Echo
}

/**
 * Disconnect and clean up the Echo instance.
 * Useful for logout or cleanup scenarios.
 */
export function disconnectEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect()
    window.Echo = null
  }
}

// Subscribe to auth store — recreate Echo when token changes.
// Initialize from current store state to avoid a spurious disconnectEcho on hydration.
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

- [ ] **Step 14: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/lib/__tests__/echo.test.ts`
Expected: ALL PASS

- [ ] **Step 15: Commit**

```bash
git add apps/web/src/lib/__tests__/echo.test.ts apps/web/src/lib/echo.ts
git commit -m "fix(echo): recreate Echo instance when auth token changes

Add a Zustand store subscription that watches for token changes.
When the token changes (login, logout, registration), the existing
Echo singleton is disconnected and nullified. The next getEcho() call
creates a fresh instance with the current token."
```

---

### Task 6: Move ImportProgressSubscriber into DashboardLayout

**Files:**
- Modify: `apps/web/src/App.tsx:9-10,22-25,46`
- Modify: `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:1-49`

- [ ] **Step 16: Remove ImportProgressSubscriber from App.tsx**

In `apps/web/src/App.tsx`:

1. Remove the two imports (lines 9-10):
```typescript
import { useImportProgress } from './features/import/hooks/useImportProgress'
import { GlobalImportProgress } from './components/organisms/GlobalImportProgress/GlobalImportProgress'
```

2. Remove the `ImportProgressSubscriber` function definition (lines 22-25):
```typescript
function ImportProgressSubscriber() {
  useImportProgress()
  return <GlobalImportProgress />
}
```

3. Remove `<ImportProgressSubscriber />` from the JSX (line 46).

- [ ] **Step 17: Add ImportProgressSubscriber to DashboardLayout**

In `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx`:

1. Add imports after line 8:
```typescript
import { useImportProgress } from '../../../features/import/hooks/useImportProgress'
import { GlobalImportProgress } from '../../organisms/GlobalImportProgress/GlobalImportProgress'
```

2. Add the component definition before `DashboardLayout`:
```typescript
function ImportProgressSubscriber() {
  useImportProgress()
  return <GlobalImportProgress />
}
```

3. Add `<ImportProgressSubscriber />` as a child of `WebSocketReconnectProvider`, before `<main>` (after line 39):
```tsx
        <WebSocketReconnectProvider>
          <ImportProgressSubscriber />
          <main className="flex flex-1 flex-col overflow-y-auto p-4 sm:p-6">
```

- [ ] **Step 18: Run typecheck and existing tests**

Run: `cd apps/web && pnpm typecheck && pnpm vitest run src/features/auth/`
Expected: ALL PASS, no type errors

- [ ] **Step 19: Commit**

```bash
git add apps/web/src/App.tsx apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx
git commit -m "fix(app): move ImportProgressSubscriber into DashboardLayout

Prevents premature Echo creation on public pages (login, register).
ImportProgressSubscriber now renders inside RequireAuth > Layout >
DashboardLayout > WebSocketReconnectProvider, ensuring it only runs
for authenticated users with a valid token."
```

---

### Task 7: Fix nginx X-Forwarded-Proto passthrough

**Files:**
- Modify: `apps/web/docker/entrypoint.sh:38-169`

- [ ] **Step 20: Add map directive and update proxy locations**

In `apps/web/docker/entrypoint.sh`, replace the heredoc content (lines 38-169). The key changes:

1. Add a `map` directive **before** the `server {` block (nginx requires `map` at http level):

After line 42 (`# Nginx Server Configuration`), before `server {`:
```nginx
# Pass through the original protocol from the upstream reverse proxy (Traefik).
# Defaults to https when accessed directly (no X-Forwarded-Proto header).
map \$http_x_forwarded_proto \$forwarded_proto {
    default \$http_x_forwarded_proto;
    ''      'https';
}
```

2. In all four proxy locations, replace `\$scheme` with `\$forwarded_proto`:

- Line 93 (`/api/`): `proxy_set_header X-Forwarded-Proto \$forwarded_proto;`
- Line 119 (`/sanctum/`): `proxy_set_header X-Forwarded-Proto \$forwarded_proto;`
- Line 135 (`/app/`): `proxy_set_header X-Forwarded-Proto \$forwarded_proto;`
- Line 148 (`/broadcasting/`): `proxy_set_header X-Forwarded-Proto \$forwarded_proto;`

- [ ] **Step 21: Commit**

```bash
git add apps/web/docker/entrypoint.sh
git commit -m "fix(nginx): pass through X-Forwarded-Proto from Traefik

The web nginx proxy was setting X-Forwarded-Proto to \$scheme (http)
instead of passing through Traefik's original https value. This caused
the API to think requests were HTTP, affecting cookie Secure flags.
Uses a map directive to default to https when the header is absent."
```

---

### Task 8: Add mail env vars to Dokploy and run full verification

**Files:**
- No code files — Dokploy MCP operation

- [ ] **Step 22: Add mail env vars to Dokploy API service**

Use Dokploy MCP `application-saveEnvironment` to append these env vars to the API service (`toYham9mvlkPMRcAHzx7D`):

```
MAIL_MAILER=resend
RESEND_API_KEY=<read from .env file at repo root>
MAIL_FROM_ADDRESS=noreply@riserpos.app
MAIL_FROM_NAME=RiserPOS
```

**Important**: Read the current env first, append these 4 lines, then save. Do NOT replace the existing env vars.

- [ ] **Step 23: Run full test suite**

Run: `cd apps/web && pnpm vitest run`
Expected: ALL PASS

- [ ] **Step 24: Run typecheck and lint**

Run: `cd apps/web && pnpm typecheck && pnpm lint`
Expected: No errors

- [ ] **Step 25: Final commit (if any lint/type fixes needed)**

Only if Step 23 required fixes. Otherwise skip.

---

## Deployment Checklist

After all tasks are complete:

1. Push to `main` (triggers auto-deploy of all services via Dokploy)
2. Redeploy API service manually if env var changes don't trigger auto-deploy
3. Smoke test: register a new account on riserpos.app → verify dashboard loads without 401 errors → verify WebSocket subscriptions connect
