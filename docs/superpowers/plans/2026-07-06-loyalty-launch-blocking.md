# Loyalty Launch-Blocking Set (LB-1..LB-4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A parapharmacy tenant runs loyalty end-to-end: seeded active program, enrollment from the customer record (boss app) and the Tauri POS (cashiers by default), and exactly-once point earning.

**Architecture:** Backend = Laravel 12 hexagonal modules (`app/Modules/Loyalty`, seams into POS projection via `Shared/Contracts`). Frontend = React 19 web admin (`apps/web`) + Tauri POS (`apps/pos`). Two branches/PRs: `feat/loyalty-launch` (Tasks 1–6, LB-1..3, reviewer `tenancy-authz-reviewer`) and `fix/loyalty-double-earn` (Tasks 7–9, LB-4, reviewer `fiscal-pos-reviewer`, board T-0005). Spec: `docs/superpowers/specs/2026-07-06-loyalty-launch-roadmap.md`.

**Tech Stack:** PHP 8.2 strict, PHPUnit (PG, `RefreshDatabase`), Spatie laravel-data DTOs, React/TanStack Query 5/Vitest, react-i18next.

## Global Constraints

- **NEVER run the full PHPUnit suite on the laptop** — tests BY PATH only: `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` or `./vendor/bin/phpunit tests/Feature/Loyalty/Xyz.php`.
- Rule 13: constructor injection, `private readonly`; never `app()`.
- Rule 19: no float touches money/points — bcmath numeric-strings; FormRequest money regex ceilings.
- Rule 12: vertical routes module-gated both layers (`module:Loyalty` + FE `hasModule('Loyalty')`).
- Rule 11: all FE strings via `t()`; add keys to EVERY locale dir that has the namespace file.
- Rule 7: new API response shapes get a Spatie `Data` DTO; run `php artisan typescript:transform` after (if it errors on cache, prefix `CACHE_STORE=array`).
- Rule 6: no cross-module model imports; `Shared/Contracts` or public services.
- Rule 18: design tokens in new/touched `.tsx` (import from `@/lib/designTokens` / existing UI atoms).
- Rule 17: valid UUIDs for all FK columns in tests (use `Str::uuid()->toString()` / factories).
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.loyalty` (base `cd7b8aefa` = origin/dev). Commit per task; commits/pushes as separate commands.
- Deploy note (carry into PR description): `loyalty.enroll` permission + new migration ⇒ staging needs per-tenant `php artisan migrate`, permission-seeder sync AND `php artisan permission:cache-reset` (Spatie cache is tenant-blind).

---

## Branch A — `feat/loyalty-launch` (Tasks 1–6)

### Task 1: Program bootstrap service + parapharmacy seeding (LB-1)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Application/Services/ProgramBootstrapService.php`
- Modify: `apps/api/database/seeders/ParapharmacySeeder.php` (run() sequence, after `seedPartners` ~line 390)
- Test: `apps/api/tests/Feature/Loyalty/ProgramBootstrapServiceTest.php`

**Interfaces:**
- Produces: `ProgramBootstrapService::ensureActiveProgram(string $tenantId, string $currency, string $name): LoyaltyProgram` — idempotent; returns existing active program untouched, else creates ACTIVE Points program + default Spend rule (mirror of `SeedDefaultEarningRuleOnProgramActivated`: name `Points per dinar`, `reward_value '1'`, `reward_type 'multiplier'`, `priority 1`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\ProgramBootstrapService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProgramBootstrapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_active_program_with_default_spend_rule(): void
    {
        $tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $service = $this->app->make(ProgramBootstrapService::class);

        $program = $service->ensureActiveProgram($tenant->id, 'TND', 'Programme fidélité');

        $this->assertSame(ProgramStatus::Active, $program->status);
        $this->assertSame('TND', $program->currency);
        $rule = EarningRule::query()->where('program_id', $program->id)->sole();
        $this->assertSame(EarningRuleType::Spend, $rule->rule_type);
        $this->assertSame('1', (string) $rule->reward_value);
        $this->assertTrue($rule->is_active);
    }

    public function test_idempotent_when_active_program_exists(): void
    {
        $tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $existing = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenant->id, 'status' => ProgramStatus::Active,
        ]);
        $service = $this->app->make(ProgramBootstrapService::class);

        $program = $service->ensureActiveProgram($tenant->id, 'TND', 'Programme fidélité');

        $this->assertSame($existing->id, $program->id);
        $this->assertSame(1, LoyaltyProgram::query()->where('tenant_id', $tenant->id)->count());
        // Does NOT add a rule to a program the admin configured.
        $this->assertSame(0, EarningRule::query()->where('program_id', $existing->id)->count());
    }
}
```

(`$this->app->make()` in a test is the standard container access — the rule-13 ban is on production code.)

- [ ] **Step 2: Run it — must fail** (`class ProgramBootstrapService not found`):
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/ProgramBootstrapServiceTest.php`

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent tenant loyalty bootstrap: guarantee one ACTIVE program with a
 * working default Spend rule. Used by launch seeders (ParapharmacySeeder and,
 * via inheritance, DemoPharmacySeeder); safe for future onboarding UX (PL-6).
 * Creates the row directly as Active (no ProgramActivated event) and seeds the
 * Spend rule explicitly — deterministic in seeder context, mirrors
 * SeedDefaultEarningRuleOnProgramActivated for admin-created programs.
 */
