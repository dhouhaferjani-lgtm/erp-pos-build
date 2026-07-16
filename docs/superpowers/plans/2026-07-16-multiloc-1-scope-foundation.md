# Multi-Location §1 Scope Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to run this plan. Steps use checkbox syntax.

**Goal:** Deliver package §1 of the multi-location design (`docs/superpowers/specs/2026-07-16-multi-location-management-design.md`): the scope foundation that turns location from a single-location *switch* into a persistent multi-select **view scope**, safely activates the dormant `allowed_location_ids` authorization axis (guarded backfill → write path → enforcement), and ships the shared `LocationScopeResolver` + FE `viewScopeStore` / `locationScopedKey` primitives that §2/§3/§4 consume verbatim. Constraints are the review findings A1–A11, F1–F5, F8 (`docs/superpowers/specs/reviews/2026-07-16-multi-location-design-review.md`).

**Architecture:** Backend adds one shared HTTP-only `LocationScopeResolver` (generalizes `OwnerReportScope` with a bypass permission + single-company default) plus two module-agnostic locations-list endpoints, all gated by the existing `user_company_memberships.allowed_location_ids` set that a guarded tenant migration first backfills to `NULL` (= all) for memberless active users — but only where the target company is unambiguous (single-company tenants; multi-company tenants skip+log and are mapped later via `users:backfill-memberships --company=`). A companion self-guarding migration registers the `users.manage_location_access` permission in the same push so the assignment UI/endpoint are safe under push=deploy. Frontend replaces the single-location `LocationSwitcher` with a persistent multi-select `ViewScopePicker` backed by a new `viewScopeStore` (persisted per company) and a `locationScopedKey` query-key helper that keeps the resource literal leading so mutation invalidation stays correct. Every write flow keeps its explicit per-form `location_id` (working location), untouched here.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types (hexagonal modules under `app/Modules/*`), PHPUnit; React 19 / Vite / TypeScript strict, Zustand 5, TanStack Query 5, react-i18next, Tailwind 4 design tokens, Vitest. Multi-tenancy = database-per-tenant (Stancl); tenant migrations under `apps/api/database/migrations/tenant/`.

## Global Constraints (copy verbatim from repo rules — apply to every task)

- **Route middleware pattern:** all module `routes.php` groups use `['api', 'auth:sanctum', SetPermissionsTeam::class]` (plus `EnforceTokenTenantClaim::class` where the module already uses it). Missing `'api'` → 401; missing `SetPermissionsTeam` → permission failures.
- **Constructor injection only** — every dependency via constructor with `private readonly`. **Never** use the `app()` helper (rule 13). The `Auth::` / `now()` facades are allowed; `app(...)` is not.
- **Strict typing** — no `mixed` in PHP (use DTOs/typed arrays with PHPDoc generics), no `any` in TypeScript (use `unknown` + type guards).
- **Enums for all status/type columns** — no magic strings (use `MembershipRole`, `MembershipStatus`, `UserStatus`).
- **i18n** — all user-facing FE text via `t()` (react-i18next); no hardcoded strings. New keys go in the `locations` namespace.
- **Design tokens** — Tailwind colors via `@/lib/designTokens` (`tokens`, `textColors`, `borderColors`, `semanticColorTokens`); no hardcoded `bg-*/text-*/border-*` color classes in new/touched lines. RTL-safe (`start-*/end-*`, `ms-*/me-*`).
- **`tenantScopedKey` on tenant queries** — every tenant-data `useQuery`/`useQueries` key uses `tenantScopedKey([...])` (or the new `locationScopedKey`, which wraps it) so tenant/company stay suffixes. Enforced by `apps/web/tools/audit-tanstack-keys.mjs`.
- **NEVER run the full PHPUnit suite** (crashes the laptop). Run tests BY PATH only: `cd apps/api && ./vendor/bin/phpunit path/to/Test.php`.
- **Push to origin/dev auto-deploys staging incl. `tenants:migrate`** — every migration must be idempotent and self-guarding (`WHERE NOT EXISTS`, no manual prerequisite). Permission additions must be paired with a documented `php artisan permission:cache-reset` deploy step (tenant-blind cache).
- **PHPStan level 8** — zero errors on new code (`cd apps/api && ./vendor/bin/phpstan`). FE: `pnpm typecheck` + `pnpm lint` clean per task.

---

### Task 1: Guarded membership backfill migration + manual-mapping command (LANDS FIRST)

Restores access for every **truly memberless** active user (zero membership rows for ANY company in this tenant DB) before any enforcement can brick them (review A1/A2/A11). **Company mapping is the load-bearing decision (review finding 1 — BLOCKER):**
- **Single-company tenant** (exactly one `companies` row): the target company is unambiguous → backfill every memberless active user into it.
- **Multi-company tenant** (>1 companies): no auditable per-user company signal exists, so the old "cross-join every user with every company" would grant every user access to every company. Instead, backfill ONLY the users whose company is unambiguous (none, absent a signal) and **SKIP** the rest, writing a structured log/report of the skipped user + company ids. Skipped users are already **deny-all today** (no membership → `LocationContext::getAllowedLocationIds` returns `[]` → fail-closed), so skipping is **not a regression**. The migration must **never fail** (push=deploy). A companion artisan command does the manual mapping for the skipped ones.

Idempotent, self-guarding, non-reversible.

**Files**
- Create: `apps/api/database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php`
- Create: `apps/api/app/Modules/Company/Presentation/Console/BackfillMembershipsCommand.php` (signature `users:backfill-memberships {--company=} {--user=*} {--all-memberless} {--force-multi}`)
- Test: `apps/api/tests/Feature/Company/Migrations/BackfillUserCompanyMembershipsTest.php`
- Test: `apps/api/tests/Feature/Company/Console/BackfillMembershipsCommandTest.php`

**Interfaces**
- Migration consumes (DB, tenant connection): `companies(id)`, `users(id, status)`, `user_company_memberships(id, user_id, company_id, role, allowed_location_ids, is_primary, status, created_at, updated_at)`.
- Migration produces: for each memberless active user in a **single-company** tenant → one row `{ role: viewer, allowed_location_ids: NULL, is_primary: false, status: active }` for that company. `NULL` = all-locations (`LocationContext::getAllowedLocationIds` returns `null` for it). In a **multi-company** tenant it produces **no rows** and logs `multiloc.backfill.skipped_ambiguous_users` with the skipped user + company ids.
- No public PHP signature on the migration (anonymous migration; test invokes `$migration->up()`).
- Command `BackfillMembershipsCommand`: `--company=<uuid>` (required) + either `--user=<uuid>` (repeatable — the auditable per-user mapping path for multi-company tenants: maps ONLY the named memberless users into the company) or `--all-memberless` (explicit bulk flag: maps every memberless active user; refuses to run in a multi-company tenant without it being paired with `--force-multi` acknowledgment). Never bulk-assigns implicitly. Idempotent; reports inserted count per user. Operates on the current tenant connection via `php artisan tenants:run`.

**Steps**

