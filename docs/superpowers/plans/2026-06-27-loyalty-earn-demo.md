# Loyalty Earn-on-Purchase (Demo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Credit loyalty points on every live POS sale using the existing points-per-money-unit Spend rule, and show a read-only derived "Loyalty points" figure in the product editor.

**Architecture:** The earning engine already works but the live fiscal-event projection never triggers it. We add a cross-module `Shared/Contracts/Loyalty` interface that `PosCoreReceiptProjection` calls (mirroring its synchronous `redeemVouchers()` side-effect block); a Loyalty `SaleEarningService` resolves the member and credits each active enrollment via the existing `EarningProcessingService::earnPoints()`. A listener seeds a default Spend rule (1 TND = 1 point) on program activation. The product editor reads the active rate from a small endpoint and displays `sale_price × rate`.

**Tech Stack:** Laravel 12 / PHP 8.2 (strict types, hexagonal modules, constructor injection), PostgreSQL (db-per-tenant), PHPUnit; React 19 / TypeScript / TanStack Query / Vitest.

## Global Constraints

- **Money/qty are strings, never floats** (rule 19). `earnBase` is the normalized receipt total string; never `(float)`.
- **Projections run with NO CompanyContext** (rule 20): pass explicit currency; scale via `getScaleSafe($currency, 3)` (already inside `earnPoints`). Never a no-arg `getScale()`.
- **Cross-module access only via `Shared/Contracts/` interface** (rule 6): POS must import no Loyalty model — only `App\Shared\Contracts\Loyalty\*`.
- **Both-layer module gating** (rule 12): new endpoint under the `module:Loyalty` route group; FE field gated on `hasModule('Loyalty')`.
- **All FE user-facing text via `t()`** (react-i18next).
- **NEVER run the full PHPUnit suite or `--parallel`** (crashes the laptop). Scoped `--filter`/by-path only.
- **TDD**: failing test first, minimal code, green, commit. Frequent commits.
- Worktree: `../erp.loyalty-earn` on branch `feat/loyalty-earn-per-product` (already created).
- Backend test base dir: `apps/api`. Frontend: `apps/web`.

---

## File Structure

| File | Responsibility |
|---|---|
| `apps/api/app/Shared/Contracts/Loyalty/LoyaltyEarningContract.php` | Cross-module earn interface (POS↔Loyalty boundary) |
| `apps/api/app/Shared/Contracts/Loyalty/SaleEarnContext.php` | Immutable DTO of primitives passed across the boundary |
| `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` | Implements the contract: module guard, member resolution, per-enrollment earn, discriminated failure |
| `apps/api/app/Modules/Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php` | Seeds the default Spend rule on activation |
| `apps/api/database/factories/Loyalty/EarningRuleFactory.php` | Test/seed factory for `EarningRule` |
| `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyEarnRateController.php` | `GET /loyalty/earn-rate` → active Spend rate |
| `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` | Add the earn trigger (edit) |
| `apps/api/app/Modules/Loyalty/Providers/LoyaltyServiceProvider.php` | Bind contract → service (edit) |
| `apps/api/app/Providers/EventServiceProvider.php` | Register the activation listener (edit) |
| `apps/api/app/Modules/Loyalty/Presentation/routes.php` | Add the earn-rate route (edit) |
| `apps/web/src/features/inventory/ProductForm.tsx` | Gated read-only loyalty display section (edit) |
| `apps/web/src/features/inventory/useLoyaltyEarnRate.ts` | FE hook reading `GET /loyalty/earn-rate` (create) |

---

## Task 1: Cross-module contract + DTO

**Files:**
- Create: `apps/api/app/Shared/Contracts/Loyalty/SaleEarnContext.php`
- Create: `apps/api/app/Shared/Contracts/Loyalty/LoyaltyEarningContract.php`
- Test: `apps/api/tests/Unit/Shared/Contracts/Loyalty/SaleEarnContextTest.php`

**Interfaces:**
- Produces: `SaleEarnContext` (readonly DTO) and `LoyaltyEarningContract::earnForSale(SaleEarnContext): void`, consumed by Tasks 2 & 3.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Contracts\Loyalty;

