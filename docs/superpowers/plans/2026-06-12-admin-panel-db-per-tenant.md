# Super-Admin Panel under DB-per-Tenant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the super-admin panel fully functional on Bearer-only db-per-tenant deployments: SPA Bearer-token auth fallback + every admin endpoint either central-safe or executing tenant-DB queries inside `$tenant->run()`.

**Architecture:** Frontend gains a memory-only Bearer token (Zustand store, never persisted) attached by an axios interceptor, with 401 → `/admin/login`. Backend fixes split into: (1) topology corrections — platform-billing tables move from tenant migrations to central, four billing models + `AdminAuditLog` pinned to the central connection; (2) cross-context query fixes — dashboard/tenant-detail/plan-usage/user-directory/token-revocation queries that hit tenant tables now run per-tenant via `$tenant->run()`, keeping their `tenant_id` where-clauses so they are correct in BOTH prod (per-tenant DB) and the shared-schema test env.

**Tech Stack:** Laravel 12 + Stancl tenancy (db-per-tenant), Sanctum `sanctum-admin` guard, React 19 + Zustand 5 + TanStack Query 5, Vitest, PHPUnit (SQLite shared schema, `TENANCY_DB_PER_TENANT=false` forced).

---

## Verified facts (do not re-derive)

- **Central DB tables:** tenants, domains, plans, tenant_subscriptions, super_admins, central_identities, admin_audit_logs, personal_access_tokens (+ failed_jobs/jobs/cache). Everything else is per-tenant.
- **Broken admin surface (confirmed by code read 2026-06-12):**
  - `SuperAdminController::dashboard()` lines 40–41: `DB::table('users')->count()`, `DB::table('companies')->count()` — tables don't exist in central → 500.
  - `SuperAdminController::showTenant()` lines 74–80: companies/users/locations counts from central → 500.
  - `SuperAdminController::users()/showUser()/verifyUserEmail()`: query `User`/`user_company_memberships` from central → 500.
  - `PlanEnforcementService::getUsageStats()` + `calculateUserOverage()` (called by `getPlanSummary` → `showTenant` + `getTenantPlanUsage`): Company/Location/Product/Partner/users/documents counts → 500 from central context.
  - `TenantObserver::revokeAllUserTokens()`: `User::where('tenant_id', …)` from central — fires on suspend + delete (G1 deletion 500).
  - **Billing topology bug (new finding):** `billing_invoices`, `billing_invoice_items`, `billing_payments`, `billing_refunds` are created by `database/migrations/tenant/2025_12_16_*` (+ unique-index migration `2026_05_08_000002`) — so they exist only in tenant DBs, while `Invoice`/`InvoiceItem`/`Payment`/`Refund` models are NOT central-pinned and are read from central context by `AdminBillingController`, `MonitoringService` (critical metrics), `InvoiceService`, `StripeWebhookController`. On a fresh central DB every billing/monitoring-critical endpoint 500s. No tenant-facing code uses these models. They are platform billing → belong central.
  - `AdminAuditLog` model is NOT `CentralConnection`-pinned (latent bug if logging ever happens in tenant context).
- **Already fine:** SuperAdminAuthController (central, returns Bearer token), VerticalConfigController (GlobalCache pattern), MonitoringController (failed_jobs/jobs = central), TenantHealthController, tenants/audit-logs/extend-trial/change-plan/suspend*/activate/update-extras handlers (central tables), `CentralPersonalAccessToken` pinned central, `Plan`/`TenantSubscription`/`SuperAdmin`/`CentralIdentity` pinned central.
- **Test env:** `phpunit.xml` forces `TENANCY_DB_PER_TENANT=false` + SQLite `:memory:`; `AppServiceProvider` loads `database/migrations/tenant/` into the same schema in testing. Therefore `$tenant->run()` executes against the shared schema (no DB swap) — keeping `where('tenant_id', …)` clauses inside `run()` closures makes tests meaningful AND is harmless in prod (per-tenant DB rows still carry tenant_id).
- **`tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php` has NO `hasTable` guard** — its `billing_*` entries must be removed when the billing creates move out of `migrations/tenant/`, or fresh tenant provisioning fails.
- **No `Relation::morphMap`** — `tokenable_type` for users is the FQCN; use `(new User())->getMorphClass()`.
- **Frontend:** `adminApi.ts` is cookie-only; `loginSuperAdmin` (`features/admin/api/index.ts:28`) discards `token` from the response; `adminAuthStore` persists only `admin` (partialize) — `isAuthenticated` resets on refresh, so memory-only token + refresh-requires-re-login is already consistent with `RequireAdminAuth`. Tenant app's `src/lib/api.ts:109-113` is the Bearer-interceptor reference pattern.
- **Live stack:** `docker compose -p erp-sidebar-demo -f docker-compose.sidebar-demo.yml up -d` → http://localhost:8089. Admin `admin@synerivia.test` / `SidebarDemo2026!`. Tenant `owner@pharmabio.fr` / `password`. SPA rebuild: `cd apps/web && VITE_API_URL=http://localhost:8089 VITE_APP_PRODUCT=izipos pnpm build` (web container mounts `dist/`).
- **HARD RULE:** NEVER run unscoped `php artisan test` / `composer test` / `scripts/preflight.sh`. Every PHPUnit run below is file-scoped or `--filter`-scoped.

Working directory for backend: `apps/erp/apps/api`. Frontend: `apps/erp/apps/web`. All paths below are relative to `apps/erp/`.

---

### Task 0: Branch

- [ ] **Step 1: Create the work branch off the module-gating branch**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git checkout feat/sidebar-regroup-module-gating
git checkout -b feat/admin-panel-db-per-tenant
```

Do NOT push at any point without asking the user.

---

## Part B — Frontend Bearer auth fallback

### Task 1: Token in adminAuthStore (memory only)

**Files:**
- Modify: `apps/web/src/features/admin/stores/adminAuthStore.ts`
- Test: `apps/web/src/features/admin/__tests__/adminAuthStore.test.ts` (create)

- [ ] **Step 1: Write the failing test**

```ts
import { beforeEach, describe, expect, it } from 'vitest'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const admin = {
  id: 'a1',
  email: 'root@synerivia.test',
  name: 'Root',
  role: 'super_admin' as const,
}

describe('adminAuthStore', () => {
  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    localStorage.clear()
  })

  it('keeps the bearer token in memory after setAuth', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    expect(useAdminAuthStore.getState().token).toBe('secret-bearer-token')
    expect(useAdminAuthStore.getState().isAuthenticated).toBe(true)
  })

  it('never persists the token to localStorage', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    const persisted = localStorage.getItem('admin-auth-storage') ?? ''
    expect(persisted).not.toContain('secret-bearer-token')
  })

  it('clears the token on logout', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    useAdminAuthStore.getState().logout()
    expect(useAdminAuthStore.getState().token).toBeNull()
    expect(useAdminAuthStore.getState().isAuthenticated).toBe(false)
  })
})
```

- [ ] **Step 2: Run to verify failure**

```bash
cd apps/web && pnpm vitest run src/features/admin/__tests__/adminAuthStore.test.ts
```
Expected: FAIL — `setAuth` doesn't accept a token / `token` undefined.

- [ ] **Step 3: Implement**

In `adminAuthStore.ts`, change the interface and store (keep the existing `SuperAdmin` interface and persistence partialize — token must stay OUT of `partialize`):

```ts
interface AdminAuthState {
  admin: SuperAdmin | null
  /**
   * Sanctum Bearer token, held in MEMORY ONLY (never persisted).
   * Bearer-only deploys (SANCTUM_STATEFUL_DOMAINS="") have no session
   * cookie, so this is the only credential; a page refresh therefore
   * requires re-login by design.
   */
  token: string | null
  isAuthenticated: boolean
  setAuth: (admin: SuperAdmin, token: string) => void
  logout: () => void
}