- [ ] Write the failing test. `apps/api/tests/Feature/Company/Migrations/BackfillUserCompanyMembershipsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class BackfillUserCompanyMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'T', 'slug' => 'bf-'.uniqid(), 'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeCompanyFor(Tenant $tenant, string $name = 'C'): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'legal_name' => $name.' SARL',
            'country_code' => 'FR', 'currency' => 'EUR', 'locale' => 'fr',
            'timezone' => 'Europe/Paris', 'date_format' => 'd/m/Y', 'status' => CompanyStatus::Active,
        ]);
    }

    /** Single-company tenant helper (the common case). */
    private function makeCompany(): Company
    {
        return $this->makeCompanyFor($this->makeTenant());
    }

    private function runBackfill(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php';
        $migration->up();
    }

    public function test_single_company_tenant_backfills_memberless_active_user_with_null_membership(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();

        $row = DB::table('user_company_memberships')
            ->where('user_id', $user->id)->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->allowed_location_ids);
        $this->assertFalse((bool) $row->is_primary);
        $this->assertSame('active', $row->status);
        $this->assertSame('viewer', $row->role);
    }

    public function test_multi_company_tenant_skips_memberless_user_and_writes_no_rows(): void
    {
        // Two companies in one tenant → ambiguous → SKIP (no regression: already deny-all).
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $companyB = $this->makeCompanyFor($tenant, 'B');
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        Log::spy();
        $this->runBackfill();

        // No membership row was created for the ambiguous user in EITHER company.
        $this->assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
        // A structured skip report was emitted (for the companion command to act on).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => $msg === 'multiloc.backfill.skipped_ambiguous_users')
            ->once();
    }

    public function test_existing_restricted_membership_is_untouched(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();
        $existing = UserCompanyMembership::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'role' => 'manager',
            'allowed_location_ids' => ['11111111-1111-1111-1111-111111111111'],
            'is_primary' => true, 'status' => 'active',
        ]);

        $this->runBackfill();

        $existing->refresh();
        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $existing->allowed_location_ids);
        $this->assertSame(1, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }

    public function test_inactive_user_gets_no_membership(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Inactive]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();

        $this->assertSame(0, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }

    public function test_rerun_is_a_noop(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }
}
```
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/Migrations/BackfillUserCompanyMembershipsTest.php`
- [ ] Implement the migration. `apps/api/database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php`:
```php
<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * §1 authorization activation, step 1 (spec §3 / review A2, A11, finding 1).
 *
 * Backfill a NULL-membership (= all locations) for every ACTIVE user that is
 * TRULY MEMBERLESS (has zero membership rows for ANY company in this tenant DB).
 * `UserController::store` historically created staff with no membership row at
 * all, so enforcement on stock transfers / replenishment would deny them
 * wholesale once restricted memberships start to exist. NULL (not []) means
 * "all" — see LocationContext::getAllowedLocationIds().
 *
 * Company mapping (review finding 1 — BLOCKER): the old cross-join of every
 * user with every company granted every user access to every company. Instead:
 *  - SINGLE-company tenant → the target company is unambiguous; backfill.
 *  - MULTI-company tenant → no auditable per-user company signal; SKIP + emit a
 *    structured `multiloc.backfill.skipped_ambiguous_users` log. Skipped users
 *    are already deny-all today (no membership → [] → fail-closed), so this is
 *    NOT a regression. The companion `users:backfill-memberships --company=`
 *    command maps them manually. The migration NEVER fails (push=deploy).
 *
 * Idempotent (memberless set already excludes anyone with a membership) and
 * self-guarding. Membership role is the least-privilege VIEWER — the membership
 * role is NOT the permission axis (Spatie roles are); its only authz consumer is
 * the self-escalation owner-exemption (Task 4), which must NOT treat backfilled
 * staff as owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyIds = DB::table('companies')->pluck('id')->all();
        $companyCount = count($companyIds);
        if ($companyCount === 0) {
            return;
        }

        // Truly memberless active users: no membership row for ANY company.
        $memberlessUserIds = DB::table('users')
            ->where('status', UserStatus::Active->value)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('user_company_memberships')
                    ->whereColumn('user_company_memberships.user_id', 'users.id');
            })
            ->pluck('id')
            ->all();

        if ($memberlessUserIds === []) {
            return;
        }

        if ($companyCount > 1) {
            // Ambiguous — cannot safely pick a company. Skip + report; never fail.
            Log::warning('multiloc.backfill.skipped_ambiguous_users', [
                'reason' => 'multi_company_tenant_no_company_mapping',
                'company_count' => $companyCount,
                'company_ids' => array_map('strval', $companyIds),
                'skipped_user_ids' => array_map('strval', $memberlessUserIds),
            ]);

            return;
        }

        $companyId = (string) $companyIds[0];
        $now = now();

        foreach ($memberlessUserIds as $userId) {
            DB::table('user_company_memberships')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'company_id' => $companyId,
                'role' => MembershipRole::Viewer->value,
                'allowed_location_ids' => null,
                'is_primary' => false,
                'status' => MembershipStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-reversible: backfilled rows are indistinguishable from legitimate
        // all-access memberships created afterwards. Intentional no-op.
    }
};
```
- [ ] Run it (green): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/Migrations/BackfillUserCompanyMembershipsTest.php`
- [ ] Write the companion command's failing test. `apps/api/tests/Feature/Company/Console/BackfillMembershipsCommandTest.php`: in a MULTI-company tenant, a memberless active user is skipped by the migration; running `$this->artisan('users:backfill-memberships', ['--company' => $companyA->id, '--user' => [$user->id]])` (with the tenant DB active) then creates exactly one NULL-membership row for THAT user in `$companyA`, exit 0, and does NOT touch a second memberless user not named in `--user`; asserts a second run is a no-op (still one row); asserts `--company=<unknown-uuid>` exits non-zero and writes no rows; asserts bare `--company` without `--user`/`--all-memberless` exits non-zero in a multi-company tenant (no implicit bulk grant); asserts `--all-memberless` WITHOUT `--force-multi` exits non-zero in a multi-company tenant and writes no rows; asserts `--all-memberless --force-multi` in a multi-company tenant backfills all memberless users into the named company (and is idempotent on re-run).
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/Console/BackfillMembershipsCommandTest.php`
- [ ] Implement the command. `apps/api/app/Modules/Company/Presentation/Console/BackfillMembershipsCommand.php` — signature `users:backfill-memberships {--company= : Company UUID to map memberless active users into}`. In `handle()`: require `--company`; verify it is a `companies.id` row in the current tenant connection (else `error()` + `return self::FAILURE`). If `--user` given: for each id verify the user exists, is active, and is memberless for that company, then insert one NULL-membership row (VIEWER, is_primary false, active) — this is the auditable per-user path. If `--all-memberless` given instead: require single-company tenant OR `--force-multi`, then apply the same insert to every memberless active user (same `whereNotExists` predicate as the migration). Bare `--company` with neither flag: `error()` + FAILURE. `info()` per-user results; `return self::SUCCESS`. Constructor injection only; no `app()`.
- [ ] Run it (green): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/Console/BackfillMembershipsCommandTest.php`
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php app/Modules/Company/Presentation/Console/BackfillMembershipsCommand.php app/Modules/Company/Domain`
- [ ] Commit: `feat(multiloc): guarded backfill (single-company) + users:backfill-memberships mapping command (§1 step 1)`

---

### Task 2: `UserController::store` creates a membership row for new staff

Closes the source of memberless staff (review A2) so the Task 1 backfill isn't re-opened by every future create.

**Files**
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- Test: `apps/api/tests/Feature/Identity/UserManagement/CreateUserTest.php` (add cases; mirror existing setUp)

**Interfaces**
- Consumes: `CompanyContext::requireCompanyId(): string` (already injected); `$validated['role']: string` (Spatie role name).
- Produces: inside the `store` transaction, one `UserCompanyMembership` row `{ user_id, company_id: current, role: MembershipRole::tryFrom($validated['role']) ?? MembershipRole::Viewer, allowed_location_ids: NULL, is_primary: (first membership), status: Active }`.

**Steps**

- [ ] Write the failing test. Add to `CreateUserTest.php`:
```php
public function test_store_creates_null_membership_for_new_staff(): void
{
    Notification::fake();

    $response = $this->withAuth($this->adminUser)->postJson('/api/v1/users', [
        'name' => 'New Cashier',
        'role' => 'cashier',
        'phone' => '+33612345678',
    ]);

    $response->assertCreated();
    $userId = $response->json('data.id');

    $membership = UserCompanyMembership::where('user_id', $userId)
        ->where('company_id', $this->company->id)->first();
    $this->assertNotNull($membership);
    $this->assertNull($membership->allowed_location_ids);
    $this->assertSame(MembershipRole::Cashier, $membership->role);
    $this->assertSame(MembershipStatus::Active, $membership->status);
}
```
(Use the file's existing auth helper — mirror the header/`Sanctum::actingAs` + `X-Company-Id` pattern already used by other tests in this class for `withAuth`.)
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Identity/UserManagement/CreateUserTest.php`
- [ ] Implement. In `UserController::store`, inside the `DB::transaction` closure, after `$user->assignRole(...)` and the null-email activation, add (import `UserCompanyMembership`, `MembershipRole`, `MembershipStatus`):
```php
UserCompanyMembership::create([
    'user_id' => $user->id,
    'company_id' => $this->companyContext->requireCompanyId(),
    'role' => MembershipRole::tryFrom($validated['role']) ?? MembershipRole::Viewer,
    'allowed_location_ids' => null, // Task 4 layers the gated allowed_location_ids write path
    'is_primary' => UserCompanyMembership::where('user_id', $user->id)->count() === 0,
    'status' => MembershipStatus::Active,
]);
```
- [ ] Run it (green): same command.
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Identity/Presentation/Controllers/UserController.php`
- [ ] Commit: `feat(multiloc): create NULL membership for staff on UserController::store (§1 step 2)`

---

### Task 3: `LocationScopeResolver` shared contract (PINNED)

The single HTTP-only read-scope resolver §2/§3/§4 consume verbatim (review A3/A10). Fail-closed, bypass-aware, single-company.

**Files**
- Create: `apps/api/app/Modules/Company/Services/LocationScopeResolver.php`
- Test: `apps/api/tests/Feature/Company/LocationScopeResolverTest.php`

**Interfaces**
- Consumes: `CompanyContext::requireCompanyId(): string`; `LocationContext::getAllowedLocationIds(string $companyId, ?User $user): ?array` (`null` = all, `[]` = deny-all, `[ids]` = subset); `User::can(string $permission): bool`; `Location` (company id set).
- Produces (PINNED — do not alter):
```php
final class LocationScopeResolver
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    /**
     * @param list<string> $requestedIds  location UUIDs from location_ids[] (empty = "all in my scope")
     * @param string|null  $bypassPermission  user holding it gets the full company location set
     * @return list<string> effective location ids
     * @throws \Illuminate\Auth\Access\AuthorizationException when any requested id is outside the allowed set
     */
    public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array;
}
```

**Resolver-application invariant (Cross-cutting 2 — copy into every consumer task, §2/§3/§4 included):** EVERY location-scoped endpoint ALWAYS calls `resolve()` and ALWAYS applies the returned effective ids to its query — **including no-param requests**. An empty `$requestedIds` is NOT "unfiltered": the resolver returns the caller's FULL effective allowed set (all company locations for NULL/bypass; the explicit subset otherwise; `[]` deny-all for a memberless caller), and the controller must `whereIn(...)` on exactly that. There is no code path where absent `location_ids[]` means "return every company location unconditionally".

**Bypass-permission policy (item 10 — the ONLY bypass used anywhere in the program):** the sole `$bypassPermission` value passed in §1–§4 is the **existing** `replenishment.process` (Task 11 replenishment index). Every other consumer — §3 treasury/finance/expenses and §4 analytics/dashboards — passes `null`. **No new `*_view_all_locations` permissions are introduced anywhere.**

**Steps**

- [ ] Write the failing test. `apps/api/tests/Feature/Company/LocationScopeResolverTest.php`. Build a tenant/company + two locations (A, B) + a bound `CompanyContext` (`app(CompanyContext::class)->setCompany($company)` in setUp is fine for an HTTP-context test), then:
```php
public function test_fail_closed_when_requesting_out_of_scope_location(): void
{
    $user = $this->userWithMembership([$this->locationA->id]); // restricted to A
    $this->expectException(AuthorizationException::class);
    $this->resolver()->resolve($user, [$this->locationB->id]);
}