use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SaleEarnContextTest extends TestCase
{
    public function test_it_holds_sale_earn_fields_as_given(): void
    {
        $postedAt = CarbonImmutable::parse('2026-06-27 10:00:00');
        $ctx = new SaleEarnContext(
            tenantId: 't-1',
            contactId: 'c-1',
            partnerId: null,
            currency: 'TND',
            sourceType: 'pos_receipt',
            sourceId: 'r-1',
            receiptNumber: 'POS-1',
            postedAt: $postedAt,
            earnBase: '12.000',
        );

        self::assertSame('t-1', $ctx->tenantId);
        self::assertSame('c-1', $ctx->contactId);
        self::assertNull($ctx->partnerId);
        self::assertSame('pos_receipt', $ctx->sourceType);
        self::assertSame('12.000', $ctx->earnBase);
        self::assertSame($postedAt, $ctx->postedAt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Shared/Contracts/Loyalty/SaleEarnContextTest.php`
Expected: FAIL — class `App\Shared\Contracts\Loyalty\SaleEarnContext` not found.

- [ ] **Step 3: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Loyalty;

use Carbon\CarbonInterface;

/**
 * Immutable, primitives-only snapshot of a completed sale needed to credit
 * loyalty points. Crosses the POS→Loyalty module boundary (rule 6) — carries
 * no Loyalty or POS model, only scalars. Money is a numeric-string (rule 19).
 */
final readonly class SaleEarnContext
{
    public function __construct(
        public string $tenantId,
        public ?string $contactId,
        public ?string $partnerId,
        public string $currency,
        public string $sourceType,
        public string $sourceId,
        public ?string $receiptNumber,
        public CarbonInterface $postedAt,
        public string $earnBase,
    ) {}
}
```

- [ ] **Step 4: Create the contract interface**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Loyalty;

/**
 * Public Loyalty earning surface for other modules (POS). The only sanctioned
 * way for POS to award points — no Loyalty model is imported by the consumer.
 */
interface LoyaltyEarningContract
{
    /**
     * Credit loyalty points for a completed sale. Best-effort and idempotent:
     * resolves the member, loops active enrollments, and swallows the
     * already-earned duplicate case. Never throws to the caller.
     */
    public function earnForSale(SaleEarnContext $context): void;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Shared/Contracts/Loyalty/SaleEarnContextTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Shared/Contracts/Loyalty apps/api/tests/Unit/Shared/Contracts/Loyalty
git commit -m "feat(loyalty): cross-module earn contract + SaleEarnContext DTO"
```

---

## Task 2: SaleEarningService (the earn implementation)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php`
- Modify: `apps/api/app/Modules/Loyalty/Providers/LoyaltyServiceProvider.php` (bind contract → service)
- Test: `apps/api/tests/Feature/Loyalty/SaleEarningServiceTest.php`

**Interfaces:**
- Consumes: `SaleEarnContext`, `LoyaltyEarningContract` (Task 1); `EarningProcessingService::earnPoints(string $enrollmentId, array $transactionData, string $sourceType, string $sourceId, ?string $description): TransactionData`; `LoyaltyProgramRepositoryInterface::findByTenantAndStatus(string, ProgramStatus): Collection`; `EnrollmentStatus::Active`; models `LoyaltyMember`, `Enrollment`.
- Produces: `SaleEarningService implements LoyaltyEarningContract`, bound for Task 3.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaleEarningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveSpendProgram(string $tenantId, string $rate = '1'): LoyaltyProgram
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);
        \App\Modules\Loyalty\Domain\Entities\EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => \App\Modules\Loyalty\Domain\Enums\EarningRuleType::Spend,
            'reward_value' => $rate,
            'is_active' => true,
            'conditions' => [],
        ]);

        return $program;
    }

    private function enrollContactMember(string $tenantId, string $programId, string $contactId): Enrollment
    {
        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $contactId,
        ]);

        return Enrollment::factory()->create([
            'program_id' => $programId,
            'member_id' => $member->id,
            'status' => EnrollmentStatus::Active,
            'current_balance' => '0.000',
        ]);
    }

    private function context(string $tenantId, ?string $contactId, string $earnBase, string $sourceId): SaleEarnContext
    {
        return new SaleEarnContext(
            tenantId: $tenantId,
            contactId: $contactId,
            partnerId: null,
            currency: 'TND',
            sourceType: 'pos_receipt',
            sourceId: $sourceId,
            receiptNumber: 'POS-1',
            postedAt: CarbonImmutable::parse('2026-06-27 10:00:00'),
            earnBase: $earnBase,
        );
    }

    public function test_it_credits_points_to_an_enrolled_contact(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $contactId = (string) \Illuminate\Support\Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        // Projections/workers run with NO CompanyContext (rule 20) — prove it.
        app(\App\Modules\Company\Services\CompanyContext::class)->clear();

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, $contactId, '12.000', 'receipt-1')
        );

        $txns = Transaction::where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)->get();
        self::assertCount(1, $txns);
        self::assertSame('12.000', $txns->first()->amount);
        self::assertSame('12.000', $enrollment->fresh()->current_balance);
    }

    public function test_no_member_is_a_silent_noop(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $this->seedActiveSpendProgram($tenantId);

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, (string) \Illuminate\Support\Str::uuid(), '12.000', 'receipt-2')
        );

        self::assertSame(0, Transaction::count());
    }

    public function test_disabled_tenant_without_active_program_is_a_noop(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $contactId = (string) \Illuminate\Support\Str::uuid();
        // No active program seeded → module guard returns early.
        LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $contactId,
        ]);

        app(LoyaltyEarningContract::class)->earnForSale(
            $this->context($tenantId, $contactId, '12.000', 'receipt-3')
        );

        self::assertSame(0, Transaction::count());
    }

    public function test_replaying_the_same_source_credits_points_exactly_once(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $contactId = (string) \Illuminate\Support\Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $ctx = $this->context($tenantId, $contactId, '12.000', 'receipt-4');
        $service = app(LoyaltyEarningContract::class);
        $service->earnForSale($ctx);
        $service->earnForSale($ctx); // replay — must not double-credit

        self::assertCount(
            1,
            Transaction::where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)->get()
        );
        self::assertSame('12.000', $enrollment->fresh()->current_balance);
    }
}
```

> CompanyContext FQCN confirmed: `App\Modules\Company\Services\CompanyContext` (its `clear()` is the real method — the sibling `PosCoreReceiptProjection` test *sets* context rather than clearing, so there's nothing to copy; call `clear()` directly as above).

- [ ] **Step 0: Create the three missing Loyalty factories (prerequisite for these tests)**

Only `LoyaltyProgramFactory` exists today, and module models are NOT auto-discovered — each needs a `newFactory()` override (mirror `LoyaltyProgram.php`). Create:

`apps/api/database/factories/Loyalty/LoyaltyMemberFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyMember> */
final class LoyaltyMemberFactory extends Factory
{
    protected $model = LoyaltyMember::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'customer_id' => null,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $this->faker->uuid(),
            'phone' => $this->faker->unique()->numerify('+216########'),
            'email' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ];
    }
}
```

`apps/api/database/factories/Loyalty/EnrollmentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
final class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'program_id' => $this->faker->uuid(),
            'member_id' => $this->faker->uuid(),
            'current_balance' => '0.000',
            'lifetime_earned' => '0.000',
            'lifetime_redeemed' => '0.000',
            'current_tier_id' => null,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ];
    }
}
```

`apps/api/database/factories/Loyalty/EarningRuleFactory.php` — see Task 4 Step 1 (create it now if doing Task 2 first).

Then add a `newFactory()` override to EACH of the three models (`LoyaltyMember`, `Enrollment`, `EarningRule`), mirroring the one already on `LoyaltyProgram`:

```php
use Illuminate\Database\Eloquent\Factories\Factory;

