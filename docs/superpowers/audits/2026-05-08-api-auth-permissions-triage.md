# api.auth-permissions cluster — Triage report

**Date:** 2026-05-08
**Branch tip:** `2ec486df` (post-module-gating lock)
**Cluster owner:** claude
**Master plan reference:** Section 15 (the LAST architectural cluster)
**Scope split:** PR1 (Invariants A+B+C) + PR2 (Invariant D)

---

## Section A — Token issuance + deletion enumeration

### A.1 — Sanctum token issuance (`->createToken('...', [...])`)

Hostile-grep across `apps/api/app/**/*.php` (excluding tests + `Password::createToken` + `EmailVerificationService::createToken`):

| # | File | Line | Context |
|---|------|------|---------|
| 1 | `app/Modules/Identity/Presentation/Controllers/AuthController.php` | 94 | `login` — issues bearer token after credential validation |
| 2 | `app/Modules/Identity/Presentation/Controllers/AuthController.php` | 233 | `register` — issues bearer token after registration |
| 3 | `app/Http/Controllers/Api/Admin/SuperAdminAuthController.php` | 45 | `super-admin login` — issues bearer token for super-admin pipeline |

**Total: 3 sites.** Matches master-plan-listed surface exactly. **NO scope expansion.**

**Out-of-scope by design (master plan §15 explicit exclusions):**
- `Password::createToken($user)` at `UserController.php:635` + `UserInvitation.php:49` — password-reset tokens, not Sanctum bearer tokens.
- `EmailVerificationService::createToken()` at `EmailVerificationService.php:28,98` — email-verification tokens, not Sanctum bearer tokens.

### A.2 — Sanctum token deletion (`$user->tokens()->delete()`)

| # | File | Line | Context |
|---|------|------|---------|
| 1 | `app/Modules/Identity/Presentation/Controllers/AuthController.php` | 288 | `logout` — clears tokens for the current user |
| 2 | `app/Modules/Identity/Presentation/Controllers/AuthController.php` | 313 | `logoutAll` — clears tokens for the current user |
| 3 | `app/Modules/Identity/Presentation/Controllers/AuthController.php` | 453 | `password-change` (forced re-login) |
| 4 | `app/Modules/Identity/Presentation/Controllers/UserController.php` | 342 | `deactivate user` |
| 5 | `app/Modules/Identity/Presentation/Controllers/UserController.php` | 481 | `reset password` |

**Total: 5 sites.** Master plan said "~6"; the variance (5 vs 6) is acceptable. All within the listed surfaces. **NO scope expansion.**

### A.3 — Tenant + User class shape

Both classes are `class` (NOT `final class`):
- `app/Modules/Tenant/Domain/Tenant.php:62` — `class Tenant extends BaseTenant implements TenantWithDatabase`
- `app/Modules/Identity/Domain/User.php:45` — `class User extends Authenticatable` (canonical, 176 use-statements)
- `app/Models/User.php:13` — Laravel stock stub, only 5 use-statements (all in `Inventory/Domain/`); NOT used for auth.

**Implication for testing:** PHPUnit `createMock` and Mockery `Mockery::mock` BOTH work on these classes. Module-gating cluster's lesson #4 (final-class workaround) does NOT apply here. Will still prefer behavioral DB-state assertions for clarity (count of `personal_access_tokens` rows before/after lifecycle event), but not because mocks are required — because behavioral tests are stronger evidence.

### A.4 — DB schema relevant to lifecycle hooks

`users.tenant_id` migration (`2025_11_30_000003_create_users_table.php:33-36`):
```
$table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
```

**Critical:** `ON DELETE CASCADE` is at the DB level — Eloquent `User::deleting`/`User::deleted` events do NOT fire when a tenant is deleted via `Tenant::delete()`. Token revocation MUST happen on `Tenant::deleting` (BEFORE the cascade) or via explicit query in the Tenant observer, NOT relying on a User-side observer triggered by cascade.

Sanctum `personal_access_tokens` is polymorphic (`tokenable_type` + `tokenable_id`), NOT a hard FK on `users.id`, so DB cascade does NOT delete tokens transitively.

### A.5 — TenantStatus enum