public function test_subset_request_within_allowed_set_returns_request(): void
{
    $user = $this->userWithMembership([$this->locationA->id, $this->locationB->id]);
    $this->assertSame([$this->locationA->id], $this->resolver()->resolve($user, [$this->locationA->id]));
}

public function test_empty_request_returns_full_allowed_set(): void
{
    $user = $this->userWithMembership([$this->locationA->id]);
    $this->assertSame([$this->locationA->id], $this->resolver()->resolve($user, []));
}

public function test_null_membership_post_backfill_resolves_to_all_company_locations(): void
{
    $user = $this->userWithMembership(null); // NULL allowed_location_ids = all
    $result = $this->resolver()->resolve($user, []);
    sort($result);
    $expected = [$this->locationA->id, $this->locationB->id]; sort($expected);
    $this->assertSame($expected, $result);
}

public function test_bypass_permission_grants_full_company_set_despite_restriction(): void
{
    $user = $this->userWithMembership([$this->locationA->id], grant: 'replenishment.process');
    // requesting B (a real company location) is allowed under the bypass
    $this->assertSame([$this->locationB->id], $this->resolver()->resolve($user, [$this->locationB->id], 'replenishment.process'));
}
```
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/LocationScopeResolverTest.php`
- [ ] Implement. `apps/api/app/Modules/Company/Services/LocationScopeResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * HTTP-only read-scope resolver for location_ids[] query params (spec §1).
 * Generalizes OwnerReportScope but: (a) single-company (no parent expansion),
 * (b) optional bypass permission (e.g. replenishment.process → all shops).
 * Requires a bound CompanyContext — backfills/migrations/queued jobs must
 * derive location directly from data, never via this resolver (review A10).
 */
final class LocationScopeResolver
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    /**
     * @param  list<string>  $requestedIds
     * @return list<string>
     *
     * @throws AuthorizationException
     */
    public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array
    {
        $companyId = $this->companyContext->requireCompanyId();
        $effectiveAllowed = $this->effectiveAllowedIds($user, $companyId, $bypassPermission);

        $requested = array_values(array_unique($requestedIds));

        if ($requested === []) {
            return $effectiveAllowed;
        }

        if (array_diff($requested, $effectiveAllowed) !== []) {
            throw new AuthorizationException('Requested location is outside your allowed scope.');
        }

        return $requested;
    }

    /**
     * @return list<string>
     */
    private function effectiveAllowedIds(User $user, string $companyId, ?string $bypassPermission): array
    {
        if ($bypassPermission !== null && $user->can($bypassPermission)) {
            return $this->allCompanyLocationIds($companyId);
        }

        $membershipAllowed = $this->locationContext->getAllowedLocationIds($companyId, $user);

        // NULL column (incl. post-backfill all-access rows) = all company locations.
        if ($membershipAllowed === null) {
            return $this->allCompanyLocationIds($companyId);
        }

        // [] = deny-all (no active membership); [ids] = explicit subset.
        return array_values($membershipAllowed);
    }

    /**
     * @return list<string>
     */
    private function allCompanyLocationIds(string $companyId): array
    {
        return Location::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }
}
```
- [ ] Run it (green): same command.
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company/Services/LocationScopeResolver.php`
- [ ] Commit: `feat(multiloc): LocationScopeResolver shared read-scope contract (§1 step 4a)`

---

### Task 4: `users.manage_location_access` permission + gated allowed_location_ids write path + self-escalation guards

Adds the location-assignment write path with fail-closed self-escalation defense (review A7/A9).

**Files**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (add permission to the array — auto-granted to `admin` via `Permission::all()`; no separate `owner` Spatie role exists, so `admin` coverage is the grant)
- Create: `apps/api/database/migrations/tenant/2026_07_16_100100_register_manage_location_access_permission.php` (**self-guarding permission-registration migration — Finding 4**: idempotently inserts the `users.manage_location_access` permission row, grants it to the `admin` role, and flushes the permission cache, so the permission exists the moment the `can:` route in Task 6 ships under push=deploy)
- Modify: `apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php`, `.../UpdateUserRequest.php` (accept + shape-validate `allowed_location_ids`)
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php` (gated write + self-escalation guards; inject `LocationContext`)
- Test: `apps/api/tests/Feature/Identity/UserManagement/UserLocationAccessTest.php`
- Test: `apps/api/tests/Feature/Company/Migrations/RegisterManageLocationAccessPermissionTest.php`

**Interfaces**
- Consumes: `User::can('users.manage_location_access'): bool`; `LocationContext::getAllowedLocationIds(companyId, currentUser): ?array`; `LocationContext::getCurrentMembership(companyId, currentUser): ?UserCompanyMembership` (for `isOwner()`); request `allowed_location_ids: list<string>|null`.
- Produces: writes/updates the TARGET user's `user_company_memberships.allowed_location_ids` for the current company. Denies (403) when: caller lacks `users.manage_location_access`; caller edits own row; caller (non-Owner, restricted membership) grants ids outside own allowed set; any granted id is not a company location.
- **Ordering guarantee (Findings 3 & 7):** the grant is **authorized/validated BEFORE any row is created or mutated**, and the create/mutation + membership write + audit happen in ONE transaction, so a denied grant leaves NO user/membership/role/audit row (`store`) and leaves profile/role/membership unchanged (`update`).

**Steps**