export const useAdminAuthStore = create<AdminAuthState>()(
  persist(
    (set) => ({
      admin: null,
      token: null,
      isAuthenticated: false,
      setAuth: (admin, token) => {
        set({ admin, token, isAuthenticated: true })
      },
      logout: () => {
        set({ admin: null, token: null, isAuthenticated: false })
      },
    }),
    {
      name: 'admin-auth-storage',
      // Only the display profile is persisted — never token/isAuthenticated.
      partialize: (state) => ({
        admin: state.admin,
      }),
    }
  )
)
```

- [ ] **Step 4: Run test to verify pass** (same command). Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/admin/stores/adminAuthStore.ts apps/web/src/features/admin/__tests__/adminAuthStore.test.ts
git commit -m "feat(web/admin): hold super-admin bearer token in memory-only auth store"
```

---

### Task 2: Bearer + 401 interceptors on adminApi

**Files:**
- Modify: `apps/web/src/features/admin/lib/adminApi.ts`
- Test: `apps/web/src/features/admin/__tests__/adminApi.test.ts` (create)

- [ ] **Step 1: Write the failing test**

These tests exercise OUR axios config (interceptors) with a stubbed adapter — they do not fake API integration.

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'
import { adminApi } from '../lib/adminApi'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const admin = { id: 'a1', email: 'r@s.test', name: 'Root', role: 'super_admin' as const }

function okAdapter(captured: { config?: InternalAxiosRequestConfig }) {
  return async (config: InternalAxiosRequestConfig) => {
    captured.config = config
    return { data: { data: null }, status: 200, statusText: 'OK', headers: {}, config }
  }
}

describe('adminApi auth interceptors', () => {
  const originalLocation = window.location

  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, pathname: '/admin/tenants', assign: vi.fn() },
      writable: true,
    })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, writable: true })
    adminApi.defaults.adapter = undefined
  })

  it('attaches Authorization: Bearer when a token is in the store', async () => {
    useAdminAuthStore.getState().setAuth(admin, 'tok-123')
    const captured: { config?: InternalAxiosRequestConfig } = {}
    adminApi.defaults.adapter = okAdapter(captured)
    await adminApi.get('/admin/dashboard')
    expect(captured.config?.headers.Authorization).toBe('Bearer tok-123')
  })

  it('sends no Authorization header without a token', async () => {
    const captured: { config?: InternalAxiosRequestConfig } = {}
    adminApi.defaults.adapter = okAdapter(captured)
    await adminApi.get('/admin/dashboard')
    expect(captured.config?.headers.Authorization).toBeUndefined()
  })

  it('clears auth and redirects to /admin/login on 401', async () => {
    useAdminAuthStore.getState().setAuth(admin, 'tok-123')
    adminApi.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
      throw new AxiosError('Unauthenticated', '401', config, null, {
        status: 401, statusText: 'Unauthorized', headers: {}, config, data: {},
      })
    }
    await expect(adminApi.get('/admin/dashboard')).rejects.toThrow()
    expect(useAdminAuthStore.getState().token).toBeNull()
    expect(window.location.assign).toHaveBeenCalledWith('/admin/login')
  })

  it('does not redirect when already on the login page', async () => {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, pathname: '/admin/login', assign: vi.fn() },
      writable: true,
    })
    adminApi.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
      throw new AxiosError('Unauthenticated', '401', config, null, {
        status: 401, statusText: 'Unauthorized', headers: {}, config, data: {},
      })
    }
    await expect(adminApi.get('/admin/auth/me')).rejects.toThrow()
    expect(window.location.assign).not.toHaveBeenCalled()
  })
})
```

If `AxiosHeaders` is unused after writing, drop the import. If the adapter signature fights the installed axios types, cast the stub with `as never` — the assertion targets are our interceptors, not the adapter.

- [ ] **Step 2: Run to verify failure**

```bash
cd apps/web && pnpm vitest run src/features/admin/__tests__/adminApi.test.ts
```
Expected: FAIL — no Authorization header attached, no redirect.

- [ ] **Step 3: Implement in `adminApi.ts`**

Replace the file body (keep the existing helpers `adminApiGet`/`adminApiGetPaginated`/`adminApiPost`/`adminApiPatch`/`adminApiPut` unchanged below):

```ts
import axios, { type AxiosInstance } from 'axios'
import { useAdminAuthStore } from '../stores/adminAuthStore'

/**
 * Admin API client — cookie-based Sanctum auth with a Bearer fallback.
 *
 * Bearer-only deploys (db-per-tenant: SANCTUM_STATEFUL_DOMAINS="") cannot
 * establish a session cookie, so the login response token (kept in the
 * memory-only adminAuthStore) is attached as Authorization: Bearer. On
 * cookie-capable deploys the cookie still works and the header is a no-op
 * for the same principal. 401 responses clear auth state and bounce to the
 * admin login page.
 */
function createAdminApiClient(): AxiosInstance {
  const client = axios.create({
    baseURL: '/api/v1',
    timeout: 30000,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    withCredentials: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
  })

  client.interceptors.request.use((config) => {
    const token = useAdminAuthStore.getState().token
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  client.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
      if (axios.isAxiosError(error) && error.response?.status === 401) {
        useAdminAuthStore.getState().logout()
        if (!window.location.pathname.startsWith('/admin/login')) {
          window.location.assign('/admin/login')
        }
      }
      return Promise.reject(error instanceof Error ? error : new Error(String(error)))
    }
  )

  return client
}

export const adminApi = createAdminApiClient()
```

- [ ] **Step 4: Run test to verify pass** (same command). Expected: PASS. Also re-run Task 1's test (store import cycle sanity).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/admin/lib/adminApi.ts apps/web/src/features/admin/__tests__/adminApi.test.ts
git commit -m "feat(web/admin): bearer fallback + 401 redirect interceptors on admin api client"
```

---

### Task 3: Wire the login token through

**Files:**
- Modify: `apps/web/src/features/admin/api/index.ts` (loginSuperAdmin)
- Modify: `apps/web/src/features/admin/pages/AdminLoginPage.tsx`
- Test: `apps/web/src/features/admin/__tests__/AdminLoginPage.test.tsx` (create)

- [ ] **Step 1: Write the failing test**

Component test — mocking the api module and router hooks is the established pattern (see `__tests__/VerticalsPage.test.tsx` for harness specifics; reuse its QueryClientProvider wrapper approach if present).

```tsx
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AdminLoginPage } from '../pages/AdminLoginPage'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const navigate = vi.fn()
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))
vi.mock('../api', () => ({
  loginSuperAdmin: vi.fn().mockResolvedValue({
    admin: { id: 'a1', email: 'root@synerivia.test', name: 'Root', role: 'super_admin' },
    token: 'fresh-bearer-token',
  }),
}))

describe('AdminLoginPage', () => {
  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    navigate.mockClear()
  })

  it('stores the bearer token from the login response', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <AdminLoginPage />
      </QueryClientProvider>
    )
    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'root@synerivia.test' } })
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'pw' } })
    fireEvent.click(screen.getByRole('button', { name: /sign in/i }))

    await waitFor(() => {
      expect(useAdminAuthStore.getState().token).toBe('fresh-bearer-token')
    })
    expect(useAdminAuthStore.getState().admin?.email).toBe('root@synerivia.test')
    expect(navigate).toHaveBeenCalledWith('/admin/dashboard')
  })
})
```