`app/Modules/Tenant/Domain/Enums/TenantStatus.php`: `Active`, `Suspended`, `Pending`, `Archived`. Suspension flow (`SuperAdminController::suspendTenant:206`) is `$tenant->update(['status' => 'suspended'])` — fires Eloquent `updating`/`updated` events (NOT a custom `suspending` event).

---

## Section B — Route group SetPermissionsTeam coverage table

### B.1 — Universal sweep across all `auth:sanctum` groups (37 module routes files + `routes/api.php`)

| Source | Group prefix | Stack | SetPermissionsTeam present? |
|--------|--------------|-------|----------------------------|
| `routes/api.php:48` | `v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `routes/api.php:41` | `v1/admin/auth` (super-admin) | `['auth:sanctum-admin', 'super_admin']` | EXEMPT (super-admin pipeline) |
| `routes/api.php:54` | `v1/admin` (super-admin) | `['auth:sanctum-admin', 'super_admin', 'throttle:admin-sensitive']` | EXEMPT (super-admin pipeline) |
| `Modules/Identity/routes.php:42` | `api/v1/auth` (protected sub-group) | `['web']` (parent) + `['auth:sanctum', SetPermissionsTeam::class]` (inner) | ✅ (note: inherits `web` from parent — see B.2) |
| `Modules/Identity/routes.php:53` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Identity/routes.php:59` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Cart/Presentation/routes.php:10` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/BatchExpiry/Presentation/routes.php:10` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Accounting/Presentation/routes.php:24` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Coupon/Presentation/routes.php:9` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Compliance/Presentation/routes.php:20` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Marketplace/Presentation/routes.php:12,47` | `api/v1` (×2) | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Progression/Presentation/routes.php:12` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Loyalty/Presentation/routes.php:24` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Document/Presentation/routes.php:32` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Catalog/Presentation/routes.php:15,27` | `api/v1` (×2) | `['api', 'auth:sanctum', SetPermissionsTeam::class, ...]` | ✅ |
| `Modules/Inventory/Presentation/routes.php:23` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Inventory']` | ✅ |
| `Modules/Pricing/Presentation/routes.php:18` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/PurchaseHub/Presentation/routes.php:12` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/PlatformIntegration/Presentation/routes.php:14` | `api/v1/webhooks` | `['api', VerifySynerivaWebhookSignature::class]` | EXEMPT (HMAC-signed webhook receiver) |
| `Modules/PlatformIntegration/Presentation/routes.php:22` | `api/v1/platform` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Menu/Presentation/routes.php:11` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Service/Presentation/routes.php:19` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Company/routes.php:21` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/SmartPrompts/Presentation/routes.php:10` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Treasury/Presentation/routes.php:25` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Promotion/Presentation/routes.php:9` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Uom/Presentation/routes.php:9` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Product/routes.php:27,41` | `api/v1` (×2) | `['api', 'auth:sanctum', SetPermissionsTeam::class, ...]` | ✅ |
| `Modules/Vehicle/Presentation/routes.php:21` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle']` | ✅ |
| `Modules/Dashboard/routes.php:18` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Contact/routes.php:18` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Scheduling/Presentation/routes.php:36` | `api/v1/storefront/{company_id}` | `['api']` (no auth:sanctum) | EXEMPT (public storefront, captcha-throttled) |
| `Modules/Scheduling/Presentation/routes.php:50` | `api/v1/scheduling` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop']` | ✅ |
| `Modules/Voucher/Presentation/routes.php:19` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Partner/routes.php:18` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Media/routes.php:18` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Expense/routes.php:20` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Tenant/routes.php:19` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/POS/routes.php:29` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Taxation/routes.php:16` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class]` | ✅ |
| `Modules/Workshop/Bundle/Presentation/routes.php:13` | `api/v1/workshop` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop']` | ✅ |
| `Modules/Workshop/Technician/Presentation/routes.php:23` | `api/v1` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop']` | ✅ |
| `Modules/Workshop/WorkOrder/Presentation/routes.php:23` | `api/v1/workshop` | `['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop']` | ✅ |

### B.2 — Coverage verdict

**ALL `auth:sanctum` (non-`-admin`) protected groups already include `SetPermissionsTeam::class`.** No coverage gaps detected.