- [ ] Write the failing test. `UserLocationAccessTest.php` — cases:
```php
public function test_forbidden_without_manage_location_access_permission(): void; // manager granter → 403
public function test_cannot_edit_own_allowed_location_ids(): void;                 // admin edits self → 403 SELF_LOCATION_ESCALATION
public function test_restricted_granter_cannot_grant_beyond_own_set(): void;       // granter allowed=[A] sets target=[A,B] → 403
public function test_restricted_granter_can_grant_within_own_set(): void;          // granter allowed=[A,B] sets target=[A] → 200, membership updated
public function test_admin_null_membership_can_grant_any_company_location(): void; // admin (NULL=all) sets target=[A,B] → 200
public function test_granting_non_company_location_is_rejected(): void;            // random uuid → 422/403

// Atomic-rollback (Finding 3 — store):
public function test_store_denied_grant_creates_no_user_membership_role_or_audit_row(): void;
//   caller WITHOUT users.manage_location_access POSTs /users with allowed_location_ids=[A]
//   → 403; assert users/user_company_memberships/model_has_roles/audit_events counts are
//   UNCHANGED from before the request (no partially-created user).

// Atomic-rollback (Finding 7 — update):
public function test_update_denied_grant_leaves_profile_role_and_membership_unchanged(): void;
//   caller edits target's name+role+allowed_location_ids where the grant is denied
//   (e.g. restricted granter granting beyond own set) → 403; assert target's name, roles,
//   and membership.allowed_location_ids are byte-identical to their pre-request values
//   (the whole update rolled back — no name/role leak).
```
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Identity/UserManagement/UserLocationAccessTest.php`
- [ ] Write the failing permission-migration test (Finding 4). `apps/api/tests/Feature/Company/Migrations/RegisterManageLocationAccessPermissionTest.php`: on a tenant whose permission table lacks `users.manage_location_access`, invoke the migration's `up()`; assert (a) a `permissions` row `users.manage_location_access` now exists, (b) the `admin` role has it, (c) a re-run is a no-op (still one permission row, still granted). Set the permission team id before asserting (mirror `StockTransferLocationScopeTest::setUp`).
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/Migrations/RegisterManageLocationAccessPermissionTest.php`
- [ ] Implement — permission-registration migration (Finding 4). `apps/api/database/migrations/tenant/2026_07_16_100100_register_manage_location_access_permission.php`: idempotently `Permission::findOrCreate('users.manage_location_access', 'sanctum')` (or a `WHERE NOT EXISTS` insert), grant it to the `admin` Spatie role (`Role::findByName('admin', 'sanctum')->givePermissionTo(...)` guarded so re-runs no-op) — guard is **`sanctum`**, matching `RolesAndPermissionsSeeder.php:438,450`; the `api` guard would silently create a duplicate permission the routes never check, then `app(PermissionRegistrar::class)->forgetCachedPermissions()` so the tenant-blind cache is flushed in the same push. This migration is the MECHANISM by which the permission ships; the seeder edit below is belt-and-suspenders for fresh installs.
- [ ] Implement — seeder (belt-and-suspenders, not the deploy mechanism). Add `'users.manage_location_access'` to the `$permissions` array in `RolesAndPermissionsSeeder::createPermissions()` (near the `users.*` block ~line 286) so fresh tenants seed it directly. `admin` gets it automatically via `Permission::all()`; do NOT add it to `manager`/`cashier` (owner+admin only per spec).
- [ ] Implement — FormRequests. In both `CreateUserRequest` and `UpdateUserRequest` `rules()`, add:
```php
'allowed_location_ids' => ['sometimes', 'nullable', 'array'],
'allowed_location_ids.*' => ['uuid'],
```
(Company-membership + subset checks are enforced in the controller where `CompanyContext` and the caller's membership are in scope.)
- [ ] Implement — controller. Inject `LocationContext $locationContext` into `UserController`. Split the grant into a **read-only authorization** method (runs BEFORE any create/mutation — Findings 3 & 7) and a **write** method (runs INSIDE the transaction):
```php
/**
 * Read-only, self-escalation-safe authorization for an allowed_location_ids grant.
 * Performs NO writes. Returns a JsonResponse on denial, else null. Callable before
 * any user/membership row exists ($targetUserId is null for store).
 *
 * @param  list<string>|null  $requested
 */
private function authorizeLocationGrant(User $currentUser, ?string $targetUserId, ?array $requested, Request $request): ?JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();

    if (! $currentUser->can('users.manage_location_access')) {
        return $this->forbidden('FORBIDDEN', 'You do not have permission to manage location access.', $request);
    }
    if ($targetUserId !== null && $targetUserId === $currentUser->id) {
        return $this->forbidden('SELF_LOCATION_ESCALATION', 'You cannot change your own location access.', $request);
    }

    if ($requested !== null) {
        $companyLocationIds = Location::where('company_id', $companyId)->pluck('id')->all();
        if (array_diff($requested, $companyLocationIds) !== []) {
            return $this->forbidden('INVALID_LOCATION', 'A selected location does not belong to this company.', $request);
        }

        $membership = $this->locationContext->getCurrentMembership($companyId, $currentUser);
        $isOwner = $membership?->isOwner() ?? false;
        $callerAllowed = $this->locationContext->getAllowedLocationIds($companyId, $currentUser); // null = all
        if (! $isOwner && $callerAllowed !== null && array_diff($requested, $callerAllowed) !== []) {
            return $this->forbidden('LOCATION_ESCALATION', 'You cannot grant locations outside your own access.', $request);
        }
    }

    return null;
}

/** Write the (already-authorized) grant. Call ONLY inside the store/update transaction. */
private function writeLocationGrant(string $targetUserId, ?array $requested, string $companyId): void
{
    UserCompanyMembership::where('user_id', $targetUserId)
        ->where('company_id', $companyId)
        ->update(['allowed_location_ids' => $requested]); // NULL clears restriction (all)
}
```
Wire `store` (**Finding 3 — authorize before creating anything, then create+write+audit atomically**):
```php
$requestedLocations = array_key_exists('allowed_location_ids', $validated)
    ? $validated['allowed_location_ids'] : false; // false = field absent

// BEFORE any row is created:
if ($requestedLocations !== false) {
    $denied = $this->authorizeLocationGrant($currentUser, null, $requestedLocations, $request);
    if ($denied !== null) {
        return $denied;
    }
}

$user = DB::transaction(function () use (...) {
    // ... existing create user + assignRole + Task 2 membership create + audit ...
    if ($requestedLocations !== false) {
        $this->writeLocationGrant($user->id, $requestedLocations, $this->companyContext->requireCompanyId());
    }
    return $user;
});
```
Wire `update` (**Finding 7 — guard runs before any mutation; write inside the existing transaction**):
```php
if (array_key_exists('allowed_location_ids', $validated)) {
    $denied = $this->authorizeLocationGrant($currentUser, $user->id, $validated['allowed_location_ids'], $request);
    if ($denied !== null) {
        return $denied; // BEFORE the DB::transaction that mutates name/role
    }
}
return DB::transaction(function () use (...) {
    // ... existing field + role mutation ...
    if (array_key_exists('allowed_location_ids', $validated)) {
        $this->writeLocationGrant($user->id, $validated['allowed_location_ids'], $this->companyContext->requireCompanyId());
    }
    // ... existing audit ...
});
```
(`forbidden()` = small helper mirroring the existing `response()->json([...], 403)` shape with `getMeta`.)
- [ ] Run it (green): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Identity/UserManagement/UserLocationAccessTest.php`
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Identity/Presentation/Controllers/UserController.php app/Modules/Identity/Presentation/Requests`
- [ ] **Deploy note (belt-and-suspenders — the migration is the mechanism):** the `2026_07_16_100100_register_manage_location_access_permission` migration auto-registers the permission + flushes the cache under push=deploy. As a redundant safety net the deploy checklist ALSO re-runs `RolesAndPermissionsSeeder` per tenant THEN `php artisan permission:cache-reset`; the route is safe even if that manual step is skipped.
- [ ] Commit: `feat(multiloc): users.manage_location_access (self-guarding migration) + authorize-before-write location grant (§1 step 3)`

---

### Task 5: Refactor `ValidLocationAccess` off `app()` + activate on stock-transfer create

Review A8: constructor-inject the contexts, activate only now (post-backfill).

**Files**
- Modify: `apps/api/app/Rules/ValidLocationAccess.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php` (inject `LocationContext`, add the rule to `source_location_id`)
- Test: `apps/api/tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php`

**Interfaces**
- Consumes: `LocationContext::canAccessLocation(locationId, companyId, user): bool`; `CompanyContext::hasCompany()/requireCompanyId()`; `Auth::user()`.
- Produces (new constructor — zero existing callsites, safe to change):
```php
public function __construct(
    private readonly LocationContext $locationContext,
    private readonly CompanyContext $companyContext,
    private readonly ?string $companyId = null,
) {}
```

**Steps**

- [ ] Write the failing test. Assert: a user restricted to location B is rejected (422) when creating a transfer with `source_location_id = A`; a NULL-membership (post-backfill) user is accepted; and grep-guard that `ValidLocationAccess.php` contains no `app(` token. Semantics reminder: stock transfer requires access to SOURCE only (destination may be anywhere) — apply the rule to `source_location_id`, not `destination_location_id`.
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php`
- [ ] Implement — rule. Replace the two `app(...)` calls with the injected `$this->locationContext` / `$this->companyContext`; keep `Auth::user()` (facade, allowed). Resolve company id via `$this->companyId ?? ($this->companyContext->hasCompany() ? $this->companyContext->requireCompanyId() : null)`; fail on null.
- [ ] Implement — request. In `StoreStockTransferRequest`, inject `LocationContext $locationContext` alongside the existing `CompanyContext`, and add to the `source_location_id` rule array (after `ScopedExists::company(...)`):
```php
new \App\Rules\ValidLocationAccess($this->locationContext, $this->companyContext, $company->id),
```
(Keep the controller's existing `canAccessLocation` check as defense-in-depth.)
- [ ] Run it (green): same command. Also re-run the existing per-user scope suite by exact path to confirm no regression: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferLocationScopeTest.php`. This file already pins the enforcement contract activated here — the load-bearing regression cases are `test_unrestricted_user_can_initiate_from_anywhere` (NULL-membership admin still creates from any source — must stay green post-backfill), `test_restricted_user_cannot_initiate_from_location_they_lack` (restricted user blocked from a source outside their set → 403 `LOCATION_ACCESS_DENIED`), and `test_restricted_user_can_initiate_from_their_location_to_any_destination` (source access is what's checked, destination may be anywhere).
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Rules/ValidLocationAccess.php app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`
- [ ] Commit: `refactor(multiloc): ValidLocationAccess off app(), activate on stock-transfer source (§1 step 4b)`

---

### Task 6: Locations-list endpoints (scoped + management) + remove duplicate controller

Review A4/A5/A6: a module-agnostic scoped picker endpoint, a management-gated unfiltered endpoint, one invariant that every list honors the allowed set.

**Files**
- Modify: `apps/api/app/Modules/Company/routes.php` (add two routes to the existing group)
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php` (inject `LocationScopeResolver`; add `scopedIndex`, `managementIndex`; scope the wired `index`)
- Delete: `apps/api/app/Modules/Inventory/Presentation/Controllers/LocationController.php` (dead duplicate — verified no route references it; `/locations` routes use the Company controller)
- Test: `apps/api/tests/Feature/Company/LocationListEndpointsTest.php`

**Interfaces**
- Consumes: `LocationScopeResolver::resolve(User, [], null): list<string>` (Task 3); `User::can('users.manage_location_access')` (via `can:` middleware on the management route).
- Produces:
  - `GET /api/v1/company/locations` (auth-only, company context required) → `{ data: list<{id,name,code,type,is_default}> }` limited to the caller's allowed set.
  - `GET /api/v1/company/locations/all` (middleware `can:users.manage_location_access`) → same shape, unfiltered company set.
  - Existing `GET /api/v1/locations` (Inventory-gated) `index` now returns only the resolver's effective set.

**Steps**

- [ ] Write the failing test. `LocationListEndpointsTest.php`:
```php
public function test_scoped_company_locations_excludes_unassigned_for_restricted_user(): void; // restricted=[A] → /company/locations returns only A
public function test_scoped_company_locations_returns_all_for_null_membership(): void;         // NULL → both A and B
public function test_management_all_returns_full_set_with_permission(): void;                  // can:users.manage_location_access → A and B
public function test_management_all_forbidden_without_permission(): void;                       // 403
public function test_wired_inventory_locations_index_honors_allowed_set(): void;               // GET /locations restricted=[A] → only A
```
- [ ] Run it (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/LocationListEndpointsTest.php`
- [ ] Implement — controller. Inject `LocationScopeResolver $scopeResolver`. Add:
```php
public function scopedIndex(Request $request): JsonResponse
{
    /** @var User $user */
    $user = $request->user();
    $allowedIds = $this->scopeResolver->resolve($user); // empty request = full allowed set
    return $this->pickerPayload($allowedIds, $request);
}

public function managementIndex(Request $request): JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();
    $ids = Location::where('company_id', $companyId)->pluck('id')->all();
    return $this->pickerPayload($ids, $request);
}
```
where `pickerPayload(array $ids, Request $request)` loads `Location::whereIn('id', $ids)->where('company_id', $companyId)->orderByDesc('is_default')->orderBy('name')->get()` and maps to `['id','name','code' => $l->code, 'type' => $l->type->value, 'is_default' => $l->is_default]`. Also scope the existing `index()`: replace its `Location::where('company_id',...)` fetch with `whereIn('id', $this->scopeResolver->resolve($request->user()))` (NULL-membership users are unaffected — resolver returns the full set).
- [ ] Implement — routes. In `apps/api/app/Modules/Company/routes.php`, inside the existing `Route::prefix('api/v1')->middleware([...])->group(...)`:
```php
Route::get('company/locations', [LocationController::class, 'scopedIndex'])->name('company.locations.scoped');
Route::get('company/locations/all', [LocationController::class, 'managementIndex'])
    ->middleware('can:users.manage_location_access')->name('company.locations.all');
```
(Import `App\Modules\Company\Presentation\Controllers\LocationController`.)
- [ ] Implement — delete the dead duplicate `app/Modules/Inventory/Presentation/Controllers/LocationController.php`. Re-verify no references: `cd apps/api && grep -rn "Inventory\\\\Presentation\\\\Controllers\\\\LocationController" app routes` returns nothing.
- [ ] Run it (green): same command.
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company/Presentation/Controllers/LocationController.php app/Modules/Company/routes.php`
- [ ] Commit: `feat(multiloc): scoped + management locations endpoints, drop duplicate controller (§1 step 4c)`

---

### Task 7: FE `viewScopeStore` (PINNED)

**Files**
- Create: `apps/web/src/stores/viewScopeStore.ts`
- Test: `apps/web/src/stores/viewScopeStore.test.ts`

**Interfaces**
- Consumes: `useCompanyStore` snapshot (`currentCompanyId`), `localStorage`.
- Produces (PINNED): state `{ scope: 'all' | string[] }` persisted per company to `autoerp-view-scope:<companyId>`; reset to `'all'` ONLY on a real company change (mirror `LocationProvider.previousCompanyIdRef`); cross-tab storage listener with a **malformed-payload guard that PRESERVES the previous scope** (Finding 6 — mirror `locationStore.ts:180-187`: a present-but-malformed `newValue` is IGNORED, it must NOT reset to `'all'`). Only a payload that validates (the literal `'all'` or an array of uuid strings) is applied. Hook `useViewScope()` is added in Task 9's slice but its shape is pinned here: `{ scope: 'all'|string[]; effectiveLocationIds: string[]; isAll: boolean; setScope(s: 'all'|string[]): void }`.

**Steps**

- [ ] Write the failing test. `viewScopeStore.test.ts` (Vitest, jsdom):
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'

// helper resets modules so the module-scope company subscription re-initialises
async function freshStore() {
  vi.resetModules()
  return await import('./viewScopeStore')
}

describe('viewScopeStore', () => {
  beforeEach(() => { localStorage.clear() })

  it('persists scope keyed by company id', async () => {
    // set currentCompanyId = 'c1' via companyStore before import, then:
    const { useViewScopeStore } = await freshStore()
    useViewScopeStore.getState().setScope(['loc-a'])
    expect(JSON.parse(localStorage.getItem('autoerp-view-scope:c1')!)).toEqual(['loc-a'])
  })

  it('resets to all on a real company change and never rehydrates A under B', async () => {
    localStorage.setItem('autoerp-view-scope:c1', JSON.stringify(['loc-a']))
    // start on c1 → hydrates ['loc-a']; switch companyStore to c2 → scope becomes 'all'
    // assert getState().scope === 'all' and c1's key is untouched
  })

  it('cross-tab storage event adopts a valid payload', async () => {
    // dispatch StorageEvent for the current company key with JSON.stringify(['loc-b'])
    // assert getState().scope === ['loc-b']
  })

  it('cross-tab malformed payload is ignored and PRESERVES the current scope', async () => {
    // Finding 6: set scope to ['loc-b'] first, THEN dispatch a StorageEvent for the
    // current company key with newValue '{not json'. Assert getState().scope is still
    // ['loc-b'] — a malformed payload must NOT reset the selection to 'all'.
    const { useViewScopeStore } = await freshStore()
    useViewScopeStore.getState().setScope(['loc-b'])
    window.dispatchEvent(new StorageEvent('storage', {
      key: 'autoerp-view-scope:c1',
      newValue: '{not json',
    }))
    expect(useViewScopeStore.getState().scope).toEqual(['loc-b'])
  })
})
```
- [ ] Run it (red): `cd apps/web && pnpm vitest run src/stores/viewScopeStore.test.ts`
- [ ] Implement. `apps/web/src/stores/viewScopeStore.ts`:
```ts
import { create } from 'zustand'
import { useCompanyStore } from './companyStore'

export type ViewScope = 'all' | string[]

interface ViewScopeState {
  scope: ViewScope
  setScope: (scope: ViewScope) => void
  /** internal hydration (company-change / cross-tab); not for component use */
  _hydrate: (scope: ViewScope) => void
}

const keyFor = (companyId: string): string => `autoerp-view-scope:${companyId}`

/**
 * Parse a payload into a ViewScope, or return null when it is absent/malformed/invalid.
 * (Finding 6) Callers distinguish "no valid payload" (null) from a valid persisted 'all'
 * so the cross-tab listener can PRESERVE the current scope instead of resetting it.
 * A valid payload is exactly the literal 'all' or an array of strings (location uuids).
 */
export function tryParseScope(raw: string | null): ViewScope | null {
  if (raw === null || raw === '') return null
  try {
    const parsed = JSON.parse(raw) as unknown
    if (parsed === 'all') return 'all'
    if (Array.isArray(parsed) && parsed.every((x) => typeof x === 'string')) {
      return parsed as string[]
    }
    return null // parsed but not a valid ViewScope shape
  } catch {
    return null // malformed JSON
  }
}

/** Initial-load parse: fall back to 'all' when there is no valid persisted scope yet. */
export function parseScope(raw: string | null): ViewScope {
  return tryParseScope(raw) ?? 'all'
}

function readPersistedScope(companyId: string): ViewScope {
  try { return parseScope(localStorage.getItem(keyFor(companyId))) } catch { return 'all' }
}

function persistScope(companyId: string, scope: ViewScope): void {
  try { localStorage.setItem(keyFor(companyId), JSON.stringify(scope)) } catch { /* quota/private mode */ }
}

export const useViewScopeStore = create<ViewScopeState>()((set) => ({
  scope: 'all',
  setScope: (scope) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    if (companyId) persistScope(companyId, scope)
    set({ scope })
  },
  _hydrate: (scope) => set({ scope }),
}))

// --- module-scope company-change + cross-tab wiring (mirrors LocationProvider
// previousCompanyIdRef + locationStore cross-tab guard) ---
if (typeof window !== 'undefined') {
  let previousCompanyId: string | null = null

  const syncForCompany = (companyId: string | null): void => {
    if (companyId === null) { previousCompanyId = null; return }
    if (previousCompanyId === null) {
      // first load / refresh → honor this company's persisted scope
      useViewScopeStore.getState()._hydrate(readPersistedScope(companyId))
    } else if (previousCompanyId !== companyId) {
      // REAL company change → reset to All (A's subset never rehydrates under B)
      useViewScopeStore.getState()._hydrate('all')
    }
    previousCompanyId = companyId
  }

  syncForCompany(useCompanyStore.getState().currentCompanyId)
  useCompanyStore.subscribe((state) => { syncForCompany(state.currentCompanyId) })

  window.addEventListener('storage', (event: StorageEvent) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    if (!companyId || event.key !== keyFor(companyId)) return
    if (event.newValue === null || event.newValue === '') return
    // Finding 6: a present-but-malformed/invalid payload must PRESERVE the current
    // scope — ignore the event unless it parses to a valid ViewScope. Only 'all' or
    // an array of uuid strings is applied; anything else leaves scope untouched.
    const next = tryParseScope(event.newValue)
    if (next !== null) {
      useViewScopeStore.getState()._hydrate(next)
    }
  })
}
```
- [ ] Run it (green): `cd apps/web && pnpm vitest run src/stores/viewScopeStore.test.ts`
- [ ] `cd apps/web && pnpm typecheck && pnpm lint`
- [ ] Commit: `feat(multiloc): viewScopeStore — per-company persisted view scope (§1 FE)`

---

### Task 8: FE `locationScopedKey` query-key helper (PINNED) + audit-tool approval

Review F1/F8: honest cache mechanism — scope as a NON-leading segment; resource literal stays leading so mutation invalidation by bare prefix still matches across scopes.

**Files**
- Create: `apps/web/src/lib/locationScopedKey.ts`
- Test: `apps/web/src/lib/locationScopedKey.test.ts`
- Modify: `apps/web/tools/audit-tanstack-keys.mjs` (add `'locationScopedKey'` to `APPROVED_FACTORY_CALLS`)
- Modify/Test: `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs` (approve `locationScopedKey`)

**Interfaces**
- Consumes: `tenantScopedKey(segments): readonly [...T, string|null, string|null]`.
- Produces (PINNED):
```ts
locationScopedKey(segments: readonly unknown[], scope: 'all' | readonly string[]): QueryKey
  === tenantScopedKey([...segments, { locScope: scope === 'all' ? 'all' : [...scope].sort() }])
