# Multi-Location §3 Financial Location Dimension Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every Treasury/finance surface a location dimension — cash position, échéancier/instrument clearing, upcoming payments, AR/AP aging, and expense analytics can each be filtered and grouped by store, with an "Unattributed" bucket so totals still reconcile to the company-wide figures.

**Architecture:** Attribution is a *reporting dimension on transactions* (spec §3 / research 2.2.4), never a new legal entity. We add a nullable `location_id` to `payments` and `payment_instruments` only (`documents.location_id` already exists), stamp it once at write time from a per-writer source rule, **freeze** the instrument's location across its lifecycle, and read it back through the shared `LocationScopeResolver` from §1. Every by-location surface declares exactly one grain (repository-grain = cash custody; instrument-grain = origin store; documents-header grain = AR/AP/expense) and sums **signed** amounts.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL 16 (db-per-tenant, tenant migrations in `apps/api/database/migrations/tenant/`), bcmath money via `CurrencyScale` + injected `CurrencyScaleResolverInterface`, PHPUnit (backend, run BY PATH), React 19 / Vite / TanStack Query 5, Vitest, `react-i18next`, design tokens.

## Global Constraints

Every task's requirements implicitly include this section. Values copied verbatim from CLAUDE.md rules 12/13/14/19/20 and spec §3.

- **Routes middleware:** all module `routes.php` use `['api', 'auth:sanctum', SetPermissionsTeam::class]` (Treasury adds `EnforceTokenTenantClaim::class`). Missing `'api'` → 401; missing `SetPermissionsTeam` → permission failure.
- **Constructor injection ONLY** — all deps `private readonly` in the constructor. **Never** `app()`.
- **API responses:** `apiGet`/`apiPost` already unwrap `response.data.data`. For paginated `{data,meta}` endpoints use `api.get` + return `response.data`. Do NOT double-unwrap.
- **Money precision (rule 19):** never let a float touch money. Store currency `decimal(N,3)`. At rest use `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))`. Intermediates at `scale+1`/`scale+4`. Aggregations use `bcadd`/`bcsub`/`bccomp`, never `SUM()`-into-float or `array_sum`.
- **Scale resolver (rules 19/20):** constructor-inject `CurrencyScaleResolverInterface`. In queued/console/projection contexts (POS bridges, backfill command, migration) pass the **explicit entity currency** — `getScale($currency)`; a bare no-arg `getScale()` **throws** there because no `CompanyContext` is bound.
- **FormRequest money regex:** money columns keep `numeric` + regex `/^-?\d+(\.\d{1,3})?$/`. `location_id` is a `uuid`, not money — validate with `ScopedExists`/`uuid`, no money regex.
- **Frontend money:** never `parseFloat`/`Number(...)` on money/quantity; use `<MoneyInput>`/`<QuantityInput>` + `formatCurrency`/`formatQuantity`; payloads as strings.
- **Queued projections run with NO CompanyContext:** projection tests must `app(CompanyContext::class)->clear()` before `apply()`. Never bind context in `setUp` — it masks the worker reality.
- **Enums** for every status/type column. **No magic strings.**
- **Frontend text** via `t()` translation keys; **colors** via `@/lib/designTokens` tokens; RTL-safe.
- **Tests run BY PATH** (never the full suite — it crashes the laptop). Aggregate tests run on **Postgres**, not SQLite.
- **Self-guarding migrations (push = staging auto-deploy incl. `tenants:migrate`):** any migration with a data prerequisite must be idempotent and guard its own preconditions in code.
- **PHPStan level 8, zero errors on new code.** Pint clean. `treasury-reviewer` (pinned) gate on every BE milestone; `frontend-conventions-reviewer` on every FE milestone.

## Consumes from §1 (foundation — MUST be merged before this plan starts)

These are produced by the §1 (scope foundation) plan. Do not re-implement them here; consume the exact signatures below. If any is missing at execution time, STOP and land §1 first.

- **Backend resolver** (`App\Modules\Company\Application\Services\LocationScopeResolver`):
  ```php
  /** @return list<string> resolved, in-scope location ids (empty ⇒ caller must treat as "all") */
  public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array
  ```
  HTTP-only (requires bound `CompanyContext`); fail-closed (`AuthorizationException`) on out-of-scope requested ids; single-company by default. Backfills/migrations/queued jobs **never** call it — they derive location directly from data (§1 rule A10).
- **Frontend scope hook** (`apps/web/src/features/locations/hooks/useViewScope.ts`):
  ```ts
  // returns the current view scope: [] ⇒ "All locations"; otherwise selected ids
  export function useViewScope(): { locationIds: string[]; isAllLocations: boolean }
  ```
- **Frontend query-key helper** (`apps/web/src/lib/locationScopedKey.ts`):
  ```ts
  // wraps tenantScopedKey; scope is a NON-leading segment so the resource literal stays element[0]
  export function locationScopedKey(segments: readonly unknown[], scope: string[]): unknown[]
  ```
  Already registered in `apps/web/tools/audit-tanstack-keys.mjs` `APPROVED_FACTORY_CALLS`.
- **Locations list endpoint:** `GET /company/locations` (module-agnostic Company route group, broad read gate) — returns only the user's allowed locations. Used by every location picker/filter here.

If §1 is only partially merged, this plan's FE picker/filter tasks must not ship — they will 403 or leak the full set.

---

## Task 1 (T2 — prerequisite): Make `payment_repositories.location_id` a real, assignable dimension

Without this the whole package attributes ~100% to Unattributed (review BLOCKER T2). Expose the column, let owners assign each cash register/safe to a store, keep bank accounts NULL = company-level. **No automatic guessing** — assignment is an explicit owner step (documented in the deploy checklist).

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php` (`formatRepository` :319-338; `store` validation :85; `update` validation :146)
- Modify: `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx` (add `location_id` to `RepositoryFormData` :34-46 and a location `<Select>`)
- Modify: `apps/web/src/features/treasury/RepositoryListPage.tsx` (`Repository` interface :23-35 — add `location_id`, `location_name`)
- Test (BE): `apps/api/tests/Feature/Treasury/PaymentRepositoryLocationTest.php`
- Test (FE): `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.location.test.tsx`

**Interfaces:**
- Consumes: `GET /company/locations` (§1); `ScopedExists::tenant('locations', $tenantId)` (existing shared rule).
- Produces: `formatRepository()` output now includes `'location_id'` + `'location_name'`. Every downstream reader (bridges, backfill, cash position) relies on `payment_repositories.location_id` being populated for cash registers/safes.

- [ ] **Step 1: Write the failing BE test** — `formatRepository` exposes `location_id`, and store/update round-trip it with tenant-scoped validation.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Location;
use App\Modules\Treasury\Domain\PaymentRepository;
use Tests\Feature\Treasury\Concerns\SeedsTreasuryCompany; // existing helper used by treasury feature tests
use Tests\TestCase;

final class PaymentRepositoryLocationTest extends TestCase
{
    use SeedsTreasuryCompany;

    public function test_index_exposes_repository_location_id(): void
    {
        [$user, $company] = $this->seedTreasuryCompany();
        $location = Location::factory()->for($company)->create(['name' => 'Store A']);
        PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $company->tenant_id,
            'type' => 'cash_register',
            'location_id' => $location->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/payment-repositories');

        $response->assertOk();
        $response->assertJsonPath('data.0.location_id', $location->id);
    }

    public function test_store_persists_scoped_location_id(): void
    {
        [$user, $company] = $this->seedTreasuryCompany();
        $location = Location::factory()->for($company)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CR1',
            'name' => 'Register 1',
            'type' => 'cash_register',
            'location_id' => $location->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_repositories', [
            'code' => 'CR1',
            'location_id' => $location->id,
        ]);
    }

    public function test_store_rejects_foreign_location_id(): void
    {
        [$user, $company] = $this->seedTreasuryCompany();
        $foreign = Location::factory()->create(); // different tenant/company

        $response = $this->actingAs($user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CR2',
            'name' => 'Register 2',
            'type' => 'cash_register',
            'location_id' => $foreign->id,
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (location_id absent from `formatRepository`; foreign id currently accepted because validation is `['nullable','uuid']`).

Run: `cd apps/api && php artisan test --filter=PaymentRepositoryLocationTest`
Expected: FAIL (`location_id` path missing; foreign id 201 not 422).

- [ ] **Step 3: Implement.** In `PaymentRepositoryController::formatRepository()` add the field and eager-load the relation.

```php
// index()/show()/store()/update(): add ->with('location:id,name') alongside glAccount
// formatRepository(): after 'gl_account' line
'location_id' => $repository->location_id,
'location_name' => $repository->location?->name,
```

Tighten validation in `store()` and `update()` (replace the bare `'location_id' => ['nullable', 'uuid']`):

```php
'location_id' => ['nullable', 'uuid', ScopedExists::tenant('locations', $tenantId)],
```

Add the `location` relation to `PaymentRepository` if absent:

```php
// app/Modules/Treasury/Domain/PaymentRepository.php
/** @return BelongsTo<Location, $this> */
public function location(): BelongsTo
{
    return $this->belongsTo(Location::class);
}
```

- [ ] **Step 4: Run BE test — expect PASS.**

Run: `cd apps/api && php artisan test --filter=PaymentRepositoryLocationTest`
Expected: PASS.

- [ ] **Step 5: Write the failing FE test** — the modal renders a location select for `cash_register`/`safe` and submits `location_id`.

```tsx
// AddRepositoryModal.location.test.tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { AddRepositoryModal } from './AddRepositoryModal'
import { renderWithProviders } from '@/test/utils' // existing helper