**Implications for `AuthLifecycleTest`:**
- The architecture test will **pass GREEN on dev tip** — no missing-coverage bug to fix.
- Frame the PR body using the **T0.4 contract-codification pattern**: the test codifies an existing-and-correct contract; future regressions (someone adding a new module forgetting `SetPermissionsTeam`) are blocked by CI.
- This matches Operational Note #5 from the module-gating session-close (layering with super-admin-context arch test): the new test is COMPLEMENTARY to `ControllerTenantContextTest`, not duplicative. Document the layering in the test docblock.

### B.3 — Identity routes:42 anomaly (NOT a coverage gap, but worth surfacing)

`Modules/Identity/routes.php:42` uses inherit-`web`-from-parent + `['auth:sanctum', SetPermissionsTeam::class]` (inner). The resolved stack is `['web', 'auth:sanctum', SetPermissionsTeam::class]`. This is unusual — every other protected group uses `'api'` not `'web'` for the framework middleware tier.

**Verdict:** Out of scope for Invariant B (which only checks SetPermissionsTeam presence). Whether `web` vs `api` is correct here is a separate question — likely intentional for session/CSRF handling on auth endpoints, but worth flagging to the orchestrator. **Not blocking PR1.**

### B.4 — Super-admin pipeline exemption

The super-admin pipeline (`auth:sanctum-admin` guard, `super_admin` middleware) is governed by separate `SuperAdmin` model + `AdminAuditLog` infrastructure, not by `users.tenant_id`. Super-admin sessions don't have tenant context — they operate cross-tenant by design (see `#[CrossTenantRoute]` annotations on `SuperAdminController` actions). **Explicitly exempt from Invariant B.** Master plan §15 confirms.

---

## Section C — Tenant + User lifecycle hook design

### C.1 — Tenant suspension hook (Invariant A.1)

**Design:** Extend the EXISTING `App\Observers\TenantObserver` (currently handles `updated` for cache invalidation only).

**Event:** `updated` (Tenant model `update(['status' => 'suspended'])` flow at `SuperAdminController:206`).

**Detection:**
```php
public function updated(Tenant $tenant): void
{
    // Existing: cache invalidation
    if ($tenant->wasChanged(['vertical', 'enabled_extras'])) {
        $this->invalidateTenantConfigCache($tenant);
    }

    // NEW: token revocation on suspension
    if ($tenant->wasChanged('status') && $tenant->status === TenantStatus::Suspended) {
        $this->revokeAllUserTokens($tenant);
    }
}

private function revokeAllUserTokens(Tenant $tenant): void
{
    User::where('tenant_id', $tenant->id)->each(function (User $user): void {
        $user->tokens()->delete();
    });
}
```

**Race window:** The token revocation runs in the `updated` event AFTER the DB commit of the status change. A request arriving in the millisecond window between commit and revocation reads a `suspended` tenant but holds a still-valid token. **Acceptable per master plan §15 explicit framing.** Document this in the observer docblock + the PR body. Codex round-1 will probe this; the answer is "documented acceptable window; tightening would require explicit DB::transaction wrapping in the controller, deferred."

**Idempotency:** Multiple suspensions in a row only trigger revocation on the first transition (because `wasChanged('status')` is true only when the value actually changed). Re-activate then re-suspend triggers two revocations — correct behavior.

**Restoration consideration:** When a tenant is `activate`d (status `Suspended → Active`), tokens are NOT re-issued — users must re-login. This is the correct UX (suspended-then-restored should not silently hand back operational state). No new test required.

### C.2 — Tenant deletion hook (Invariant A.2)

**Event:** `deleting` (fires BEFORE the DB deletion + the `ON DELETE CASCADE` on `users.tenant_id`).

**Why `deleting` not `deleted`:** Once `users` rows are cascaded, the `User::where('tenant_id', $tenant->id)` query returns zero rows. We need to revoke tokens BEFORE the cascade.

**Implementation:**
```php
public function deleting(Tenant $tenant): void
{
    $this->revokeAllUserTokens($tenant);
}

public function forceDeleted(Tenant $tenant): void
{
    // Defensive: SoftDeletes is not currently in use, but if added later
    // this ensures the force-delete path is also covered.
    $this->revokeAllUserTokens($tenant);
}
```