- [ ] **Step 2: Run to verify failure**

```bash
cd apps/web && pnpm vitest run src/features/admin/__tests__/AdminLoginPage.test.tsx
```
Expected: FAIL — token stays null (current code discards `data.token` and `setAuth` has the old arity).

- [ ] **Step 3: Implement**

In `api/index.ts`, replace `loginSuperAdmin` (lines 28–40). `apiPost` unwraps `response.data.data`, and the backend returns `{ data: { admin, token } }` (`SuperAdminAuthController::login` line 51–56):

```ts
export interface AdminLoginResult {
  admin: AdminAuthResponse
  token: string
}

// Authentication (uses regular API since not authenticated yet)
export async function loginSuperAdmin(
  email: string,
  password: string
): Promise<AdminLoginResult> {
  // Cookie-capable deploys need the CSRF cookie before login; Bearer-only
  // deploys may not serve a usable session — never let this block login.
  try {
    await ensureCsrfCookie()
  } catch {
    // Bearer-only deploy: proceed without a session cookie.
  }
  const response = await apiPost<AdminLoginResult>('/admin/auth/login', {
    email,
    password,
  })
  return response
}
```

In `AdminLoginPage.tsx`, update the mutation `onSuccess` (lines 17–26):

```tsx
    onSuccess: (data) => {
      // Memory-only Bearer token; also works alongside the session cookie
      // on cookie-capable deploys.
      setAuth(
        {
          id: data.admin.id,
          email: data.admin.email,
          name: data.admin.name,
          role: data.admin.role,
        },
        data.token
      )
      navigate('/admin/dashboard')
    },
```

- [ ] **Step 4: Run test to verify pass** (same command). Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/admin/api/index.ts apps/web/src/features/admin/pages/AdminLoginPage.tsx apps/web/src/features/admin/__tests__/AdminLoginPage.test.tsx
git commit -m "feat(web/admin): store login bearer token; tolerate missing csrf cookie on bearer-only deploys"
```

---

### Task 4: Logout revokes the token server-side

**Files:**
- Modify: `apps/web/src/features/admin/components/AdminLayout.tsx`

The backend already revokes (`SuperAdminAuthController::logout` → `currentAccessToken()->delete()`); the SPA just never calls it.

- [ ] **Step 1: Implement** (no new test file — behavior is covered by the live-stack walk in Task 13; the logout endpoint itself is backend-tested in `SuperAdminAuthorizationTest`)

In `AdminLayout.tsx`, import the api call and make `handleLogout` revoke first:

```tsx
import { logoutSuperAdmin } from '../api'
```

```tsx
  const handleLogout = async () => {
    try {
      await logoutSuperAdmin()
    } catch {
      // Token may already be expired/revoked — local logout regardless.
    }
    logout()
    navigate('/admin/login')
  }
```

- [ ] **Step 2: Frontend gates on everything changed so far**

```bash
cd apps/web
pnpm vitest run src/features/admin/__tests__/
pnpm exec tsc --noEmit -p tsconfig.json
pnpm exec eslint src/features/admin
```
Expected: all PASS / zero errors. (Scoped — do not run the full suite.)

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/admin/components/AdminLayout.tsx
git commit -m "feat(web/admin): revoke bearer token server-side on logout"
```

---

## Part C — Backend cross-context fixes

### Task 5: Pin AdminAuditLog to the central connection

**Files:**
- Modify: `apps/api/app/Models/AdminAuditLog.php`
- Test: `apps/api/tests/Feature/Admin/CentralModelPinningTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use Tests\TestCase;

class CentralModelPinningTest extends TestCase
{
    public function test_admin_audit_log_is_pinned_to_the_central_connection(): void
    {
        $central = (string) config('tenancy.database.central_connection');

        $this->assertSame($central, (new AdminAuditLog)->getConnectionName());
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
cd apps/api && php artisan test tests/Feature/Admin/CentralModelPinningTest.php
```
Expected: FAIL — `getConnectionName()` returns null (default connection).

- [ ] **Step 3: Implement** — in `AdminAuditLog.php` add the trait (match how `app/Models/SuperAdmin.php` does it):

```php
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AdminAuditLog extends Model
{
    use CentralConnection;
    // … existing body unchanged
```

- [ ] **Step 4: Run test to verify pass** (same command). Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Models/AdminAuditLog.php apps/api/tests/Feature/Admin/CentralModelPinningTest.php
git commit -m "fix(api): pin AdminAuditLog to the central connection"
```

---

### Task 6: Platform-billing tables are central (topology fix)

**Files:**
- Move (git mv, content edits noted): `apps/api/database/migrations/tenant/2025_12_16_100002_create_billing_invoices_table.php`, `…100003_create_billing_invoice_items_table.php`, `…100004_create_billing_payments_table.php`, `…100005_create_billing_refunds_table.php`, `…2026_05_08_000002_add_unique_to_billing_payments_provider_payment_id.php` → `apps/api/database/migrations/`
- Modify: `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php` (remove `billing_*` entries)
- Modify: `apps/api/app/Modules/Billing/Domain/Invoice.php`, `InvoiceItem.php`, `Payment.php`, `Refund.php` (add `CentralConnection`)
- Test: extend `apps/api/tests/Feature/Admin/CentralModelPinningTest.php`

**Why safe:** no production central DB has these tables yet (db-per-tenant deploy is local-only); the sidebar-demo stack is rebuilt fresh; in testing both migration dirs load into one schema so the move is test-neutral. Existing tenant DBs keep orphan (empty-for-new-data) billing tables — harmless; new tenant DBs won't create them.

- [ ] **Step 1: Write the failing test** — add to `CentralModelPinningTest`:

```php
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\InvoiceItem;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Refund;

    public function test_platform_billing_models_are_pinned_to_the_central_connection(): void
    {
        $central = (string) config('tenancy.database.central_connection');

        foreach ([Invoice::class, InvoiceItem::class, Payment::class, Refund::class] as $model) {
            $this->assertSame($central, (new $model)->getConnectionName(), $model);
        }
    }

    public function test_no_billing_table_migrations_remain_in_the_tenant_set(): void
    {
        $this->assertSame([], glob(database_path('migrations/tenant/*billing_*')));
    }
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/CentralModelPinningTest.php
```
Expected: FAIL on both new tests.

- [ ] **Step 3: Move migrations + edit scales**

```bash
cd apps/api/database/migrations
git mv tenant/2025_12_16_100002_create_billing_invoices_table.php .
git mv tenant/2025_12_16_100003_create_billing_invoice_items_table.php .
git mv tenant/2025_12_16_100004_create_billing_payments_table.php .
git mv tenant/2025_12_16_100005_create_billing_refunds_table.php .
git mv tenant/2026_05_08_000002_add_unique_to_billing_payments_provider_payment_id.php .
```

In the four moved **create** migrations, change monetary column scales from `, 2)` to `, 3)` to bake in the precision contract (these files have never run against any central DB, so editing them is safe; the scales mirror exactly what the tenant-side widen migration applied):
- `billing_invoices`: subtotal, tax_amount, discount_amount, total, amount_paid, amount_due → `decimal(12, 3)`
- `billing_invoice_items`: unit_price, amount, tax_amount, discount_amount → `decimal(12, 3)` (`quantity` stays `decimal(10, 2)` — SaaS billing quantity, per the widen-quantity migration's own comment)
- `billing_payments`: amount, fee, net_amount, refunded_amount → `decimal(12, 3)`
- `billing_refunds`: amount → `decimal(12, 3)`

Add a header comment to each moved create migration:

```php
/**
 * CENTRAL table — platform billing (invoices the platform issues to tenants).
 * Moved out of database/migrations/tenant/ on 2026-06-12: these tables were
 * misfiled during the T6 db-per-tenant flip and must live in the central DB
 * (read from central context by AdminBillingController / MonitoringService /
 * StripeWebhookController / InvoiceService). Monetary scales baked in at 3
 * (precision contract) since no central DB has ever run this file.
 */