```
Resource literal stays `segments[0]`; scope object precedes the tenant/company suffixes; deterministic (sorted scope).

**Steps**

- [ ] Write the failing test. `locationScopedKey.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { locationScopedKey } from './locationScopedKey'

describe('locationScopedKey', () => {
  it('keeps the resource literal leading for bare-prefix invalidation across scopes', () => {
    const a = locationScopedKey(['stock-levels', 'search'], 'all')
    const b = locationScopedKey(['stock-levels', 'search'], ['loc-b', 'loc-a'])
    expect(a[0]).toBe('stock-levels')
    expect(b[0]).toBe('stock-levels')
  })

  it('sorts the scope array for a deterministic key', () => {
    const k = locationScopedKey(['x'], ['loc-b', 'loc-a']) as unknown[]
    expect(k).toContainEqual({ locScope: ['loc-a', 'loc-b'] })
  })

  it("encodes 'all' as a literal", () => {
    const k = locationScopedKey(['x'], 'all') as unknown[]
    expect(k).toContainEqual({ locScope: 'all' })
  })
})
```
Add to `audit-tanstack-keys.test.mjs` a case asserting `scanCode("useQuery({ queryKey: locationScopedKey(['stock-levels'], scope), queryFn })")` yields zero violations.
- [ ] Run them (red): `cd apps/web && pnpm vitest run src/lib/locationScopedKey.test.ts tools/__tests__/audit-tanstack-keys.test.mjs`
- [ ] Implement helper. `apps/web/src/lib/locationScopedKey.ts`:
```ts
import type { QueryKey } from '@tanstack/react-query'
import { tenantScopedKey } from './tenantScopedKey'