final readonly class ProgramBootstrapService
{
    public function __construct(
        private LoyaltyProgramRepositoryInterface $programRepository,
        private EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function ensureActiveProgram(string $tenantId, string $currency, string $name): LoyaltyProgram
    {
        $existing = $this->programRepository
            ->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($tenantId, $currency, $name): LoyaltyProgram {
            $program = LoyaltyProgram::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'program_type' => ProgramType::Points,
                'status' => ProgramStatus::Active,
                'currency' => $currency,
            ]);

            $this->earningRuleRepository->save(new EarningRule([
                'program_id' => $program->id,
                'name' => 'Points per dinar',
                'rule_type' => EarningRuleType::Spend,
                'priority' => 1,
                'is_active' => true,
                'conditions' => [],
                'reward_value' => '1',
                'reward_type' => 'multiplier',
            ]));

            return $program;
        });
    }
}
```

- [ ] **Step 4: Run the test — PASS.** If `LoyaltyProgram::factory()` lacks needed states, check `database/factories` (`LoyaltyProgramFactory` exists — entity declares it).

- [ ] **Step 5: Wire into ParapharmacySeeder.** **Do NOT change the `run()` signature** — `DemoPharmacySeeder::run()` calls `parent::run()` with zero args (plain PHP call, no container injection), so a required parameter fatals the demo seeding (adversarial review MAJOR-1). Instead **constructor-inject** the service (`public function __construct(private readonly ProgramBootstrapService $loyaltyBootstrap) {}` — seeders are container-resolved and `DemoPharmacySeeder` inherits the ctor; if `ParapharmacySeeder` already has a constructor, add the param there). Then in `run()`, after the `seedPartners` block (~line 390, before "7. Seed stock levels"), add:

```php
// 6b. Guarantee an ACTIVE loyalty program (extra is enabled at line ~473 but
//     nothing seeds a program, so every earn/balance call silently no-ops —
//     2026-07-06 loyalty launch roadmap LB-1).
$this->command->info('🎁 Seeding loyalty program...');
$loyaltyBootstrap->ensureActiveProgram(
    $this->tenant->id,
    $this->localeCurrency(),
    'Programme fidélité',
);
$this->command->info('✓ Loyalty program active');
```

`DemoPharmacySeeder extends ParapharmacySeeder`; its FIRST run delegates to `parent::run()` and gets this for free. **Its RE-RUN branch skips `parent::run()`** (`DemoPharmacySeeder.php:369-388`) — so ALSO call `$this->loyaltyBootstrap->ensureActiveProgram($this->tenant->id, $this->localeCurrency(), 'Programme fidélité')` (or the equivalent tenant reference in that scope — read the re-run closure) inside the re-run tenant closure (`DemoPharmacySeeder.php:391+`), otherwise an already-provisioned demo/staging tenant stays program-less on redeploy (adversarial review MAJOR-2). It's idempotent, so calling it in both paths is safe. Add to the PR deploy note: tenants provisioned BEFORE this change need one re-run of the demo seeder (or a manual `ensureActiveProgram`) to get their program.

- [ ] **Step 6: PHPStan + commit**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Loyalty/Application/Services/ProgramBootstrapService.php database/seeders/ParapharmacySeeder.php
git add apps/api/app/Modules/Loyalty/Application/Services/ProgramBootstrapService.php apps/api/database/seeders/ParapharmacySeeder.php apps/api/tests/Feature/Loyalty/ProgramBootstrapServiceTest.php
git commit -m "feat(loyalty): bootstrap active program + spend rule in parapharmacy seeders (LB-1)"
```

### Task 2: `loyalty.enroll` permission (backend seeder + FE map) (LB-3a)

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (permissions list ~line 311; manager ~483; cashier ~507)
- Modify: `apps/web/src/hooks/usePermissions.ts` (~line 161)
- Test: `apps/api/tests/Feature/Seeders/RolesAndPermissionsLoyaltyEnrollTest.php` (create)