```

- [ ] **Step 4: Remove `billing_*` entries from the tenant widen migration**

In `tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php`, delete the `// Billing` block (lines ~151–174: the `billing_invoices`, `billing_invoice_items`, `billing_payments`, `billing_refunds` keys) and replace it with:

```php
        // Billing (billing_invoices / billing_invoice_items / billing_payments /
        // billing_refunds) entries removed 2026-06-12: those tables moved to the
        // CENTRAL migration set (they were misfiled in the T6 flip) with scale 3
        // baked into the create migrations. Fresh tenant DBs no longer have them,
        // and this migration has no hasTable guard — leaving the entries would
        // break tenant provisioning. Already-provisioned tenant DBs ran the old
        // version of this file; editing it does not re-run there.
```

- [ ] **Step 5: Pin the four models** — in `Invoice.php`, `InvoiceItem.php`, `Payment.php`, `Refund.php`:

```php
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class Invoice extends Model
{
    use CentralConnection;
    // … existing body unchanged
```
(same one-line trait addition in each of the four).

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Admin/CentralModelPinningTest.php
php artisan test tests/Feature/Tenant/CrossDbForeignKeyAuditTest.php
php artisan test tests/Feature/Tenant/TenantMigrationsLoadedInTestingTest.php
php artisan test tests/Feature/Billing/CreateManualInvoicePrecisionTest.php
```
Expected: all PASS.

- [ ] **Step 7: PHPStan + pint on changed PHP files**

```bash
./vendor/bin/phpstan analyse app/Modules/Billing/Domain/Invoice.php app/Modules/Billing/Domain/InvoiceItem.php app/Modules/Billing/Domain/Payment.php app/Modules/Billing/Domain/Refund.php app/Models/AdminAuditLog.php --no-progress
./vendor/bin/pint app/Modules/Billing/Domain app/Models/AdminAuditLog.php database/migrations
```

- [ ] **Step 8: Commit**

```bash
git add -A apps/api/database/migrations apps/api/app/Modules/Billing/Domain apps/api/tests/Feature/Admin/CentralModelPinningTest.php
git commit -m "fix(api): platform billing tables are central — move misfiled tenant migrations, pin billing models"
```

---

### Task 7: Dashboard fleet stats via per-tenant execution + GlobalCache

**Files:**
- Create: `apps/api/app/Services/TenantFleetStatsService.php`
- Modify: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php` (`dashboard()`, constructor)
- Test: `apps/api/tests/Feature/Admin/AdminDashboardStatsTest.php` (create)

Decision (vs denormalized counters on `tenants`): fan-out with a 5-minute `GlobalCache` TTL. The fleet is small (first customer ~July); counters add write-path coupling in every tenant signup/user-create flow. Revisit if fleet size makes the fan-out slow — the service is the single seam to swap.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\TenantFleetStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stancl\Tenancy\Facades\GlobalCache;
use Tests\TestCase;

class AdminDashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        GlobalCache::forget(TenantFleetStatsService::CACHE_KEY);

        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_dashboard_aggregates_user_and_company_counts_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $tenantA->id]);
        User::factory()->create(['tenant_id' => $tenantB->id]);
        Company::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.total_users', 3)
            ->assertJsonPath('data.total_companies', 1)
            ->assertJsonPath('data.total_tenants', 2);
    }

    public function test_fleet_stats_are_cached_in_the_tenancy_neutral_global_cache(): void
    {
        Tenant::factory()->create();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertTrue(GlobalCache::has(TenantFleetStatsService::CACHE_KEY));
    }
}
```

If `Tenant::factory()` requires attributes (check `database/factories/TenantFactory.php`), pass the same fields the `UpdateTenantExtrasTest::setUp` uses (`name`, `slug`, `status`, `plan`). If `Company::factory()` requires a country/currency, supply whatever its definition lacks — read the factory before guessing.

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/AdminDashboardStatsTest.php
```
Expected: FAIL — `TenantFleetStatsService` class not found.

- [ ] **Step 3: Implement the service**

`apps/api/app/Services/TenantFleetStatsService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Facades\GlobalCache;
use Throwable;

/**
 * Fleet-wide aggregates for the super-admin dashboard.
 *
 * users/companies live in PER-TENANT databases (T6 Phase 0b), so totals are
 * computed by running the count inside each tenant's DB via $tenant->run().
 * The tenant_id where-clauses are kept: harmless in prod (per-tenant DB rows
 * still carry tenant_id) and REQUIRED for correctness in the shared-schema
 * test environment where run() does not swap databases.
 *
 * CACHE TOPOLOGY — GlobalCache, NOT the Cache facade: this is read from
 * central (admin) context and must not land in a tenant-tagged keyspace
 * (see VerticalConfigService for the canonical pattern). Unreachable tenant
 * DBs are skipped (logged) so one broken tenant cannot 500 the dashboard.
 */
class TenantFleetStatsService
{
    public const CACHE_KEY = 'admin:fleet-stats';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{total_users: int, total_companies: int}
     */
    public function getUserAndCompanyTotals(): array
    {
        /** @var array{total_users: int, total_companies: int} */
        return GlobalCache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): array {
                $totals = ['total_users' => 0, 'total_companies' => 0];

                foreach (Tenant::query()->cursor() as $tenant) {
                    try {
                        /** @var array{users: int, companies: int} $counts */
                        $counts = $tenant->run(static fn (): array => [
                            'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
                            'companies' => DB::table('companies')->where('tenant_id', $tenant->id)->count(),
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('Fleet stats: tenant database unreachable, skipping', [
                            'tenant_id' => $tenant->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $totals['total_users'] += $counts['users'];
                    $totals['total_companies'] += $counts['companies'];
                }

                return $totals;
            }
        );
    }
}
```

(If PHPStan complains that `$tenant` is not usable inside `static fn`, arrow functions auto-capture by value — it is fine; if it complains about `run()` types, add `/** @var array{users:int, companies:int} $counts */` as shown.)

- [ ] **Step 4: Use it in the controller**

In `SuperAdminController`: add to the constructor

```php
    public function __construct(
        private readonly AdminAuditService $auditService,
        private readonly PlanEnforcementService $planEnforcementService,
        private readonly VerticalConfigService $verticalConfigService,
        private readonly TenantFleetStatsService $fleetStatsService
    ) {}
```

and replace `dashboard()` lines 31–42:

