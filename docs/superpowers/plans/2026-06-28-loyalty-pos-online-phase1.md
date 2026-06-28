# POS Loyalty Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a customer's loyalty balance + tier + a live earn estimate in the POS when online and the Loyalty module is on, auto-enrolling the customer (via the balance call) so points then accrue server-side on sale sync.

**Architecture:** A single side-effecting `POST /loyalty/pos/balance` endpoint (ensure-enroll + return balance) reusing a shared `MemberResolver` extracted from `SaleEarningService`. The POS calls it on customer attach (online, synced customer, phone in hand), gated by `hasModule('Loyalty')`, and renders a `Badge`-based chrome with a local `floor(cart TTC × rate)` estimate (rate cached at login). The fiscal earn path is unchanged.

**Tech Stack:** Laravel 12 / PHP 8.2 (strict types, hexagonal, constructor injection), PHPUnit; Tauri + React / TypeScript / Zustand / Vitest. Spec: `docs/superpowers/specs/2026-06-28-loyalty-pos-online-phase1-design.md`.

## Global Constraints

- **Online-only**: offline / network error ⇒ loyalty chrome hidden. No offline mirror.
- **Earn basis = TTC**: estimate = `floor(cartStore.total() × rate)`; `total()` is the tax-inclusive cart total (a `number` — acceptable for a display-only estimate; never persisted).
- **Auto-enroll at attach**, not in the fiscal projection. `SaleEarningService` earn path is **unchanged** except for using the extracted resolver.
- **`loyalty_members.phone` is NOT NULL + UNIQUE(tenant_id, phone)** — the POS supplies the phone; no phone ⇒ no member created (`enrolled:false`). Dedupe members by `(tenant, phone)` and by `(tenant, loyaltyable)`.
- **`MemberEnrollmentService::enroll()` throws if already enrolled** — always guard with `findByMemberAndProgram` first (replay/re-attach safe).
- **Both-layer module gating (rule 12)**: endpoint under `module:Loyalty` + `can:pos.operate_terminal`; FE gated on `hasModule('Loyalty')`.
- **Cross-module (rule 6)**: no Partner model import; the POS supplies the phone, so no Partner contract is needed.
- **Money as strings** in API payloads/balances (rule 19); the display estimate is the only permitted `number` (display-only).
- **Constructor injection only** (`private readonly`); no `app()` in production. Strict types / no `any`.
- **All FE text via `t()`** (`useTranslation('pos')`).
- **NEVER run the full PHPUnit suite or `--parallel`** (crashes the machine) — scoped `--filter`/by-path only. POS: scoped `pnpm vitest run <path>`.
- **Worktree deps**: this worktree (`../erp.loyalty-pos`) is fresh — Task 0 installs `composer` + `pnpm` deps before any test runs.
- Worktree `../erp.loyalty-pos`, branch `feat/loyalty-pos-online` (already created off local `dev`).

---

## File Structure

| File | Responsibility |
|---|---|
| `apps/api/app/Modules/Loyalty/Application/Resolvers/MemberResolver.php` | Resolve a `LoyaltyMember` by contact/partner (extracted from `SaleEarningService`) |
| `apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php` | Ensure-enroll + read balance/tier for an attached customer |
| `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` | Add `balance()` action (edit) |
| `apps/api/app/Modules/Loyalty/Presentation/Requests/PosBalanceRequest.php` | Validate `partner_id?/contact_id?/phone?/name?` |
| `apps/api/app/Modules/Loyalty/Presentation/routes.php` | Add the route (edit) |
| `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` | Use the shared resolver (edit) |
| `apps/pos/src/stores/productStore.ts` | Add `useHasModule(name)` (edit) |
| `apps/pos/src/lib/loyaltyRateCache.ts` | Persist/load the earn rate offline (new) |
| `apps/pos/src/stores/loyaltyStore.ts` | Hold the cached earn rate (new) |
| `apps/pos/src/stores/authStore.ts` | Fetch+cache the rate in `refreshCompanyConfig` (edit) |
| `apps/pos/src/lib/loyalty/loyaltyApi.ts` + `useLoyaltyBalance.ts` | API client + balance hook (new) |
| `apps/pos/src/components/customers/CustomerLoyaltyBadge.tsx` | The chrome (new) |
| `apps/pos/src/components/customers/CartCustomerControl.tsx` | Render the badge (edit) |
| `apps/pos/src/locales/{en,fr}/pos.json` | `loyalty.*` labels (edit) |