protected static function newFactory(): Factory
{
    return \Database\Factories\Loyalty\LoyaltyMemberFactory::new(); // EnrollmentFactory / EarningRuleFactory respectively
}
```

> Open `LoyaltyProgram.php` and copy its exact `newFactory()` + `HasFactory` usage. Verify each model's real columns/casts (`MemberStatus`, balance casts) before finalizing the factory defaults — adjust to match the migration. If you prefer zero model edits, instead rewrite the Task 2 helpers to insert via `Model::create([...])` (mirror `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php` ~lines 225-245), but factories are cleaner and reused by Task 4.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/SaleEarningServiceTest.php`
Expected: FAIL — `LoyaltyEarningContract` has no binding.

- [ ] **Step 3: Implement `SaleEarningService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Credits loyalty points for a completed POS sale using the existing
 * points-per-money-unit Spend rule. Relocates the (retired)
 * EarnPointsOnReceiptCompleted listener logic behind the cross-module
 * contract, reading the buyer from the sealed sale snapshot and passing
 * money as strings (rule 19). Best-effort: never throws to the caller.
 */
final readonly class SaleEarningService implements LoyaltyEarningContract
{
    public function __construct(
        private EarningProcessingService $earningService,
        private LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    public function earnForSale(SaleEarnContext $context): void
    {
        // Module guard (Codex SF-2): no active loyalty program ⇒ Loyalty not
        // set up for this tenant ⇒ nothing to earn. Worker-safe (no CompanyContext).
        $activePrograms = $this->programRepository->findByTenantAndStatus(
            $context->tenantId,
            ProgramStatus::Active,
        );
        if ($activePrograms->isEmpty()) {
            return;
        }

        $member = $this->resolveMember($context);
        if ($member === null) {
            return;
        }

        $enrollments = Enrollment::query()
            ->where('member_id', $member->id)
            ->where('status', EnrollmentStatus::Active)
            ->get();

        $transactionData = [
            'amount' => $context->earnBase,        // numeric-string (rule 19)
            'currency' => $context->currency,
            'items' => [],                          // Spend rule needs only the aggregate base
            'timestamp' => $context->postedAt,      // sealed device time (Codex SF-3)
        ];

        foreach ($enrollments as $enrollment) {
            try {
                $this->earningService->earnPoints(
                    enrollmentId: $enrollment->id,
                    transactionData: $transactionData,
                    sourceType: $context->sourceType,
                    sourceId: $context->sourceId,
                    description: $context->receiptNumber !== null
                        ? "POS receipt #{$context->receiptNumber}"
                        : null,
                );
            } catch (InvalidArgumentException $e) {
                // earnPoints() throws InvalidArgumentException for BOTH the
                // already-earned duplicate AND enrollment-not-found. Only the
                // duplicate is the idempotent replay/double-fire we swallow —
                // discriminate by message (Codex S1). Anything else falls through
                // to the loud-log branch.
                if (str_contains($e->getMessage(), 'already earned')) {
                    continue;
                }
                Log::error('Loyalty earn failed for sale (recoverable by hand)', [
                    'tenant_id' => $context->tenantId,
                    'enrollment_id' => $enrollment->id,
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'receipt_number' => $context->receiptNumber,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Real, non-duplicate failure (e.g. transient DB error). Log
                // loudly with recovery context; never break the sale. Auto-retry
                // is deferred (best-effort this cutoff).
                Log::error('Loyalty earn failed for sale (recoverable by hand)', [
                    'tenant_id' => $context->tenantId,
                    'enrollment_id' => $enrollment->id,
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'receipt_number' => $context->receiptNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveMember(SaleEarnContext $context): ?LoyaltyMember
    {
        $member = null;

        if ($context->contactId !== null) {
            $member = LoyaltyMember::query()
                ->where('loyaltyable_type', 'contact')
                ->where('loyaltyable_id', $context->contactId)
                ->where('tenant_id', $context->tenantId)
                ->first();
        }

        if ($member === null && $context->partnerId !== null) {
            $partnerId = $context->partnerId;
            $member = LoyaltyMember::query()
                ->where(function ($q) use ($partnerId) {
                    $q->where(function ($q2) use ($partnerId) {
                        $q2->where('loyaltyable_type', 'partner')
                            ->where('loyaltyable_id', $partnerId);
                    })->orWhere('customer_id', $partnerId);
                })
                ->where('tenant_id', $context->tenantId)
                ->first();
        }

        return $member;
    }
}
```