```php
        $fleetTotals = $this->fleetStatsService->getUserAndCompanyTotals();

        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'trial_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'trial')
                ->count(),
            'expired_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'expired')
                ->count(),
            'total_users' => $fleetTotals['total_users'],
            'total_companies' => $fleetTotals['total_companies'],
        ];
```

Add `use App\Services\TenantFleetStatsService;` to the imports.

- [ ] **Step 5: Run tests, then static gates**

```bash
php artisan test tests/Feature/Admin/AdminDashboardStatsTest.php
./vendor/bin/phpstan analyse app/Services/TenantFleetStatsService.php app/Http/Controllers/Api/Admin/SuperAdminController.php --no-progress
./vendor/bin/pint app/Services/TenantFleetStatsService.php app/Http/Controllers/Api/Admin/SuperAdminController.php
```
Expected: PASS / 0 errors.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Services/TenantFleetStatsService.php apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php apps/api/tests/Feature/Admin/AdminDashboardStatsTest.php
git commit -m "fix(api): admin dashboard fleet totals via per-tenant execution + GlobalCache"
```

---

### Task 8: showTenant stats inside $tenant->run()

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php` (`showTenant()`)
- Test: add to `apps/api/tests/Feature/Admin/AdminDashboardStatsTest.php`? No — create `apps/api/tests/Feature/Admin/AdminTenantDetailTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_detail_stats_come_from_the_tenant_database(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['tenant_id' => $other->id]);
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}");

        $response->assertOk()
            ->assertJsonPath('data.stats.users_count', 2)
            ->assertJsonPath('data.stats.companies_count', 1)
            ->assertJsonPath('data.stats.locations_count', 0)
            ->assertJsonPath('data.stats_available', true);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/AdminTenantDetailTest.php
```
Expected: FAIL — `data.stats_available` missing (and in a real per-tenant env the counts would 500; the shared-schema env only catches the contract change, the topology is verified live in Task 13).

- [ ] **Step 3: Implement** — in `showTenant()`, replace lines 74–80 with:

```php
        try {
            /** @var array{users_count: int, companies_count: int, locations_count: int} $stats */
            $stats = $tenant->run(static function () use ($id): array {
                $companyIds = DB::table('companies')->where('tenant_id', $id)->pluck('id');

                return [
                    'users_count' => DB::table('users')->where('tenant_id', $id)->count(),
                    'companies_count' => $companyIds->count(),
                    'locations_count' => DB::table('locations')->whereIn('company_id', $companyIds)->count(),
                ];
            });
            $statsAvailable = true;
        } catch (Throwable $e) {
            Log::warning('Admin tenant detail: tenant database unreachable', [
                'tenant_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $stats = ['users_count' => 0, 'companies_count' => 0, 'locations_count' => 0];
            $statsAvailable = false;
        }
```

and add `'stats_available' => $statsAvailable,` to the response `data` array (after `'stats' => $stats,`). Add imports `use Illuminate\Support\Facades\Log;` and `use Throwable;`.

- [ ] **Step 4: Run test to verify pass**, then gates:

```bash
php artisan test tests/Feature/Admin/AdminTenantDetailTest.php
php artisan test tests/Feature/Admin/UpdateTenantExtrasTest.php
./vendor/bin/phpstan analyse app/Http/Controllers/Api/Admin/SuperAdminController.php --no-progress
./vendor/bin/pint app/Http/Controllers/Api/Admin/SuperAdminController.php
```

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php apps/api/tests/Feature/Admin/AdminTenantDetailTest.php
git commit -m "fix(api): admin tenant-detail stats run inside the tenant database"
```

---

### Task 9: PlanEnforcementService usage counts inside $tenant->run()

**Files:**
- Modify: `apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php` (`getUsageStats()` lines 253–324, `calculateUserOverage()` lines 386–401)
- Test: `apps/api/tests/Feature/Admin/AdminTenantPlanUsageTest.php` (create)

`getPlanSummary` is called from CENTRAL context (`showTenant`, `getTenantPlanUsage`) — its count queries must run per-tenant. It is ALSO called from tenant context; `$tenant->run()` for the already-initialized tenant is safe (Stancl re-initializes and restores).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantPlanUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_usage_counts_are_scoped_to_the_target_tenant(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        User::factory()->count(5)->create(['tenant_id' => $other->id]);
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}/plan-usage");

        $response->assertOk()
            ->assertJsonPath('data.usage.users.current', 2)
            ->assertJsonPath('data.usage.companies.current', 1)
            ->assertJsonPath('data.overage.extra_users', 0);
    }
}
```

(If the response asserts fail because a tenant without a subscription short-circuits, create a `TenantSubscription`/plan the same way other Billing feature tests seed one — check `tests/Feature/` for a `TenantSubscription::create` example and mirror it. The endpoint itself returns the summary regardless of plan presence — `plan: null` is allowed.)

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/AdminTenantPlanUsageTest.php
```
Expected: PASS or FAIL depending on shared-schema masking — if it passes pre-change, that confirms the shared schema masks the bug; proceed anyway (the refactor is behavior-preserving in tests, topology-fixing in prod). Record which it was in the commit message.

- [ ] **Step 3: Implement**

In `getUsageStats()`, compute `$limits` first (central-safe), then move ALL count queries into one `run()`:

```php
    public function getUsageStats(Tenant $tenant): array
    {
        $limits = $this->getLimitsForTenant($tenant);

        // users/companies/locations/products/partners/documents live in the
        // PER-TENANT database — counts must execute inside $tenant->run().
        // tenant_id scoping is kept: harmless in prod, required in the
        // shared-schema test env (run() does not swap DBs there).
        /** @var array{companies: int, locations: int, users: int, products: int, partners: int, documents: int} $counts */
        $counts = $tenant->run(static function () use ($tenant): array {
            $companyIds = Company::where('tenant_id', $tenant->id)->pluck('id');

            return [
                'companies' => $companyIds->count(),
                'locations' => Location::whereIn('company_id', $companyIds)->count(),
                'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
                'products' => Product::where('tenant_id', $tenant->id)->count(),
                'partners' => Partner::where('tenant_id', $tenant->id)->count(),
                'documents' => DB::table('documents')
                    ->where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ];
        });

        $stats = [];

        $companiesLimit = (int) ($limits[PlanLimits::MAX_COMPANIES] ?? 1);
        $stats['companies'] = [
            'current' => $counts['companies'],
            'limit' => $companiesLimit,
            'percent' => $companiesLimit > 0 ? min(100, ($counts['companies'] / $companiesLimit) * 100) : 0,
        ];

        $locationsLimit = (int) ($limits[PlanLimits::MAX_LOCATIONS] ?? 1);
        $stats['locations'] = [
            'current' => $counts['locations'],
            'limit' => $locationsLimit,
            'percent' => $locationsLimit > 0 ? min(100, ($counts['locations'] / $locationsLimit) * 100) : 0,
        ];

        $usersLimit = (int) ($limits[PlanLimits::MAX_USERS] ?? 2);
        $stats['users'] = [
            'current' => $counts['users'],
            'limit' => $usersLimit,
            'percent' => $usersLimit > 0 ? min(100, ($counts['users'] / $usersLimit) * 100) : 0,
        ];

        $productsLimit = (int) ($limits[PlanLimits::MAX_PRODUCTS] ?? 50);
        $stats['products'] = [
            'current' => $counts['products'],
            'limit' => $productsLimit,
            'percent' => $productsLimit > 0 && $productsLimit < PHP_INT_MAX
                ? min(100, ($counts['products'] / $productsLimit) * 100)
                : 0,
        ];

        $partnersLimit = (int) ($limits[PlanLimits::MAX_PARTNERS] ?? 20);
        $stats['partners'] = [
            'current' => $counts['partners'],
            'limit' => $partnersLimit,
            'percent' => $partnersLimit > 0 && $partnersLimit < PHP_INT_MAX
                ? min(100, ($counts['partners'] / $partnersLimit) * 100)
                : 0,
        ];

        $docsLimit = (int) ($limits[PlanLimits::MAX_DOCUMENTS_PER_MONTH] ?? 30);
        $stats['documents_this_month'] = [
            'current' => $counts['documents'],
            'limit' => $docsLimit,
            'percent' => $docsLimit > 0 && $docsLimit < PHP_INT_MAX
                ? min(100, ($counts['documents'] / $docsLimit) * 100)
                : 0,
        ];

        return $stats;
    }