---

## Task 0: Install worktree dependencies (prerequisite)

**Files:** none (environment).

- [ ] **Step 1: Install backend deps**

Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.loyalty-pos/apps/api && composer install --no-interaction --prefer-dist`
Expected: completes; `vendor/bin/phpunit` exists.

- [ ] **Step 2: Install frontend deps**

Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.loyalty-pos && pnpm install --frozen-lockfile`
Expected: completes.

- [ ] **Step 3: Smoke-check the test runners (no commit)**

Run: `cd apps/api && ./vendor/bin/phpunit --filter __none__ 2>&1 | tail -3` (expect "No tests executed" — runner works).
Run: `cd ../pos 2>/dev/null; cd /Users/houssamr/Projects/syneriva/apps/erp.loyalty-pos/apps/pos && pnpm vitest run --reporter=dot src/stores 2>&1 | tail -5` (any existing store test passes — runner works). Do NOT run the full suite.

---

## Task 1: Extract shared `MemberResolver` (backend refactor)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Application/Resolvers/MemberResolver.php`
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` (use the resolver; remove the private `resolveMember`)
- Test: `apps/api/tests/Feature/Loyalty/MemberResolverTest.php`

**Interfaces:**
- Produces: `MemberResolver::resolveByContactOrPartner(string $tenantId, ?string $contactId, ?string $partnerId): ?LoyaltyMember` — consumed by Task 2 and by `SaleEarningService`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_by_contact_then_partner_then_null(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $partnerId = (string) Str::uuid();

        $byContact = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId, 'loyaltyable_type' => 'contact', 'loyaltyable_id' => $contactId,
        ]);
        $byPartner = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId, 'loyaltyable_type' => 'partner', 'loyaltyable_id' => $partnerId,
        ]);

        $resolver = app(MemberResolver::class);

        self::assertSame($byContact->id, $resolver->resolveByContactOrPartner($tenantId, $contactId, null)?->id);
        self::assertSame($byPartner->id, $resolver->resolveByContactOrPartner($tenantId, null, $partnerId)?->id);
        self::assertNull($resolver->resolveByContactOrPartner($tenantId, (string) Str::uuid(), null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/MemberResolverTest.php`
Expected: FAIL — class `MemberResolver` not found.

- [ ] **Step 3: Create the resolver (body lifted verbatim from `SaleEarningService::resolveMember`)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Resolvers;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;