/**
 * Location-aware tenant-scoped query key. The effective view scope is baked as a
 * NON-leading segment ({ locScope }) so the resource literal stays segments[0]:
 * mutations can still invalidate by bare literal prefix (e.g. ['stock-levels'])
 * and match every scope variant. tenant/company remain suffixes via tenantScopedKey.
 */
export function locationScopedKey(
  segments: readonly unknown[],
  scope: 'all' | readonly string[],
): QueryKey {
  const locScope = scope === 'all' ? 'all' : [...scope].sort()
  return tenantScopedKey([...segments, { locScope }]) as unknown as QueryKey
}
```
- [ ] Implement audit approval. In `audit-tanstack-keys.mjs`: `const APPROVED_FACTORY_CALLS = new Set(['tenantScopedKey', 'locationScopedKey']);`
- [ ] Run them (green): same command. Then `cd apps/web && pnpm audit:keys` (must exit 0).
- [ ] `cd apps/web && pnpm typecheck && pnpm lint`
- [ ] Commit: `feat(multiloc): locationScopedKey helper + audit approval (§1 FE)`

---

### Task 9: FE `ViewScopePicker` in TopBar + `useViewScope` + delete LocationSwitcher + migrate the four view pages

The canonical view-scope control (spec §1, review F2/F3/F5). Replaces `LocationSwitcher`; deletes the redundant pickers; migrates the four single-location view pages.

**Files**
- Create: `apps/web/src/components/organisms/ViewScopePicker/ViewScopePicker.tsx` (+ `index.ts`)
- Create: `apps/web/src/features/locations/hooks/useViewScope.ts`, `apps/web/src/features/locations/hooks/useScopedLocations.ts`, `apps/web/src/features/locations/api/scopedLocations.ts`
- Modify: `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx` (drop `showLocationSwitcher` suppression; render `ViewScopePicker`); `.../TopBar` (swap the control)
- Delete: `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx` (+ its `activeScopePredicate` broad invalidation)
- Modify: `apps/web/src/features/owner-dashboard/components/OwnerDashboardFilters.tsx` (DELETE the inline location checkbox picker + the `locationIds` field/handlers; dashboard reads the global scope)
- Modify (migrate off `locationStore.currentLocationId` as VIEW filter → `useViewScope().effectiveLocationIds` sent as `location_ids[]`): `apps/web/src/features/inventory/StockLevelsPage.tsx`, `.../StockMovementsPage.tsx`, `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx`, `apps/web/src/pages/POS/POSShiftsDashboard.tsx`
- Test: `apps/web/src/components/organisms/ViewScopePicker/ViewScopePicker.test.tsx`, `apps/web/src/features/locations/hooks/useViewScope.test.ts`

**Interfaces**
- `useScopedLocations(): UseQueryResult<ScopedLocation[]>` — GET `/company/locations`, key `tenantScopedKey(['company-locations','scoped'])`. `ScopedLocation = { id; name; code; type: LocationType; isDefault: boolean }`.
- `useViewScope(): { scope: 'all'|string[]; effectiveLocationIds: string[]; isAll: boolean; setScope(s: 'all'|string[]): void }` — `effectiveLocationIds` resolves `'all'` to `useScopedLocations()` ids; else the subset. `setScope` = `viewScopeStore.setScope`.
- `ViewScopePicker` props: `{ className?: string }` — renders "All locations" + a multi-select of the allowed set.

**Steps**

- [ ] Write the failing tests. `useViewScope.test.ts`: with `scope='all'`, `effectiveLocationIds` equals the scoped-locations ids (mock `useScopedLocations`); with `scope=['a']`, equals `['a']`; `isAll` reflects scope. `ViewScopePicker.test.tsx`: renders "All locations" + one option per allowed location (mock `useScopedLocations`); selecting narrows the scope (spy `setScope`); toggling to All calls `setScope('all')`; uses `t()` keys (assert rendered text, not classes); RTL-safe (uses `start/end` utilities).
- [ ] Run them (red): `cd apps/web && pnpm vitest run src/features/locations/hooks/useViewScope.test.ts src/components/organisms/ViewScopePicker/ViewScopePicker.test.tsx`
- [ ] Implement `api/scopedLocations.ts` (`apiGet<Raw[]>('/company/locations')` → `ScopedLocation[]`), `hooks/useScopedLocations.ts` (`useQuery` + `tenantScopedKey`, `enabled: !!tenantId && !!companyId`), `hooks/useViewScope.ts`:
```ts
import { useViewScopeStore } from '@/stores/viewScopeStore'
import { useScopedLocations } from './useScopedLocations'