```

In `calculateUserOverage()`, replace the count line:

```php
        /** @var int $currentUsers */
        $currentUsers = $tenant->run(
            static fn (): int => DB::table('users')->where('tenant_id', $tenant->id)->count()
        );
        $extraUsers = max(0, $currentUsers - $includedUsers);
```

- [ ] **Step 4: Run tests** — the new test, plus the service's existing consumers:

```bash
php artisan test tests/Feature/Admin/AdminTenantPlanUsageTest.php
php artisan test tests/Feature/Admin/AdminTenantDetailTest.php
php artisan test --filter PlanEnforcement
```
Expected: PASS. Also run any test files that surface in `grep -rl "getUsageStats\|getPlanSummary\|calculateUserOverage" tests/` (file-scoped runs only).

- [ ] **Step 5: Gates + commit**

```bash
./vendor/bin/phpstan analyse app/Modules/Billing/Application/Services/PlanEnforcementService.php --no-progress
./vendor/bin/pint app/Modules/Billing/Application/Services/PlanEnforcementService.php
git add apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php apps/api/tests/Feature/Admin/AdminTenantPlanUsageTest.php
git commit -m "fix(api): plan-usage counts execute inside the tenant database"
```

---

### Task 10: User directory — per-tenant fan-out + tenant_id-addressed detail/verify

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php` (`users()`, `showUser()`, `verifyUserEmail()`)
- Modify: `apps/web/src/features/admin/api/index.ts` (`verifyUserEmail`)
- Modify: `apps/web/src/features/admin/hooks/useUsers.ts`
- Modify: `apps/web/src/features/admin/pages/CompanyOwnersPage.tsx`
- Test: `apps/api/tests/Feature/Admin/AdminUserDirectoryTest.php` (create)

Design (locked):
- `GET /admin/users` — fan-out over all tenants; filters (`search` name/email, `tenant_id`, `email_verified`, `status`) applied INSIDE each tenant DB; merged, sorted `created_at` desc, in-memory `LengthAwarePaginator` (20/page) to preserve the existing `{ data: { data: [...] } }` response shape. Unreachable tenant DBs are skipped + logged. `tenant` relation replaced by manually attached `{id, name}` (we hold the Tenant row in the loop). Acceptable at current fleet size; the cache/counters seam is Task 7's service if this ever needs scaling.
- `GET /admin/users/{id}` — now REQUIRES `tenant_id` query param (user ids are only resolvable inside a tenant DB; `central_identities` is pointers-only and not guaranteed complete for non-owner users). Not called by the SPA today.
- `POST /admin/users/{id}/verify-email` — now REQUIRES `tenant_id` in the body; SPA passes `user.tenant_id` it already has from the list.
- Closures NEVER throw inside `run()` (Stancl's `run()` is not exception-safe re: context restore) — they return null/arrays and the controller handles not-found outside.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    }

    public function test_users_index_fans_out_across_tenants_and_attaches_tenant_info(): void
    {
        User::factory()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Alice A']);
        User::factory()->create(['tenant_id' => $this->tenantB->id, 'name' => 'Bob B']);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/users');

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice A', $names);
        $this->assertContains('Bob B', $names);
        $tenantNames = array_map(static fn (array $r): string => $r['tenant']['name'], $rows);
        $this->assertContains('Tenant A', $tenantNames);
    }

    public function test_users_index_filters_by_email_verified_inside_each_tenant(): void
    {
        User::factory()->create(['tenant_id' => $this->tenantA->id, 'email_verified_at' => null]);
        User::factory()->create(['tenant_id' => $this->tenantB->id]); // factory default: verified

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/users?email_verified=false');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_show_user_requires_tenant_id_and_resolves_inside_that_tenant(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}?tenant_id={$this->tenantA->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}?tenant_id={$this->tenantB->id}")
            ->assertNotFound();
    }

    public function test_verify_email_requires_tenant_id_updates_user_and_writes_audit_log(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'email_verified_at' => null,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", ['notes' => 'checked'])
            ->assertStatus(422);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", [
                'tenant_id' => $this->tenantA->id,
                'notes' => 'checked',
            ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'verify_user_email',
            'entity_id' => $user->id,
        ]);
    }

    public function test_verify_email_rejects_already_verified(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]); // verified by default

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", [
                'tenant_id' => $this->tenantA->id,
            ])
            ->assertStatus(400);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/AdminUserDirectoryTest.php