/** Resolves a loyalty member by polymorphic contact/partner, tenant-scoped. */
final readonly class MemberResolver
{
    public function resolveByContactOrPartner(string $tenantId, ?string $contactId, ?string $partnerId): ?LoyaltyMember
    {
        $member = null;

        if ($contactId !== null) {
            $member = LoyaltyMember::query()
                ->where('loyaltyable_type', 'contact')
                ->where('loyaltyable_id', $contactId)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        if ($member === null && $partnerId !== null) {
            $member = LoyaltyMember::query()
                ->where(function ($q) use ($partnerId) {
                    $q->where(function ($q2) use ($partnerId) {
                        $q2->where('loyaltyable_type', 'partner')
                            ->where('loyaltyable_id', $partnerId);
                    })->orWhere('customer_id', $partnerId);
                })
                ->where('tenant_id', $tenantId)
                ->first();
        }

        return $member;
    }
}
```

- [ ] **Step 4: Use it in `SaleEarningService`; delete the private `resolveMember`**

Inject it and replace the call. Add to the constructor (alongside existing deps):
```php
use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
// ...
private MemberResolver $memberResolver,
```
Replace `$member = $this->resolveMember($context);` with:
```php
$member = $this->memberResolver->resolveByContactOrPartner(
    $context->tenantId, $context->contactId, $context->partnerId,
);
```
Delete the now-unused private `resolveMember()` method. (`MemberResolver` is a concrete class — Laravel auto-resolves it; no binding needed.)

- [ ] **Step 5: Run the resolver test AND the existing earn test (characterization — behavior unchanged)**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/MemberResolverTest.php tests/Feature/Loyalty/SaleEarningServiceTest.php`
Expected: PASS (resolver test + all existing SaleEarningService tests still green).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Loyalty/Application/Resolvers/MemberResolver.php \
        apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php \
        apps/api/tests/Feature/Loyalty/MemberResolverTest.php
git commit -m "refactor(loyalty): extract MemberResolver shared by earn + POS balance"
```

---

## Task 2: `POST /loyalty/pos/balance` (ensure-enroll + balance)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php`
- Create: `apps/api/app/Modules/Loyalty/Presentation/Requests/PosBalanceRequest.php`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` (add `balance()`)
- Modify: `apps/api/app/Modules/Loyalty/Presentation/routes.php`
- Test: `apps/api/tests/Feature/Loyalty/PosLoyaltyBalanceTest.php`

**Interfaces:**
- Consumes: `MemberResolver` (Task 1); `LoyaltyProgramRepositoryInterface::findByTenantAndStatus($tenantId, ProgramStatus::Active): Collection`; `MemberEnrollmentService::enroll(string $memberId, string $programId, ?float $welcomeBonus = null): EnrollmentData`; `EnrollmentRepositoryInterface::findByMemberAndProgram(string $memberId, string $programId): ?Enrollment`; `LoyaltyMember` model (`::create`, `normalizePhone`).
- Produces: `PosLoyaltyBalanceService::ensureAndGetBalance(string $tenantId, ?string $partnerId, ?string $contactId, ?string $phone, ?string $name): array{enrolled: bool, balance: string, tier: string|null}`; route `loyalty.pos.balance`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Domain\UserCompanyMembership;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PosLoyaltyBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Mirror LoyaltyPOSControllerTest::setUp() exactly for the auth/tenant/permission bootstrap.
        $this->tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        Sanctum::actingAs($this->user);
    }

    private function activeProgram(): LoyaltyProgram
    {
        return LoyaltyProgram::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ProgramStatus::Active]);
    }

    public function test_creates_member_and_enrollment_for_new_customer_with_phone(): void
    {
        $this->activeProgram();
        $partnerId = (string) Str::uuid();

        $res = $this->postJson('/api/v1/loyalty/pos/balance', [
            'partner_id' => $partnerId, 'phone' => '+21620123456', 'name' => 'Amina',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.enrolled', true)
            ->assertJsonPath('data.balance', '0.000');
        $this->assertDatabaseHas('loyalty_members', ['tenant_id' => $this->tenant->id, 'loyaltyable_id' => $partnerId]);
    }

    public function test_repeat_call_does_not_duplicate_member_or_enrollment(): void
    {
        $this->activeProgram();
        $partnerId = (string) Str::uuid();
        $payload = ['partner_id' => $partnerId, 'phone' => '+21620123456', 'name' => 'Amina'];

        $this->postJson('/api/v1/loyalty/pos/balance', $payload)->assertOk();
        $this->postJson('/api/v1/loyalty/pos/balance', $payload)->assertOk()->assertJsonPath('data.enrolled', true);

        self::assertSame(1, LoyaltyMember::where('tenant_id', $this->tenant->id)->where('loyaltyable_id', $partnerId)->count());
    }

    public function test_no_phone_returns_not_enrolled_and_creates_nothing(): void
    {
        $this->activeProgram();
        $partnerId = (string) Str::uuid();

        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => $partnerId])
            ->assertOk()->assertJsonPath('data.enrolled', false)->assertJsonPath('data.balance', '0.000');

        self::assertSame(0, LoyaltyMember::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_no_active_program_returns_not_enrolled(): void
    {
        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => (string) Str::uuid(), 'phone' => '+21620123456'])
            ->assertOk()->assertJsonPath('data.enrolled', false);
    }

    public function test_403_when_loyalty_module_disabled(): void
    {
        $t = Tenant::factory()->create(['enabled_extras' => []]);
        $c = Company::factory()->create(['tenant_id' => $t->id]);
        $u = User::factory()->create(['tenant_id' => $t->id]);
        UserCompanyMembership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($t->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $u->givePermissionTo('pos.operate_terminal'); // isolate: only module:Loyalty can 403
        Sanctum::actingAs($u);

        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => (string) Str::uuid(), 'phone' => '+21620123456'])
            ->assertForbidden();
    }
}
```

> Confirm the exact factory/membership class names against `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php` setUp and copy any differences (e.g. `Company::factory` vs explicit `create`). The harness pattern is the source of truth.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/PosLoyaltyBalanceTest.php`
Expected: FAIL — route `loyalty/pos/balance` not defined (404/405).

- [ ] **Step 3: Implement `PosLoyaltyBalanceService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Application\Resolvers\MemberResolver;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;

/**
 * Attach-time ensure-enroll + balance read. Online (normal request, CompanyContext
 * present). The POS supplies the phone (no cross-module Partner read). Idempotent:
 * find-first on member + enrollment so re-attach never duplicates.
 *
 * @phpstan-type BalanceResult array{enrolled: bool, balance: string, tier: string|null}
 */
final readonly class PosLoyaltyBalanceService
{
    public function __construct(
        private MemberResolver $memberResolver,
        private LoyaltyProgramRepositoryInterface $programRepository,
        private EnrollmentRepositoryInterface $enrollmentRepository,
        private MemberEnrollmentService $enrollmentService,
    ) {}

    /** @return BalanceResult */
    public function ensureAndGetBalance(
        string $tenantId,
        ?string $partnerId,
        ?string $contactId,
        ?string $phone,
        ?string $name,
    ): array {
        $notEnrolled = ['enrolled' => false, 'balance' => '0.000', 'tier' => null];

        $program = $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
        if ($program === null) {
            return $notEnrolled;
        }

        $member = $this->memberResolver->resolveByContactOrPartner($tenantId, $contactId, $partnerId);

        if ($member === null) {
            if ($phone === null || $phone === '') {
                return $notEnrolled; // phone is the required unique key — cannot create
            }
            $member = $this->findOrCreateMember($tenantId, $partnerId, $contactId, $phone, $name);
        }

        $enrollment = $this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id);
        if ($enrollment === null) {
            // enroll() throws if already enrolled — guarded above. Returns EnrollmentData.
            $this->enrollmentService->enroll($member->id, $program->id);
            $enrollment = $this->enrollmentRepository->findByMemberAndProgram($member->id, $program->id);
        }

        if ($enrollment === null) {
            return $notEnrolled;
        }

        $enrollment->loadMissing('currentTier');

        return [
            'enrolled' => true,
            'balance' => (string) $enrollment->current_balance,
            'tier' => $enrollment->currentTier?->name,
        ];
    }

    private function findOrCreateMember(
        string $tenantId,
        ?string $partnerId,
        ?string $contactId,
        string $phone,
        ?string $name,
    ): LoyaltyMember {
        $normalized = LoyaltyMember::normalizePhone($phone);

        // Dedupe by the unique key (tenant, phone): reuse an existing member if present.
        $existing = LoyaltyMember::query()
            ->where('tenant_id', $tenantId)->where('phone', $normalized)->first();
        if ($existing !== null) {
            return $existing;
        }

        [$type, $id] = $contactId !== null ? ['contact', $contactId] : ['partner', $partnerId];

        return LoyaltyMember::create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => $type,
            'loyaltyable_id' => $id,
            'customer_id' => $partnerId,
            'phone' => $normalized,
            'first_name' => $name,
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
    }
}
```

> Verify `LoyaltyMember::normalizePhone` is a static method (used by `EloquentLoyaltyMemberRepository::save` and `LoyaltyPOSController::memberLookup`). Verify `current_balance` casts to a string `'0.000'` (decimal:3) — the test asserts that exact value; adjust if the cast differs. Confirm `MemberStatus::Active` and the `loyalty_members` columns match (`first_name`, `customer_id`).

- [ ] **Step 4: Add the `PosBalanceRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PosBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (auth:sanctum + module:Loyalty + can:pos.operate_terminal) gates access
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partner_id' => ['nullable', 'uuid', 'required_without:contact_id'],
            'contact_id' => ['nullable', 'uuid', 'required_without:partner_id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Add the controller action**

In `LoyaltyPOSController`, add the dependency + method (mirror `memberLookup`'s tenant/companyContext style):
```php
use App\Modules\Loyalty\Application\Services\PosLoyaltyBalanceService;
use App\Modules\Loyalty\Presentation\Requests\PosBalanceRequest;

// constructor: add `private readonly PosLoyaltyBalanceService $posBalanceService,`

public function balance(PosBalanceRequest $request): JsonResponse
{
    $company = $this->companyContext->requireCompany();

    $result = $this->posBalanceService->ensureAndGetBalance(
        $company->tenant_id,
        $request->input('partner_id'),
        $request->input('contact_id'),
        $request->input('phone'),
        $request->input('name'),
    );

    return response()->json(['data' => $result]);
}
```

- [ ] **Step 6: Add the route (inside the existing `loyalty/pos` group)**

```php
Route::post('/balance', [LoyaltyPOSController::class, 'balance'])
    ->middleware('can:pos.operate_terminal')
    ->name('loyalty.pos.balance');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/PosLoyaltyBalanceTest.php`
Expected: PASS (all 5).

- [ ] **Step 8: Commit**

```bash
git add apps/api/app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php \
        apps/api/app/Modules/Loyalty/Presentation/Requests/PosBalanceRequest.php \
        apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php \
        apps/api/app/Modules/Loyalty/Presentation/routes.php \
        apps/api/tests/Feature/Loyalty/PosLoyaltyBalanceTest.php
git commit -m "feat(loyalty): POST /loyalty/pos/balance — attach-time ensure-enroll + balance"
```

---

## Task 3: POS `useHasModule` selector + earn-rate cache

**Files:**
- Modify: `apps/pos/src/stores/productStore.ts` (add `useHasModule`)
- Create: `apps/pos/src/stores/loyaltyStore.ts` (holds the rate)
- Create: `apps/pos/src/lib/loyaltyRateCache.ts`
- Modify: `apps/pos/src/stores/authStore.ts` (`refreshCompanyConfig` fetch+cache)
- Test: `apps/pos/src/stores/__tests__/loyalty.test.ts`

**Interfaces:**
- Produces: `useHasModule(name: string): boolean`; `useLoyaltyStore` with `earnRate: string | null` + `setEarnRate(rate: string | null)`; `loadCachedLoyaltyRate(companyId)`, `persistLoyaltyRate(companyId, rate)`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest'
import { hasModule } from '../productStore'
import { useLoyaltyStore } from '../loyaltyStore'

describe('loyalty store + module gate', () => {
  it('hasModule detects Loyalty', () => {
    expect(hasModule({ all_enabled_modules: ['Loyalty'] } as never, 'Loyalty')).toBe(true)
    expect(hasModule({ all_enabled_modules: ['Inventory'] } as never, 'Loyalty')).toBe(false)
  })

  it('loyalty store holds the earn rate', () => {
    useLoyaltyStore.getState().setEarnRate('2')
    expect(useLoyaltyStore.getState().earnRate).toBe('2')
    useLoyaltyStore.getState().setEarnRate(null)
    expect(useLoyaltyStore.getState().earnRate).toBeNull()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/loyalty.test.ts`
Expected: FAIL — `loyaltyStore` not found.

- [ ] **Step 3: Create `loyaltyStore.ts`**

```ts
import { create } from 'zustand'

interface LoyaltyState {
  earnRate: string | null
  setEarnRate: (rate: string | null) => void
}

export const useLoyaltyStore = create<LoyaltyState>((set) => ({
  earnRate: null,
  setEarnRate: (rate) => set({ earnRate: rate }),
}))
```

- [ ] **Step 4: Create `loyaltyRateCache.ts` (mirror `companyConfigCache.ts`)**

```ts
import { getStoredValue, setStoredValue } from './storage' // confirm the real storage helpers used by companyConfigCache.ts

const key = (companyId: string): string => `loyalty_rate:${companyId}`

export async function persistLoyaltyRate(companyId: string, rate: string | null): Promise<void> {
  await setStoredValue(key(companyId), rate)
}

export async function loadCachedLoyaltyRate(companyId: string): Promise<string | null> {
  return (await getStoredValue<string | null>(key(companyId))) ?? null
}
```

> Open `companyConfigCache.ts` and reuse its exact storage imports/helpers (`getStoredValue`/`setStoredValue` names may differ) — match them verbatim.

- [ ] **Step 5: Add `useHasModule` to `productStore.ts` (near `hasModule`)**

```ts
export function useHasModule(moduleName: string): boolean {
  return useProductStore((s) => hasModule(s.companyConfig, moduleName))
}
```

- [ ] **Step 6: Fetch+cache the rate in `authStore.refreshCompanyConfig` (after config is cached)**

After `persistCompanyConfig(...)` in `refreshCompanyConfig`, add:
```ts
const { hasModule } = await import('@/stores/productStore')
if (companyId && hasModule(config, 'Loyalty')) {
  try {
    const res = await apiGet<{ rate: string | null }>('/loyalty/earn-rate')
    const { useLoyaltyStore } = await import('@/stores/loyaltyStore')
    useLoyaltyStore.getState().setEarnRate(res.rate ?? null)
    const { persistLoyaltyRate } = await import('@/lib/loyaltyRateCache')
    await persistLoyaltyRate(companyId, res.rate ?? null).catch(() => {})
  } catch (error) {
    console.debug('[auth] earn-rate fetch failed (loyalty estimate disabled)', error)
  }
}
```

> `apiGet` unwraps `response.data`, so `res` is `{ rate }` (the endpoint returns `{data:{rate}}`). Confirm `apiGet` is already imported in `authStore.ts`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/loyalty.test.ts`
Expected: PASS (2).

- [ ] **Step 8: Commit**

```bash
git add apps/pos/src/stores/loyaltyStore.ts apps/pos/src/lib/loyaltyRateCache.ts \
        apps/pos/src/stores/productStore.ts apps/pos/src/stores/authStore.ts \
        apps/pos/src/stores/__tests__/loyalty.test.ts
git commit -m "feat(pos): useHasModule selector + earn-rate cache at login"
```

---

## Task 4: POS loyalty chrome (component + API + wiring + i18n)

**Files:**
- Create: `apps/pos/src/lib/loyalty/loyaltyApi.ts` (balance client)
- Create: `apps/pos/src/lib/loyalty/useLoyaltyBalance.ts` (fetch-on-attach hook)
- Create: `apps/pos/src/components/customers/CustomerLoyaltyBadge.tsx`
- Modify: `apps/pos/src/components/customers/CartCustomerControl.tsx` (render the badge)
- Modify: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`
- Test: `apps/pos/src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx`

**Interfaces:**
- Consumes: `useHasModule` + `useLoyaltyStore.earnRate` (Task 3); `apiPost`; `cartStore.total()`; `AttachedCheckoutCustomer` (`id`, `phone`, `customer_sync_status`).
- Produces: `fetchLoyaltyBalance(customer)` → `{enrolled, balance, tier}`; `<CustomerLoyaltyBadge customer={...} />`.

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string, o?: Record<string, unknown>) => (o ? `${k}:${JSON.stringify(o)}` : k) }) }))
vi.mock('@/stores/productStore', () => ({ useHasModule: () => true }))
vi.mock('@/stores/loyaltyStore', () => ({ useLoyaltyStore: (sel: (s: { earnRate: string | null }) => unknown) => sel({ earnRate: '2' }) }))
vi.mock('@/stores/cartStore', () => ({ useCartStore: (sel: (s: { total: () => number }) => unknown) => sel({ total: () => 12 }) }))

const balance = { current: { enrolled: true, balance: '340.000', tier: 'Gold' } as { enrolled: boolean; balance: string; tier: string | null } | null }
vi.mock('@/lib/loyalty/useLoyaltyBalance', () => ({ useLoyaltyBalance: () => balance.current }))

import { CustomerLoyaltyBadge } from '../CustomerLoyaltyBadge'
const synced = { id: 'p1', phone: '+216200', customer_sync_status: 'synced' } as never

describe('CustomerLoyaltyBadge', () => {
  it('shows tier, balance and the floor(total*rate) estimate when enrolled', () => {
    balance.current = { enrolled: true, balance: '340.000', tier: 'Gold' }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.getByTestId('loyalty-balance')).toHaveTextContent('340')
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24') // floor(12 * 2)
  })

  it('shows the estimate + joins-on-purchase when not enrolled', () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-balance')).toBeNull()
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24')
  })

  it('renders nothing when the balance call returned null (offline/unsynced)', () => {
    balance.current = null
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-chrome')).toBeNull()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx`
Expected: FAIL — `CustomerLoyaltyBadge` / `useLoyaltyBalance` not found.

- [ ] **Step 3: Implement the balance API client**

```ts
import { apiPost } from '@/lib/api'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'

export interface LoyaltyBalance {
  enrolled: boolean
  balance: string
  tier: string | null
}

export async function fetchLoyaltyBalance(customer: AttachedCheckoutCustomer): Promise<LoyaltyBalance> {
  // apiPost unwraps response.data → the endpoint's {data:{...}} returns the inner object.
  return apiPost<LoyaltyBalance>('/loyalty/pos/balance', {
    partner_id: customer.id,
    phone: customer.phone,
  })
}
```

- [ ] **Step 4: Implement the fetch-on-attach hook**

```ts
import { useEffect, useState } from 'react'
import { useHasModule } from '@/stores/productStore'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'
import { fetchLoyaltyBalance, type LoyaltyBalance } from './loyaltyApi'

/** Online-only: returns null unless module on + customer server-synced + the call succeeds. */
export function useLoyaltyBalance(customer: AttachedCheckoutCustomer | null): LoyaltyBalance | null {
  const hasLoyalty = useHasModule('Loyalty')
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null)

  useEffect(() => {
    let active = true
    setBalance(null)
    const eligible = hasLoyalty && customer !== null && customer.customer_sync_status === 'synced'
    if (!eligible) return
    fetchLoyaltyBalance(customer)
      .then((b) => { if (active) setBalance(b) })
      .catch(() => { if (active) setBalance(null) }) // offline/network ⇒ no chrome
    return () => { active = false }
  }, [hasLoyalty, customer])

  return balance
}
```

- [ ] **Step 5: Implement the component**

```tsx
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { useCartStore } from '@/stores/cartStore'
import { useLoyaltyStore } from '@/stores/loyaltyStore'
import { useLoyaltyBalance } from '@/lib/loyalty/useLoyaltyBalance'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'

interface Props { customer: AttachedCheckoutCustomer }

export function CustomerLoyaltyBadge({ customer }: Props): JSX.Element | null {
  const { t } = useTranslation('pos')
  const balance = useLoyaltyBalance(customer)
  const rate = useLoyaltyStore((s) => s.earnRate)
  const total = useCartStore((s) => s.total())

  if (balance === null) return null // module off / offline / unsynced / call failed

  // Display-only estimate (never persisted): floor(cart TTC total × rate).
  // eslint-disable-next-line precision/no-parsefloat-on-money -- display estimate, not persisted money
  const estimate = rate !== null ? Math.floor(total * Number(rate)) : null

  return (
    <div data-testid="loyalty-chrome" className="flex flex-wrap items-center gap-1">
      {balance.enrolled && (
        <>
          {balance.tier !== null && <Badge tone="action">{balance.tier}</Badge>}
          <Badge tone="neutral" data-testid="loyalty-balance">
            {t('loyalty.points', { amount: Math.floor(Number(balance.balance)) })}
          </Badge>
        </>
      )}
      {estimate !== null && (
        <Badge tone="success" data-testid="loyalty-estimate">
          {t('loyalty.earnEstimate', { points: estimate })}
        </Badge>
      )}
      {!balance.enrolled && (
        <span className="text-xs text-muted-foreground">{t('loyalty.joinsOnPurchase')}</span>
      )}
    </div>
  )
}
```

> Confirm `JSX.Element | null` vs `ReactElement | null` per the project's TS strictness (Task 6 of the earlier feature used `ReactElement` to avoid a strict-mode trap — match the POS convention). Confirm `Badge` tone names (`action`/`neutral`/`success` exist per `Badge.tsx`).

- [ ] **Step 6: Wire it into `CartCustomerControl.tsx`**

In the attached-customer branch (where `selectedCustomer` renders the name chip), render the badge beside/under the name:
```tsx
import { CustomerLoyaltyBadge } from './CustomerLoyaltyBadge'
// ...inside the `selectedCustomer !== null` block, after the name button:
<CustomerLoyaltyBadge customer={selectedCustomer} />
```

- [ ] **Step 7: Add i18n keys (en + fr)**

`locales/en/pos.json` — add under the root object:
```json
"loyalty": {
  "points": "{{amount}} pts",
  "earnEstimate": "+{{points}} pts this sale",
  "joinsOnPurchase": "Joins on purchase"
}
```
`locales/fr/pos.json`:
```json
"loyalty": {
  "points": "{{amount}} pts",
  "earnEstimate": "+{{points}} pts cette vente",
  "joinsOnPurchase": "Adhésion à l'achat"
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx`
Expected: PASS (3).

- [ ] **Step 9: Commit**

```bash
git add apps/pos/src/lib/loyalty apps/pos/src/components/customers/CustomerLoyaltyBadge.tsx \
        apps/pos/src/components/customers/CartCustomerControl.tsx \
        apps/pos/src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx \
        apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(pos): gated loyalty chrome — balance, tier, live earn estimate on attach"
```

---

## Task 5: Preflight + manual E2E

**Files:** none (verification).

- [ ] **Step 1: Backend — new tests by path only**

```bash
cd apps/api && ./vendor/bin/phpunit \
  tests/Feature/Loyalty/MemberResolverTest.php \
  tests/Feature/Loyalty/PosLoyaltyBalanceTest.php \
  tests/Feature/Loyalty/SaleEarningServiceTest.php
```
Expected: all PASS. **Never** the full suite/`--parallel`.

- [ ] **Step 2: Backend static + style on changed files**

```bash
cd apps/api && ./vendor/bin/phpstan analyse \
  app/Modules/Loyalty/Application/Resolvers/MemberResolver.php \
  app/Modules/Loyalty/Application/Services/PosLoyaltyBalanceService.php \
  app/Modules/Loyalty/Application/Services/SaleEarningService.php \
  app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php \
  app/Modules/Loyalty/Presentation/Requests/PosBalanceRequest.php
./vendor/bin/pint app/Modules/Loyalty
```
Expected: zero PHPStan errors; Pint clean.

- [ ] **Step 3: POS — typecheck, lint changed files, the new tests**

```bash
cd apps/pos && pnpm typecheck && \
  pnpm exec eslint src/stores/loyaltyStore.ts src/lib/loyaltyRateCache.ts src/lib/loyalty src/components/customers/CustomerLoyaltyBadge.tsx && \
  pnpm vitest run src/stores/__tests__/loyalty.test.ts src/components/customers/__tests__/CustomerLoyaltyBadge.test.tsx
```
Expected: PASS; 0 lint errors (pre-existing warnings unchanged).

- [ ] **Step 4: Manual E2E (demo data, online)**

1. Loyalty module ON for a parapharmacy tenant with an active Spend program (rate set).
2. Attach a **synced** customer (with a phone) at checkout → chrome shows tier + balance; a **new** customer shows `+N pts this sale` + "Joins on purchase"; after the first balance call, the member exists (enrolled).
3. Add items → estimate updates `floor(cart TTC × rate)`.
4. Complete the sale; on sync, confirm the server credited points (balance increases on next attach).
5. Walk-in (no customer) / module off / offline → no loyalty chrome.

- [ ] **Step 5: Commit verification note**

```bash
git add docs/superpowers/specs/2026-06-28-loyalty-pos-online-phase1-design.md
git commit -m "docs(loyalty-pos): mark Phase 1 verified end-to-end"
```

---

## Self-review (completed by plan author)

- **Spec coverage:** auto-enroll-at-attach + balance endpoint (T2), shared resolver (T1), module selector + rate cache (T3), gated chrome + estimate + i18n + unsynced/offline handling (T4), verify (T5). Earn basis TTC (T4 estimate + unchanged server path). Out-of-scope items (offline mirror, projection auto-enroll, redeem, QR, consent UI) intentionally have no task.
- **Placeholder scan:** none — every code step has full code; the few "confirm X against file Y" notes name an exact existing file to mirror.
- **Type consistency:** `MemberResolver::resolveByContactOrPartner`, `PosLoyaltyBalanceService::ensureAndGetBalance` shape `{enrolled,balance,tier}`, `useHasModule`, `useLoyaltyStore.earnRate`, `fetchLoyaltyBalance`/`useLoyaltyBalance`, and the `{enrolled,balance,tier}` contract are consistent across backend and POS tasks.

## Task ordering note

T0 (deps) first. T1 before T2 (resolver). T3 before T4 (selector + rate). T2 and T3 are independent after T1. T5 last.