**Interfaces:**
- Produces: permission string `loyalty.enroll` granted to `cashier`, `manager` (admin syncs `Permission::all()`); FE `hasPermission('loyalty.enroll')` true for admin/manager/cashier.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RolesAndPermissionsLoyaltyEnrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_and_manager_get_loyalty_enroll_but_not_manage_for_cashier(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cashier = Role::findByName('cashier', 'sanctum');
        $manager = Role::findByName('manager', 'sanctum');

        $this->assertTrue($cashier->hasPermissionTo('loyalty.enroll', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('loyalty.manage', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('loyalty.view', 'sanctum'));
        $this->assertTrue($manager->hasPermissionTo('loyalty.enroll', 'sanctum'));
    }
}
```

- [ ] **Step 2: Run — FAIL** (`There is no permission named loyalty.enroll`).
- [ ] **Step 3: Implement.** In the permissions array after `'loyalty.manage',` add `'loyalty.enroll',` (comment: `// narrow enrollment right — cashiers enroll by default, owner 2026-07-05`). Add `'loyalty.enroll',` to the manager `syncPermissions` list next to `'loyalty.view', 'loyalty.manage',` and to the cashier list next to the `pos.*` block.
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: FE map.** In `usePermissions.ts` after `'loyalty.manage': ['admin', 'manager'],`:

```ts
  'loyalty.enroll': ['admin', 'manager', 'cashier'],
```

(Leave `MODULE_PERMISSIONS.loyalty` as `['loyalty.view']` — the sidebar loyalty pages stay manager/admin.)

- [ ] **Step 6: Commit**

```bash
git add apps/api/database/seeders/RolesAndPermissionsSeeder.php apps/api/tests/Feature/Seeders/RolesAndPermissionsLoyaltyEnrollTest.php apps/web/src/hooks/usePermissions.ts
git commit -m "feat(loyalty): loyalty.enroll permission — cashiers enroll by default (LB-3a)"
```

### Task 3: Partner loyalty summary + enroll endpoints (LB-2 backend)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPartnerController.php`
- Create: `apps/api/app/Modules/Loyalty/Presentation/Requests/EnrollPartnerRequest.php`
- Create: `apps/api/app/Modules/Loyalty/Application/DTOs/PartnerLoyaltySummaryData.php` (+ nested `PartnerEnrollmentSummaryData`)
- Create: `apps/api/app/Modules/Loyalty/Application/Services/MemberProvisioningService.php` (extracted)
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php` (delegate `findOrCreateMember` to the extracted service; behavior identical)
- Modify: `apps/api/app/Modules/Loyalty/Presentation/routes.php` (new `loyalty/partners` group)
- Test: `apps/api/tests/Feature/Loyalty/LoyaltyPartnerControllerTest.php`

**Interfaces:**
- Consumes: `MemberResolver::resolveByContactOrPartner(string $tenantId, ?string $contactId, ?string $partnerId): ?LoyaltyMember`; `MemberEnrollmentService::enroll(string $memberId, string $programId, ?float $welcomeBonus = null): EnrollmentData` (throws `InvalidArgumentException` on duplicate/inactive); `EnrollmentRepositoryInterface::findByMemberAndProgram`.
- Produces:
  - `GET  /api/v1/loyalty/partners/{partnerId}` → `{data: PartnerLoyaltySummaryData}` — `can:loyalty.enroll`
  - `POST /api/v1/loyalty/partners/{partnerId}/enroll` body `{phone: string, program_id?: uuid}` → 201 `{data: PartnerLoyaltySummaryData}` — `can:loyalty.enroll`
  - `MemberProvisioningService::findOrCreateMember(string $tenantId, ?string $partnerId, ?string $contactId, string $phone, ?string $name): LoyaltyMember` — verbatim relocation of `PosLoyaltyBalanceService::findOrCreateMember` (lines 90–130: normalizePhone, withTrashed find/restore, loyaltyable re-point, create).
  - `PartnerLoyaltySummaryData`: `bool $isMember; ?string $memberId; ?string $phone; ?string $firstName; array $enrollments` of `PartnerEnrollmentSummaryData {string $enrollmentId; string $programId; string $programName; string $balance; ?string $tier; string $status}` (balances as strings — rule 19).

- [ ] **Step 1: Failing tests** (harness: copy the two-tenant setUp pattern from `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php:40-80` — Tenant/Company/User factories + `UserCompanyMembership` + Sanctum; give the acting user only the `loyalty.enroll` permission via `Permission::firstOrCreate(['name' => 'loyalty.enroll', 'guard_name' => 'sanctum'])` + direct `$user->givePermissionTo`). Cover:

```php
public function test_summary_returns_not_a_member_for_unenrolled_partner(): void
// GET /api/v1/loyalty/partners/{partnerId} → 200 {data: {is_member: false, enrollments: []}}
// $partnerId = a real partners-table row created via DB::table('partners')->insert([...])
// (Partner model is another module — insert the row directly; check the partners
// schema for required columns before writing the insert — rule 17.)

public function test_summary_returns_member_with_enrollments_and_balance(): void
// seed LoyaltyMember(customer_id=$partnerId) + active program + Enrollment(current_balance '12.000')
// → is_member true, one enrollment, balance '12.000' as STRING, program_name set

public function test_enroll_creates_member_and_enrollment_with_phone(): void
// POST .../enroll {phone: '+216 20 123 456'} with an active program seeded
// → 201; loyalty_members row exists (normalized phone), enrollment Active

public function test_enroll_is_idempotent_on_second_call(): void
// second POST → 200/201 without duplicate enrollment (assert 1 enrollment row)

public function test_enroll_404s_on_unknown_partner_and_invalid_uuid(): void
// unknown uuid → 404; 'not-a-uuid' → 404 (Str::isUuid guard — PG uuid cast 500 pitfall)

public function test_enroll_422s_when_no_active_program(): void

public function test_endpoints_require_loyalty_enroll_permission(): void
// user WITHOUT the permission → 403 on both routes

public function test_module_gate_blocks_tenant_without_loyalty_extra(): void
// tenant enabled_extras [] → assertStatus(403) (RequireModule aborts 403)

public function test_cross_tenant_partner_is_not_reachable(): void
// partner + member created under tenant B; acting as tenant A user with
// loyalty.enroll, GET and POST enroll for that partner id → 404 (db-per-tenant:
// the row simply doesn't exist on tenant A's connection). Mirror the
// LoyaltyTenantIsolationTest arrangement.
```

Write them as real PHPUnit methods with full arrange/act/assert (mirror sibling tests' style, `postJson`/`getJson`, assert JSON paths).

- [ ] **Step 2: Run — FAIL** (404 route not found).
- [ ] **Step 3: Implement.**

`MemberProvisioningService` = move `findOrCreateMember` verbatim from `PosLoyaltyBalanceService` (keep the Codex N2 withTrashed comment); inject it into `PosLoyaltyBalanceService` and replace the private call — no behavior change (existing `tests/Feature/Loyalty/PosLoyaltyBalanceTest.php` must stay green).

`EnrollPartnerRequest` (pattern: `EnrollMemberRequest` — CompanyContext ctor injection, `ScopedExists::tenant`):

```php
public function rules(): array
{
    $tenantId = $this->companyContext->requireCompany()->tenant_id;

    return [
        'phone' => ['required', 'string', 'max:20'],
        'program_id' => ['nullable', 'uuid', ScopedExists::tenant('loyalty_programs', $tenantId)],
    ];
}
```

`LoyaltyPartnerController` (ctor: `CompanyContext`, `MemberResolver`, `MemberProvisioningService`, `MemberEnrollmentService`, `LoyaltyProgramRepositoryInterface`, `EnrollmentRepositoryInterface`):

```php
public function show(string $partnerId): JsonResponse
{
    $tenantId = $this->companyContext->requireCompany()->tenant_id;
    abort_unless(Str::isUuid($partnerId), 404);           // PG uuid-cast guard
    abort_unless($this->partnerExists($partnerId), 404);

    $member = $this->memberResolver->resolveByContactOrPartner($tenantId, null, $partnerId);

    return response()->json(['data' => $this->summarize($member)]);
}

public function enroll(EnrollPartnerRequest $request, string $partnerId): JsonResponse
{
    $tenantId = $this->companyContext->requireCompany()->tenant_id;
    abort_unless(Str::isUuid($partnerId), 404);
    $partner = DB::table('partners')->where('id', $partnerId)->first(['id', 'name', 'phone']);
    abort_if($partner === null, 404);

    $data = $request->validated();
    $program = isset($data['program_id'])
        ? $this->programRepository->findById($data['program_id'])
        : $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
    if ($program === null || ! $program->isActive()) {
        return response()->json(['error' => ['message' => 'No active loyalty program']], 422);
    }

    $member = $this->memberProvisioning->findOrCreateMember(
        $tenantId, $partnerId, null, $data['phone'], $partner->name,
    );

    if ($this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id) === null) {
        $this->enrollmentService->enroll($member->id, $program->id);
    }

    return response()->json(['data' => $this->summarize($member->refresh())], 201);
}
```

`partners` is a tenant-DB table — the db-per-tenant connection IS the tenant scope; `DB::table` avoids a cross-module model import (rule 6; same trade the POS path makes by accepting `partner_id` blind — this is stricter). `summarize(?LoyaltyMember $member): PartnerLoyaltySummaryData` loads `enrollments.program` + `currentTier` and maps to the DTO; balances via `(string) $enrollment->current_balance`.

Routes (inside the existing `module:Loyalty` group, after the `loyalty/pos` prefix group):

```php
// Partner-record loyalty surface (boss-app card + cashier enrollment).
// Deliberately gated on the NARROW loyalty.enroll (cashier-holdable), not
// loyalty.view/manage — this is an enrollment surface, not loyalty admin.
Route::prefix('loyalty/partners')->group(function () {
    Route::get('/{partnerId}', [LoyaltyPartnerController::class, 'show'])
        ->middleware('can:loyalty.enroll')
        ->name('loyalty.partners.show');

    Route::post('/{partnerId}/enroll', [LoyaltyPartnerController::class, 'enroll'])
        ->middleware('can:loyalty.enroll')
        ->name('loyalty.partners.enroll');
});
```

- [ ] **Step 4: Run new tests + `tests/Feature/Loyalty/PosLoyaltyBalanceTest.php` (extraction regression) — ALL PASS.**
- [ ] **Step 5: Type-gen + PHPStan + commit**

```bash
cd apps/api && php artisan typescript:transform && ./vendor/bin/phpstan analyse app/Modules/Loyalty
git add apps/api/app/Modules/Loyalty apps/api/tests/Feature/Loyalty/LoyaltyPartnerControllerTest.php packages/shared/types
git commit -m "feat(loyalty): partner loyalty summary + enroll-by-partner endpoints (LB-2 backend)"
```

### Task 4: PartnerDetailPage loyalty card + enroll modal (LB-2 frontend)

**Files:**
- Create: `apps/web/src/features/loyalty/api/partnerLoyaltyApi.ts`
- Create: `apps/web/src/features/loyalty/hooks/usePartnerLoyalty.ts`
- Create: `apps/web/src/features/loyalty/components/PartnerLoyaltyCard.tsx` (+ inline enroll modal)
- Modify: `apps/web/src/features/loyalty/index.ts` (export card)
- Modify: `apps/web/src/features/partners/PartnerDetailPage.tsx` (overview grid, ~line 380-520)
- Modify: web locale files: every `<locale>/loyalty.json` under `apps/web/src/locales/` (or `public/locales` — follow where existing loyalty keys live)
- Test: `apps/web/src/features/loyalty/__tests__/PartnerLoyaltyCard.test.tsx`

**Interfaces:**
- Consumes: `GET /loyalty/partners/{id}` / `POST /loyalty/partners/{id}/enroll` (Task 3), generated `PartnerLoyaltySummaryData` TS type, `tenantScopedKey` (`@/lib/tenantScopedKey`), `useCompanyConfig().hasModule`, `usePermissions().hasPermission`.
- Produces: `usePartnerLoyalty(partnerId: string, enabled: boolean)` query (`tenantScopedKey(['loyalty', 'partner', partnerId])`), `useEnrollPartner(partnerId)` mutation invalidating that key; `<PartnerLoyaltyCard partnerId={id} partnerPhone={partner.phone ?? null} />`.

- [ ] **Step 1: Failing Vitest** — render states: not-member → Enroll button (permission `loyalty.enroll` mocked true); member → points balance + program name + tier; no `loyalty.enroll` → enroll button hidden but balance still shown; mutation called with typed phone. `vi.mock` the hooks module and `usePermissions` (component-test mocking is allowed by repo conventions). Assert rendered text/testids, not classes.
- [ ] **Step 2: Run — FAIL** (`cd apps/web && pnpm test src/features/loyalty/__tests__/PartnerLoyaltyCard.test.tsx`).
- [ ] **Step 3: Implement.** API fns return `apiGet`/`apiPost` directly (NO double-unwrap). Card = a `Card` in the overview grid matching the Balance-card idiom (reuse the same Card/typography components the page already imports; tokens only). Enroll modal: phone input prefilled with `partnerPhone`, note text "enrolls into the active program"; submit → mutation → invalidate. All strings `t('loyalty:partnerCard.*')`; add keys (`title`, `points`, `tier`, `notMember`, `enroll`, `phoneLabel`, `enrollSuccess`, `enrollError`) to EVERY locale's `loyalty.json`.
- [ ] **Step 4: Wire into `PartnerDetailPage`** overview grid (after the Business Information card, ~line 512). The page does NOT currently import `usePermissions` — add the import (`import { usePermissions } from '@/hooks/usePermissions'`):

```tsx
{showLoyaltyCard && (
  <PartnerLoyaltyCard partnerId={id} partnerPhone={partner?.phone ?? null} />
)}
```

with, next to `showDepositsTab` (~line 210):

```tsx
const { hasPermission } = usePermissions()
const showLoyaltyCard =
  isCustomerContext &&
  hasModule('Loyalty') &&
  (partner?.type === 'customer' || partner?.type === 'both') &&
  hasPermission('loyalty.enroll')