```
Expected: FAIL — 422 assertions fail (old endpoints don't require tenant_id), tenant attachment shape differs.

- [ ] **Step 3: Implement the three controller methods**

Replace `users()` (lines 347–378):

```php
    #[CrossTenantRoute(reason: 'Super-admin user directory: fans out over every tenant database (users are per-tenant post-T6) applying name/email search, tenant_id, email_verified, and status filters inside each tenant DB; merged + paginated in-memory; super_admin middleware gated.')]
    public function users(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->input('page', 1));

        $tenantQuery = Tenant::query();
        if ($tenantId = $request->input('tenant_id')) {
            $tenantQuery->where('id', $tenantId);
        }

        /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $rows */
        $rows = collect();

        foreach ($tenantQuery->cursor() as $tenant) {
            try {
                /** @var array<int, array<string, mixed>> $tenantUsers */
                $tenantUsers = $tenant->run(static function () use ($request, $tenant): array {
                    $query = User::query()->where('tenant_id', $tenant->id);

                    if ($search = $request->input('search')) {
                        $query->where(function ($q) use ($search): void {
                            $q->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                    }

                    if ($request->has('email_verified')) {
                        $emailVerified = filter_var($request->input('email_verified'), FILTER_VALIDATE_BOOLEAN);
                        if ($emailVerified) {
                            $query->whereNotNull('email_verified_at');
                        } else {
                            $query->whereNull('email_verified_at');
                        }
                    }

                    if ($status = $request->input('status')) {
                        $query->where('status', $status);
                    }

                    return $query->orderBy('created_at', 'desc')->get()->toArray();
                });
            } catch (Throwable $e) {
                Log::warning('Admin user directory: tenant database unreachable, skipping', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $tenantInfo = ['id' => $tenant->id, 'name' => $tenant->name];
            foreach ($tenantUsers as $userRow) {
                $userRow['tenant'] = $tenantInfo;
                $rows->push($userRow);
            }
        }

        $sorted = $rows->sortByDesc('created_at')->values();
        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        );

        return response()->json(['data' => $paginator]);
    }
```

Replace `showUser()` (lines 384–403):

```php
    #[CrossTenantRoute(reason: 'Super-admin user detail view: resolves the user INSIDE the addressed tenant database (tenant_id query param required — user rows are per-tenant post-T6) with company memberships; super_admin middleware gated.')]
    public function showUser(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|uuid',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail((string) $request->input('tenant_id'));

        /** @var array{user: array<string, mixed>, memberships: array<int, mixed>}|null $data */
        $data = $tenant->run(static function () use ($id, $tenant): ?array {
            $user = User::where('tenant_id', $tenant->id)->find($id);
            if ($user === null) {
                return null;
            }

            $memberships = DB::table('user_company_memberships')
                ->join('companies', 'user_company_memberships.company_id', '=', 'companies.id')
                ->where('user_company_memberships.user_id', $id)
                ->select([
                    'user_company_memberships.*',
                    'companies.name as company_name',
                ])
                ->get();

            return ['user' => $user->toArray(), 'memberships' => $memberships->all()];
        });

        if ($data === null) {
            return response()->json(['error' => 'User not found in this tenant'], 404);
        }

        $data['user']['tenant'] = ['id' => $tenant->id, 'name' => $tenant->name];

        return response()->json(['data' => $data]);
    }
```

Replace `verifyUserEmail()` (lines 409–452):

```php
    #[CrossTenantRoute(reason: 'Super-admin email-verification override: stamps email_verified_at on a user INSIDE the addressed tenant database (tenant_id required post-T6); logged to AdminAuditLog (central) after the tenant context is released.')]
    public function verifyUserEmail(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|uuid',
            'notes' => 'nullable|string|max:500',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail((string) $request->input('tenant_id'));

        /** @var array{user: array<string, mixed>, already_verified: bool}|null $result */
        $result = $tenant->run(static function () use ($id, $tenant): ?array {
            $user = User::where('tenant_id', $tenant->id)->find($id);
            if ($user === null) {
                return null;
            }
            if ($user->email_verified_at !== null) {
                return ['user' => $user->toArray(), 'already_verified' => true];
            }

            $user->update(['email_verified_at' => now()]);

            return ['user' => $user->fresh()?->toArray() ?? [], 'already_verified' => false];
        });

        if ($result === null) {
            return response()->json(['error' => 'User not found in this tenant'], 404);
        }

        if ($result['already_verified']) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_VERIFIED',
                    'message' => 'User email is already verified.',
                ],
            ], 400);
        }

        /** @var SuperAdmin $admin */
        $admin = $request->user();

        // AdminAuditLog is central-pinned (Task 5) — logged after run() releases
        // the tenant context, so the write lands centrally either way.
        $this->auditService->log(
            admin: $admin,
            action: 'verify_user_email',
            tenant: $tenant,
            entityType: 'user',
            entityId: $id,
            oldValues: ['email_verified_at' => null],
            newValues: ['email_verified_at' => now()->toDateTimeString()],
            notes: $request->input('notes') ?? 'Email manually verified by admin'
        );

        return response()->json([
            'data' => $result['user'],
            'message' => 'User email verified successfully.',
        ]);
    }
```

Add imports to the controller: `use Illuminate\Pagination\LengthAwarePaginator;` (plus `Log`/`Throwable` if not added in Task 8). Update the `showUser` route in `routes/api.php` only if its signature changed parameter binding (it doesn't — `Request` injection is positional-safe).

- [ ] **Step 4: Run tests + gates**

```bash
php artisan test tests/Feature/Admin/AdminUserDirectoryTest.php
php artisan test tests/Feature/Admin/SuperAdminAuthorizationTest.php
./vendor/bin/phpstan analyse app/Http/Controllers/Api/Admin/SuperAdminController.php --no-progress
./vendor/bin/pint app/Http/Controllers/Api/Admin/SuperAdminController.php
```
Expected: PASS / 0 errors.

- [ ] **Step 5: Frontend — pass tenant_id through verify-email**

`api/index.ts` (line 547):

```ts
export async function verifyUserEmail(
  userId: string,
  tenantId: string,
  notes?: string
): Promise<AdminUser> {
  return adminApiPost<AdminUser>(`/admin/users/${userId}/verify-email`, {
    tenant_id: tenantId,
    notes,
  })
}
```

`hooks/useUsers.ts`:

```ts
    mutationFn: ({ userId, tenantId, notes }: { userId: string; tenantId: string; notes?: string }) =>
      verifyUserEmail(userId, tenantId, notes),
```

`pages/CompanyOwnersPage.tsx` — `handleVerifyEmail` gains the tenant id (line 20–25):

```tsx
  const handleVerifyEmail = (userId: string, tenantId: string, userName: string) => {
    const notes = prompt(`Enter verification notes for ${userName} (optional):`)
    if (confirm(`Are you sure you want to verify email for ${userName}?`)) {
      verifyEmailMutation.mutate(notes ? { userId, tenantId, notes } : { userId, tenantId })
    }
  }
```

and the button callsite (line 156):

```tsx
                        onClick={() => { handleVerifyEmail(user.id, user.tenant_id, user.name); }}
```

(`AdminUser.tenant_id` already exists in the interface.)

- [ ] **Step 6: Frontend gates**

```bash
cd apps/web
pnpm exec tsc --noEmit -p tsconfig.json
pnpm exec eslint src/features/admin
pnpm vitest run src/features/admin/__tests__/
```
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php apps/api/tests/Feature/Admin/AdminUserDirectoryTest.php apps/web/src/features/admin/api/index.ts apps/web/src/features/admin/hooks/useUsers.ts apps/web/src/features/admin/pages/CompanyOwnersPage.tsx
git commit -m "fix(api+web): admin user directory fans out per tenant; detail/verify addressed by tenant_id"
```

---

### Task 11: TenantObserver token revocation under db-per-tenant (G1)

**Files:**
- Modify: `apps/api/app/Observers/TenantObserver.php` (`revokeAllUserTokens()`)
- Test: `apps/api/tests/Feature/Admin/AdminTenantSuspensionRevokesTokensTest.php` (create)

