# API Super-Admin Context + Web Super-Admin Frontend — Triage

**Date:** 2026-05-08
**Cluster IDs:** `api.super-admin-context` (Section 9) + `web.super-admin-frontend` (Section 13) — paired session
**Branch:** `feat/tenant-isolation-sweep-execution`
**HEAD at triage:** `fcb4c7ab` (platform-integration lock)
**Inventory baseline:** 1700 events / 345 callsites / 0 problems (verify-history clean)

---

## Cluster invariants

### `api.super-admin-context` (universal controller invariant)

Every public controller method under `app/Http/Controllers/**` and
`app/Modules/*/Presentation/Controllers/*Controller.php` MUST EITHER:

- **(a)** Resolve `CompanyContext` (constructor-injected `App\Modules\Company\Services\CompanyContext`)
  or otherwise scope its DB reads/writes to a single tenant via
  `tenant_id` / `company_id` predicates derived from the authenticated
  user's context.
- **(b)** Carry `#[CrossTenantRoute(reason: "<non-blank justification>")]`
  on the method, with reason text that names the specific fleet-wide /
  pre-auth / cross-tenant behavior the method performs.

No third option. The new architecture test enforces this universal.

### `web.super-admin-frontend` (queryKey namespace invariant)

Every TanStack `queryKey` literal in `apps/web/src/**` MUST start with EITHER:

- **(a)** A tenant-scoped domain identifier (e.g. `'documents'`,
  `'partners'`, `'fraud-alerts'`). These queries fire in the per-tenant
  auth context and are bound to the current tenant via the API client's
  `useAuthStore`/Sanctum cookie.
- **(b)** The literal `'admin'` segment. These queries fire under the
  super-admin auth context (`useAdminAuthStore` + `adminApiGet*`
  wrappers, distinct token, distinct route prefix). Files under
  `apps/web/src/features/admin/**` MUST always pick branch (b); files
  outside `features/admin/` MUST always pick branch (a). Cache
  contamination prevention.

The new web architecture test enforces this universal.

---

## Existing infrastructure (per orchestrator scope-check)

| Surface | Status |
|---|---|
| `App\Shared\Architecture\CrossTenantRoute` attribute | EXISTS — `TARGET_METHOD`, blank-`reason` rejected at construction |
| Sweep visitors (`ExistsRuleVisitor`, `FindCallVisitor`) honor the attribute | EXISTS — already skip flagging when present |
| `App\Models\SuperAdmin` (Authenticatable) + `App\Models\AdminAuditLog` | EXISTS |
| `App\Http\Middleware\EnsureSuperAdmin` (`super_admin` middleware) | EXISTS, mounted on every admin route group |
| `App\Http\Middleware\CrossTenantContext` | EXISTS |
| Admin route group at `routes/api.php:54` | EXISTS — `auth:sanctum-admin` + `super_admin` + `throttle:admin-sensitive` |
| `apps/web/src/features/admin/{components,hooks,pages,stores,api,lib}` | EXISTS — full structure with dedicated `useAdminAuthStore` + `adminApi*` wrappers |
| `apps/web/src/features/admin/api/index.ts` exports `adminApiGet/Patch/Post/GetPaginated` from `../lib/adminApi` | EXISTS — distinct from per-tenant `apiGet`/`apiPost` in `@/lib/api` |