```

- [ ] **Step 5: Run Vitest + `pnpm typecheck` + `pnpm lint` + `pnpm audit:keys` — PASS. Commit** (`feat(loyalty): partner-record loyalty card + enroll modal (LB-2 FE)`).

### Task 5: Tauri POS enroll affordance (LB-3b)

**Files:**
- Modify: `apps/pos/src/lib/loyalty/loyaltyApi.ts` (add `enrollLoyalty`)
- Modify: `apps/pos/src/lib/loyalty/useLoyaltyBalance.ts` (expose `refresh`)
- Modify: `apps/pos/src/components/customers/CustomerLoyaltyBadge.tsx` (affordance)
- Create: `apps/pos/src/components/customers/LoyaltyEnrollDialog.tsx`
- Modify: `apps/pos/src/locales/<every locale>/pos.json` (loyalty.* keys, ~line 1089 block)
- Test: `apps/pos/src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx` (extend/create alongside existing loyalty tests)

**Interfaces:**
- Consumes: existing `POST /loyalty/pos/balance` (find-or-create + enroll on phone — NO new backend endpoint; gated `pos.operate_terminal` which cashiers hold).
- Produces: `useLoyaltyBalance(customer): { balance: LoyaltyBalance | null; refresh: () => void }` (**signature change** — update its one consumer, the badge); `enrollLoyalty(customerId: string, phone: string): Promise<LoyaltyBalance>` posting `{partner_id, phone}`.

- [ ] **Step 1: Failing Vitest**: (a) not-enrolled + `rate !== null` renders an Enroll button (not the inert text); (b) not-enrolled + `rate === null` (no active program) renders NOTHING — the badge's existing `if (!balance.enrolled && estimate === null) return null` early return; assert no button AND no chrome; (c) dialog submit calls `enrollLoyalty` with the typed phone then `refresh`; (d) enrolled state unchanged.
- [ ] **Step 2: Run — FAIL** (`cd apps/pos && pnpm test`... scope to the file).
- [ ] **Step 3: Implement.**

`useLoyaltyBalance`: add `const [nonce, setNonce] = useState(0)`, include `nonce` in the effect deps, return `{ balance, refresh: () => setNonce((n) => n + 1) }`. After a successful enroll, `refresh()` re-posts with `customer.phone` (still null) — the member now resolves **by partner id**, so `enrolled: true` comes back without the phone.

Badge: replace the `!balance.enrolled` inert span with:

```tsx
{!balance.enrolled && (
  <>
    <Button variant="secondary" size="sm" onClick={() => setEnrollOpen(true)}>
      {t('loyalty.enroll')}
    </Button>
    <LoyaltyEnrollDialog
      open={enrollOpen}
      customer={customer}
      onClose={() => setEnrollOpen(false)}
      onEnrolled={() => { setEnrollOpen(false); refresh() }}
    />
  </>
)}
```

(The whole chrome is already gated on `balance !== null` ⇒ module on + online + synced; `rate === null` ⇒ no active program ⇒ existing early-return already hides everything — verify that branch and keep it.)

`LoyaltyEnrollDialog`: controlled dialog (reuse the POS dialog/modal primitive used by `CustomerAttachPanel` — read it first), phone input prefilled `customer.phone ?? ''`, submit → `enrollLoyalty(customer.id, phone)` → success → `onEnrolled()`; error → inline `t('loyalty.enrollFailed')`. Keys to add in every `pos.json` locale next to `joinsOnPurchase`: `enroll`, `enrollTitle`, `enrollPhoneLabel`, `enrollSubmit`, `enrollFailed`.

- [ ] **Step 4: Vitest + `pnpm typecheck` + lint — PASS.**
- [ ] **Step 5: Commit** (`feat(pos): cashier loyalty enroll affordance via balance ensure-enroll (LB-3b)`).

### Task 6: Branch A verify + PR

- [ ] Scoped preflight:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.loyalty
PREFLIGHT_TEST_PATHS='tests/Feature/Loyalty tests/Feature/Seeders/RolesAndPermissionsLoyaltyEnrollTest.php' ./scripts/preflight.sh
```