- [ ] **Step 4: Bind the contract in `LoyaltyServiceProvider::register()`**

Add the import and binding (place beside the existing repository bindings):

```php
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Modules\Loyalty\Application\Services\SaleEarningService;

// inside register():
$this->app->bind(LoyaltyEarningContract::class, SaleEarningService::class);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/SaleEarningServiceTest.php`
Expected: PASS (all 4). If `EarningRule::factory()`/`Enrollment::factory()`/`LoyaltyMember::factory()` are missing, add them (Task 4 covers `EarningRuleFactory`; `LoyaltyProgramFactory` already exists — check for `EnrollmentFactory`/`LoyaltyMemberFactory` and create minimal ones if absent, mirroring `LoyaltyProgramFactory`).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php \
        apps/api/app/Modules/Loyalty/Providers/LoyaltyServiceProvider.php \
        apps/api/tests/Feature/Loyalty/SaleEarningServiceTest.php
git commit -m "feat(loyalty): SaleEarningService — credit Spend-rule points for a sale (idempotent, module-guarded)"
```

---

## Task 3: Trigger earning from the live projection

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (ctor + new private method + call site after `redeemVouchers` at line ~318)
- Test: `apps/api/tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php`

**Interfaces:**
- Consumes: `LoyaltyEarningContract::earnForSale(SaleEarnContext)` (Tasks 1–2); projection locals `$receiptId`, `$event`, `$view` (`SaleReceiptCanonicalView`), `$payload`, `$receiptTypeEnum` (`ReceiptType`), `$totalNorm`; `$view->buyer` (`?BuyerDTO` with `customerId`/`contactId`), `$payload->currencyCode`, `$payload->trainingFlag`, `$event->event_time_device`, `$event->tenant_id`.

- [ ] **Step 1: Write the failing test**

Model this on the existing `PosCoreReceiptProjection` feature tests (copy their fiscal-event/`FiscalEvent` builder + `apply()` invocation + `CompanyContext::clear()` setup). Assert four behaviors:

```php
// Pseudostructure — reuse the existing projection test's helpers to build a
// SALE_RECEIPT FiscalEvent with a buyer snapshot and apply the projection.

public function test_sale_with_enrolled_buyer_credits_points_once(): void
{
    // GIVEN an active Spend program (rate 1) + a contact member enrolled,
    //   and a SALE_RECEIPT fiscal event whose buyer.contactId = that contact,
    //   with payload.total = '12.000'.
    // WHEN apply($event) runs.
    // THEN exactly one Earn transaction exists for the enrollment with amount '12.000'.
}

public function test_sale_without_buyer_credits_nothing(): void
{
    // buyer == null ⇒ no Transaction rows.
}

public function test_refund_receipt_credits_nothing(): void
{
    // Codex B3: a REFUND/VOID event throws OriginalReceiptUnresolvableException and
    // ROLLS BACK unless the original SALE was projected first — so a naive refund
    // event would fail for the wrong reason and never reach the earn guard.
    // GIVEN: first project a normal SALE for the enrolled buyer (original receipt),
    //   THEN build+apply a REFUND event whose original_receipt_reference resolves to it.
    // THEN: the enrolled buyer's Earn count stays at 1 (the original sale's), the
    //   refund adds NO Earn transaction (earn-eligibility guard skips Return).
}

public function test_training_receipt_credits_nothing(): void
{
    // Codex B2: the sibling builder hardcodes training_flag=false. Build the event
    // with trainingFlag=true — either parametrize storeSaleReceiptFiscalEvent() to
    // accept a trainingFlag arg, or construct the payload inline with
    // 'training_flag' => true. THEN apply ⇒ no Earn transaction.
}

public function test_replaying_the_same_fiscal_event_credits_once(): void
{
    // apply($event) twice (same fiscal_event_id) ⇒ exactly one Earn transaction.
}
```

Write these out fully using the real `FiscalEvent` fixture builder from the sibling projection test. **Two builder limitations to handle (Codex B2/B3):** (a) the builder hardcodes `training_flag => false` — parametrize it (add an optional `bool $trainingFlag = false` param) so the training test can set `true`; (b) a REFUND/VOID needs its original SALE projected first or it throws `OriginalReceiptUnresolvableException` and rolls back — the refund test must project the original sale, then a refund event referencing it. The replay test directly satisfies rule 20.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php`
Expected: FAIL — no points credited (trigger not wired yet).

- [ ] **Step 3: Add the constructor dependency**

In `PosCoreReceiptProjection`, add the import and a 5th promoted ctor param:

```php
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;

public function __construct(
    private readonly VoucherRedemptionService $voucherRedemptionService,
    private readonly ReceiptHashService $receiptHashService,
    private readonly CanonicalPayloadReader $canonicalReader,
    private readonly PaymentMethodResolver $paymentMethodResolver,
    private readonly LoyaltyEarningContract $loyaltyEarning,
) {}
```

- [ ] **Step 4: Call the earn hook after `redeemVouchers` (line ~318)**

```php
            $this->redeemVouchers($receiptId, $event, $view);
            $this->earnLoyaltyPoints($receiptId, $event, $view, $payload, $receiptTypeEnum, $totalNorm);
            $this->decrementStockForLines($receiptId, $event, $terminal, $view);
```

- [ ] **Step 5: Add the private `earnLoyaltyPoints()` method (beside `redeemVouchers`)**