export function useViewScope() {
  const scope = useViewScopeStore((s) => s.scope)
  const setScope = useViewScopeStore((s) => s.setScope)
  const { data: locations = [] } = useScopedLocations()
  const isAll = scope === 'all'
  const effectiveLocationIds = isAll ? locations.map((l) => l.id) : scope
  return { scope, effectiveLocationIds, isAll, setScope }
}
```
- [ ] Implement `ViewScopePicker.tsx` — a token-styled dropdown (reuse the `LocationSwitcher` visual shell but multi-select): "All locations" row (checked when `isAll`) + one checkbox row per allowed location; `t('locations:viewScope.*')` labels; design tokens only; `start-*/end-*` positioning. On change → `setScope('all')` or the toggled subset. No cross-scope broad `invalidateQueries` (the `locationScopedKey` mechanism handles refetch).
- [ ] Wire TopBar/DashboardLayout: render `<ViewScopePicker />` where `<LocationSwitcher />` was; remove the `showLocationSwitcher` const (line 24) and the `showLocationSwitcher={...}` prop (line 47) — the picker shows on all routes.
- [ ] Delete `LocationSwitcher.tsx` (and its `activeScopePredicate`). Update the barrel/exports; fix any remaining imports (`grep -rn "LocationSwitcher" src`).
- [ ] Edit `OwnerDashboardFilters.tsx`: remove the `locationIds` field from `OwnerDashboardFiltersValue`, the `handleLocationToggle`/`handleAllLocations` handlers, the `useLocationStore` import, and the location checkbox UI. Update the owner-dashboard page to source `location_ids[]` from `useViewScope().effectiveLocationIds` instead.
- [ ] Migrate the four pages: replace `const { currentLocationId } = useLocation()` VIEW usage with `const { effectiveLocationIds } = useViewScope()`; send `location_ids[]` (append each id) instead of a single `location_id`; switch the query key to `locationScopedKey([...], scope)`. Keep working-location reads (form prefills; `POSShiftsDashboard`'s get-or-create-web-terminal, which is single-location transact) on `locationStore`. For `ExpiryWriteOffPage`/`POSShiftsDashboard`, preserve their existing "no location selected" empty states in terms of `effectiveLocationIds.length === 0`.
- [ ] Run them (green) by exact file path (no directory-wide invocations): `cd apps/web && pnpm vitest run src/features/locations/hooks/useViewScope.test.ts src/components/organisms/ViewScopePicker/ViewScopePicker.test.tsx src/features/inventory/StockLevelsPage.test.tsx src/features/inventory/StockMovementsPage.test.tsx src/features/batches/pages/ExpiryWriteOffPage.test.tsx` (fix the migrated pages' existing tests to the new scope hook; `POSShiftsDashboard` has no test file — if the migration changes its behavior, add `src/pages/POS/POSShiftsDashboard.test.tsx` and run it by that exact path).
- [ ] Add `locations` namespace `viewScope.*` keys to the i18n resource files (all locales present in `apps/web/src/i18n`); run `cd apps/web && pnpm audit:keys` (must pass — migrated queries use `locationScopedKey`).
- [ ] `cd apps/web && pnpm typecheck && pnpm lint`
- [ ] Commit: `feat(multiloc): ViewScopePicker replaces LocationSwitcher; migrate view pages to global scope (§1 FE)`

---

### Task 10: FE staff location-assignment UI (create + edit) — Finding 2

The spec-required create/edit assignment surface for `allowed_location_ids`, without which the Task 4 write path has no UI (review finding 2 — BLOCKER). An "all locations" vs subset control, fed by the management endpoint, permission-gated, self-edit disabled. Depends on Task 4 (write path + permission) and Task 6 (`GET /company/locations/all`).

**Files**
- Modify: `apps/web/src/hooks/usePermissions.ts` (add `'users.manage_location_access': ['admin']` to the `PERMISSIONS` map — owner+admin only; there is no `owner` FE role, `admin` is the grant)
- Create: `apps/web/src/features/locations/hooks/useManagementLocations.ts` (`apiGet<Raw[]>('/company/locations/all')` → `ScopedLocation[]`; key `tenantScopedKey(['company-locations','all'])`; `enabled: !!tenantId && !!companyId && hasPermission('users.manage_location_access')`)
- Create: `apps/web/src/features/settings/components/LocationAccessField.tsx` (shared control: "All locations" radio/checkbox vs a subset multi-select of the management set; value `'all' | string[]`; `disabled` + `readOnly` props; `t()` + tokens; RTL-safe)
- Modify: `apps/web/src/features/settings/UsersPage.tsx` (create payload — `CreateUserData` gains `allowed_location_ids?: string[] | null`; render `<LocationAccessField>` in `AddUserModal` only when `hasPermission('users.manage_location_access')`; send it in the create mutation body)
- Modify: `apps/web/src/features/settings/components/UserEditModal.tsx` (edit — add `allowed_location_ids` to the mutation `data`; render `<LocationAccessField>` gated by the permission; **self-edit disabled**: when `user.id === currentUserId` (read `useAuthStore(s => s.user?.id)`) render the field `readOnly`/`disabled` with a hint, and never send `allowed_location_ids` for the own row)
- Test: `apps/web/src/features/settings/components/LocationAccessField.test.tsx`, `apps/web/src/features/settings/UsersPage.locationAccess.test.tsx`, `apps/web/src/features/settings/components/UserEditModal.locationAccess.test.tsx`

**Interfaces**
- Consumes: `useManagementLocations(): UseQueryResult<ScopedLocation[]>` (Task 6 `/company/locations/all`); `usePermissions().hasPermission('users.manage_location_access')`; `useAuthStore(s => s.user?.id)`.
- Produces: create/edit requests include `allowed_location_ids: string[] | null` (`null` = all locations, `[ids]` = subset), consumed by the Task 4 controller guard. Field hidden for callers lacking the permission; disabled+omitted on the caller's own row.

**Steps**

- [ ] Write the failing tests (render + payload; assert rendered text via `t()` keys, not classes):
  - `LocationAccessField.test.tsx`: renders an "All locations" option + one row per management location (mock `useManagementLocations`); choosing "All" yields value `null`; selecting a subset yields the sorted id array; `disabled`/`readOnly` blocks changes.
  - `UsersPage.locationAccess.test.tsx`: with the permission, `AddUserModal` renders the field and the create mutation body includes `allowed_location_ids`; WITHOUT the permission the field is absent and no `allowed_location_ids` key is sent.
  - `UserEditModal.locationAccess.test.tsx`: editing another user sends `allowed_location_ids`; editing OWN row (`user.id === currentUserId`) renders the field disabled and omits `allowed_location_ids` from the payload.
- [ ] Run them (red) by exact path: `cd apps/web && pnpm vitest run src/features/settings/components/LocationAccessField.test.tsx src/features/settings/UsersPage.locationAccess.test.tsx src/features/settings/components/UserEditModal.locationAccess.test.tsx`
- [ ] Implement `usePermissions.ts` map entry, `useManagementLocations.ts`, `LocationAccessField.tsx`, then wire it into `AddUserModal` (UsersPage) and `UserEditModal` per the Files notes. Add the `locations` namespace keys used by the field (e.g. `locations:staffAccess.allLocations`, `locations:staffAccess.subset`, `locations:staffAccess.selfDisabledHint`) to every locale in `apps/web/src/i18n`.
- [ ] Run them (green): same command. Then `cd apps/web && pnpm vitest run src/features/settings/UsersPage.test.tsx` if that file exists, to confirm no regression to the existing create flow.
- [ ] `cd apps/web && pnpm audit:keys` (must pass) then `cd apps/web && pnpm typecheck && pnpm lint`
- [ ] Commit: `feat(multiloc): staff location-assignment UI (create + edit, self-edit disabled) (§1 FE, finding 2)`

---

### Task 11: Behavioral-change surface — enumerate + regression-test enforcing endpoints under restricted memberships

Spec §1 "behavioral-change surface must be listed": the endpoints that already enforce memberships change behavior the moment restricted memberships exist. Verify no regression + preserve the replenishment processor bypass via the resolver.

**Files**
- Modify: `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php` (migrate the inline `getAllowedLocationIds` + `replenishment.process` carve-out to `LocationScopeResolver::resolve($user, $requested, 'replenishment.process')`)
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentLocationScopeTest.php`; `apps/api/tests/Feature/Inventory/StockTransferRestrictedMembershipTest.php`