vi.mock('@/hooks/useLocations', () => ({
  useLocations: () => ({ data: [{ id: 'loc-a', name: 'Store A' }], isLoading: false }),
}))

describe('AddRepositoryModal location assignment', () => {
  it('offers a location select and includes location_id in the payload', async () => {
    const onSuccess = vi.fn()
    renderWithProviders(<AddRepositoryModal isOpen onClose={() => {}} onSuccess={onSuccess} />)
    expect(screen.getByLabelText(/location/i)).toBeInTheDocument()
    // select Store A, fill required fields, submit — assert POST body carries location_id: 'loc-a'
  })
})
```

- [ ] **Step 6: Run it — expect FAIL** (no location field).

Run: `cd apps/web && pnpm test AddRepositoryModal.location`
Expected: FAIL.

- [ ] **Step 7: Implement FE.** Add `location_id: string` to `RepositoryFormData`; render a `FormField` + token-styled `<Select>` fed by a `useLocations()` hook (thin wrapper over `GET /company/locations`), gated to show for `cash_register`/`safe` types (bank_account/virtual may leave it blank = company-level). Add the `location_id` (empty-string → omit) to the submit payload. All labels via `t('treasury:repositories.location')`. Reflect `location_name` in the `RepositoryListPage` table (new column via existing `DataTable`).

- [ ] **Step 8: Run FE test — expect PASS.**

Run: `cd apps/web && pnpm test AddRepositoryModal.location`
Expected: PASS.

- [ ] **Step 9: typescript:transform is not needed** (no PHP DTO changed — `formatRepository` returns an array). Skip.

- [ ] **Step 10: Commit.**

```bash
git add apps/api/app/Modules/Treasury apps/api/tests/Feature/Treasury/PaymentRepositoryLocationTest.php apps/web/src/components/organisms/AddRepositoryModal apps/web/src/features/treasury/RepositoryListPage.tsx
git commit -m "feat(treasury): expose + assign payment_repositories.location_id (multiloc §3 T2 prerequisite)"
```

---

## Task 2 (T1/T7 — schema): One migration adding `location_id` to `payments` and `payment_instruments` only

Corrected per review T1/T7/T8: `documents.location_id` **already exists** (no documents migration, no line-derived backfill — it would overwrite live values); `expense_metadata` gets **no** location column (an expense IS a Document; location = parent `documents.location_id`); `payment_allocations.location_id` and `journal_entries.location_id` stay **inert** (already-present dead schema — do not populate; GL dimension belongs to the accounting-GL roadmap).

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_16_110000_add_location_id_to_payments_and_instruments.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/Payment.php` (`$fillable` :75) — add `'location_id'`
- Modify: `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php` (`$fillable` :75) — add `'location_id'`
- Test: `apps/api/tests/Feature/Treasury/PaymentLocationSchemaTest.php`

**Interfaces:**
- Produces: nullable indexed FK `payments.location_id` and `payment_instruments.location_id`; both fillable. Every writer/reader task below relies on these two columns existing.

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentLocationSchemaTest extends TestCase
{
    public function test_payments_and_instruments_have_nullable_location_id(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'location_id'));
        $this->assertTrue(Schema::hasColumn('payment_instruments', 'location_id'));
    }

    public function test_documents_migration_absent_and_expense_metadata_untouched(): void
    {
        // documents.location_id predates this plan; we must NOT have re-added it.
        $this->assertTrue(Schema::hasColumn('documents', 'location_id'));
        $this->assertFalse(Schema::hasColumn('expense_metadata', 'location_id'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (columns missing).

Run: `cd apps/api && php artisan test --filter=PaymentLocationSchemaTest`
Expected: FAIL.

- [ ] **Step 3: Write the migration** (mirror the existing `payment_allocations` location migration pattern; docblock references the inert columns and the spec).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Location §3 (financial location dimension) — schema.
 *
 * Adds nullable, indexed FK `location_id` to `payments` and `payment_instruments`
 * ONLY. Deliberate scope (spec §3 / review T1/T7/T8):
 *   - `documents.location_id` ALREADY EXISTS (2025_11_30_130000). NOT re-added here;
 *     no line-derived backfill (it would overwrite live values).
 *   - `expense_metadata` gets NO location column — an expense IS a Document; its
 *     location is the parent `documents.location_id`.
 *   - `payment_allocations.location_id` (2025_12_27_150001) and
 *     `journal_entries.location_id` (2025_12_27_150002) remain INERT — not populated
 *     by this plan. The GL-level P&L-by-location dimension belongs to the
 *     accounting-GL roadmap, not §3.
 *
 * Self-guarding (push = staging auto-deploy): each column add is column-existence
 * guarded so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'location_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->uuid('location_id')->nullable()->after('repository_id');
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
                $table->index('location_id', 'idx_payments_location');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'location_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->uuid('location_id')->nullable()->after('repository_id');
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
                $table->index('location_id', 'idx_payment_instruments_location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'location_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('idx_payments_location');
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasColumn('payment_instruments', 'location_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('idx_payment_instruments_location');
                $table->dropColumn('location_id');
            });
        }
    }
};
```

- [ ] **Step 4: Add `'location_id'` to both `$fillable` arrays** (`Payment.php`, `PaymentInstrument.php`) and the `@property string|null $location_id` docblock line on each.

- [ ] **Step 5: Migrate + run test — expect PASS.**

Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant --database=tenant && php artisan test --filter=PaymentLocationSchemaTest`
Expected: PASS.

- [ ] **Step 6: PHPStan the touched models.**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Domain/Payment.php app/Modules/Treasury/Domain/PaymentInstrument.php`
Expected: no errors.

- [ ] **Step 7: Commit.**

```bash
git add apps/api/database/migrations/tenant/2026_07_16_110000_add_location_id_to_payments_and_instruments.php apps/api/app/Modules/Treasury/Domain/Payment.php apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php apps/api/tests/Feature/Treasury/PaymentLocationSchemaTest.php
git commit -m "feat(treasury): add nullable location_id to payments + payment_instruments (multiloc §3 T1)"
```

---

## Task 3 (T3 — writer): POS queued bridges attribute location from terminal→location

The three POS bridges run on Horizon workers with **no CompanyContext** (rule 20). Terminal→location is resolvable via `event->terminal_id` (clone `PosCoreReceiptProjection::resolveTerminal` :377-394). Terminal location **overrides** the repository default, and the leg carries the **explicit tender currency** to the movement port already — no new scale call, but any new bcmath here passes explicit currency.

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` (`Payment::create` :604; maturity context :544-551,586-592)
- Modify: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php` (`Payment::create` :156; `handleMaturityLeg` :255-283)
- Modify: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php` (`Payment::create` :171; `handleMaturityLeg` :268-296)
- Modify: `apps/api/app/Modules/Treasury/Application/DTOs/MaturityLegContext.php` (add `?string $locationId = null`)
- Modify: `apps/api/app/Modules/Treasury/Application/DTOs/ReceiveInstrumentData.php` (add `?string $locationId = null`)
- Modify: `apps/api/app/Modules/Treasury/Application/Projections/Concerns/HandlesMaturityTenderLeg.php` (pass `locationId: $context->locationId` into the `ReceiveInstrumentData` at :76)
- Test: `apps/api/tests/Feature/Treasury/Projections/PosBridgeLocationAttributionTest.php`

**Interfaces:**
- Consumes: `Terminal.location_id`; `event->terminal_id`.
- Produces: POS `payments.location_id` = terminal location; POS maturity-leg `payment_instruments.location_id` = terminal location (frozen — Task 6).