```php
    /**
     * Credit loyalty points for an earning SALE. Mirrors redeemVouchers() —
     * synchronous, try/catch, must never break the sale projection.
     * Earns only on a real SALE (not REFUND/VOID → Return, not training).
     */
    private function earnLoyaltyPoints(
        string $receiptId,
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        SaleReceiptPayload $payload,
        ReceiptType $receiptType,
        string $totalNorm,
    ): void {
        // Earn-eligibility guard (Codex BLOCKER-2): refunds/voids/training earn nothing.
        if ($receiptType !== ReceiptType::Sale || $payload->trainingFlag === true) {
            return;
        }

        try {
            $this->loyaltyEarning->earnForSale(new SaleEarnContext(
                tenantId: $event->tenant_id,
                contactId: $view->buyer?->contactId,
                partnerId: $view->buyer?->customerId,
                currency: $payload->currencyCode,
                sourceType: 'pos_receipt',
                sourceId: $receiptId,
                receiptNumber: $event->sequence_number !== null
                    ? (string) $event->sequence_number
                    : null,
                postedAt: $event->event_time_device,
                earnBase: $totalNorm,
            ));
        } catch (\Throwable $e) {
            Log::error('PosCoreReceiptProjection: loyalty earn failed (sale unaffected)', [
                'fiscal_event_id' => $event->id,
                'receipt_id' => $receiptId,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

> Confirm the imports for `ReceiptType` (`App\Modules\POS\Domain\Enums\ReceiptType`) and `SaleReceiptPayload` (the type of `$view->payload`) are present; add them if missing. Use the receipt number the rest of the projection uses if `$receiptNumber` (the built human number) is preferable to `sequence_number` — pass whichever local is already in scope; it's only used for the transaction description.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php`
Expected: PASS (all 5).

- [ ] **Step 7: Regression — run the existing projection test by path**

Run: `cd apps/api && ./vendor/bin/phpunit --filter PosCoreReceiptProjection tests/Feature/POS`
Expected: PASS (the new ctor dep resolves via the container; voucher/stock paths unchanged).

- [ ] **Step 8: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php \
        apps/api/tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php
git commit -m "feat(pos): trigger loyalty earn from the live receipt projection (sale-only, idempotent)"
```

---

## Task 4: Seed the default Spend rule on program activation

**Files:**
- Create: `apps/api/database/factories/Loyalty/EarningRuleFactory.php`
- Create: `apps/api/app/Modules/Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php`
- Modify: `apps/api/app/Providers/EventServiceProvider.php` (register the listener)
- Test: `apps/api/tests/Feature/Loyalty/SeedDefaultEarningRuleOnProgramActivatedTest.php`

**Interfaces:**
- Consumes: `ProgramActivated` (`programId`, `tenantId`, `programName`, `activatedAt`); `EarningRuleRepositoryInterface::findActiveByProgram(string): Collection` and `::save(EarningRule): EarningRule`; `EarningRuleType::Spend`.
- Produces: a seeded active `Spend` rule (`reward_value='1'`) for a freshly-activated program; `EarningRuleFactory` for tests.

- [ ] **Step 1: Create `EarningRuleFactory`** (needed by Task 2 tests too)

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EarningRule> */
final class EarningRuleFactory extends Factory
{
    protected $model = EarningRule::class;

    public function definition(): array
    {
        return [
            'program_id' => $this->faker->uuid(),
            'name' => 'Points per dinar',
            'rule_type' => EarningRuleType::Spend,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'reward_value' => '1',
            'reward_type' => 'multiplier',
            'start_date' => null,
            'end_date' => null,
            'max_earn_per_transaction' => null,
            'max_earn_per_day' => null,
        ];
    }
}
```

> Confirm `EarningRule` uses `HasFactory` and resolves `Database\Factories\Loyalty\EarningRuleFactory` (Laravel maps `App\Modules\...\EarningRule` → default factory namespace only if `newFactory()` is defined). If the model is in a non-standard namespace, add a `protected static function newFactory(): Factory { return EarningRuleFactory::new(); }` to the model (mirror how `LoyaltyProgram` wires `LoyaltyProgramFactory`).

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\ProgramManagementService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedDefaultEarningRuleOnProgramActivatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_seeds_a_single_spend_rule_on_a_fresh_program(): void
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ProgramStatus::Draft,
        ]);

        app(ProgramManagementService::class)->activateProgram($program->id);

        $rules = EarningRule::where('program_id', $program->id)->get();
        self::assertCount(1, $rules);
        self::assertSame(EarningRuleType::Spend, $rules->first()->rule_type);
        self::assertSame('1.0000', $rules->first()->reward_value); // decimal:4 cast
        self::assertTrue($rules->first()->is_active);
    }

    public function test_activation_does_not_seed_when_an_active_rule_already_exists(): void
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ProgramStatus::Draft,
        ]);
        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Item,
            'reward_value' => '5',
            'is_active' => true,
        ]);

        app(ProgramManagementService::class)->activateProgram($program->id);

        self::assertCount(1, EarningRule::where('program_id', $program->id)->get());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/SeedDefaultEarningRuleOnProgramActivatedTest.php`
Expected: FAIL — no rule seeded (no listener registered).

- [ ] **Step 4: Implement the listener**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Listeners;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;

/**
 * On program activation, seed a default points-per-money-unit Spend rule
 * (1 TND = 1 point) so every purchase earns out of the box. No-op if the
 * program already has an active earn rule (the admin configured their own).
 */
final readonly class SeedDefaultEarningRuleOnProgramActivated
{
    public function __construct(
        private EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function handle(ProgramActivated $event): void
    {
        $existing = $this->earningRuleRepository->findActiveByProgram($event->programId);
        if ($existing->isNotEmpty()) {
            return;
        }

        // EarningRule uses HasUuids — `id` is auto-generated, NOT fillable (Codex S4).
        $rule = new EarningRule([
            'program_id' => $event->programId,
            'name' => 'Points per dinar',
            'rule_type' => EarningRuleType::Spend,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'reward_value' => '1',
            'reward_type' => 'multiplier',
        ]);

        $this->earningRuleRepository->save($rule);
    }
}
```