- [ ] `superpowers:verify` the flow end-to-end against the local stack if available (recipe: memory `reference_local_db_per_tenant_demo_launch`): seed → partner page shows card → enroll → POS attach shows badge.
- [ ] Dispatch `tenancy-authz-reviewer` (permission + route change) — APPROVED required; fix + re-dispatch on CHANGES-REQUESTED.
- [ ] Push branch; `gh pr create` into `dev` (title `feat(loyalty): launch-blocking enrollment set (LB-1..LB-3)`); body includes the deploy note (migrate + permission seeder sync + `permission:cache-reset`) and roadmap link.
- [ ] Board: `node scripts/factory/board.mjs update T-0002 --status ready-for-review --note "PR #<n>"` (check `update` flags via the CLI usage first).

---

## Branch B — `fix/loyalty-double-earn` (Tasks 7–9; base off origin/dev, claim board T-0005)

### Task 7: Atomic earn dedupe — source columns + partial unique index

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100000_add_source_columns_to_loyalty_transactions.php`
- Modify: `apps/api/app/Modules/Loyalty/Domain/Entities/Transaction.php` (fillable)
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php` (write columns; catch 23505)
- Modify: `apps/api/app/Modules/Loyalty/Infrastructure/Repositories/EloquentTransactionRepository.php` (`findBySourceDocument` on columns)
- Test: `apps/api/tests/Feature/Loyalty/EarningDedupeConstraintTest.php`