**Note:** Tenant model does NOT use `SoftDeletes` today, so `deleting` is the only path. Including `forceDeleted` is forward-compatible defense — Codex will likely call this out as good practice. Cost is one method, small surface.

### C.3 — User tenant-change hook (Invariant A.3)

**Event:** `updated` on User model (after tenant_id change is committed).

**Why a NEW UserObserver:** No `App\Observers\UserObserver` exists today. Will create at `app/Observers/UserObserver.php` and register in `AppServiceProvider::boot()` alongside `Tenant::observe(TenantObserver::class)`.

**Implementation:**
```php
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('tenant_id')) {
            $user->tokens()->delete();
        }
    }
}
```

**Note on current state:** No production endpoint mutates `users.tenant_id` post-creation today. The defense is forward-compatible — when a future super-admin "move user between tenants" feature lands, this hook automatically protects it. Codex round-1 may ask "why bother if no path exists today" — answer: defense in depth + forward compatibility.

**Spatie team-id consistency (Invariant C):** `SetPermissionsTeam` middleware (`app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28`) reads `auth()->user()->tenant_id` directly each request — no caching at this layer. After tenant_id change + token revocation, the next request from this user (after re-login) flows through the new tenant_id. **No additional code needed for Invariant C** — already satisfied by `SetPermissionsTeam` reading the live attribute. Worth a unit test covering the "user re-logs in after tenant move, permissions resolve against new team" flow for evidence in the PR.

### C.4 — Architecture test (Invariant B)

**Test:** New file `apps/api/tests/Architecture/AuthLifecycleTest.php`.

**Scope:** Scan every `Route::middleware([...])` group across:
1. `apps/api/routes/api.php`
2. `apps/api/app/Modules/*/Presentation/routes.php`
3. `apps/api/app/Modules/*/routes.php`
4. `apps/api/app/Modules/Workshop/*/Presentation/routes.php` (sub-modules)

**Assertion:** For each group containing `'auth:sanctum'` (NOT `'auth:sanctum-admin'`), assert `SetPermissionsTeam::class` is also in the middleware list.