> `EarningRule` uses `HasUuids` (`id` auto-generated, not in `$fillable`). Mirror `EarningRuleController@store`'s construction exactly if any other column is required by a NOT NULL constraint.

- [ ] **Step 5: Register the listener in `EventServiceProvider::$listen`**

```php
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Loyalty\Application\Listeners\SeedDefaultEarningRuleOnProgramActivated;

// inside the $listen array:
ProgramActivated::class => [
    SeedDefaultEarningRuleOnProgramActivated::class,
],
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/SeedDefaultEarningRuleOnProgramActivatedTest.php`
Expected: PASS (both). `ProgramActivated` dispatches via `DB::afterCommit`; if the test doesn't observe the seeded rule, ensure the activation runs inside a committed transaction (the service wraps it) — `RefreshDatabase` commits per test, so `afterCommit` fires.

- [ ] **Step 7: Commit**

```bash
git add apps/api/database/factories/Loyalty/EarningRuleFactory.php \
        apps/api/app/Modules/Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php \
        apps/api/app/Providers/EventServiceProvider.php \
        apps/api/tests/Feature/Loyalty/SeedDefaultEarningRuleOnProgramActivatedTest.php
git commit -m "feat(loyalty): seed default 1 TND = 1 point Spend rule on program activation"
```

---

## Task 5: `GET /loyalty/earn-rate` endpoint (for the editor display)

**Files:**
- Create: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyEarnRateController.php`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/routes.php` (add route to the `module:Loyalty` group)
- Test: `apps/api/tests/Feature/Loyalty/LoyaltyEarnRateEndpointTest.php`

**Interfaces:**
- Consumes: `CompanyContext` (for `tenant_id`), `LoyaltyProgramRepositoryInterface::findByTenantAndStatus`, `EarningRuleRepositoryInterface::findActiveByProgram`, `EarningRuleType::Spend`.
- Produces: `GET /loyalty/earn-rate` → `{ "data": { "rate": string|null } }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

// Use the project's standard authenticated-tenant-user test setup (copy from an
// existing Loyalty endpoint test, e.g. the programs index test) so the request
// passes auth:sanctum + SetPermissionsTeam + module:Loyalty.

final class LoyaltyEarnRateEndpointTest extends \Tests\TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_it_returns_the_active_spend_rate(): void
    {
        // GIVEN an authenticated tenant user with the Loyalty module enabled,
        //   an active program with an active Spend rule reward_value = '2'.
        // WHEN GET /api/v1/loyalty/earn-rate
        // THEN 200 with data.rate === '2.0000' (decimal:4) or '2' — assert the
        //   actual cast value returned by EarningRule->reward_value.
    }

    public function test_it_returns_null_rate_when_no_active_program(): void
    {
        // GIVEN Loyalty enabled but no active program.
        // WHEN GET /api/v1/loyalty/earn-rate
        // THEN 200 with data.rate === null.
    }
}
```

Fill these in fully using the existing Loyalty endpoint test harness (member/role seeding + `module:Loyalty`-enabled tenant). Look at an existing test under `tests/Feature/Loyalty` for the exact auth/tenant bootstrapping and copy it.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyEarnRateEndpointTest.php`
Expected: FAIL — route not defined (404/405).

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the effective points-per-money-unit earning rate so the product
 * editor can show an indicative "≈ N points" figure. Read-only.
 */
final class LoyaltyEarnRateController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $programs = $this->programRepository->findByTenantAndStatus($tenantId, ProgramStatus::Active);
        $rate = null;

        foreach ($programs as $program) {
            $spend = $this->earningRuleRepository->findActiveByProgram($program->id)
                ->first(fn (EarningRule $r) => $r->rule_type === EarningRuleType::Spend);
            if ($spend !== null) {
                $rate = (string) $spend->reward_value;
                break;
            }
        }

        return response()->json(['data' => ['rate' => $rate]]);
    }
}
```

> CompanyContext FQCN confirmed: `App\Modules\Company\Services\CompanyContext` (same one `LoyaltyProgramController` injects; `requireCompany()->tenant_id` is the pattern).

- [ ] **Step 4: Add the route inside the `module:Loyalty` group**

In `apps/api/app/Modules/Loyalty/Presentation/routes.php`, within the existing
`->middleware(['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Loyalty'])` group:

```php
Route::get('loyalty/earn-rate', [\App\Modules\Loyalty\Presentation\Controllers\LoyaltyEarnRateController::class, 'show'])
    ->middleware('can:loyalty.view')
    ->name('loyalty.earn-rate');
```

> Ability confirmed: `can:loyalty.view` — the module's read permission used by every Loyalty GET, and the ability a back-office editor user holds (NOT `pos.operate_terminal`, which is the POS-operator perm). The `module:Loyalty` group provides both-layer gating (rule 12).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyEarnRateEndpointTest.php`
Expected: PASS (both).