**Interfaces:**
- Produces: columns `loyalty_transactions.source_type varchar(40) NULL`, `source_id varchar(64) NULL`; index `loyalty_txn_earn_source_unique ON (enrollment_id, source_type, source_id) WHERE transaction_type = 'earn' AND source_type IS NOT NULL`; `earnPoints` rethrows a unique-violation as `InvalidArgumentException("Points already earned for {$sourceType} {$sourceId}")` — the exact message-shape both callers already discriminate on.

- [ ] **Step 1: Failing test**

```php
public function test_concurrent_style_duplicate_insert_hits_unique_constraint(): void
// arrange enrollment; call earnPoints once (succeeds);
// bypass the pre-check by inserting a second Transaction row directly with the
// same (enrollment_id, 'pos_receipt', $sourceId) + transaction_type 'earn'
// → expect Illuminate\Database\QueryException (SQLSTATE 23505)

public function test_translateEarnDuplicate_converts_constraint_violation(): void
// The pre-check is strictly broader than the index, so the catch branch is
// unreachable in a single-connection test (adversarial review MAJOR-3). Extract
// the translation into a small internal method and unit-test IT directly:
//   translateEarnDuplicate(QueryException $e, string $sourceType, string $sourceId): ?InvalidArgumentException
//   → returns the InvalidArgumentException("Points already earned for …") when
//     errorInfo[0]==='23505' && message contains 'loyalty_txn_earn_source_unique',
//     null otherwise. earnPoints' catch becomes:
//       if (($iae = $this->translateEarnDuplicate($e, $sourceType, $sourceId)) !== null) { throw $iae; }
//       throw $e;
// Test with FABRICATED QueryExceptions (constructor takes connectionName, sql,
// bindings, previous PDOException — set $previous->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "loyalty_txn_earn_source_unique"'];
// check how QueryException derives errorInfo/message in this Laravel version first):
//   (a) 23505 + index name in message → InvalidArgumentException with 'already earned'
//   (b) 23505 + a DIFFERENT constraint name → null (rethrown as-is by caller)
//   (c) non-23505 (e.g. '23503') → null

public function test_duplicate_earn_via_earnPoints_still_yields_already_earned_and_one_row(): void
// end-to-end guard (pre-check path): earn once, earn again with same source →
// InvalidArgumentException message contains 'already earned'; exactly ONE earn row

public function test_source_columns_written_and_backfill_query_shape(): void
// earnPoints → row has source_type='pos_receipt', source_id=$id (columns, not just metadata)
```

- [ ] **Step 2: Run — FAIL** (columns missing).
- [ ] **Step 3: Migration.** **IMPORTANT — phpunit runs on SQLite `:memory:` (`phpunit.xml`), production/staging is PG.** Branch on `DB::connection()->getDriverName()` like the existing precedent (`2025_12_02_070000_create_inventory_countings_table.php:95`): PG path below; SQLite path uses `json_extract(metadata, '$.source_type') IS NOT NULL` + `json_extract(...)` for the backfill (SQLite supports partial `CREATE UNIQUE INDEX ... WHERE`, so the index statement is shared). `translateEarnDuplicate` stays PG-shaped (23505) — production is PG; the unit test fabricates the PG QueryException; the SQLite feature test only asserts a QueryException is thrown.