Design: enumerate user ids INSIDE `$tenant->run()`; delete `CentralPersonalAccessToken` rows (central-pinned) by `tokenable_type` + chunked `tokenable_id` OUTSIDE the tenant context. If the tenant DB is unreachable (G1 deletion path on broken/unprovisioned tenants), fall back to `central_identities.user_id` pointers so suspension/deletion still revokes what it can instead of 500ing.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantSuspensionRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspending_a_tenant_revokes_its_users_tokens(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $bystander = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $user->createToken('t');
        $bystander->createToken('t');

        $this->actingAs($superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$tenant->id}/suspend", ['reason' => 'billing'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Admin/AdminTenantSuspensionRevokesTokensTest.php
```
Expected: PASS in the shared-schema env (the bug is topology-only) **or** FAIL — either way this pins the invariant before the refactor. If it passes, the refactor must keep it green.

- [ ] **Step 3: Implement** — replace `revokeAllUserTokens()` (lines 95–104):

```php
    /**
     * Revoke every personal access token for every user belonging to the
     * given tenant.
     *
     * Users live in the PER-TENANT database (T6 Phase 0b) — enumeration runs
     * inside $tenant->run(); token rows live in the CENTRAL
     * personal_access_tokens table and are deleted from central context by
     * (tokenable_type, tokenable_id) chunks. If the tenant DB is unreachable
     * (e.g. deletion of a broken/unprovisioned tenant — G1), fall back to the
     * central_identities user_id pointers so the lifecycle hook degrades
     * instead of aborting the suspend/delete.
     */
    private function revokeAllUserTokens(Tenant $tenant): void
    {
        try {
            /** @var array<int, string> $userIds */
            $userIds = $tenant->run(
                static fn (): array => User::where('tenant_id', $tenant->id)->pluck('id')->all()
            );
        } catch (Throwable $e) {
            Log::warning('Token revocation: tenant DB unreachable, using central identity index', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            /** @var array<int, string> $userIds */
            $userIds = CentralIdentity::where('tenant_id', $tenant->id)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all();
        }

        if ($userIds === []) {
            return;
        }

        $morphClass = (new User)->getMorphClass();

        foreach (array_chunk($userIds, 200) as $chunk) {
            CentralPersonalAccessToken::query()
                ->where('tokenable_type', $morphClass)
                ->whereIn('tokenable_id', $chunk)
                ->delete();
        }
    }
```

Add imports: `use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;`, `use App\Modules\Tenant\Domain\CentralIdentity;`, `use Illuminate\Support\Facades\Log;`, `use Throwable;`.

- [ ] **Step 4: Run tests** — the new test plus existing observer coverage:

```bash
php artisan test tests/Feature/Admin/AdminTenantSuspensionRevokesTokensTest.php
php artisan test tests/Feature/Tenant/TenantDeprovisioningTest.php
grep -rl "revokeAllUserTokens\|TenantObserver" tests/ | xargs -n1 php artisan test
```
Expected: PASS.

- [ ] **Step 5: Gates + commit**

```bash
./vendor/bin/phpstan analyse app/Observers/TenantObserver.php --no-progress
./vendor/bin/pint app/Observers/TenantObserver.php
git add apps/api/app/Observers/TenantObserver.php apps/api/tests/Feature/Admin/AdminTenantSuspensionRevokesTokensTest.php
git commit -m "fix(api): tenant token revocation enumerates users in tenant DB, deletes central tokens (G1)"
```

---

## Part D — Verification

### Task 12: Static gates over the full changed surface

- [ ] **Step 1: Backend**

```bash
cd apps/api
git diff --name-only feat/sidebar-regroup-module-gating -- '*.php' | grep -v ^tests | sed 's|^apps/api/||' | xargs ./vendor/bin/phpstan analyse --no-progress
git diff --name-only feat/sidebar-regroup-module-gating -- '*.php' | sed 's|^apps/api/||' | xargs ./vendor/bin/pint --test
```
Expected: 0 PHPStan errors, pint clean (run pint without `--test` to fix, then re-stage). Adjust the `sed` if paths come out repo-rooted vs app-rooted — feed phpstan paths that exist.

- [ ] **Step 2: All new/changed backend feature tests in one scoped run**

```bash
php artisan test tests/Feature/Admin/
```
(This directory is small — it is NOT the full suite.)

- [ ] **Step 3: Frontend**

```bash
cd apps/web
pnpm vitest run src/features/admin
pnpm exec tsc --noEmit -p tsconfig.json
pnpm exec eslint src/features/admin
```

- [ ] **Step 4: Commit anything pint touched**

```bash
git add -u && git commit -m "style: pint/eslint fixes for admin db-per-tenant surface" || true
```

---

### Task 13: Live Bearer-only stack end-to-end (Playwright MCP)

- [ ] **Step 1: Bring the stack up and rebuild the SPA**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
docker compose -p erp-sidebar-demo -f docker-compose.sidebar-demo.yml up -d
cd apps/web && VITE_API_URL=http://localhost:8089 VITE_APP_PRODUCT=izipos pnpm build
```
The web container mounts `dist/` — no container rebuild needed. If the API container predates this branch's PHP changes, restart/rebuild the API service so the new controller code is live (check how the compose file mounts `apps/api` — if it's a bind mount, a restart suffices).

- [ ] **Step 2: Walk the panel with Playwright MCP** (http://localhost:8089). At each step, fail the task on ANY 401/500 in the network log:
  1. `/admin/login` → sign in `admin@synerivia.test` / `SidebarDemo2026!` → lands on dashboard.
  2. Dashboard: total tenants / users / companies render non-zero real numbers.
  3. Tenants list → open the pharmabio tenant → detail shows stats (users/companies/locations counts) and plan usage; toggle an extras module on and off (audit-logged).
  4. `/admin/verticals` renders and a config can be opened.
  5. `/admin/company-owners` lists owners with tenant names; if an unverified user exists, verify one (confirm toast + row flips to Verified).
  6. `/admin/billing`, subscriptions, invoices, payments tabs all render (empty tables are fine — zero 500s is the gate; this proves the central billing tables exist).
  7. `/admin/monitoring` renders health + critical metrics.
  8. `/admin/audit-logs` shows the extras-toggle and verify-email entries from this walk.
  9. Suspend then re-activate a NON-pharmabio tenant if a disposable one exists; otherwise suspend+immediately-activate pharmabio (verifies the observer path live). Then confirm tenant login still works: open a fresh tab → tenant login `owner@pharmabio.fr` / `password`.
  10. Logout → redirected to `/admin/login`; deep-link to `/admin/dashboard` redirects back to login (memory-only token gone).
- [ ] **Step 3: Screenshot the dashboard + company-owners pages** into `docs/sessions/` for the PR description.
- [ ] **Step 4: Fresh-tenant provisioning regression** (billing migrations left the tenant set): on the stack, create/provision a new tenant if a signup path exists, or run the tenant-migrate command for a new test tenant inside the API container, and confirm `widen_monetary_columns_to_scale_3` completes (it would fail if `billing_*` entries had remained). Command shape (verify against the container):

```bash
docker compose -p erp-sidebar-demo -f docker-compose.sidebar-demo.yml exec api php artisan tenants:migrate
```

- [ ] **Step 5: Update memory + handover artifacts** — mark the admin-panel Bearer-auth gap resolved in `project_sidebar_regroup_module_hardening` memory file; note the billing-tables topology move prominently in the PR body (ops implication: existing tenant DBs keep orphan billing tables; central DB gains them on next `migrate`).

---

## Out of scope (explicitly)

- Converting existing hardcoded-English admin pages to i18n (only NEW user-facing strings would need the `admin` namespace; this plan adds none).
- Denormalized fleet counters on `tenants` (seam exists in `TenantFleetStatsService` if needed).
- `central_identities` completeness backfill for non-owner users.
- Web-POS gating, module-management work (parent branch).

## Self-review notes

- Spec coverage: Problem 1 → Tasks 1–4; Problem 2 audit classifications → Tasks 5–11 (dashboard, showTenant, plan-usage, users×3, observer/G1, billing topology, audit-log pinning); cache gotcha → GlobalCache in Task 7; verification → Tasks 12–13; Bearer feature tests → Tasks 1–3 + live walk; cross-context data-pull feature tests → Tasks 7–11.
- Type consistency: `setAuth(admin, token)` used identically in Tasks 1/2/3; `verifyUserEmail(userId, tenantId, notes)` consistent across api/hook/page in Task 10; `TenantFleetStatsService::CACHE_KEY` public const used by its test.
- The shared-schema test env cannot prove cross-DB topology — every task notes this; Task 13 is the topology gate. This mirrors the existing suite's limitation and is accepted.