- [ ] **Step 6: Add the module-off 403 test + verify**

Add `test_it_403s_when_loyalty_module_disabled()` (tenant on a vertical without Loyalty) and assert `assertForbidden()`. Run the file again; expected PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyEarnRateController.php \
        apps/api/app/Modules/Loyalty/Presentation/routes.php \
        apps/api/tests/Feature/Loyalty/LoyaltyEarnRateEndpointTest.php
git commit -m "feat(loyalty): GET /loyalty/earn-rate endpoint (module-gated) for editor display"
```

---

## Task 6: Product-editor read-only loyalty display (FE)

**Files:**
- Create: `apps/web/src/features/inventory/useLoyaltyEarnRate.ts`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx` (gated section + derived display)
- Modify: i18n catalog for `catalog:editor.sectionLabels.loyalty` + field/caption keys (the namespace ProductForm already uses — find the JSON under `apps/web/src/.../locales` and add keys for en + fr)
- Test: `apps/web/src/features/inventory/__tests__/ProductFormLoyaltyDisplay.test.tsx`

**Interfaces:**
- Consumes: `useCompanyConfig().hasModule('Loyalty')`; `apiGet`; `watch('sale_price')` (string, TTC). The endpoint `GET /loyalty/earn-rate` → `{ rate: string|null }` (apiGet unwraps `data`).
- Produces: a read-only display; nothing added to `buildProductPayload`.

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'

// Mock the company-config + the earn-rate hook so the test is hermetic.
vi.mock('../../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ config: null, hasModule: (m: string) => m === 'Loyalty' }),
}))
vi.mock('../useLoyaltyEarnRate', () => ({
  useLoyaltyEarnRate: () => ({ rate: '2', isLoading: false }),
}))

import { LoyaltyPointsDisplay } from '../LoyaltyPointsDisplay'

describe('LoyaltyPointsDisplay', () => {
  it('shows derived points = salePrice * rate when the module is on', () => {
    render(<LoyaltyPointsDisplay salePrice="12.000" />)
    expect(screen.getByTestId('loyalty-derived-points')).toHaveTextContent('24')
  })

  it('renders nothing when there is no rate', () => {
    // Re-mock useLoyaltyEarnRate to return rate: null for this case (see note).
  })
})
```

> Extract the field into a small `LoyaltyPointsDisplay` component (`apps/web/src/features/inventory/LoyaltyPointsDisplay.tsx`) so it's unit-testable in isolation, then render it from `ProductForm`. Write both cases fully (the "no rate" case by overriding the hook mock per-test with `vi.mocked`).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/inventory/__tests__/ProductFormLoyaltyDisplay.test.tsx`
Expected: FAIL — `LoyaltyPointsDisplay` / `useLoyaltyEarnRate` not found.

- [ ] **Step 3: Implement the earn-rate hook**

```tsx
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../lib/api' // confirm the apiGet import path used in this feature

interface EarnRateResponse {
  rate: string | null
}

export function useLoyaltyEarnRate(enabled: boolean): { rate: string | null; isLoading: boolean } {
  const { data, isLoading } = useQuery({
    queryKey: ['loyalty', 'earn-rate'],
    queryFn: () => apiGet<EarnRateResponse>('/loyalty/earn-rate'),
    enabled,
    staleTime: 5 * 60 * 1000,
  })
  return { rate: data?.rate ?? null, isLoading }
}
```

- [ ] **Step 4: Implement the display component**

```tsx
import { useTranslation } from 'react-i18next'
import { useLoyaltyEarnRate } from './useLoyaltyEarnRate'

interface Props {
  salePrice: string
}

/** Read-only, indicative "≈ N points" derived from sale price × active rate. */
export function LoyaltyPointsDisplay({ salePrice }: Props): JSX.Element | null {
  const { t } = useTranslation()
  const { rate } = useLoyaltyEarnRate(true)

  if (rate === null) {
    return null
  }

  // Indicative integer display; avoid parseFloat on money — use a decimal-safe
  // multiply. salePrice and rate are well-formed numeric strings here.
  const points = Math.round(Number(salePrice) * Number(rate))
  if (!Number.isFinite(points)) {
    return null
  }

  return (
    <div data-testid="loyalty-derived-points-wrap">
      <span data-testid="loyalty-derived-points">{points}</span>
      <p className="text-xs text-muted-foreground">
        {t('catalog:editor.loyalty.indicativeCaption')}
      </p>
    </div>
  )
}
```

> `Number(...)` for a *display-only* indicative figure is acceptable here (no money is stored or transmitted — rule 19 governs persisted/payload money, not a read-only on-screen estimate). Do NOT route this value into any payload.

- [ ] **Step 5: Render it from `ProductForm` inside a gated section**

Mirror the parapharmacy section. Where `salePriceValue = watch('sale_price')` already exists (~line 512) and `hasModule` is in scope (~line 136):

```tsx
{hasModule('Loyalty') && (
  <EditorSectionCard
    id="section-loyalty"
    title={t('catalog:editor.sectionLabels.loyalty')}
  >
    <LoyaltyPointsDisplay salePrice={salePriceValue ?? ''} />
  </EditorSectionCard>
)}
```

Add the import: `import { LoyaltyPointsDisplay } from './LoyaltyPointsDisplay'`. Add `'section-loyalty'` to the `sectionDefs` array (gated the same way the pharmacy entry is) so the section nav lists it.

- [ ] **Step 6: Add i18n keys**