- [ ] **Step 1: Write the failing projection test.** Clears CompanyContext (rule 20), projects a SALE_RECEIPT, asserts the Payment's location = the terminal's location, overriding the repository's (deliberately different) location.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury\Projections;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Payment;
use Tests\Feature\Fiscal\Concerns\ProjectsSaleReceiptEvents; // existing bridge-test harness
use Tests\TestCase;

final class PosBridgeLocationAttributionTest extends TestCase
{
    use ProjectsSaleReceiptEvents;

    public function test_receipt_bridge_stamps_terminal_location_overriding_repository_default(): void
    {
        $ctx = $this->seedTreasuryPosContext(); // terminal @ locationTerminal; repository @ locationRepo (different)
        app(CompanyContext::class)->clear(); // worker reality — NO bound context

        $event = $this->authorAndVerifySaleReceipt($ctx, ['payments' => [['method_code' => 'CASH', 'amount' => '10.000']]]);
        $this->applyTreasuryReceiptBridge($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame($ctx->locationTerminalId, $payment->location_id);
        $this->assertNotSame($ctx->locationRepoId, $payment->location_id);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`location_id` null).

Run: `cd apps/api && php artisan test tests/Feature/Treasury/Projections/PosBridgeLocationAttributionTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement.** In `TreasuryReceiptBridge`, add a resolver mirroring `PosCoreReceiptProjection::resolveTerminal`, compute once in `apply()`, thread into `projectPaymentLineFromCanonical`.

```php
// TreasuryReceiptBridge — new private method
private function resolveTerminalLocationId(FiscalEvent $event): ?string
{
    try {
        $locationId = DB::table('terminals')
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('id', $event->terminal_id)
            ->value('location_id');
    } catch (QueryException) {
        return null;
    }

    return is_string($locationId) ? $locationId : null;
}
```

In `apply()` compute `$terminalLocationId = $this->resolveTerminalLocationId($event);` and pass it to `projectPaymentLineFromCanonical`. In the `Payment::create` add:

```php
'location_id' => $terminalLocationId,
```

and in **both** `MaturityLegContext` constructions add `locationId: $terminalLocationId,`.

- [ ] **Step 4: Thread `locationId` through the DTOs + handler.** Add `public ?string $locationId = null,` to `MaturityLegContext` and `ReceiveInstrumentData`. In `HandlesMaturityTenderLeg::handleMaturityLeg`, pass `locationId: $context->locationId,` into the `ReceiveInstrumentData` at :76. In `InstrumentLifecycleService::receive()` add `'location_id' => $data->locationId,` to the `PaymentInstrument::query()->create([...])` (Task 6 owns the freeze test, but the write goes in here).

- [ ] **Step 5: Repeat the Payment.create + context change** in `TreasuryAccountPaymentBridge` and `TreasuryDepositBridge`. Both resolve the terminal the same way. **Note:** `DEPOSIT_RECEIPT` is server-authored/`isServerOnly()` and may carry a null/absent `terminal_id` — `resolveTerminalLocationId` returns null and we **fall back to `$repository->location_id`** for the deposit bridge:

```php
// TreasuryDepositBridge::apply() Payment::create
'location_id' => $this->resolveTerminalLocationId($event) ?? $repository->location_id,
```

Add the identical `resolveTerminalLocationId` helper to both bridges (or extract to a shared trait `ResolvesTerminalLocation` in `App\Modules\Treasury\Application\Projections\Concerns` — preferred, DRY).

- [ ] **Step 6: Extend the test** to cover the account-payment + deposit bridges (deposit falls back to repository location when terminal is absent). Run — expect PASS.

Run: `cd apps/api && php artisan test tests/Feature/Treasury/Projections/PosBridgeLocationAttributionTest.php`
Expected: PASS.

- [ ] **Step 7: PHPStan.**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Projections app/Modules/Treasury/Application/DTOs/MaturityLegContext.php app/Modules/Treasury/Application/DTOs/ReceiveInstrumentData.php`
Expected: no errors.

- [ ] **Step 8: Commit.**

```bash
git add apps/api/app/Modules/Treasury/Application/Projections apps/api/app/Modules/Treasury/Application/DTOs apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php apps/api/tests/Feature/Treasury/Projections/PosBridgeLocationAttributionTest.php
git commit -m "feat(treasury): POS bridges attribute payments+instruments to terminal location (multiloc §3 T3)"
```

---

## Task 4 (T4 — writer): `PaymentController` attributes from the paid document; outbound supplier instrument inherits

Location = the paid document's `documents.location_id`. Advances (no allocations) stay NULL = company-level. Deferred-supplier **outbound** instruments (`:757-780`) inherit `location_id` from the originating supplier document — G20 fold-in, **no schema change** (spec §3 writer table + `HANDOFF-outbound-instruments-2026-07-13.md`: Phase ④ ships direction guards only; §3 only sets the reporting dimension, it does not touch outbound accounting).

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` (`Payment::create` :790; deferred-supplier `ReceiveInstrumentData` :759; second create site :1373)
- Test: `apps/api/tests/Feature/Treasury/PaymentControllerLocationAttributionTest.php`

**Interfaces:**
- Consumes: `documents.location_id`; the validated `allocations[]` (each has `document_id`).
- Produces: manual/B2B `payments.location_id` = first allocated document's location; deferred-supplier outbound `payment_instruments.location_id` = originating document location.

- [ ] **Step 1: Write the failing test.** A B2B payment allocated to an invoice at Store A stamps `location_id = Store A`; an advance (no allocations) stamps NULL.

```php
public function test_b2b_payment_inherits_location_from_paid_document(): void
{
    [$user, $company, $location] = $this->seedCompanyWithLocation();
    $invoice = $this->postedInvoiceAt($company, $location, total: '100.000');

    $response = $this->actingAs($user)->postJson('/api/v1/payments', [
        'partner_id' => $invoice->partner_id,
        'payment_method_id' => $this->cashMethod($company)->id,
        'repository_id' => $this->cashRepo($company)->id,
        'amount' => '100.000',
        'currency' => 'TND',
        'payment_date' => now()->toDateString(),
        'allocations' => [['document_id' => $invoice->id, 'amount' => '100.000']],
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('payments', ['id' => $response->json('data.id'), 'location_id' => $location->id]);
}

public function test_advance_payment_has_null_location(): void
{
    [$user, $company] = $this->seedCompanyWithLocation();
    $partner = $this->customer($company);

    $response = $this->actingAs($user)->postJson('/api/v1/payments', [
        'partner_id' => $partner->id,
        'payment_method_id' => $this->cashMethod($company)->id,
        'repository_id' => $this->cashRepo($company)->id,
        'amount' => '50.000',
        'currency' => 'TND',
        'payment_date' => now()->toDateString(),
        'allocations' => [],
    ]);

    $response->assertCreated();
    $this->assertNull(Payment::findOrFail($response->json('data.id'))->location_id);
}
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=PaymentControllerLocationAttributionTest`
Expected: FAIL.

- [ ] **Step 3: Implement.** Before the `Payment::create` at :790, resolve the location from the first allocated document (allocations already validated/tenant-scoped in this method):

```php
$attributionLocationId = null;
if (! empty($adjustedAllocations)) {
    $firstDocumentId = $adjustedAllocations[0]['document_id'] ?? null;
    if (is_string($firstDocumentId)) {
        $attributionLocationId = Document::query()
            ->where('company_id', $companyId)
            ->whereKey($firstDocumentId)
            ->value('location_id');
        $attributionLocationId = is_string($attributionLocationId) ? $attributionLocationId : null;
    }
}
```

Add `'location_id' => $attributionLocationId,` to the `Payment::create` array. For the deferred-supplier `ReceiveInstrumentData` at :759, pass `locationId: $attributionLocationId,` (the supplier document being paid). Apply the same `location_id` line to the second create site (:1373) using its own document context. Add `use App\Modules\Document\Domain\Document;` if not present.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=PaymentControllerLocationAttributionTest`
Expected: PASS.

- [ ] **Step 5: PHPStan + commit.**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Presentation/Controllers/PaymentController.php
git add apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php apps/api/tests/Feature/Treasury/PaymentControllerLocationAttributionTest.php
git commit -m "feat(treasury): PaymentController attributes payments+outbound instruments to document location (multiloc §3 T4)"
```

---

## Task 5 (T4 — writer): `MultiPaymentService` / `VendorRefundService` / `PaymentRefundService` inherit originating location

Refunds and multi-payments derive from an originating document or payment. Each `Payment::create` gets `location_id` from that source: `VendorRefundService` from the locked PO (`$lockedPo->location_id`); `PaymentRefundService` from the original payment (`$original->location_id`); `MultiPaymentService` from the first allocated document.

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php` (`Payment::create` :83,:182,:370)
- Modify: `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php` (`Payment::create` :118)
- Modify: `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php` (`Payment::create` :137,:264,:760)
- Test: `apps/api/tests/Feature/Treasury/RefundLocationAttributionTest.php`

**Interfaces:**
- Consumes: `documents.location_id`, originating `payments.location_id`.
- Produces: refund/multipayment `payments.location_id` = originating source location.

- [ ] **Step 1: Write the failing test** — a vendor prepayment refund inherits the PO location; a customer payment refund inherits the original payment's location.

```php
// Real signatures (app/Modules/Treasury/Domain/Services/):
//   VendorRefundService::refundPrepayment(Document $po, string $amount, string $paymentMethodId, string $repositoryId, ?string $reason, ?string $userId): Payment
//   PaymentRefundService::refundPayment(Payment $payment, ?string $reason, ?string $userId, ?string $refundRequestId = null): Payment  // confirm exact params at :79 before writing
public function test_vendor_refund_inherits_po_location(): void
{
    [$user, $company, $location] = $this->seedCompanyWithLocation();
    $po = $this->prepaidPurchaseOrderAt($company, $location); // helper from Step 1 setUp: PO with documents.location_id = $location->id + prepayment of '100.000'

    $refund = app(VendorRefundService::class)->refundPrepayment(
        $po,
        '50.000',
        $this->paymentMethod->id,
        $this->repository->id,
        'over-prepaid',
        $user->id,
    );

    $this->assertSame($location->id, $refund->location_id);
}

public function test_payment_refund_inherits_original_payment_location(): void
{
    [$user, $company, $location] = $this->seedCompanyWithLocation();
    $original = $this->paymentAt($company, $location, '75.000'); // helper: Payment row with location_id = $location->id

    $refund = app(PaymentRefundService::class)->refundPayment($original, 'customer return', $user->id);

    $this->assertSame($location->id, $refund->location_id);
}
```
(`seedCompanyWithLocation` / `prepaidPurchaseOrderAt` / `paymentAt` are private helpers written in this same test class in this step — direct model creates per the repo test idiom, e.g. `AuditDiscountsCommandTest::setUp`. Tests may use `app()`; the constructor-injection rule binds production code only.)

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=RefundLocationAttributionTest`
Expected: FAIL.

- [ ] **Step 3: Implement.**
  - `VendorRefundService::refundPrepayment` (:118): add `'location_id' => $lockedPo->location_id,`.
  - `PaymentRefundService` (:137,:264,:760): each site has the original payment in scope (the row being refunded/reversed) — add `'location_id' => $originalPayment->location_id,` (use the locked original in each method's scope).
  - `MultiPaymentService` (:83,:182,:370): resolve from the first allocated document id in that create's scope (`Document::where('company_id',…)->whereKey($documentId)->value('location_id')`), NULL if a pure advance.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=RefundLocationAttributionTest`
Expected: PASS.

- [ ] **Step 5: PHPStan + commit.**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Domain/Services/MultiPaymentService.php app/Modules/Treasury/Domain/Services/VendorRefundService.php app/Modules/Treasury/Domain/Services/PaymentRefundService.php
git add apps/api/app/Modules/Treasury/Domain/Services apps/api/tests/Feature/Treasury/RefundLocationAttributionTest.php
git commit -m "feat(treasury): refund/multipayment writers inherit originating location (multiloc §3 T4)"
```

---

## Task 6 (T5 — writer + freeze): `InstrumentLifecycleService::receive` sets location once; frozen across the lifecycle

`payment_instruments.location_id` is set **once at receive** (form field if provided, else repository default) and **never re-derived** on `custodyTransfer`/`deposit`/`clear`/`bounce` (which move `repository_id`). Instrument-grain (échéancier/clearing = **origin** store) and repository-grain (cash position = **custody** location) are intentionally different dimensions.

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php` (`store` validation :131-159; `receive` call :184-205)
- Modify: `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php` (`receive` create :83-106 — already receives `location_id` from Task 3 DTO change; add nothing new here except verifying custodyTransfer/deposit/clear/bounce never touch it)
- Test: `apps/api/tests/Feature/Treasury/InstrumentLocationFreezeTest.php`

**Interfaces:**
- Consumes: `ReceiveInstrumentData::$locationId` (added in Task 3); `payment_repositories.location_id`.
- Produces: instrument location frozen at origin.

- [ ] **Step 1: Write the failing freeze regression test** — receive at Store A (from a Store-A repository), then custody-transfer → deposit → clear into a Store-B bank repository; `location_id` stays Store A throughout.

```php
public function test_instrument_location_is_frozen_across_full_lifecycle(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $cashRepoA = $this->cashRepoAt($company, $storeA);
    $bankRepoB = $this->bankRepoAt($company, $storeB);

    $instrument = app(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
        /* …cheque… */ repositoryId: $cashRepoA->id, locationId: null, // else-branch: repo default
    ));
    $this->assertSame($storeA->id, $instrument->location_id);

    app(InstrumentLifecycleService::class)->custodyTransfer($instrument->id, $bankRepoB->id, $user->id);
    $this->assertSame($storeA->id, $instrument->fresh()->location_id);

    // deposit → clear into bankRepoB, then re-assert:
    $this->depositAndClear($instrument, $bankRepoB, $user);
    $this->assertSame($storeA->id, $instrument->fresh()->location_id);
}

public function test_receive_prefers_form_location_over_repository_default(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $cashRepoA = $this->cashRepoAt($company, $storeA); // repo default = Store A

    $response = $this->actingAs($user)->postJson('/api/v1/payment-instruments', [
        /* …cheque payment method… */
        'repository_id' => $cashRepoA->id,
        'location_id' => $storeB->id, // explicit form override
        'amount' => '10.000', 'reference' => 'CHQ1', 'received_date' => now()->toDateString(),
    ]);

    $response->assertCreated();
    $this->assertSame($storeB->id, PaymentInstrument::findOrFail($response->json('data.id'))->location_id);
}
```

- [ ] **Step 2: Run — expect FAIL** (`location_id` unset at receive; controller ignores form field).

Run: `cd apps/api && php artisan test --filter=InstrumentLocationFreezeTest`
Expected: FAIL.

- [ ] **Step 3: Implement controller.** In `PaymentInstrumentController::store`, add validation `'location_id' => ['nullable', 'uuid', ScopedExists::tenant('locations', $company->tenant_id)]`, then resolve default and pass to `receive`:

```php
$repository = PaymentRepository::query()
    ->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)
    ->findOrFail($validated['repository_id']);
$locationId = $validated['location_id'] ?? $repository->location_id;
// … in new ReceiveInstrumentData(...): locationId: is_string($locationId) ? $locationId : null,
```

`InstrumentLifecycleService::receive` already writes `'location_id' => $data->locationId` (Task 3, Step 4). Verify `custodyTransfer`/`deposit`/`clear`/`bounce` bodies contain **no** `location_id` assignment (they update only `repository_id`/`status` — leave as-is; the freeze is by omission).

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=InstrumentLocationFreezeTest`
Expected: PASS.

- [ ] **Step 5: PHPStan + commit.**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php
git add apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php apps/api/tests/Feature/Treasury/InstrumentLocationFreezeTest.php
git commit -m "feat(treasury): freeze instrument location at receive across lifecycle (multiloc §3 T5)"
```

---

## Task 7 (backfill): guarded `treasury:backfill-location-attribution` command + self-guarding migration wrapper

Fill existing NULLs only. Sources (spec §3): instruments/payments ← `repository.location_id` where set; POS-originated payments ← `fiscal_event → terminal → location`; everything else stays NULL/Unattributed. **Idempotent** (WHERE `location_id IS NULL`); **never touches `documents`**. `runForMultiple`-compatible with an **explicit Company** (seeder-optional-company trap: a bare `?Company $company = null` silently no-ops under `tenants:run`).

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Console/BackfillLocationAttributionCommand.php`
- Create: `apps/api/database/migrations/tenant/2026_07_16_110500_backfill_location_attribution.php`
- Test: `apps/api/tests/Feature/Treasury/BackfillLocationAttributionTest.php`

**Interfaces:**
- Consumes: `payment_repositories.location_id`, `fiscal_events.terminal_id`, `terminals.location_id`.
- Produces: fills `payments.location_id` and `payment_instruments.location_id` for pre-cutover rows where derivable.

- [ ] **Step 1: Write the failing test** — three assertions: (a) instruments/payments pick up repository location; (b) POS payment picks up terminal location; (c) idempotency + no-documents-touch.

```php
public function test_backfill_fills_from_repository_and_terminal_and_is_idempotent(): void
{
    [$company, $location] = $this->seedCompanyWithLocation();
    $repo = $this->cashRepoAt($company, $location);
    $payment = Payment::factory()->for($company)->create(['repository_id' => $repo->id, 'location_id' => null, 'origin' => PaymentOrigin::WebAdmin]);
    $instrument = PaymentInstrument::factory()->for($company)->create(['repository_id' => $repo->id, 'location_id' => null]);

    // POS payment via fiscal_event → terminal → location (terminal @ different location)
    $terminalLocation = Location::factory()->for($company)->create();
    $posPayment = $this->posPaymentWithTerminalAt($company, $terminalLocation);

    // A document row we must NEVER touch
    $doc = Document::factory()->for($company)->create(['location_id' => null]);

    $this->artisan('treasury:backfill-location-attribution', ['--company' => $company->id])->assertOk();

    $this->assertSame($location->id, $payment->fresh()->location_id);
    $this->assertSame($location->id, $instrument->fresh()->location_id);
    $this->assertSame($terminalLocation->id, $posPayment->fresh()->location_id);
    $this->assertNull($doc->fresh()->location_id); // documents untouched

    // idempotent — a manually-assigned row is not overwritten, a second run changes nothing
    $payment->update(['location_id' => $terminalLocation->id]);
    $this->artisan('treasury:backfill-location-attribution', ['--company' => $company->id])->assertOk();
    $this->assertSame($terminalLocation->id, $payment->fresh()->location_id);
}
```

- [ ] **Step 2: Run — expect FAIL** (command missing).

Run: `cd apps/api && php artisan test --filter=BackfillLocationAttributionTest`
Expected: FAIL.

- [ ] **Step 3: Implement the command.** Constructor-inject nothing beyond what it needs; operate on explicit `--company` (required) so `tenants:run` supplies a real company.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Multi-Location §3 backfill. Idempotent (fills NULL location_id only); NEVER
 * touches `documents`. Derives location directly from data — NEVER via
 * LocationScopeResolver (HTTP-only, review A10).
 */
final class BackfillLocationAttributionCommand extends Command
{
    protected $signature = 'treasury:backfill-location-attribution {--company= : Company id (required — pass an explicit Company under tenants:run)}';

    protected $description = 'Backfill payments/payment_instruments location_id from repository + terminal, NULLs only.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        if (! is_string($companyId) || $companyId === '') {
            $this->error('The --company option is required (seeder-optional-company trap: no bare null).');

            return self::FAILURE;
        }
        $company = Company::query()->find($companyId);
        if ($company === null) {
            $this->error("Company {$companyId} not found in this tenant DB.");

            return self::FAILURE;
        }

        // (1) instruments ← repository.location_id (NULLs only, repo location set)
        DB::table('payment_instruments as pi')
            ->join('payment_repositories as r', 'r.id', '=', 'pi.repository_id')
            ->where('pi.company_id', $company->id)
            ->whereNull('pi.location_id')
            ->whereNotNull('r.location_id')
            ->update(['pi.location_id' => DB::raw('r.location_id')]);

        // (2) payments ← repository.location_id
        DB::table('payments as p')
            ->join('payment_repositories as r', 'r.id', '=', 'p.repository_id')
            ->where('p.company_id', $company->id)
            ->whereNull('p.location_id')
            ->whereNotNull('r.location_id')
            ->update(['p.location_id' => DB::raw('r.location_id')]);

        // (3) POS-originated payments ← fiscal_event → terminal → location
        //     (overrides nothing already set; still NULLs only)
        DB::table('payments as p')
            ->join('fiscal_events as fe', 'fe.id', '=', 'p.fiscal_event_id')
            ->join('terminals as t', 't.id', '=', 'fe.terminal_id')
            ->where('p.company_id', $company->id)
            ->whereNotNull('p.fiscal_event_id')
            ->whereNull('p.location_id')
            ->whereNotNull('t.location_id')
            ->update(['p.location_id' => DB::raw('t.location_id')]);

        $this->info("Backfilled location attribution for company {$company->id}.");

        return self::SUCCESS;
    }
}
```

Register the command in the Treasury service provider's `commands([...])` (or `bootstrap/app.php` console list — follow the existing pattern for `treasury:reconcile`).

> **PG join-update note:** if the Postgres driver rejects `join(...)->update(...)`, use the `UPDATE … FROM … WHERE` raw form via `DB::statement` — same predicates, still NULLs-only + company-scoped. Keep SQLite-test parity by branching on `DB::getDriverName()` exactly as the existing aggregates do.

- [ ] **Step 4: Write the self-guarding migration wrapper** — runs the same fills for **every** company in the tenant DB, column-guarded, idempotent.

```php
<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Self-guarding backfill wrapper (push = staging auto-deploy). Runs
 * treasury:backfill-location-attribution for each company in this tenant DB.
 * Idempotent (NULLs only) so re-runs are no-ops. Repository→location assignment
 * (Task 1) is an OWNER step done BEFORE deploy — rows whose repository has no
 * location simply stay Unattributed until assigned + re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'location_id')) {
            return; // schema migration hasn't run yet; nothing to fill
        }

        Company::query()->pluck('id')->each(function (string $companyId): void {
            Artisan::call('treasury:backfill-location-attribution', ['--company' => $companyId]);
        });
    }

    public function down(): void
    {
        // Non-destructive: leave attributed values in place.
    }
};
```

- [ ] **Step 5: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=BackfillLocationAttributionTest`
Expected: PASS.

- [ ] **Step 6: PHPStan + commit.**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Presentation/Console/BackfillLocationAttributionCommand.php
git add apps/api/app/Modules/Treasury/Presentation/Console/BackfillLocationAttributionCommand.php apps/api/database/migrations/tenant/2026_07_16_110500_backfill_location_attribution.php apps/api/tests/Feature/Treasury/BackfillLocationAttributionTest.php
git commit -m "feat(treasury): guarded location-attribution backfill command + self-guarding migration (multiloc §3)"
```

---

## Task 8 (surface): Cash position by location (repository-grain = custody)

`CashPositionController` gains `group_by=location` + `location_ids[]` (resolver-scoped) and an **Unattributed** bucket so grouped totals reconcile to the grand total. Grain = repository custody; sums are single-sign (a balance is a balance). FE `CashPositionWidget`/`TreasuryOverviewPage` adopt the view scope.

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php`
- Modify: `apps/web/src/features/treasury/hooks/useCashPosition.ts`, `apps/web/src/features/treasury/components/CashPositionWidget.tsx`, `apps/web/src/features/finance/pages/TreasuryOverviewPage.tsx`
- Test (BE): `apps/api/tests/Feature/Treasury/CashPositionByLocationTest.php`
- Test (FE): `apps/web/src/features/treasury/components/CashPositionWidget.location.test.tsx`

**Interfaces:**
- Consumes: `LocationScopeResolver::resolve($user, $requestedIds, 'treasury.view_all_locations')` (bypass permission declared here — a treasury-wide viewer sees all; seed it in §1's permission task or note it as owed); `useViewScope`, `locationScopedKey`.
- Produces: `data.groups_by_location[]` with per-location `total` + an `unattributed` entry; `grand_total` unchanged.

- [ ] **Step 1: Write the failing reconcile test (Postgres).** Two locations + one location-less bank repo; `group_by=location` returns three buckets (A, B, Unattributed) whose totals `bcadd` to `grand_total`.

```php
public function test_group_by_location_reconciles_to_grand_total(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $this->cashRepoAt($company, $storeA, balance: '100.000');
    $this->cashRepoAt($company, $storeB, balance: '250.000');
    $this->bankRepo($company, balance: '500.000'); // no location → Unattributed

    $response = $this->actingAs($user)->getJson('/api/v1/treasury/cash-position?group_by=location');

    $response->assertOk();
    $byLocation = collect($response->json('data.groups_by_location'));
    $sum = $byLocation->reduce(fn (string $c, array $g): string => bcadd($c, $g['total'], 3), '0.000');
    $this->assertSame($response->json('data.grand_total'), $sum);
    $this->assertNotNull($byLocation->firstWhere('location_id', null)); // Unattributed present
}

public function test_location_ids_filter_scopes_and_fails_closed_out_of_scope(): void
{
    // restricted user requesting a store outside their allowed set → 403 (resolver fail-closed)
}
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=CashPositionByLocationTest`
Expected: FAIL.

- [ ] **Step 3: Implement.** Inject `LocationScopeResolver`. Validate `group_by` (`in:type,location`) and `location_ids` (`array` / `uuid` each). When `location_ids` present, `resolve()` and add `->whereIn('location_id', $ids)` (a NULL location survives only when the caller is unrestricted/"all"). When `group_by=location`, group the already-fetched `$repositories` by `location_id`, `bcadd` balances at `$scale` (explicit `$company->currency`), emit an `unattributed` bucket for NULL locations, and keep the existing type-grouped payload for back-compat. Rule 19 throughout.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=CashPositionByLocationTest`
Expected: PASS.

- [ ] **Step 5: FE** — `useCashPosition` reads `useViewScope()`, passes `location_ids[]`, uses `locationScopedKey(['cash-position'], scope)`; widget adds a per-location column/breakdown with `formatCurrency`, an "Unattributed" row (`t('treasury:cashPosition.unattributed')`), tokens only. Failing FE test first, then implement, then:

Run: `cd apps/web && pnpm test CashPositionWidget.location`
Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php apps/api/tests/Feature/Treasury/CashPositionByLocationTest.php apps/web/src/features/treasury/hooks/useCashPosition.ts apps/web/src/features/treasury/components/CashPositionWidget.tsx apps/web/src/features/finance/pages/TreasuryOverviewPage.tsx apps/web/src/features/treasury/components/CashPositionWidget.location.test.tsx
git commit -m "feat(treasury): cash position by location + Unattributed bucket (multiloc §3)"
```

---

## Task 9 (surface): Échéancier / instrument list filter + grouping by location (instrument-grain = origin, frozen)

`MaturingInstrumentsController` and `PaymentInstrumentController::index` gain a `location_ids[]` filter (resolver-scoped) and optional location grouping; FE `InstrumentListPage`/`TreasuryOverviewPage` add a location column + filter via view scope. Grain = instrument origin (frozen — Task 6), **never** presented as reconcilable with cash position (different grain).

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php` (validation :27-35; query :37-47; `formatRow` :156-174)
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php` (validation :41-51; query :52-60; `formatInstrument` :378 — add `location_id`)
- Modify: `apps/web/src/features/treasury/InstrumentListPage.tsx`, `apps/web/src/features/treasury/api/*` (instrument list query)
- Test (BE): `apps/api/tests/Feature/Treasury/InstrumentListByLocationTest.php`
- Test (FE): `apps/web/src/features/treasury/InstrumentListPage.location.test.tsx`

**Interfaces:**
- Consumes: `LocationScopeResolver::resolve($user, $ids, 'treasury.view_all_locations')`; frozen `payment_instruments.location_id`.
- Produces: instrument rows carry `location_id`/`location_name`; both endpoints accept `location_ids[]`.

- [ ] **Step 1: Write the failing test** — filtering `location_ids[]=Store A` on the maturing-instruments endpoint returns only Store-A-origin instruments even after they've been custody-transferred elsewhere (proves the frozen origin grain).

```php
public function test_maturing_instruments_filter_by_frozen_origin_location(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $inA = $this->receivedInstrumentAt($company, $storeA);          // origin Store A
    $this->receivedInstrumentAt($company, $storeB);                 // origin Store B
    $this->custodyTransfer($inA, $this->bankRepoAt($company, $storeB)); // moves repository, not location

    $response = $this->actingAs($user)->getJson('/api/v1/treasury/maturing-instruments?location_ids[]='.$storeA->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    $this->assertTrue($ids->contains($inA->id));
    $this->assertCount(1, $ids);
}
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=InstrumentListByLocationTest`
Expected: FAIL.

- [ ] **Step 3: Implement.** Add `'location_ids' => ['nullable','array']`, `'location_ids.*' => ['uuid']` to both validators; when present, `resolve()` and `->whereIn('location_id', $ids)`. Add `'location_id' => $instrument->location_id` (+ eager `location:id,name` → `location_name`) to `formatRow`/`formatInstrument`. Bucketing sums stay signed per direction (in/out) exactly as today — do not blend grains.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=InstrumentListByLocationTest`
Expected: PASS.

- [ ] **Step 5: FE** — `InstrumentListPage` reads `useViewScope`, passes `location_ids[]`, keys via `locationScopedKey`, adds a location column and a caveat tooltip that instrument-grain (origin) ≠ cash-position custody grain (`t('treasury:instruments.originGrainCaveat')`). Failing FE test first, then implement.

Run: `cd apps/web && pnpm test InstrumentListPage.location`
Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php apps/api/tests/Feature/Treasury/InstrumentListByLocationTest.php apps/web/src/features/treasury/InstrumentListPage.tsx apps/web/src/features/treasury/api apps/web/src/features/treasury/InstrumentListPage.location.test.tsx
git commit -m "feat(treasury): instrument list/échéancier filter+group by frozen origin location (multiloc §3)"
```

---

## Task 10 (surface): Upcoming payments + Aged AR/AP by location (documents-header grain)

`UpcomingPaymentsService`, `AgedReceivablesService`, `AgedPayablesService` gain an optional `location_ids[]` filter (documents-header grain via `documents.location_id`; instruments via their frozen `location_id`) + optional by-location breakdown with an **Unattributed** bucket. Historical rows are documented as **"default-attributed"** (i18n caveat in the report UI). All bcmath keeps the existing injected scale resolver + explicit currency.

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php` (`generate` signature + `openDocuments`/`openUnpaidExpenses`/`pendingInstruments` filters)
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php` (`generate` + `getOutstandingInvoices`)
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedPayablesService.php` (`generate` + `getOutstandingInvoices`)
- Modify: the three report controllers (accept `location_ids[]`, resolve via `LocationScopeResolver`) — locate via `grep -rn "UpcomingPaymentsService\|AgedReceivablesService\|AgedPayablesService" apps/api/app/Modules/Accounting/Presentation`
- Modify: `apps/web/src/features/finance/pages/AgedReceivablesPage.tsx`, `AgedPayablesPage.tsx`, and the upcoming-payments/échéancier page (view scope + caveat)
- Test (BE): `apps/api/tests/Feature/Accounting/Reports/ReportsByLocationTest.php`

**Interfaces:**
- Consumes: `documents.location_id` (already written by §1's real-location transact field going forward; historical = default-attributed), frozen `payment_instruments.location_id`.
- Produces: `generate(string $companyId, …, array $locationIds = [])` on each service; Unattributed bucket in each report DTO where breakdown is requested.

- [ ] **Step 1: Write the failing test** — a company with invoices at Store A + Store B; `location_ids[]=A` returns only A's balance; the Unattributed bucket (documents with NULL location) is present and the buckets `bcadd` to the unfiltered grand total.

```php
public function test_aged_receivables_filter_and_unattributed_reconcile(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $this->postedInvoiceAt($company, $storeA, balance: '100.000');
    $this->postedInvoiceAt($company, $storeB, balance: '40.000');
    $this->postedInvoiceAt($company, null,   balance: '10.000'); // Unattributed

    $all = app(AgedReceivablesService::class)->generate($company->id, null, []);
    $filtered = app(AgedReceivablesService::class)->generate($company->id, null, [$storeA->id]);

    $this->assertSame('100.0000', $filtered->grand_total);
    $this->assertSame('150.0000', $all->grand_total); // 100 + 40 + 10 reconciles
}
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=ReportsByLocationTest`
Expected: FAIL.

- [ ] **Step 3: Implement.** Add `array $locationIds = []` to each `generate`; when non-empty, add `->whereIn('location_id', $locationIds)` to the document/instrument queries. Controllers validate `location_ids[]`, call `LocationScopeResolver::resolve($user, $ids, 'finance.view_all_locations')`, and pass through. Do not change the bucket math; only the WHERE narrows. Rule 19 unchanged.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=ReportsByLocationTest`
Expected: PASS.

- [ ] **Step 5: FE** — the three pages read `useViewScope`, pass `location_ids[]`, key via `locationScopedKey`, and render a "pre-cutover rows are default-attributed" caveat (`t('finance:reports.defaultAttributedCaveat')`). Tokens + `formatCurrency` only. Failing FE tests first, then implement.

Run: `cd apps/web && pnpm test AgedReceivablesPage AgedPayablesPage`
Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add apps/api/app/Modules/Accounting/Application/Services/Reports apps/api/app/Modules/Accounting/Presentation apps/api/tests/Feature/Accounting/Reports/ReportsByLocationTest.php apps/web/src/features/finance/pages/AgedReceivablesPage.tsx apps/web/src/features/finance/pages/AgedPayablesPage.tsx
git commit -m "feat(finance): upcoming payments + AR/AP aging by location with Unattributed + caveat (multiloc §3)"
```

---

## Task 11 (surface): Expense analytics + list filter by location (parent documents.location_id join)

`ExpenseAnalyticsService`, `ExpenseIndexQuery`, `ExpenseAnalyticsRequest` gain a `location_ids[]` filter joining the parent `documents.location_id` (an expense IS a Document — no expense_metadata column). Resolver-scoped.

**Files:**
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseAnalyticsService.php` (`baseQuery` :202-226 — add location filter; `AnalyticsFilters` DTO gains `locationIds`)
- Modify: `apps/api/app/Modules/Expense/Application/DTOs/AnalyticsFilters.php` (add `public array $location_ids = []`)
- Modify: `apps/api/app/Modules/Expense/Application/Queries/ExpenseIndexQuery.php` (add `documents.location_id` filter)
- Modify: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseAnalyticsRequest.php` (validate `location_ids[]`, resolve, feed the DTO)
- Test (BE): `apps/api/tests/Feature/Expense/ExpenseAnalyticsByLocationTest.php`

**Interfaces:**
- Consumes: `documents.location_id`; `LocationScopeResolver::resolve($user, $ids, 'expenses.view_all_locations')`.
- Produces: analytics + list narrowed to scoped locations.

- [ ] **Step 1: Write the failing test** — expenses at Store A + Store B; `location_ids=[A]` returns only A's total.

```php
public function test_expense_analytics_filters_by_parent_document_location(): void
{
    [$user, $company, $storeA, $storeB] = $this->seedTwoStoreCompany();
    $this->expenseAt($company, $storeA, total: '100.000');
    $this->expenseAt($company, $storeB, total: '40.000');

    $filters = new AnalyticsFilters(date_from: '2026-01-01', date_to: '2026-12-31', category_id: null, status: DocumentStatus::Posted->value, location_ids: [$storeA->id]);
    $data = app(ExpenseAnalyticsService::class)->generate($company->tenant_id, $company->id, $filters);

    $this->assertSame('100.000', $data->tiles->total);
}
```

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/api && php artisan test --filter=ExpenseAnalyticsByLocationTest`
Expected: FAIL.

- [ ] **Step 3: Implement.** In `ExpenseAnalyticsService::baseQuery`, after the type/status/date predicates add:

```php
->when(
    $filters->location_ids !== [],
    static fn (Builder $query): Builder => $query->whereIn('documents.location_id', $filters->location_ids),
);
```

Add `location_ids` to `AnalyticsFilters` (and `priorPeriod` carries it forward). In `ExpenseIndexQuery::build`, add:

```php
if ($request->filled('location_ids')) {
    $query->whereIn('documents.location_id', (array) $request->input('location_ids'));
}
```

In `ExpenseAnalyticsRequest::rules`, add `'location_ids' => ['nullable','array']`, `'location_ids.*' => ['uuid', ScopedExists::tenant('locations', $user->tenant_id)]`; in a `resolvedFilters()`/`prepareForValidation` step, run `LocationScopeResolver::resolve($this->user(), $ids, 'expenses.view_all_locations')` and inject into the DTO (constructor-injected resolver — the request already injects `CompanyContext`).

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/api && php artisan test --filter=ExpenseAnalyticsByLocationTest`
Expected: PASS.

- [ ] **Step 5: PHPStan + commit.**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Expense/Application app/Modules/Expense/Presentation/Requests/ExpenseAnalyticsRequest.php
git add apps/api/app/Modules/Expense apps/api/tests/Feature/Expense/ExpenseAnalyticsByLocationTest.php
git commit -m "feat(expense): analytics + list filter by parent document location (multiloc §3)"
```

---

## Task 12 (surface): `FinanceHubPage` adopts the global view scope

The finance hub's aggregate widgets (cash across stores, due-this-week, AR/AP) read `useViewScope` so they respect the persistent scope picker and deep-link into the scoped detail pages.

**Files:**
- Modify: `apps/web/src/features/finance/pages/FinanceHubPage.tsx`
- Test (FE): `apps/web/src/features/finance/pages/FinanceHubPage.location.test.tsx`

**Interfaces:**
- Consumes: `useViewScope`, `locationScopedKey`, the scoped endpoints from Tasks 8–11.

- [ ] **Step 1: Write the failing FE test** — switching the view scope re-queries the hub widgets with `location_ids[]` and the query keys are `locationScopedKey`-shaped.

- [ ] **Step 2: Run — expect FAIL.**

Run: `cd apps/web && pnpm test FinanceHubPage.location`
Expected: FAIL.

- [ ] **Step 3: Implement.** Thread `useViewScope()` into each hub widget's query (`location_ids[]` param + `locationScopedKey`). No hardcoded strings/colors. Deep-links preserve scope.

- [ ] **Step 4: Run — expect PASS.**

Run: `cd apps/web && pnpm test FinanceHubPage.location`
Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add apps/web/src/features/finance/pages/FinanceHubPage.tsx apps/web/src/features/finance/pages/FinanceHubPage.location.test.tsx
git commit -m "feat(finance): FinanceHubPage adopts global view scope (multiloc §3)"
```

---

## Task 13 (T6 — reconciliation): the three named cross-grain tests + Unattributed-reconciles-to-company total

Dedicated deliverable: the three named reconciliation tests plus the "Unattributed bucket makes every by-location surface reconcile to the company-wide figure" assertions. Signed sums (bounce writes negative allocations — `InstrumentLifecycleService::bounce` :508-528).

**Files:**
- Create: `apps/api/tests/Feature/Treasury/LocationReconciliationTest.php`

**Interfaces:**
- Consumes: everything from Tasks 3–11.

- [ ] **Step 1: Write the three named tests (all Postgres).**

```php
final class LocationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // setUp (repo idiom, cf. AuditDiscountsCommandTest): tenant + company (EUR/FR),
    // two Locations A/B, one cash repository per location (location_id set),
    // a customer Partner, an authed user with reports.view + dashboard.owner.
    // Helper invoiceAt(Location $l, string $total): Document — posted sales
    // invoice with documents.location_id = $l->id, balance_due = $total.

    // (a) One payment of '100.000' allocated '60.000' to an invoice at A and
    //     '40.000' to an invoice at B. Payment.location_id = A (first-allocated
    //     rule, Task 6). Assert via GET /reports/aged-receivables?group_by=location:
    //     A's bucket dropped by 60, B's by 40, and sum(all location buckets +
    //     unattributed) === company-wide aged-receivables grand total (bcmath
    //     string compare, scale 3). No row appears in two buckets.
    public function test_a_payment_allocated_across_two_location_documents_reconciles(): void
    {
        $invA = $this->invoiceAt($this->locationA, '200.000');
        $invB = $this->invoiceAt($this->locationB, '200.000');
        $payment = $this->paymentAllocatedAcross($invA, '60.000', $invB, '40.000');

        $byLocation = $this->getJson('/api/v1/reports/aged-receivables?group_by=location')->json('data');
        $companyWide = $this->getJson('/api/v1/reports/aged-receivables')->json('data');

        $this->assertSame('140.000', $this->bucketTotal($byLocation, $this->locationA->id)); // 200 − 60
        $this->assertSame('160.000', $this->bucketTotal($byLocation, $this->locationB->id)); // 200 − 40
        $this->assertSame(0, bccomp($this->sumAllBuckets($byLocation), $companyWide['grand_total'], 3));
    }

    // (b) Instrument received at A ('100.000'), allocated to an A invoice,
    //     bounced (negative allocations, InstrumentLifecycleService::bounce),
    //     then re-allocated to a B invoice. Assert: signed AR-by-location shows
    //     A's balance_due fully restored (no residual credit), B reduced once,
    //     and company grand total identical before/after the round trip.
    public function test_b_bounce_then_reallocate_signed_sum_reconciles(): void
    {
        $invA = $this->invoiceAt($this->locationA, '100.000');
        $invB = $this->invoiceAt($this->locationB, '100.000');
        $before = $this->getJson('/api/v1/reports/aged-receivables')->json('data.grand_total');

        $instrument = $this->receiveInstrumentAt($this->locationA, '100.000', allocateTo: $invA);
        $this->lifecycle->bounce($instrument, reason: 'nsf');
        $this->allocate($instrument->payment, $invB, '100.000');

        $byLocation = $this->getJson('/api/v1/reports/aged-receivables?group_by=location')->json('data');
        $this->assertSame('100.000', $this->bucketTotal($byLocation, $this->locationA->id)); // restored
        $this->assertSame('0.000', $this->bucketTotal($byLocation, $this->locationB->id));   // reduced once
        // company total unchanged by the bounce round-trip (one 100 payment in the system either way)
        $this->assertSame(0, bccomp($this->getJson('/api/v1/reports/aged-receivables')->json('data.grand_total'), bcsub($before, '100.000', 3), 3));
    }

    // (c) Instrument received at A's cash repo, custody-transferred to the
    //     company bank repo (location NULL). Assert the two grains stay
    //     separate: échéancier (GET /treasury/instruments?location_ids[]=A)
    //     still returns it exactly once under A (frozen origin, Task 7);
    //     cash position (GET /treasury/cash-position?group_by=location) shows
    //     its value under Unattributed (bank custody), NOT under A — and the
    //     instrument appears in exactly one bucket of EACH surface, never two.
    public function test_c_instrument_in_transit_not_double_counted_across_grains(): void
    {
        $instrument = $this->receiveInstrumentAt($this->locationA, '80.000');
        $this->lifecycle->custodyTransfer($instrument, $this->bankRepository->id);

        $echeancier = $this->getJson('/api/v1/treasury/instruments?location_ids[]=' . $this->locationA->id)->json('data');
        $this->assertCount(1, array_filter($echeancier, fn (array $row): bool => $row['id'] === $instrument->id));

        $cash = $this->getJson('/api/v1/treasury/cash-position?group_by=location')->json('data');
        $this->assertSame(0, bccomp($this->locationBucket($cash, null)['total'], '80.000', 3));      // Unattributed custody
        $this->assertSame(0, bccomp($this->locationBucket($cash, $this->locationA->id)['total'], '0.000', 3)); // not at origin
    }

    // Unattributed reconciliation applied to cash position + AR/AP: with a mix
    // of located and NULL-location rows, sum(location buckets) + unattributed
    // === the surface's own company-wide total, per surface (bcmath, scale 3).
    public function test_unattributed_bucket_makes_each_surface_reconcile_to_company_total(): void
    {
        $this->invoiceAt($this->locationA, '50.000');
        $this->invoiceWithoutLocation('30.000'); // historical/default-attributed row
        $this->receiveInstrumentAt($this->locationA, '20.000');

        foreach (['/api/v1/treasury/cash-position?group_by=location', '/api/v1/reports/aged-receivables?group_by=location'] as $url) {
            $data = $this->getJson($url)->json('data');
            $this->assertSame(0, bccomp($this->sumAllBuckets($data), $data['grand_total'], 3), "surface {$url} does not reconcile");
        }
    }
}
```
The private helpers (`invoiceAt`, `invoiceWithoutLocation`, `paymentAllocatedAcross`, `receiveInstrumentAt`, `allocate`, `bucketTotal`, `locationBucket`, `sumAllBuckets`) are written in this class in this step using direct model creates + the real `InstrumentLifecycleService` (injected as `$this->lifecycle`) — no mocks, per repo testing conventions. Match response paths (`data.grand_total`, bucket shapes) to the shapes shipped in Tasks 8–10; if a helper's assertion can't be satisfied, that is a bug in Tasks 3–11 (fix there — never weaken the test).

- [ ] **Step 2: Run — expect FAIL** (until the assertions match shipped behavior; iterate on Tasks 3–11 if a reconcile breaks — a broken reconcile is a real bug, not a test to weaken).

Run: `cd apps/api && php artisan test tests/Feature/Treasury/LocationReconciliationTest.php`
Expected: FAIL then PASS after fixes.

- [ ] **Step 3: Commit.**

```bash
git add apps/api/tests/Feature/Treasury/LocationReconciliationTest.php
git commit -m "test(treasury): §3 named reconciliation + Unattributed-reconciles tests (multiloc §3 T6)"
```

---

## Gates & verification

Run before declaring the plan complete. Do NOT run the full PHPUnit suite (crashes the laptop) — run BY PATH.

- [ ] **Backend, by path:**
  ```bash
  cd apps/api && php artisan test \
    tests/Feature/Treasury/PaymentRepositoryLocationTest.php \
    tests/Feature/Treasury/PaymentLocationSchemaTest.php \
    tests/Feature/Treasury/Projections/PosBridgeLocationAttributionTest.php \
    tests/Feature/Treasury/PaymentControllerLocationAttributionTest.php \
    tests/Feature/Treasury/RefundLocationAttributionTest.php \
    tests/Feature/Treasury/InstrumentLocationFreezeTest.php \
    tests/Feature/Treasury/BackfillLocationAttributionTest.php \
    tests/Feature/Treasury/CashPositionByLocationTest.php \
    tests/Feature/Treasury/InstrumentListByLocationTest.php \
    tests/Feature/Accounting/Reports/ReportsByLocationTest.php \
    tests/Feature/Expense/ExpenseAnalyticsByLocationTest.php \
    tests/Feature/Treasury/LocationReconciliationTest.php
  ./vendor/bin/phpstan analyse app/Modules/Treasury app/Modules/Expense app/Modules/Accounting/Application/Services/Reports
  ./vendor/bin/pint --test app/Modules/Treasury app/Modules/Expense app/Modules/Accounting
  ```
- [ ] **`php artisan typescript:transform`** after any PHP DTO change (`AnalyticsFilters` gained `location_ids`; run with `CACHE_STORE=array` per worktree gotcha). Regenerated types in `packages/shared/types/` are the source of truth — never hand-edit.
- [ ] **Frontend:**
  ```bash
  cd apps/web && pnpm test src/features/treasury src/features/finance src/components/organisms/AddRepositoryModal && pnpm lint && pnpm typecheck
  ```
- [ ] **Reviewer gates (pinned model = Opus):**
  - `treasury-reviewer` — the whole branch (schema, writer attribution, freeze rule, backfill, cash/instrument/report surfaces). **Pin it.**
  - `frontend-conventions-reviewer` — every FE task (Tasks 1, 8, 9, 10, 11, 12): tokens, `t()`/RTL, `PageHeader`/`DataTable` canonical components, `locationScopedKey` on every location-consuming query (linter can't infer this — reviewer enforces the contract), `formatCurrency`, no `parseFloat` on money.
- [ ] **Playwright e2e** (add to `apps/web/e2e/`): cash position by location incl. Unattributed; échéancier filtered by view scope (frozen origin grain); expense analytics by location; visual pass **light + dark**. Confirm a restricted manager sees only their store's figures end-to-end.

## Deploy checklist (ordering is load-bearing; stacks on treasury ③/④ owes)

Record in `docs/handoff/multiloc-3-financial-deploy-checklist.md`. **Ordering:** repository assignment (owner) → backfill → surfaces live. Push = staging auto-deploy incl. `tenants:migrate`, so the schema + self-guarding backfill migrations run automatically — but the backfill only fills rows whose repository already has a location.

1. **OWNER, BEFORE deploy:** assign every cash register / safe repository to its store (Task 1 UI). Bank accounts may stay NULL (company-level). **No automatic guess** — unassigned repositories leave their rows Unattributed until assigned + re-run.
2. **Deploy** — `2026_07_16_110000` (schema) then `2026_07_16_110500` (self-guarding backfill, NULLs only, per-company via the artisan command with explicit Company) run via `tenants:migrate`.
3. **Post-deploy, per real tenant that assigned repositories AFTER the deploy window:** re-run `php artisan tenants:run "treasury:backfill-location-attribution --company={id}"` (idempotent).
4. **Permissions:** seed + grant the bypass permissions referenced by the resolver calls (`treasury.view_all_locations`, `finance.view_all_locations`, `expenses.view_all_locations`) in `RolesAndPermissionsSeeder`; reseed + `permission:cache-reset` (tenant-blind cache). If §1 already seeds a single umbrella bypass permission, use it instead and delete the extras — coordinate with the §1 permission task so the names match exactly.
5. **Verify:** cash position `group_by=location` reconciles to `grand_total`; échéancier filter returns frozen-origin rows; expense analytics respects scope; historical reports show the "default-attributed" caveat.

---

**Execution:** REQUIRED SUB-SKILL — Use superpowers:subagent-driven-development (fresh subagent per task, `treasury-reviewer` pinned + `frontend-conventions-reviewer` between tasks) or superpowers:executing-plans (batched with checkpoints).