**What is missing (this cluster's job):**

1. **ZERO production controller methods carry `#[CrossTenantRoute]` today.** A grep across `app/` for the attribute returns only the visitor source (`app/Application/Sweep/Visitors/`) and the attribute class itself. All production callsites that legitimately operate cross-tenant rely on the route-middleware gate (`super_admin`) for protection, but the static guarantee — that any tenant-unscoped controller method has been *reviewed and labeled* — does not yet exist.
2. **No top-level architecture test enforces the universal invariant.** The 7 existing arch tests are per-surface (broadcast channels, queue jobs, webhook controllers, console commands, sweep rule visitors, etc.). The universal "every controller method either gates via CompanyContext OR carries #[CrossTenantRoute]" guard does not yet exist — and the inventory mutate gate doesn't replace it (sweep visitors check validation rules + ad-hoc Find calls, not the universal controller-level invariant).
3. **No frontend architecture test enforces queryKey namespacing.** The convention is observed (verified below) but unguarded — a future PR could ship `['admin', ...]` from a tenant-scoped feature directory without tripping any test.

---

## API Side — Controllers Requiring `#[CrossTenantRoute]`

Discovery method: read every controller class under `app/Http/Controllers/Api/Admin/`, the admin-route handlers referenced from `routes/api.php` lines 35-116, plus the public-route handlers (Country, health-check `MonitoringController::ping`). Each method below operates fleet-wide, pre-auth, or on the actor's own session — none establishes a tenant context for DB reads, so each requires the attribute.

### A. `app/Http/Controllers/Api/Admin/SuperAdminController.php` (13 methods)

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `dashboard` | `Tenant::count()` + fleet-wide `users`/`companies`/`tenant_subscriptions` counts | `Super-admin dashboard aggregates fleet-wide tenant, user, and subscription counts for the platform-operations panel.` |
| 2 | `tenants` | `Tenant::with('subscription.plan')` paginated across the fleet | `Super-admin tenant directory: lists every tenant in the fleet with optional name/slug/tax_id search for support and billing operations.` |
| 3 | `showTenant` | `Tenant::findOrFail($id)` (any tenant by id) + per-tenant stats | `Super-admin tenant detail view: reads any tenant by id and renders fleet-context stats for support tickets and renewal review.` |
| 4 | `getTenantPlanUsage` | `Tenant::findOrFail($id)` then `PlanEnforcementService::getPlanSummary` | `Super-admin plan-usage probe: reads any tenant's plan limits and current usage to advise upgrades or investigate quota incidents.` |
| 5 | `extendTrial` | `Tenant::findOrFail($id)` + writes `tenant_subscriptions.trial_ends_at` | `Tenant lifecycle: super-admin extends trial period on any tenant; logged to AdminAuditLog with super_admin_id, oldValues, newValues.` |
| 6 | `changePlan` | `Tenant::findOrFail($id)` + writes `tenant_subscriptions.plan_id` | `Tenant lifecycle: super-admin changes subscription plan on any tenant; logged to AdminAuditLog with old plan_id and new plan_id.` |
| 7 | `suspendTenant` | `Tenant::findOrFail($id)` + writes `tenants.status = suspended` | `Tenant lifecycle: super-admin suspends any tenant for billing/abuse reasons; logged to AdminAuditLog with reason.` |
| 8 | `activateTenant` | `Tenant::findOrFail($id)` + writes `tenants.status = active` | `Tenant lifecycle: super-admin reactivates any tenant after suspension/expiration; logged to AdminAuditLog.` |
| 9 | `updateExtras` | `Tenant::findOrFail($id)` + writes `tenants.enabled_extras` | `Tenant lifecycle: super-admin updates the optional-modules whitelist on any tenant; logged to AdminAuditLog with previous and new extras.` |
| 10 | `auditLogs` | Reads `admin_audit_logs` joined to `super_admins` and `tenants` across the fleet | `Super-admin audit-log viewer: reads admin_audit_logs across all tenants and super-admin actors for compliance review.` |
| 11 | `users` | `User::with(['tenant'])` paginated across the fleet | `Super-admin user directory: lists users across all tenants for support, account verification, and incident response.` |
| 12 | `showUser` | `User::findOrFail($id)` + cross-tenant `user_company_memberships` join | `Super-admin user detail view: reads any user by id with their company memberships for support and audit.` |
| 13 | `verifyUserEmail` | `User::findOrFail($id)` + writes `email_verified_at`; logs to AdminAuditLog | `Super-admin email-verification override: manually verifies email_verified_at on any user; logged to AdminAuditLog.` |

### B. `app/Http/Controllers/Api/Admin/SuperAdminAuthController.php` (3 methods)

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `login` | Pre-auth: `SuperAdmin::where('email', ...)` lookup before any session/tenant exists | `Super-admin authentication: pre-auth credential check against the super_admins table; no tenant context exists until session is established (and super-admins operate fleet-wide thereafter).` |
| 2 | `logout` | Operates on `$request->user()` (current super-admin's own Sanctum token); no DB write outside that token row | `Super-admin session logout: revokes the current super-admin's own access token; no tenant context — super-admins are platform-level actors.` |
| 3 | `me` | Returns `$request->user()` (super-admin's own profile) | `Super-admin self-profile: returns the authenticated super-admin's own record; super-admins are platform-level actors with no tenant context by design.` |

### C. `app/Modules/Billing/Presentation/Controllers/AdminBillingController.php` (14 methods)

This entire controller is mounted under the `admin/billing` route group and operates fleet-wide on `tenant_subscriptions`, `billing_invoices`, `billing_payments` — platform-level tables tenant-isolated by `tenant_id` column rather than schema (per `StripeWebhookController`'s docblock).

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `dashboard` | `TenantSubscription::*->count/sum`, `Payment::sum`, `Invoice::sum` across the fleet | `Super-admin billing dashboard: aggregates fleet-wide MRR/ARR/revenue, active/trial/past-due subscription counts, and outstanding/overdue invoice totals.` |
| 2 | `providers` | Reads `PaymentProviderManager::getProviderStatus()` — platform-level provider config | `Super-admin payment-provider config view: reads platform-level (Stripe/PayPal/etc.) provider status; provider configuration is fleet-wide, not per-tenant.` |
| 3 | `listPlans` | `Plan::orderBy('display_order')->get()` — platform-level catalog | `Super-admin plan catalog: lists all subscription plans in the platform-level Plan catalog; not tenant-scoped by design.` |
| 4 | `listSubscriptions` | `TenantSubscription::with(['tenant','plan'])` paginated across the fleet | `Super-admin subscription directory: lists subscriptions across all tenants for billing operations and renewal management.` |
| 5 | `getSubscription` | `TenantSubscription::findOrFail($id)` (any subscription by id) | `Super-admin subscription detail view: reads any tenant's subscription by id for support and dispute resolution.` |
| 6 | `updateSubscription` | `TenantSubscription::findOrFail($id)->update(...)` (status/notes on any subscription) | `Super-admin subscription manual edit: changes status (active/paused/cancelled) or appends admin notes on any tenant's subscription.` |
| 7 | `listInvoices` | `Invoice::with(['tenant','subscription.plan'])` paginated across the fleet | `Super-admin invoice directory: lists invoices across all tenants with optional status/tenant filter for billing reconciliation.` |
| 8 | `getInvoice` | `Invoice::findOrFail($id)` (any invoice by id) | `Super-admin invoice detail view: reads any tenant's invoice with line items, payments, and subscription/plan join for billing operations.` |
| 9 | `downloadInvoice` | `Invoice::findOrFail($id)` + `InvoiceService::generatePdf` | `Super-admin invoice PDF download: generates and serves a PDF for any tenant's invoice on demand from the billing console.` |
| 10 | `createInvoice` | Validates and creates `Invoice` for arbitrary `tenant_id` (super-admin manual invoice) | `Super-admin manual invoice creation: creates an invoice for any tenant outside the automated billing flow (custom-line-item charges, contractual adjustments).` |
| 11 | `listPayments` | `Payment::with(['tenant','invoice'])` paginated across the fleet | `Super-admin payment directory: lists payments across all tenants with optional status/provider/tenant filter for finance reconciliation.` |
| 12 | `getPayment` | `Payment::findOrFail($id)` (any payment by id, includes refunds + recorder) | `Super-admin payment detail view: reads any tenant's payment with refund history and recorder for dispute investigation.` |
| 13 | `recordPayment` | Creates `Payment` with arbitrary `tenant_id` (manual payment for any tenant) | `Super-admin manual payment record: records an out-of-band payment (bank transfer, cash, check) on any tenant's invoice; recorded_by stamps the super-admin id.` |
| 14 | `refundPayment` | `Payment::findOrFail($id)`, dispatches refund through provider, records refund row | `Super-admin payment refund: processes a refund (full or partial) on any tenant's payment; routes through provider for online payments and records refund row with super-admin as initiator.` |

### D. `app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php` (1 public method)

The class already carries a class-level `@cross-tenant-by-design` annotation per the locked `api.webhooks-incoming` cluster — the `WebhookControllerTenantContextTest` arch test already validates this docblock. For the *new* universal controller-level arch test, the class-level annotation is not the same enforcement mechanism as `#[CrossTenantRoute]` on the method. The webhook-controller test is *narrower* (only `*Webhook*Controller*` filename pattern) and the universal test will see `handle()` as not using `CompanyContext` literally.

**Decision needed:** apply `#[CrossTenantRoute]` on `handle()` ALSO, OR have the universal arch test recognize the class-level `@cross-tenant-by-design` annotation as an equivalent classification.

**Recommendation:** apply the method-level attribute too. Reasons:
- The two enforcement mechanisms answer different questions (per-class shape inspection vs. per-method invariant gating). Both can coexist; the method-level attribute is cheap.
- Codex round 2 of `api.webhooks-incoming` already validated the class-level annotation; this would only ADD a method-level annotation, not rewrite the class-level one.
- It keeps the universal arch test's logic simple — one classifier (the attribute) instead of dual-classifier (attribute OR docblock).

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `handle` | Public webhook entry; no auth; tenant resolved from verified Stripe payload via globally-unique resource IDs | `Stripe webhook entry: tenant resolved from the Stripe-signed payload via globally-unique resource IDs (sub_*/in_*/pi_*) per master plan §8 shape (b); see class-level @cross-tenant-by-design annotation for the full tenant-resolution proof.` |

### E. `app/Modules/Admin/Presentation/Controllers/MonitoringController.php` (12 methods)

`ping` is mounted as a public health-check at `routes/api.php:24` (no auth — load-balancer probe). The other 11 methods are mounted under the `admin/monitoring` route group (super-admin auth) and operate fleet-wide on Laravel queue/horizon infrastructure.

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `ping` | Public load-balancer probe; reads `HealthCheckService::ping()` (DB reachability test); no tenant context | `Public load-balancer health probe: reachability check used by Dokploy/upstream load balancers; no auth, no tenant — must remain accessible without a session for liveness routing.` |
| 2 | `health` | Detailed health check via `HealthCheckService::check()` aggregating DB/Redis/queue health | `Super-admin platform health check: detailed reachability diagnostics across the fleet's shared infrastructure (DB, Redis, queue workers); not tenant-scoped by design.` |
| 3 | `systemHealth` | `MonitoringService::getSystemHealth()` aggregating fleet-wide metrics | `Super-admin system-health monitor: fleet-wide CPU/memory/disk/error-rate metrics for platform operations; not tenant-scoped.` |
| 4 | `performance` | `MonitoringService::getPerformanceMetrics()` aggregating fleet-wide metrics | `Super-admin performance monitor: fleet-wide request-latency/throughput metrics for platform operations; not tenant-scoped.` |
| 5 | `critical` | `MonitoringService::getCriticalMetrics()` aggregating business-critical fleet-wide metrics | `Super-admin critical-metric monitor: fleet-wide business-critical KPIs (failed-payment rate, fiscal-chain break count) for platform incident response; not tenant-scoped.` |
| 6 | `queues` | `MonitoringService::getQueueMonitoring()` aggregating Laravel queue depth/failed-jobs across the fleet | `Super-admin queue monitor: fleet-wide queue depth, processing rate, and failed-job counts for platform operations; queue infrastructure is fleet-wide.` |
| 7 | `dashboard` | Combines all four monitoring sources above | `Super-admin monitoring dashboard: combined system/performance/critical/queue dashboard for platform operations; aggregates fleet-wide metrics, not tenant-scoped.` |
| 8 | `testSentry` | Throws `RuntimeException` (production-blocked) | `Super-admin Sentry probe: throws a controlled exception to validate Sentry error-tracking pipeline; non-production gate; no DB or tenant interaction.` |
| 9 | `retryFailedJob` | Reads/writes `failed_jobs` and `jobs` tables (Laravel queue infrastructure) | `Super-admin queue operation: requeues a specific failed job; failed_jobs/jobs tables are platform-level Laravel queue infrastructure, not tenant-scoped.` |
| 10 | `deleteFailedJob` | Deletes from `failed_jobs` (Laravel queue infrastructure) | `Super-admin queue operation: deletes a specific failed job permanently; failed_jobs is platform-level Laravel queue infrastructure, not tenant-scoped.` |
| 11 | `retryAllFailedJobs` | Iterates `failed_jobs` and re-queues each (Laravel queue infrastructure) | `Super-admin queue operation: bulk-retries every failed job in the platform-level failed_jobs queue; not tenant-scoped.` |
| 12 | `flushFailedJobs` | Truncates `failed_jobs` (Laravel queue infrastructure) | `Super-admin queue operation: flushes (truncates) the platform-level failed_jobs queue; not tenant-scoped.` |

### F. `app/Http/Controllers/Api/CountryController.php` (2 methods)

Mounted public at `routes/api.php:31-32` — read-only static reference data (countries + tax rates). No auth, tenant-agnostic by design.

| # | Method | Cross-tenant operation | Reason text to apply |
|---|---|---|---|
| 1 | `index` | `Country::where('is_active', true)->with('taxRates')->get()` — public reference catalog | `Public reference data: lists active countries with their default VAT rates from the platform-level countries catalog; no auth, tenant-agnostic — needed by signup forms and tenant-onboarding before any tenant context exists.` |
| 2 | `show` | `Country::with('taxRates')->findOrFail($code)` — public reference catalog | `Public reference data: reads a single country by ISO code from the platform-level catalog; no auth, tenant-agnostic — used by signup and onboarding forms.` |

### G. `app/Modules/Identity/Presentation/Controllers/AuthController.php` (pre-auth flows — needs deeper read)

The kickoff prompt explicitly cites this as a deferral candidate ("auth controllers that are pre-auth and tenant-agnostic by design"). Methods like `login`, `register`, `forgotPassword`, `resetPassword`, `verifyEmail`, `checkEmail`, `me`, `logout` are pre-auth or self-scoped. **Recommendation: apply `#[CrossTenantRoute]` on each pre-auth method individually**, with reason text citing the pre-auth nature; the post-auth methods (`me`, `logout`, `device-management`) operate on `$request->user()` (the actor's own row), which is the actor's *own* tenant — those should also carry the attribute since they don't pass through `CompanyContext`.

I deferred reading the full `AuthController` body line-by-line in this triage to keep scope tight; the per-method attribute application + reason text will be enumerated at Step 4 once Step 1 is approved. Listing it here as in-scope.

### Universe NOT requiring the attribute (verified or assumed cat-(a))

These controllers are tenant-scoped and rely on `CompanyContext` or equivalent pre-resolution of the user's tenant — they should pass the universal arch test's heuristic check naturally:

- All tenant-scoped controllers under `app/Modules/*/Presentation/Controllers/` that take `private readonly CompanyContext $companyContext` in their constructor (verified via grep on `Treasury\\PaymentController`, which is the canonical reference). Non-exhaustive examples: `Treasury/PaymentController`, `Treasury/MultiPaymentController`, `Document/InvoiceController`, `Catalog/*Controller`, `Inventory/*Controller`, `POS/*Controller`, `Loyalty/*Controller`, `Workshop/**/*Controller`, `Service/*Controller`, etc.

The arch test's heuristic regex must accept the literal substrings `CompanyContext`, `tenantId`, `tenant_id`, `companyId`, `company_id` somewhere in the method body. This works for the canonical pattern.

### Heuristic-gap candidates (cat-(a) but heuristic may NOT match)

These methods *are* tenant-scoped, but the heuristic regex (looking for `CompanyContext`/`tenantId`/`companyId`/`tenant_id`/`company_id`) may not match because the tenant derivation flows through `$user->tenant` or Route Model Binding rather than the literal strings:

| # | File:method | Tenant derivation path | Heuristic-gap risk |
|---|---|---|---|
| 1 | `app/Http/Controllers/Api/CompanyConfigController.php:show` | `$user->tenant()->first()` then `$user->companyMemberships()->where('is_primary', true)` | The body uses `$user->tenant`, `companyMemberships`, but NOT the literal `tenant_id`/`companyId`/`CompanyContext` strings — heuristic FAILS |
| 2 | `app/Http/Controllers/Api/SubscriptionController.php:show` | `$user->tenant` then `PlanLimitsService::getSubscriptionInfo($tenant)` | Same gap — `$user->tenant` is not in the regex set |
| 3 | `app/Http/Controllers/Api/DocumentAdditionalCostController.php:*` | Route Model Binding: `Document $document` is tenant-scoped via Eloquent global scope; method bodies use `$document->id`, `$cost->document_id` | The literal strings `tenant_id`/`companyId` do not appear; tenant binding is implicit through the model's global scope |

**Decision needed at Step 4:** how to make the heuristic robust enough to admit these legitimate cases without forcing them to carry `#[CrossTenantRoute]` (which would be misleading — they ARE tenant-scoped). Options:

- **Expand the heuristic regex** to also match `Auth::user()`, `auth()->user()`, `$request->user()`, `->tenant`, `->tenant_id` literally (catches options 1 and 2 above; option 3 would still fail).
- **Recognize Route Model Binding** by adding to the heuristic: "OR the method's parameter list type-hints a tenant-scoped Eloquent model" (catches option 3, but increases test complexity).
- **Use a deferrals fixture** (`tests/Architecture/fixtures/controller-tenant-context-deferrals.json`) for these specific cases with a documented reason.

**Recommendation:** combine (1) + (3). Expand the regex to admit `->tenant`/`Auth::user()`/`$request->user()` and explicitly defer the Route-Model-Binding cases (option 3). The fixture entry per case documents the alternate tenant-binding shape and prevents silent regressions if those methods are later refactored to lose the binding.

---

## Frontend Side — `queryKey` Audit

### Distinct first-segments inside `apps/web/src/features/admin/`

```
admin
```

Every `queryKey` literal under `features/admin/` is multi-segment with `'admin'` as the first segment:
- `['admin', 'dashboard']`
- `['admin', 'tenants', params]`
- `['admin', 'tenant', id]`
- `['admin', 'tenant-plan-usage', id]`
- `['admin', 'audit-logs', params]`
- `['admin', 'users', params]`
- `['admin', 'monitoring', 'health']`
- `['admin', 'monitoring', 'system']`
- `['admin', 'billing', ...]`
- etc.

**Result for cat-(b):** ✅ Clean. Every super-admin queryKey starts with `'admin'` consistently.

### Distinct first-segments outside `apps/web/src/features/admin/`

100+ tenant-scoped domain identifiers — all start with a domain noun (`'documents'`, `'partners'`, `'fraud-alerts'`, `'invoices'`, `'partners-search'`, `'pos-refund-policies'`, `'product-stock'`, etc.). Full list captured in the audit but not pasted here.

**Result for cat-(a):** ✅ ALMOST clean — ONE leakage found.

### LEAKAGE — `'admin-users'` in `features/compliance/`

**File:** `apps/web/src/features/compliance/components/FraudAlertActionModals.tsx:22`
**Code:**
```tsx
const { data: adminUsersData, isLoading: loadingUsers } = useQuery({
  queryKey: ['admin-users'],
  queryFn: getAdminUsers,
})
```

The `getAdminUsers` here is imported from `../api/fraudApi` (NOT the super-admin `features/admin/api`). `features/compliance/api/fraudApi.ts:95` defines it as:

```ts
export const getAdminUsers = async (): Promise<{ data: AdminUser[] }> => {
  return apiGet('/users?role=admin')
}
```

**Diagnosis:** This queryKey leaks the `admin-` prefix into a tenant-scoped feature. The function is misleadingly named — it queries the **tenant-scoped** `/users?role=admin` endpoint via the per-tenant `apiGet` wrapper, returning users with the admin role *within the current tenant*. It is NOT a super-admin call.

**Cache-contamination risk:** if a user signs out and a different user signs in (in the same browser), TanStack's queryClient does not automatically invalidate `['admin-users']` because (a) it doesn't share its first segment with `'admin'` (it's `'admin-users'` as a single string, not `['admin', 'users']` as a tuple) and (b) the tenant-scoped invalidation cycle invalidates by domain segment (`'users'`, `'partners'`, etc.) — `'admin-users'` falls outside both invalidation patterns.

**Confusing dual-naming:** there are now TWO functions named `getAdminUsers` in the codebase:
- `features/admin/api/index.ts:486:getAdminUsers` → super-admin scope (`/admin/users` with `adminApiGetPaginated`)
- `features/compliance/api/fraudApi.ts:95:getAdminUsers` → tenant scope (`/users?role=admin` with `apiGet`)

**Recommendation (Step 4 fix scope):**
1. Rename the queryKey from `['admin-users']` to `['users', 'admin-role']` (tenant-scoped namespace, as a tuple).
2. Optionally rename the compliance-side `getAdminUsers` function to `getTenantAdminUsers` to remove the dual-name confusion (deferred — out of scope for this cluster, but flag in the closing observations).

This is a SINGLE-LINE fix for the queryKey + a corresponding update to any `queryClient.invalidateQueries({ queryKey: ['admin-users'] })` callsites if they exist (none found in grep — likely the only one).

### Auth-store separation

| Surface | Result |
|---|---|
| `useAdminAuthStore` imports inside `features/admin/` | ✅ Used in `AdminLayout.tsx`, `RequireAdminAuth.tsx`, `AdminLoginPage.tsx` |
| `useAdminAuthStore` imports outside `features/admin/` | ✅ ZERO — clean separation |
| `adminApiGet*` wrappers used inside `features/admin/api/` | ✅ Consistent — every super-admin endpoint uses `adminApiGet`/`adminApiGetPaginated`/`adminApiPost`/`adminApiPatch` from `../lib/adminApi` |

**Result:** ✅ Auth-context boundary is clean. The `useAdminAuthStore` and `adminApi*` infrastructure is encapsulated to `features/admin/`.

### Existing web architecture-test scaffolding

`apps/web/src/__tests__/` contains:
- `__tests__/hooks/` — hook tests
- `__tests__/i18n/arLocaleCoverage.test.ts` — closest precedent for static-scan architecture tests on the web side

There is **no existing `__tests__/architecture/`** directory — this cluster will create one. Pattern: lift the static-scan + AST-walk approach from the API-side `tests/Architecture/*Test.php` (Vitest equivalent: read every `.tsx`/`.ts` file under `apps/web/src/`, extract `queryKey:` literals, classify by file path).

---

## Anticipated Codex Review Surface

The handoff prompt enumerates 6 likely scrutiny vectors. Pre-emptive responses to each, to fold into the round-1 submission:

1. **Reason text quality** — Each reason in tables A–F above names the *specific* fleet-wide / pre-auth / cross-tenant behavior the method performs (not generic "super-admin operation"). Codex hostile-grep should find non-vacuous justifications. The `extendTrial`/`changePlan`/etc. cases additionally cite the AdminAuditLog stamping path.

2. **Attribute application coverage** — Triage covered all 7 controllers reachable via the admin route groups + 2 public route handlers + the AuthController pre-auth flows. The completeness check is: `grep -rn "Tenant::all\|Company::all\|withoutTenancy\|UnsetCompanyId" app/` returns nothing in production code (verified). Methods that take no `$companyId`/`$tenantId` arg and don't construct-inject `CompanyContext` are flagged in tables A–G or in the heuristic-gap section.

3. **Heuristic gap honesty** — Documented openly in this triage (heuristic-gap candidates section) and flagged in Codex bullet #3 of the kickoff. The arch test's class docblock will explicitly state the limit: "matches the literal substrings `CompanyContext`, `tenantId`, `tenant_id`, `companyId`, `company_id`, `Auth::user`, `$request->user`, `->tenant` in the method body. The runtime invariant — does it ACTUALLY scope correctly — is enforced by the per-cluster cross-tenant tests already shipped." The 3 heuristic-gap cases are explicitly enumerated in `controller-tenant-context-deferrals.json` if the regex expansion does not catch them.

4. **Non-controller cross-tenant code** — Out of scope. Listeners, queue jobs, schedulers, broadcast channels, console commands are all covered by the existing 7 arch tests (per their cluster handoffs). The new universal arch test ONLY scans controllers; the boundary will be documented in the test's class docblock.

5. **Frontend leakage probes** — Triage found exactly ONE leakage (`features/compliance/.../FraudAlertActionModals.tsx:22`). Will be fixed by Step 4 as part of the queryKey-namespace contract codification (the arch test will RED on this single line until the rename ships). Frame as cat-(a) leakage with documented fix.

6. **AdminAuditLog interaction** — Cross-cluster observation. Of the 7 SuperAdminController methods that mutate state (`extendTrial`, `changePlan`, `suspendTenant`, `activateTenant`, `updateExtras`, `verifyUserEmail` + the auditLogs read), all 6 mutators DO log to `AdminAuditLog` via `AdminAuditService::logTenantAction` / `::log`. AdminBillingController mutators (`recordPayment`, `refundPayment`, `createInvoice`, `updateSubscription`) do NOT consistently log to AdminAuditLog — they stamp `recorded_by`/`initiated_by`/etc. on the row but don't append a separate audit-log entry. **This is a separate auditing-discipline gap** that does not gate this cluster's invariant; will surface as a future cluster observation if Codex asks.

---

## STOP for orchestrator approval

The triage is complete. Awaiting orchestrator decision on:

1. **API arch test heuristic shape** — adopt the expanded regex (`->tenant`, `Auth::user`, `$request->user`) AND a deferrals fixture for the 1 Route-Model-Binding case (DocumentAdditionalCostController), OR a different design choice?
2. **`StripeWebhookController::handle` dual-attribution** — apply method-level `#[CrossTenantRoute]` IN ADDITION to the existing class-level `@cross-tenant-by-design` annotation, OR have the universal test recognize the docblock as an equivalent classification?
3. **AuthController scope** — confirmed to apply method-level `#[CrossTenantRoute]` to every pre-auth and self-scoped method (login/register/forgotPassword/resetPassword/verifyEmail/checkEmail + me/logout/devices/etc.) at Step 4, with method-by-method reason text drafted at that time?
4. **Frontend leakage fix scope** — rename the single leaked queryKey to `['users', 'admin-role']` AS PART of this cluster's Step 4 (so the new web arch test goes from RED → GREEN in the same PR), OR ship the test red-anchored and fix in a follow-up?
5. **Triage-to-Step-2 sequencing** — once approved, seed manual rows + claim/start both clusters per Step 2 of the handoff, then proceed to Step 3 (TDD red anchors).

Estimated remaining time after approval: 2–3 hr (matches handoff estimate; infrastructure already in place collapses the work to attribute-application + arch-test authoring).