```php
public function up(): void
{
    Schema::table('loyalty_transactions', function (Blueprint $table) {
        $table->string('source_type', 40)->nullable();
        $table->string('source_id', 64)->nullable();
    });

    // Backfill from metadata (jsonb_exists avoids PDO '?' operator escaping) — PG branch;
    // SQLite branch uses json_extract equivalents (see driver note above).
    DB::statement(<<<'SQL'
        UPDATE loyalty_transactions
        SET source_type = metadata->>'source_type', source_id = metadata->>'source_id'
        WHERE jsonb_exists(metadata, 'source_type')
    SQL);

    // Pre-existing double-earns (the very bug this fixes) would break the unique
    // index build: keep the EARLIEST earn per (enrollment, source), null the
    // columns on later duplicates. Rows/balances are untouched (audit stays
    // intact — metadata still carries the original source refs).
    DB::statement(<<<'SQL'
        WITH ranked AS (
            SELECT id, row_number() OVER (
                PARTITION BY enrollment_id, source_type, source_id
                ORDER BY created_at, id
            ) AS rn
            FROM loyalty_transactions
            WHERE transaction_type = 'earn' AND source_type IS NOT NULL
        )
        UPDATE loyalty_transactions t
        SET source_type = NULL, source_id = NULL
        FROM ranked r WHERE t.id = r.id AND r.rn > 1
    SQL);

    // NOTE: the index is PER-ENROLLMENT while the earnPoints pre-check
    // (findBySourceDocument) is GLOBAL — the index only fires on a true
    // same-enrollment concurrent race (the defect being fixed). A future
    // multi-program-earn change must relax the global pre-check; this index
    // already supports per-enrollment earns.
    DB::statement(<<<'SQL'
        CREATE UNIQUE INDEX loyalty_txn_earn_source_unique
        ON loyalty_transactions (enrollment_id, source_type, source_id)
        WHERE transaction_type = 'earn' AND source_type IS NOT NULL
    SQL);
}

public function down(): void
{
    DB::statement('DROP INDEX IF EXISTS loyalty_txn_earn_source_unique');
    Schema::table('loyalty_transactions', function (Blueprint $table) {
        $table->dropColumn(['source_type', 'source_id']);
    });
}
```

Add `'source_type', 'source_id'` to `Transaction::$fillable`; in `EarningProcessingService::earnPoints` add both to the `new Transaction([...])` array; wrap the `DB::transaction(...)` in:

```php
try {
    return DB::transaction(function () use (...) { ... });
} catch (QueryException $e) {
    $duplicate = $this->translateEarnDuplicate($e, $sourceType, $sourceId);
    if ($duplicate !== null) {
        throw $duplicate;
    }
    throw $e;
}
```

```php
/**
 * Concurrent duplicate lost the race on loyalty_txn_earn_source_unique —
 * translate to the same benign "already earned" signal the pre-check throws
 * (TOCTOU closed by the partial unique index). Extracted for direct unit
 * testing: the broader global pre-check makes this branch unreachable in a
 * single-connection feature test.
 */
private function translateEarnDuplicate(QueryException $e, string $sourceType, string $sourceId): ?InvalidArgumentException
{
    if (($e->errorInfo[0] ?? null) === '23505'
        && str_contains($e->getMessage(), 'loyalty_txn_earn_source_unique')) {
        return new InvalidArgumentException(
            "Points already earned for {$sourceType} {$sourceId}", 0, $e,
        );
    }

    return null;
}
```