**Parser approach:** PhpParser-based AST scan (consistent with broadcast-channels round-3 lesson + module-gating's `ControllerTenantContextTest` precedent). The architecture test scans for `Route::*->middleware([...])` calls, extracts the array argument, classifies each call as protected/super-admin/public, and asserts the protected-group invariant.

**Documented parser limits (per broadcast-channels lesson #1):**
- Multi-line array definitions: handled by AST traversal.
- Conditional middleware via `if` blocks: NOT supported (none in current codebase; flag if introduced).
- Middleware merged via `Route::middlewareGroup()` server-side: assumed pre-resolved into the literal array (verified by spot-checking — none use this pattern).
- Closure-based middleware factories: NOT supported (none in current codebase).

**Expected outcome on dev tip:** PASSES GREEN. Per Section B.2, all protected groups already have `SetPermissionsTeam`. The test is **regression-protection / contract-codification** (T0.4 framing), not a red-anchored bug fix. Frame the PR body accordingly.

### C.5 — EnforceTokenTenantClaim middleware (Invariant D, PR2)

**Implementation sketch:**
```php
namespace App\Modules\Identity\Presentation\Middleware;

class EnforceTokenTenantClaim
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $next($request); // upstream auth:sanctum will reject
        }

        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();
        if (! $token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            // Not a Sanctum bearer token (e.g., session cookie or super-admin) — pass through.
            return $next($request);
        }

        $abilities = $token->abilities;
        $tenantAbility = collect($abilities)->first(
            fn (string $a): bool => str_starts_with($a, 'tenant:')
        );

        if ($tenantAbility === null) {
            // Grandfathering: pre-deploy tokens lack the claim. Allow until expiry.
            // See PR2 body for grandfathering policy decision.
            return $next($request);
        }

        $expectedTenantId = substr($tenantAbility, strlen('tenant:'));
        if ($expectedTenantId !== $user->tenant_id) {
            return response()->json(['error' => 'Token tenant mismatch'], 401);
        }

        return $next($request);
    }
}
```

**Issuance change:** Both `AuthController.php:94`, `AuthController.php:233`, AND `SuperAdminAuthController.php:45` updated to encode tenant ability:
```php
// Before:
$user->createToken($tokenName, ['*']);
// After:
$user->createToken($tokenName, ["tenant:{$user->tenant_id}", '*']);
```

**Super-admin handling:** SuperAdmin doesn't have `tenant_id` (separate model). Super-admin tokens encode `'super-admin'` ability instead of `tenant:*`, and `EnforceTokenTenantClaim` recognizes super-admin tokens (presence of `'super-admin'` ability) as exempt. Will be documented in PR2 body.

**Middleware order (CRITICAL — Codex anticipated finding #7 from prompt):**
```
['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, ...]
```
EnforceTokenTenantClaim MUST run AFTER `auth:sanctum` (so `currentAccessToken()` is resolved) AND AFTER `SetPermissionsTeam` (so the team-id is bound before any permission check, even though our middleware doesn't depend on team-id, it's the correct topological order).

**Grandfathering policy (PR2 explicit decision required):**

Three options Codex round-1 will probe:

| Option | UX | Security | Recommendation |
|--------|----|----|----|
| **(a) Reject all tokens lacking the claim** | All users forced to re-login on deploy | Strictest | NO — operational disruption |
| **(b) Allow tokens lacking the claim, enforce when claim present** | Smooth migration | Some tokens unprotected during migration window | **YES — recommend for PR2** |
| **(c) Time-bound: reject tokens issued before deploy_timestamp** | Smooth, but requires schema migration to track issued_at | Best of both | Defer; (b) is good-enough for a defense-in-depth layer atop A/B/C |

**PR2 will encode option (b)** with explicit unit-test coverage of all three branches:
- token has matching claim → allow ✅
- token has mismatching claim → reject 401 ✅
- token has no claim (grandfathered) → allow ✅

Operational follow-up (NOT in PR2): after a 30-day window where new tokens have the claim, optionally tighten to option (c). Track as future work.

---

## Section D — PR1 vs PR2 scope split confirmation

### D.1 — PR1: Invariants A + B + C (lifecycle hooks + universal coverage arch test)

**Scope:**
- Extend `app/Observers/TenantObserver.php` with `updated`-on-status-suspended + `deleting` + `forceDeleted` hooks.
- Create `app/Observers/UserObserver.php` with `updated`-on-tenant_id-changed hook.
- Register `User::observe(UserObserver::class)` in `AppServiceProvider::boot()`.
- Create `tests/Architecture/AuthLifecycleTest.php` (regression-protection per T0.4 contract-codification).
- Create `tests/Feature/Identity/AuthPermissionsTenantIsolationTest.php` with behavioral DB-state lifecycle tests (suspension, deletion, user-tenant-change).

**Manual rows (4):**
1. `manual:api.auth-permissions:tenant-suspension-token-revocation`
2. `manual:api.auth-permissions:tenant-deletion-token-revocation`
3. `manual:api.auth-permissions:user-tenant-change-token-revocation`
4. `manual:api.auth-permissions:setpermissionsteam-universal-coverage-arch-test`

**Risk:** LOW. No changes to the request path; only model lifecycle hooks. Worst-case bug: a hook fails, nothing else breaks (the tenant suspension still completes; just the token revocation might fail and surface as test failure).

### D.2 — PR2: Invariant D (token-tenant-claim defense-in-depth)

**Scope:**
- Create `app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`.
- Modify 3 issuance callsites in `AuthController.php` (×2) + `SuperAdminAuthController.php` to encode tenant ability (or `super-admin` ability for super-admin path).
- Register middleware in api stack alongside `SetPermissionsTeam` in `bootstrap/app.php` or a route-level alias.
- Document grandfathering policy (option b) in PR body.
- Tests: middleware unit tests covering all 3 grandfathering branches.

**Manual rows (1):**
5. `manual:api.auth-permissions:token-tenant-claim-defense-in-depth`

**Risk:** MEDIUM. Touches the request pipeline. A misconfigured middleware can lock everyone out. Mitigation: option (b) grandfathering means pre-deploy tokens still work; only mismatching new tokens are rejected. Strong test coverage required.

### D.3 — Why split into two PRs?

| Aspect | PR1 (A+B+C) | PR2 (D) |
|--------|-------------|---------|
| Surface | Eloquent observers + arch test | Request-path middleware |
| Risk | LOW (model hooks only) | MEDIUM (request pipeline) |
| Test pattern | Behavioral DB-state | Middleware mocking |
| Reviewer load | Smaller, focused | Smaller, focused |
| Failure mode | Hook fires too late or not at all | Lockout on misconfiguration |
| Codex review surface | A+B+C concerns are "is the lifecycle correct" | D's concern is "is the defense layer correct given A+B+C are correct" |

Splitting matches the master plan's "**One commit (or two — lifecycle hooks + architecture test as one, defense-in-depth tenant claim as second)**" guidance directly. **Confirmed: TWO PRs.**

### D.4 — Lock criteria

The cluster locks ONLY after BOTH PRs reach Codex `APPROVE` (potentially with minor edits applied). Per module-gating lesson #7: if the lock spans multiple SHAs, use the derivative review file pattern (`-001-NNN.md` aliases with single-SHA headers).

---

## Section E — Test design

### E.1 — PR1 lifecycle tests (Feature/Identity)

**File:** `apps/api/tests/Feature/Identity/AuthPermissionsTenantIsolationTest.php`

Test cases:

| # | Name | Pre-state | Mutation | Post-assertion |
|---|------|-----------|----------|----------------|
| 1 | `test_tenant_suspension_revokes_all_user_tokens` | Tenant A with 2 users (each with 1 token); Tenant B with 2 users (each with 1 token) | `Tenant A->update(['status' => 'suspended'])` | `personal_access_tokens` table has 0 rows for Tenant A's users; Tenant B users' tokens unaffected (count 2) |
| 2 | `test_tenant_deletion_revokes_all_user_tokens` | Tenant A with 2 users (each with 1 token); Tenant B with 2 users | `Tenant A->delete()` | Tenant A users' tokens revoked BEFORE the cascade deletes user rows; Tenant B unaffected |
| 3 | `test_user_tenant_change_revokes_old_tokens` | User U with 1 token, in Tenant A; Tenant B exists | `U->update(['tenant_id' => Tenant B->id])` | U's tokens revoked (count 0); other users' tokens unaffected |
| 4 | `test_tenant_status_change_other_than_suspend_does_not_revoke` | Tenant A with 1 user (with 1 token), status = Active | `Tenant A->update(['status' => 'pending'])` | User's tokens unchanged (count 1) — only `Suspended` triggers revocation |
| 5 | `test_tenant_re_suspend_idempotent` | Tenant A previously suspended, user re-logged after activate, status = Active | `update(['status' => 'suspended'])` | User's NEW tokens revoked (idempotent on the new token-set) |

**Assertion style (per module-gating lesson #4 preference):** Behavioral DB-state via Eloquent `count()` on `personal_access_tokens`. Examples:
```php
expect(DB::table('personal_access_tokens')
    ->whereIn('tokenable_id', [$userA1->id, $userA2->id])
    ->count())->toBe(0);
```
NOT using mocks, even though Tenant + User are not final.

### E.2 — PR1 architecture test

**File:** `apps/api/tests/Architecture/AuthLifecycleTest.php`

Single test method:
```php
test_every_auth_sanctum_route_group_includes_set_permissions_team()
```

**Approach:** PhpParser AST scan of the 3 route source paths (api.php + Modules/*/{Presentation/,}routes.php + Workshop/*/Presentation/routes.php). For each `Route::middleware($array)` call found, classify:
- Contains `'auth:sanctum'` (literal) AND NOT `'auth:sanctum-admin'` → MUST contain `SetPermissionsTeam::class`.
- Contains `'auth:sanctum-admin'` → exempt.
- Contains neither → exempt (public).

**Failure mode:** When a future contributor adds a new module forgetting `SetPermissionsTeam`, this test fails with a clear message:
```
Route group at app/Modules/NewModule/Presentation/routes.php:N is protected (auth:sanctum) but does not include SetPermissionsTeam::class. Per CLAUDE.md rule #12, all protected route groups must include this middleware.
```

**Layering with existing arch tests:**
- `tests/Architecture/ControllerTenantContextTest.php` (super-admin-context cluster): enforces "every controller method that resolves company_id sources from `CompanyContext` not raw header."
- `tests/Architecture/AuthLifecycleTest.php` (THIS): enforces "every protected route group has `SetPermissionsTeam::class`."

The two are complementary — one is method-level, one is route-level. Document this in `AuthLifecycleTest` docblock.

### E.3 — PR2 middleware tests

**File:** `apps/api/tests/Feature/Identity/EnforceTokenTenantClaimTest.php` (or unit test).

Test cases:

| # | Name | Token state | Expected |
|---|------|-------------|----------|
| 1 | `test_request_with_matching_tenant_claim_passes` | Token abilities `["tenant:$tenantId", "*"]`; user.tenant_id = $tenantId | 200 OK |
| 2 | `test_request_with_mismatching_tenant_claim_rejected_401` | Token abilities `["tenant:other-tenant-id", "*"]`; user.tenant_id != other | 401 |
| 3 | `test_request_with_grandfathered_token_no_claim_passes` | Token abilities `["*"]` (no `tenant:` ability) | 200 OK (grandfathering option b) |
| 4 | `test_super_admin_token_with_super_admin_ability_passes` | Token abilities `["super-admin"]` (super-admin pipeline) | 200 OK on super-admin route |
| 5 | `test_session_cookie_auth_passes_without_token_check` | Session-based auth (no PAT) | 200 OK |

**Pre-fix red anchor:** Before modifying issuance + adding middleware, write a feature test asserting that a hand-crafted token with `tenant:foreign-tenant-id` ability bound to a user in a DIFFERENT tenant successfully reaches a controller and returns data. After the fix, the same test asserts 401. Diff is RED→GREEN.

### E.4 — Verification gates

Per the orchestrator prompt Step 6:
- `vendor/bin/phpunit tests/Feature/Identity/` — all green
- `vendor/bin/phpunit tests/Architecture/` — full suite green (including AuthLifecycleTest)
- `vendor/bin/phpunit` — full backend green (no regressions)
- `./vendor/bin/phpstan analyse` — zero errors
- `./vendor/bin/pint --test app/Modules/Identity app/Modules/Tenant tests/` — clean
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — empty (no POS/Voucher drift)

---

## Codex preempts (folded in at orchestrator's instruction, 2026-05-09)

### Preempt 1 — `ON DELETE CASCADE` schema constraint dictates Tenant-side hook placement

Schema-level `ON DELETE CASCADE` on `users.tenant_id → tenants.id` (migration `2025_11_30_000003_create_users_table.php:33-36`) means Eloquent `User::deleting` events DO NOT fire when a tenant is deleted. The DB cascade removes user rows directly, bypassing Eloquent's event pipeline.

**Implication:** Tenant deletion token revocation MUST hook on `Tenant::deleting` (BEFORE cascade fires) and synchronously revoke tokens for all users belonging to the tenant. A `User::deleting` hook would never fire and would silently leave stale tokens.

This is the architectural reason A.2 lives on Tenant lifecycle, not on User lifecycle. Codex round-1 will probe this; the answer is here.

### Preempt 2 — A.2 / A.3 are forward-compatibility defense, not bug-fix gates

Tenant deletion + user-tenant-change endpoints don't exist in production today. The tests for A.2 and A.3 use direct Eloquent operations to exercise the lifecycle hook contract:
- A.2: `$tenant->delete()` (no admin endpoint exists)
- A.3: `$user->tenant_id = otherTenantId; $user->save()` (no admin endpoint exists)

PR1's PR body MUST explicitly frame this:

> Lifecycle hooks defend against admin endpoints that may be added later. Tests exercise the contract via direct Eloquent operations because no current production path triggers these events. The hooks are forward-compatible defense-in-depth, not bug-fix gates for a current vulnerability.

When Codex round-1 asks "what production path triggers tenant deletion / user tenant_id change?", the answer is in the PR body.

### Preempt 3 — `AuthLifecycleTest` is contract-codification (T0.4 framing), NOT a bug-fix gate

All 38 protected route groups already include `SetPermissionsTeam::class` per the hostile-grep in Section B. The arch test is REGRESSION-PROTECTION, not a bug-fix gate. Apply the T0.4 framing in the PR body verbatim:

> AuthLifecycleTest's SetPermissionsTeam coverage check is test-codification, not bug fix. All 38 protected route groups already include the middleware on dev tip. The test pins the contract so future route additions can't silently regress it. Standard "verify RED first" does not apply because there's no missing-coverage bug to fix today.
>
> The Invariant A lifecycle tests (tenant-suspension-revokes-tokens, tenant-deletion-revokes-tokens, user-tenant-change-revokes-tokens) ARE red-anchored bug-fix gates — those hooks don't exist on dev tip and the tests fail RED until the implementation lands.
>
> PR1 mixes test-codification (arch test) with bug-fix-gate (lifecycle hooks). PR body documents both shapes explicitly so reviewer doesn't conflate.

---

## Anticipated Codex round-1 findings (pre-emptive answers)

| # | Likely finding | Pre-emptive response |
|---|----------------|----------------------|
| 1 | Race window between status commit and token revocation | Documented as acceptable per master plan §15; tightening would require explicit DB::transaction wrapping in controllers. Cited in observer docblock + PR1 body. |
| 2 | User tenant_id change semantics — should we also wipe Spatie roles assigned in old tenant? | NO. Roles are assigned per-team-id; old roles remain in old team_id rows but the user's new tenant_id never resolves to those rows. Acceptable; if super-admin wants a "clean slate" move, they can explicitly remove old role rows in a separate operation. Document this in PR1 body. |
| 3 | Architecture-test parser robustness | Documented limits in test docblock (no conditionals, no closure factories, no middleware-group facade resolution). Spot-check confirms current codebase doesn't use these patterns. |
| 4 | PR2 grandfathering policy | Option (b): allow missing-claim tokens, enforce mismatching claim. Documented decision with all three options compared. |
| 5 | AuthLifecycleTest passes GREEN on dev tip — is this a real test? | YES — T0.4 contract-codification framing. The test codifies an existing-and-correct contract; future regressions are blocked by CI. Same precedent as several arch tests already in the suite. |
| 6 | Final-class testing approach | N/A — Tenant + User are NOT final. Mocks would work but we prefer behavioral DB-state assertions (cleaner evidence, matches module-gating lesson #4 preference). |
| 7 | Middleware order in PR2 stack | EnforceTokenTenantClaim AFTER auth:sanctum + AFTER SetPermissionsTeam. Documented in PR2 body + middleware docblock. |
| 8 | Existing TenantObserver only handles `updated` — risk of breaking existing cache invalidation | Extension is purely additive; the existing `wasChanged(['vertical', 'enabled_extras'])` block is preserved. |
| 9 | Why bother with user-tenant-change hook when no production endpoint exposes it today | Defense in depth + forward compatibility. When a future super-admin "move user" feature lands (likely needed for billing escalations / tenant merges), the hook is already in place. |

---

## Stop conditions checklist

| Stop condition | Status |
|----------------|--------|
| Hostile-grep surfaces token sites OUTSIDE master-plan-listed surfaces | ❌ NO — 3 issuance + 5 deletion sites all in listed surfaces |
| Step 1 finds protected route groups MISSING `SetPermissionsTeam` | ❌ NO — all 38 `auth:sanctum` groups have it |
| Tenant or User class is `final` (would change test approach) | ❌ NO — both are `class` |
| Codex round-1 surfaces sister bug requiring scope expansion | (deferred to PR1 review) |
| POS/Voucher diff non-empty | (verified at Step 6) |

**No stop conditions hit. Cleared to proceed to Step 2 (manual rows + claim/start cluster) on orchestrator approval.**

---

## Recommendation

1. **Proceed to Step 2** with TWO PRs as scoped in Section D.
2. PR1 manual rows: 4 (suspension, deletion, user-tenant-change, arch test).
3. PR2 manual rows: 1 (token-tenant-claim defense-in-depth).
4. Confidence: HIGH on PR1 scope; HIGH on PR2 with grandfathering decision documented.
5. Estimated cost: 4-6 hr realistic (triage 1hr ✅ done, manual rows + tests 2hr, PR1 impl 0.5hr, PR1 Codex 1hr, PR2 impl + tests 1hr, PR2 Codex 1hr, lock 0.5hr).

**Awaiting orchestrator approval before Step 2.**