**Interfaces**
- Consumes: `LocationScopeResolver::resolve(User, list<string>, ?string): list<string>` (Task 3).
- Produces: replenishment index filters `replenishment_requests.location_id IN resolve($user, $validated['location_ids'] ?? [], 'replenishment.process')`; processors (holders of `replenishment.process`) still see all shops; restricted requesters see only their set; out-of-scope `location_ids[]` → 403.

**Steps**

- [ ] Write the failing tests. `ReplenishmentLocationScopeTest.php`: (a) restricted requester (allowed=[A]) index returns only A rows; (b) processor sees A and B; (c) restricted requester passing `location_ids[]=[B]` gets 403 (resolver fail-closed). `StockTransferRestrictedMembershipTest.php`: restricted user (allowed=[A]) sees only transfers touching A (list), can create a transfer from A, and is denied creating from B (Task 5 rule) — documents the pre-existing enforcement still holds post-backfill.
- [ ] Run them (red): `cd apps/api && ./vendor/bin/phpunit tests/Feature/Replenishment/ReplenishmentLocationScopeTest.php tests/Feature/Inventory/StockTransferRestrictedMembershipTest.php`
- [ ] Implement. In `ReplenishmentRequestController::index`, inject `LocationScopeResolver` and replace the `if (! $user->can('replenishment.process')) { $allowed = ...; whereIn(...); }` block plus the separate `location_ids` filter with a single resolver call:
```php
$scoped = $this->scopeResolver->resolve($user, $validated['location_ids'] ?? [], 'replenishment.process');
$query->whereIn('replenishment_requests.location_id', $scoped);
```
(Keep `LocationContext` for the `validateLocationAccess` used on create at `:120`, or migrate that to the resolver too if trivial — but do NOT expand scope beyond index here.)
- [ ] Run them (green): same command. Re-run the existing endpoint suite by exact path to confirm the processor path is unchanged: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Replenishment/ReplenishmentRequestEndpointsTest.php` (its `test_list_open_returns_pending_and_in_progress_and_scopes_non_processors` covers the processor-vs-non-processor split the resolver now drives).
- [ ] PHPStan: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php`
- [ ] Commit: `refactor(multiloc): replenishment index via LocationScopeResolver; regression-cover restricted memberships (§1 step 4d)`

---

## Gates & verification

**Reviewer gates (per standing rule — both required at package end, before promoting to origin/dev):**
- `tenancy-authz-reviewer` — over the whole §1 diff: migration ordering (backfill → permission-registration → enforcement), the backfill's **company-mapping safety** (single-company backfill vs multi-company skip+log — no all-users-all-companies cross-join; the `users:backfill-memberships` command as the manual-mapping path), the `2026_07_16_100100_register_manage_location_access_permission` migration registers the permission + flushes cache in the same push, resolver fail-closed/bypass/single-company semantics + the **always-resolve/always-apply** invariant (empty request = full effective set, never unfiltered), the **only bypass permission is `replenishment.process`** (no new `*_view_all_locations`), self-escalation deny paths + **authorize-before-write atomicity** in `store`/`update` (denied grant leaves no/unchanged rows), route middleware (`['api','auth:sanctum',SetPermissionsTeam::class]` + `EnforceTokenTenantClaim` where used; `can:users.manage_location_access` on the management endpoint), no `app()` in `ValidLocationAccess`, the "every locations list honors the allowed set" invariant (both `/company/locations` and the wired `/locations`).
- `frontend-conventions-reviewer` — over the FE diff: `ViewScopePicker` on canonical/token styling + `t()` (locations ns) + RTL; `viewScopeStore` persistence keyed by company + reset-only-on-real-change + **malformed-guard PRESERVES previous scope** (never resets to 'all'); `locationScopedKey` non-leading scope segment + audit-tool approval; deletion of `LocationSwitcher.activeScopePredicate` broad invalidation and `OwnerDashboardFilters` inline picker; migrated pages send `location_ids[]` and key with `locationScopedKey`; **staff location-assignment UI** (Task 10) permission-gated + self-edit disabled + `LocationAccessField` on tokens/`t()`.

**Per-task local gates already inline:** PHPStan level 8 on touched backend paths; `pnpm typecheck` + `pnpm lint` per FE task; `pnpm audit:keys` after Tasks 8–10; tests BY PATH only — every command in this plan names exact file paths (no `--filter`, no directory-wide or "adjust to the real path" invocations); never the full PHPUnit suite. After any PHP DTO change run `cd apps/api && php artisan typescript:transform` then `cd apps/web && pnpm typecheck` — §1 adds plain-array endpoints (no transformed DTOs), so this is a guard, not expected to change generated types.

**E2E (Playwright, after both reviewer gates APPROVE):**
- Restricted manager (membership allowed = one store) logs in → `ViewScopePicker` lists only that store; a list page (Stock levels) shows only that store's rows.
- Scope persistence: pick a subset, reload → same subset (persisted per company); switch company → resets to "All".
- Visual check of `ViewScopePicker` in the TopBar in light AND dark theme (tokens render correctly, RTL locale mirrors the dropdown).

**Deploy checklist owed (record in the branch deploy note; stacks on prior treasury/location owes):**
1. `php artisan tenants:migrate` (runs `2026_07_16_100000_backfill_user_company_memberships` — single-company backfill / multi-company skip+log — AND `2026_07_16_100100_register_manage_location_access_permission`, which registers `users.manage_location_access` + grants `admin` + flushes cache. Both idempotent/self-guarding — the permission is live from this push alone).
2. **Multi-company tenants only:** grep the deploy logs for `multiloc.backfill.skipped_ambiguous_users`; for each such tenant, decide the correct company PER USER (from the skip log's user list) and run `php artisan tenants:run --tenants=<uuid> "users:backfill-memberships --company=<companyId> --user=<userId> [--user=<userId2> ...]"` — per-user mapping, never a bulk grant into one company. Until run, those users stay deny-all (no regression). Single-company tenants need nothing here.
3. Belt-and-suspenders (NOT the mechanism): optionally re-run `RolesAndPermissionsSeeder` per tenant THEN `php artisan permission:cache-reset`. The route is already safe via step 1's migration.
4. Verify the four migrated pages, the two new locations endpoints, and the staff location-assignment UI (create + edit) on staging with a deliberately restricted membership before considering the deploy done.