(PHPStan will flag a private method tested via reflection — prefer making it `@internal` public or test through a tiny protected-subclass shim; pick whichever the repo's existing tests use for cases like this.)

`findBySourceDocument` → `Transaction::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->first()` (backfill makes legacy rows visible; keep the global — not per-enrollment — semantics: the multi-enrollment idempotency change is explicitly OUT, memory `project_loyalty_earn_demo_cutoff`).

- [ ] **Step 4: Run new test + `tests/Feature/Loyalty/SaleEarningServiceTest.php` — PASS.**
- [ ] **Step 5: PHPStan Loyalty module; commit** (`fix(loyalty): constraint-backed earn dedupe — source columns + partial unique index (T-0005)`).

### Task 8: Quiet + rule-19-clean listener; truthful docblock

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php`
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` (docblock only)
- Test: extend `apps/api/tests/Feature/Loyalty/EarningDedupeConstraintTest.php` (or the listener's existing test file if one exists — search first)

- [ ] **Step 1: Failing tests** — (a) listener handling a `ReceiptCompleted` for a receipt whose points were already earned (seed the earn txn first) must NOT `Log::error` (use `Log::spy()` / `Log::shouldReceive('error')->never()` scoped to the duplicate case) and must not create a second txn; (b) a receipt with no prior earn still earns, and the txn `amount` came through bcmath (assert exact decimal string, e.g. `'25.500'`); (c) **listener-path earn-eligibility**: a non-Sale receipt (refund/return/void — check the `Receipt` model's type/status column and build accordingly) fired through the listener earns NOTHING (the projection has this guard at `PosCoreReceiptProjection.php:872`; the listener has none — adversarial review MINOR-6).
- [ ] **Step 2: Run — FAIL** (current catch-all logs the duplicate as an error; non-Sale earns when total is positive).
- [ ] **Step 3: Implement.** In the listener's catch, mirror `SaleEarningService.php:74-90`: catch `InvalidArgumentException`, `continue` silently when the message contains `'already earned'`, `Log::error` otherwise; keep the generic `\Throwable` loud branch. Add an earn-eligibility guard right after loading the receipt: skip unless the receipt is a real Sale (read the `Receipt` model for the authoritative type/training columns — mirror the projection's `ReceiptType::Sale && !trainingFlag` semantics as closely as the server model allows). Rule-19 fixes in the same file: remove the `(float)` casts — `'price' => (string) $line->unit_price`, `'amount' => $event->totalAmount` (already a string on the event; the fix is deleting the float cast, not adding a string cast). Update the `SaleEarningService` class docblock: the listener is NOT retired — it is the live earn path for server-authored receipts (exchange flow); the projection covers device-authored sales.
- [ ] **Step 4: Run — PASS. Commit** (`fix(loyalty): quiet duplicate earns in receipt listener + rule-19 string amounts`).

### Task 9: Close the projection/listener rule-coverage divergence (items)

**Files:**
- Modify: `apps/api/app/Shared/Contracts/Loyalty/SaleEarnContext.php` (append `public array $items = []`)
- Modify: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (`earnLoyaltyPoints`, ~line 863: build items from `$view->lineItems()`)
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` (category resolution + pass items)
- Test: extend `apps/api/tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php`

**Interfaces:**
- Produces: `SaleEarnContext::$items` — `list<array{product_id: string, quantity: string}>` (primitives only, rule 6; **price deliberately omitted** — no `EarningRuleType` reads it); `SaleEarningService` resolves `category_id` per product and feeds `transactionData['items']` as `list<array{product_id: string, category_id: string|null, quantity: string}>` — the exact shape `PointEarningService::calculateItemPoints/calculateCategoryPoints/calculateQuantityPoints` consume.
- Known limits to DOCUMENT in the SaleEarnContext docblock (not fix — acceptable for parapharmacy launch, adversarial review MINOR-7): `LineItemDTO.productId` is the PARENT product id, so an Item rule keyed on a variant id never matches device sales; `calculateItemPoints`/`getTotalQuantity` int-cast quantity, truncating fractional quantities ("2.500" → 2).

- [ ] **Step 1: Failing test** (in the existing projection-earn test file — read its helpers first and reuse its fiscal-event fixture builder): active **Item** rule (`conditions: ['product_ids' => [$productId]]`, `reward_value '5'`) + projected sale with `quantity '2.000'` of that product → enrollment balance increases by `'10.000'`; replay the same fiscal event → still `'10.000'`, exactly one earn txn (rule 20). A second test: **Category** rule matches via the product's `category_id`.
- [ ] **Step 2: Run — FAIL** (items empty ⇒ 0 points).
- [ ] **Step 3: Implement.**

Context (append after `$earnBase`, defaulted so existing constructions compile):

```php
/**
 * Sale line snapshot for Item/Category/Quantity rules —
 * list<array{product_id: string, quantity: string}>. Category is resolved
 * on the Loyalty side (the fiscal canonical payload carries no category).
 *
 * @var list<array{product_id: string, quantity: string}>
 */
public array $items = [],
```

Projection (`earnLoyaltyPoints`), add to the `new SaleEarnContext(...)`:

```php
items: array_map(static fn (LineItemDTO $li): array => [
    'product_id' => $li->productId,
    'quantity' => $li->quantity,
], $view->lineItems()),
```

`SaleEarningService::earnForSale`, replace `'items' => []`:

```php
'items' => $this->resolveItemCategories($context->items),
```

```php
/**
 * @param  list<array{product_id: string, quantity: string}>  $items
 * @return list<array{product_id: string, category_id: string|null, quantity: string}>
 */
private function resolveItemCategories(array $items): array
{
    if ($items === []) {
        return [];
    }
    // Deliberate DB-level read of the catalog table (no cross-module model
    // import, rule 6) — mirrors what EarnPointsOnReceiptCompleted gets via
    // $line->product->category_id, without the POS-model dependency.
    $categories = DB::table('products')
        ->whereIn('id', array_values(array_unique(array_column($items, 'product_id'))))
        ->pluck('category_id', 'id');

    return array_map(static fn (array $i): array => [
        'product_id' => $i['product_id'],
        'category_id' => $categories[$i['product_id']] ?? null,
        'quantity' => $i['quantity'],
    ], $items);
}
```

- [ ] **Step 4: Run the projection test file + `SaleEarningServiceTest` — PASS.**
- [ ] **Step 5: Scoped preflight for branch B**

```bash
PREFLIGHT_TEST_PATHS='tests/Feature/Loyalty tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php' ./scripts/preflight.sh
```

- [ ] **Step 6: Commit** (`fix(loyalty): item/category/quantity rules earn on device sales via SaleEarnContext items (T-0005)`); dispatch `fiscal-pos-reviewer` (earn path + projection + migration touching fiscal-adjacent projections); on APPROVED, PR into dev (`fix(loyalty): double-earn quiet fix + rule-coverage divergence (T-0005)`), board update T-0005 → ready-for-review with PR number.

---

## Self-review notes (spec → plan)

- LB-1 seeding → Task 1. LB-2 → Tasks 3–4. LB-3 → Tasks 2, 5. LB-4 → Tasks 7–9. LB-5 = decision only (roadmap §LB-5), no task — correct.
- Reviewer gates + PR/board steps folded into Tasks 6 and 9 (WORKFLOW Stages 4–6).
- Type consistency checked: `MemberProvisioningService::findOrCreateMember` signature matches its PosLoyaltyBalanceService origin; `useLoyaltyBalance` return-shape change has exactly one consumer (the badge, updated in Task 5); `SaleEarnContext::$items` is optional-last (no other construction sites break — the projection is the only producer, `SaleEarningServiceTest` constructs directly and compiles unchanged).
- Known risks recorded in the roadmap (phone-collision re-point via cashier-typed phone; multi-active-program `.first()`) — surfaced to the adversarial reviewer, not silently absorbed.