Add to the relevant `catalog` namespace JSON for **en** and **fr**:
- `editor.sectionLabels.loyalty` → "Loyalty" / "Fidélité"
- `editor.loyalty.indicativeCaption` → "Indicative — actual points depend on the amount paid." / "Indicatif — les points réels dépendent du montant payé."

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/features/inventory/__tests__/ProductFormLoyaltyDisplay.test.tsx`
Expected: PASS (both).

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/features/inventory/useLoyaltyEarnRate.ts \
        apps/web/src/features/inventory/LoyaltyPointsDisplay.tsx \
        apps/web/src/features/inventory/ProductForm.tsx \
        apps/web/src/features/inventory/__tests__/ProductFormLoyaltyDisplay.test.tsx \
        apps/web/src/**/locales/**
git commit -m "feat(web): gated read-only loyalty points display in the product editor"
```

---

## Task 7: Preflight + manual end-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Backend — run only the new/affected tests by path**

```bash
cd apps/api && ./vendor/bin/phpunit \
  tests/Unit/Shared/Contracts/Loyalty \
  tests/Feature/Loyalty/SaleEarningServiceTest.php \
  tests/Feature/Loyalty/SeedDefaultEarningRuleOnProgramActivatedTest.php \
  tests/Feature/Loyalty/LoyaltyEarnRateEndpointTest.php \
  tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php
```
Expected: all PASS. **Do NOT run the full suite or `--parallel`.**

- [ ] **Step 2: Static analysis + style on changed files only**

```bash
cd apps/api && ./vendor/bin/phpstan analyse \
  app/Shared/Contracts/Loyalty \
  app/Modules/Loyalty/Application/Services/SaleEarningService.php \
  app/Modules/Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php \
  app/Modules/Loyalty/Presentation/Controllers/LoyaltyEarnRateController.php \
  app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php
./vendor/bin/pint app/Shared/Contracts/Loyalty app/Modules/Loyalty app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php
```
Expected: zero PHPStan errors on new code; Pint clean.

- [ ] **Step 3: Frontend — typecheck, lint, the new test**

```bash
cd apps/web && pnpm typecheck && pnpm lint && \
  pnpm vitest run src/features/inventory/__tests__/ProductFormLoyaltyDisplay.test.tsx
```
Expected: PASS.

- [ ] **Step 4: Manual E2E (parapharmacy demo data)**

1. Activate a Loyalty program for the demo tenant → confirm a default Spend rule (1 TND = 1 point) appears in the earning-rules admin.
2. Open the product editor for a product with the Loyalty module on → the "Loyalty" section shows `≈ sale_price × 1` points (read-only).
3. Enroll a customer; ring a POS sale for that customer through the live fiscal-event path → confirm exactly one Earn transaction crediting `receipt total × rate`, member balance increased.
4. Ring the same sale flow as a refund/return → confirm **no** points credited.
5. Redeem a catalog reward via the existing POS redeem flow (`/loyalty/pos/redeem`) → **verify-only**; if it works, done. If a gap surfaces, note it (do not rebuild here).

- [ ] **Step 5: Update the design spec's verification status + commit**

```bash
git add docs/superpowers/specs/2026-06-27-loyalty-earn-per-product-design.md
git commit -m "docs(loyalty): mark earn-on-purchase demo verified end-to-end"
```

---

## Task ordering note

Execute in order 1 → 7. The three Loyalty factories + their `newFactory()` model overrides are created in **Task 2 Step 0** and are a prerequisite for Tasks 2 and 4 (`EarningRuleFactory`'s canonical body is shown in Task 4 Step 1; create it during Task 2 Step 0). Task 3 depends on Tasks 1–2. Tasks 5 and 6 depend only on the endpoint contract and can follow in any order after Task 4. Task 7 is last.

## Codex review fold-in (2026-06-27)

Adversarial review (`docs/superpowers/audits/2026-06-27-loyalty-earn-plan-codex-review.md`) confirmed all signatures, enums, decimal casts, Spend math, the safe 5th ctor param, projection-local scope, and FE assumptions. Folded fixes: **B1** three missing factories + `newFactory()` overrides (Task 2 Step 0); **B2** parametrize the training-flag in the FiscalEvent builder (Task 3); **B3** refund test must project the original SALE first (Task 3); **S1** discriminate `already earned` before swallowing (Task 2); **S2** route ability `can:loyalty.view` (Task 5); **S3** `CompanyContext` = `App\Modules\Company\Services\CompanyContext` (Tasks 2, 5); **S4** `EarningRule` `id` is `HasUuids`, not fillable (Task 4).

## Self-review (completed by plan author)

- **Spec coverage:** earn trigger (T3), Spend-rule earning + member resolution + module guard + discriminated failure (T2), idempotency/replay test (T2+T3), refund/training guard (T3), activation seed (T4), derived display + endpoint (T5–T6), redemption verify-only (T7 manual), device-time stamp (T2/T3), strings-not-floats (T1/T2). Out-of-scope items (override table, pay-with-points, discounts field, refund reversal, durable queue) intentionally have no task.
- **Placeholders:** none — every code step shows full code; the few "confirm the FQCN / copy the harness" notes point at an exact existing file to mirror, not vague instructions.
- **Type consistency:** `earnForSale(SaleEarnContext): void`, `SaleEarnContext` field names, `earnBase`/`reward_value`/`rate` usages, and repo method names (`findByTenantAndStatus`, `findActiveByProgram`, `save`) match across tasks.
