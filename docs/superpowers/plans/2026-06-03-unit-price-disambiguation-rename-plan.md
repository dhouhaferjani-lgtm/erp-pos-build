# `unit_price` Disambiguation Rename — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the backend `unit_price` overload by renaming every backend per-unit-money field to `unit_price_excl_tax` (B2B/net) or `unit_price_incl_tax` (POS/inclusive), keeping `unit_price` only inside the signed canonical bytes + POS device, with an explicit, tested POS→backend translation seam.

**Architecture:** Three zones (from the spec). **Zone 1** (POS device `apps/pos` + signed canonical bytes + the PHP canonical-mirror DTOs) keeps `unit_price` — never renamed, so no versioned fiscal event and no fixture regeneration. **Zone 2** (the POS→backend boundary: canonical projection + HTTP request/response mapping) translates inclusive `unit_price` ↔ backend `unit_price_incl_tax`. **Zone 3** (all backend storage/DTOs/web) is fully renamed. DB columns migrate via **expand/contract** at `decimal(15,3)`.

**Tech Stack:** Laravel 12 / PHP 8.2 (strict types, spatie/laravel-data DTOs, `php artisan typescript:transform`), PostgreSQL (per-tenant migrations under `apps/api/database/migrations/tenant/`), React 19 / TS (`apps/web`, `apps/pos`), PHPUnit + Vitest.

**Spec:** `docs/superpowers/specs/2026-06-03-unit-price-disambiguation-rename-design.md` (v3.2). Reviews r1–r3 in `docs/superpowers/reviews/`.

---

## Conventions used throughout this plan

**Rename discipline (this is a rename, not a feature):** the primary correctness test for each mechanical task is *"the existing suite stays green AND the new name is in place."* Genuine TDD (red-first) is reserved for the **behavioral boundaries**: the canonical-unchanged regression guard (Task 1), the projection seam (Task 21), and the wire-contract mapping (Task 23). Mechanical per-module renames are grouped per module with a green-suite verification, because splitting a pure rename into 5 micro-steps per file adds no safety.

**Expand/contract recipe (every backend money-column rename uses this):**
1. **Expand migration** (in the phase PR): add the new column at `decimal(15,3)`, backfill `UPDATE … SET new = old`, keep the old column.
2. **Dual-write + read-new:** model writers set BOTH columns; readers/casts use the NEW column. (Transitional — guarantees a tenant mid-migration is never wrong.)
3. **Contract migration** (a LATER PR, after every tenant DB is confirmed read-switched): drop the old column and replace any CHECK constraints that referenced it. Collected in Phase 4 (§ Deferred drop).

**Test commands** (run from repo root unless noted):
- Backend single test: `cd apps/api && ./vendor/bin/phpunit --filter <TestName>`
- Backend suite (module): `cd apps/api && ./vendor/bin/phpunit --testsuite Feature -- --group <module>` (or path: `./vendor/bin/phpunit tests/Unit/Document`)
- Static + style: `cd apps/api && ./vendor/bin/phpstan && ./vendor/bin/pint --dirty`
- TS types regen: `cd apps/api && php artisan typescript:transform`
- Frontend: `cd apps/web && pnpm test && pnpm typecheck && pnpm lint`
- POS: `cd apps/pos && pnpm test && pnpm typecheck`
- Fiscal parity (must stay green, proves Zone 1 untouched): `bash apps/pos/scripts/check-fiscal-fixture-parity.sh`
- Full gate: `./scripts/preflight.sh`

**Commit discipline:** stage explicit files only (never `git add -A`); one commit per task; conventional-commit messages ending with the Co-Authored-By trailer.

---

## File structure map

| Area | Files | Phase |
|---|---|---|
| Canonical-unchanged guard | new `apps/api/tests/Feature/Fiscal/CanonicalUnitPriceUnchangedTest.php` | 0/1 |
| Document module | `Document/Application/DTOs/DocumentLineData.php`, `Document/Domain/DocumentLine.php`, `document_lines` migration(s), `DocumentTotalsCalculator`, `DraftPersistenceService`, `Conversion/*`, `CreditNoteService`, `RefundService`, `FacturXService`, `DocumentAdditionalCostController`, requests/controllers, `DraftLineAddedV3`/`DraftLineModifiedV3` | 1 |
| Facture-bridge seam (B2B) | `Document/Application/Projections/DocumentAccountChargeFactureBridge.php`, `Document/Application/Services/POSAccountChargeDraftService.php`, its test | 1 |
| Workshop | `Workshop/Bundle/Application/DTOs/*`, `Workshop/WorkOrder/Application/DTOs/WorkOrderLineData.php`, `Bundle/Presentation/{Requests,Controllers}/*`, factories | 1 |
| Billing | `Billing/Domain/InvoiceItem.php`, `billing_invoice_items` migration(s), `Billing/Application/Services/InvoiceService.php` | 1 |
| Catalog cart | `Cart/Application/DTOs/CatalogCartItemData.php`, `Cart/Domain/Models/CatalogCartItem.php`, `catalog_cart_items` migration(s), `CartService`, `CartConversionService`, `MarketplaceCheckoutService`, `CatalogCartController`, factory | 1 |
| Marketplace | `Marketplace/Domain/Models/MarketplaceOrderLine.php`, `marketplace_order_lines` migration(s), `MarketplaceOrderData`, `MarketplaceOrderService` | 1 |
| Tax/pricing helpers | `Taxation/Domain/Services/TaxCalculationService.php`, `Pricing/Presentation/Controllers/PricingController.php`, `Treasury/.../AuditDiscountsCommand.php` | 1 |
| Web (B2B) | `packages/shared/types/generated.d.ts` (regen), `apps/web/src/types/document.ts`, document features/fixtures/e2e | 1 |
| POS order/receipt storage | `pos_order_lines` + `pos_receipt_lines` migration(s), `POS/Application/DTOs/OrderLineData.php`, `POS/Domain/{OrderLine,ReceiptLine}.php` | 2 |
| Projection seam | `POS/Application/Projections/PosCoreReceiptProjection.php` | 2 |
| Wire-contract mapping | `AddOrderLineRequest`, `HoldOrderRequest`, `OrderController`, `OrderLineResource`, `ReceiptController`, return/exchange/coupon/discount requests | 2 |
| POS read/report/print | `OrderManagementService`, `HeldOrderService`, `HeldOrder`, `OrderToReceiptService`, `ReceiptReturnService`, `Nf525DataProvider`, `resources/views/pos/receipt.blade.php` | 2 |
| Web POS | `apps/web/src/features/pos/**`, `apps/web/src/features/coupons/api/couponApi.ts` | 2 |
| Docs | `docs/architecture/precision-contract.md`, `CLAUDE.md` §19, monorepo `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` | 3 |
| Deferred drops | contract migrations for every renamed column | 4 |

---

## Phase 0 — Pre-flight

### Task 0: Preconditions & re-inventory

**Files:** none (verification + notes only)

- [ ] **Step 1: Confirm T2 variants merged.** Run: `git log --oneline origin/dev | grep -i "t2-variants" | head`. Expected: a merge commit. If absent, STOP — the spec's §3 sequencing gate forbids starting before T2 merges.
- [ ] **Step 2: Re-derive the surface list against current `dev`.** Run:
```bash
git grep -nIw "unit_price\|override_unit_price\|unitPrice" -- 'apps/api/**' 'apps/web/**' 'apps/pos/**' 'packages/**' \
  ':!*test*' ':!*__tests__*' ':!*.snap' ':!apps/api/backup_before_phase0.sql' > /tmp/unit_price_inventory.txt
wc -l /tmp/unit_price_inventory.txt
```
Compare against the spec §7 table. Note any new surface T2 introduced; add a task for it in the matching phase. Do not proceed until every hit is classified Zone 1 (keep) / Phase 1 / Phase 2 / disposition.
- [ ] **Step 3: Confirm the REALIGNMENT-LOG path.** Run: `ls ../../docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` (from `apps/erp`). Record the resolved absolute path for Phase 3; if absent, the Phase 3 task creates it.
- [ ] **Step 4: Baseline green.** Run: `./scripts/preflight.sh` and `bash apps/pos/scripts/check-fiscal-fixture-parity.sh`. Expected: all pass. Record counts; this is the regression baseline.
- [ ] **Step 5: Commit notes.** Write findings to `docs/sessions/2026-06-03-unit-price-rename-preflight.md` (gitignored session file) — no commit needed.

---

## Phase 1 — B2B / net → `unit_price_excl_tax`

### Task 1: Canonical-unchanged regression guard (TDD)

> This guard proves the whole rename never alters the signed bytes. Write it FIRST so every later task is checked against it.

**Files:**
- Create: `apps/api/tests/Feature/Fiscal/CanonicalUnitPriceUnchangedTest.php`

- [ ] **Step 1: Write the test.** It asserts the canonical builder/validator still uses the bare key `unit_price` and that golden hashes are unchanged.
```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Tests\TestCase;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;

final class CanonicalUnitPriceUnchangedTest extends TestCase
{
    public function test_sale_receipt_golden_line_key_is_bare_unit_price(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $line = $payload['line_items'][0];
        $this->assertArrayHasKey('unit_price', $line);
        $this->assertArrayNotHasKey('unit_price_incl_tax', $line);
        $this->assertArrayNotHasKey('unit_price_excl_tax', $line);
    }

    public function test_account_charge_canonical_keeps_bare_unit_price(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur']; // adjust to the ACCOUNT_CHARGE golden key surfaced in step 2
        $this->assertArrayHasKey('unit_price', $payload['line_items'][0]);
    }
}
```
- [ ] **Step 2: Resolve fixture keys.** Run: `cd apps/api && ./vendor/bin/phpunit --filter CanonicalUnitPriceUnchangedTest`. If a fixture key is wrong, list available keys with `grep -rn "=> [" tests/Helpers/Fiscal/GoldenFixtureBuilder.php | head` and correct the test. Expected after fix: PASS (it documents the current state).
- [ ] **Step 3: Add the parity script to the guard.** Append a note in the test docblock: "Companion gate: `bash apps/pos/scripts/check-fiscal-fixture-parity.sh` must stay green for every task in this plan."
- [ ] **Step 4: Commit.**
```bash
git add apps/api/tests/Feature/Fiscal/CanonicalUnitPriceUnchangedTest.php
git commit -m "test(fiscal): guard canonical unit_price key is never renamed"
```

### Task 2: `document_lines` expand migration (add `unit_price_excl_tax`)

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_03_100001_add_unit_price_excl_tax_to_document_lines.php`

- [ ] **Step 1: Write the migration.**
```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->decimal('unit_price_excl_tax', 15, 3)->nullable()->after('unit_price');
        });
        DB::statement('UPDATE document_lines SET unit_price_excl_tax = unit_price');
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn('unit_price_excl_tax');
        });
    }
};
```
- [ ] **Step 2: Run migrations against the test DB.** Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant --database=tenant_testing` (or the project's tenant-test migrate command discovered in Task 0). Expected: migrates cleanly.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/database/migrations/tenant/2026_06_03_100001_add_unit_price_excl_tax_to_document_lines.php
git commit -m "feat(document): expand document_lines with unit_price_excl_tax"
```

### Task 3: `DocumentLine` model — dual-write + read-new

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/DocumentLine.php`

- [ ] **Step 1: Add the new column to `$fillable`/casts and a transitional accessor.** Add `unit_price_excl_tax` to `$fillable` and casts (`'unit_price_excl_tax' => 'decimal:3'`). Keep `unit_price` fillable during the dual-write window. Add a boot hook that mirrors writes:
```php
protected static function booted(): void
{
    static::saving(function (DocumentLine $line): void {
        // Dual-write window: keep both columns in sync until the contract migration (Phase 4).
        if ($line->isDirty('unit_price_excl_tax')) {
            $line->unit_price = $line->unit_price_excl_tax;
        } elseif ($line->isDirty('unit_price')) {
            $line->unit_price_excl_tax = $line->unit_price;
        }
    });
}
```
- [ ] **Step 2: Run the document unit suite.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Document`. Expected: PASS (no behavior change yet).
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/Document/Domain/DocumentLine.php
git commit -m "feat(document): dual-write unit_price/unit_price_excl_tax on DocumentLine"
```

### Task 4: `DocumentLineData` DTO + TS regen

**Files:**
- Modify: `apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php`
- Modify (generated): `packages/shared/types/generated.d.ts`

- [ ] **Step 1: Rename the property.** Change the constructor param `public string $unit_price` → `public string $unit_price_excl_tax`, and in `fromModel` change `unit_price: CurrencyScale::bcformat($line->unit_price, $scale)` → `unit_price_excl_tax: CurrencyScale::bcformat($line->unit_price_excl_tax, $scale)`.
- [ ] **Step 2: Regenerate TS types.** Run: `cd apps/api && php artisan typescript:transform`. Expected: `generated.d.ts` now shows `unit_price_excl_tax` on `DocumentLineData`.
- [ ] **Step 3: Run document unit tests + PHPStan.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Document && ./vendor/bin/phpstan`. Fix any DTO consumer the compiler/PHPStan flags (defer web to Task 12). Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php packages/shared/types/generated.d.ts
git commit -m "feat(document): rename DocumentLineData.unit_price -> unit_price_excl_tax"
```

### Task 5: Document services read/write the new name

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/Services/DocumentTotalsCalculator.php`
- Modify: `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php`
- Modify: `apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php`
- Modify: `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php`, `DeliveryNoteToInvoiceConverter.php`, `SalesOrderToInvoiceConverter.php`
- Modify: `apps/api/app/Modules/Document/Application/Services/CreditNoteService.php`
- Modify: `apps/api/app/Modules/Document/Domain/Services/RefundService.php`
- Modify: `apps/api/app/Modules/Document/Application/Services/FacturXService.php`

- [ ] **Step 1: Replace reads/writes.** In each file replace `$line->unit_price`/`'unit_price' => …`/`$data['unit_price']` for **document lines** with `unit_price_excl_tax`. Leave the dual-write boot hook (Task 3) to keep the old column in sync. For array-key writes into `document_lines` use `'unit_price_excl_tax' => …`.
- [ ] **Step 2: Run document suites.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Document tests/Feature/Document`. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/Document/Domain/Services apps/api/app/Modules/Document/Application/Services/CreditNoteService.php apps/api/app/Modules/Document/Application/Services/FacturXService.php
git commit -m "feat(document): services use unit_price_excl_tax"
```

### Task 6: Document FormRequests & controllers

**Files:**
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/{QuoteController,SalesOrderController,InvoiceController,DeliveryNoteController,PurchaseOrderController,ReturnNoteController}.php`
- Modify: `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php`
- Modify: refund request `apps/api/app/Modules/Document/Presentation/Requests/RefundController.php` (or its FormRequest)

- [ ] **Step 1: Rename validation keys + reads.** Change `'lines.*.unit_price'` → `'lines.*.unit_price_excl_tax'` (and `'line_items.*.unit_price'` where the document refund path uses it), keep the money regex `/^-?\d+(\.\d{1,3})?$/`, and update the controller reads/writes to `unit_price_excl_tax`.
- [ ] **Step 2: Run feature suites.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Document`. Fix fixtures that post `unit_price` to use the new key. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/Document/Presentation apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php
git commit -m "feat(document): requests/controllers use unit_price_excl_tax"
```

### Task 7: Versioned draft-line events (Rule 8)

**Files:**
- Create: `apps/api/app/Modules/Document/Domain/Events/DraftLineAddedV3.php`
- Create: `apps/api/app/Modules/Document/Domain/Events/DraftLineModifiedV3.php`
- Modify: the emitter(s) that currently raise `DraftLineAddedV2`/`DraftLineModifiedV2`

- [ ] **Step 1: Create V3 events** identical to V2 but with the audit payload key `'unit_price_excl_tax' => $this->unitPriceExclTax`. Leave V1/V2 classes and their serialized keys untouched (immutable).
```php
<?php
declare(strict_types=1);
namespace App\Modules\Document\Domain\Events;

// Supersedes DraftLineModifiedV2: renames the audit key unit_price -> unit_price_excl_tax.
final class DraftLineModifiedV3
{
    public function __construct(
        public readonly string $documentId,
        public readonly int $lineNumber,
        public readonly string $quantity,
        public readonly string $unitPriceExclTax,
        public readonly string $lineTotal,
    ) {}

    /** @return array<string,mixed> */
    public function toAudit(): array
    {
        return [
            'document_id' => $this->documentId,
            'line_number' => $this->lineNumber,
            'quantity' => $this->quantity,
            'unit_price_excl_tax' => $this->unitPriceExclTax,
            'line_total' => $this->lineTotal,
        ];
    }
}
```
(Mirror for `DraftLineAddedV3`.)
- [ ] **Step 2: Switch emitters to V3.** Update the service(s) raising V2 to raise V3 with `unitPriceExclTax`.
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Document tests/Feature/Document --filter Draft`. Expected: PASS. Add a test asserting the V3 audit payload carries `unit_price_excl_tax`.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Document/Domain/Events/DraftLineAddedV3.php apps/api/app/Modules/Document/Domain/Events/DraftLineModifiedV3.php
git commit -m "feat(document): DraftLine*V3 events with unit_price_excl_tax"
```

### Task 8: Facture-bridge seam write target (B2B side of ACCOUNT_CHARGE)

> The bridge READS canonical `unit_price` (Zone 1, unchanged) and WRITES `document_lines`. Only the write key changes; the value stays net (canonical stays net — value correction is the finalization's job).

**Files:**
- Modify: `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:88,220`
- Modify: `apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php`
- Modify: `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`

- [ ] **Step 1: Update the write + assertion.** In `POSAccountChargeDraftService`, change the `document_lines` write `'unit_price' => $this->money($lineItem, 'unit_price')` → `'unit_price_excl_tax' => $this->money($lineItem, 'unit_price')` (read side stays the canonical key `unit_price`). Update the existing-line comparison loop similarly. In the test, change `$line->unit_price` → `$line->unit_price_excl_tax` (still `'100.000'`).
- [ ] **Step 2: Add a guard comment** above the read: `// Reads canonical 'unit_price' (Zone 1, net today); writes document net 'unit_price_excl_tax'. Canonical->inclusive correction + net derivation are owned by charge-to-account finalization.`
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit --filter DocumentAccountChargeFactureBridgeTest`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php apps/api/app/Modules/Document/Application/Projections/DocumentAccountChargeFactureBridge.php apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php
git commit -m "feat(document): facture-bridge writes unit_price_excl_tax (canonical read unchanged)"
```

### Task 9: Workshop (expand + DTOs + presentation)

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_03_100002_rename_workshop_unit_price.php` (expand any workshop tables with a stored `unit_price`/`override_unit_price`; check `service_bundle_components`, work-order line tables)
- Modify DTOs: `Workshop/Bundle/Application/DTOs/BundleExpansionLineData.php`, `Workshop/WorkOrder/Application/DTOs/WorkOrderLineData.php`, `Workshop/Bundle/Application/DTOs/ServiceBundleComponentData.php` (`override_unit_price` → `override_unit_price_excl_tax`)
- Modify presentation: `Workshop/Bundle/Presentation/Requests/AddComponentRequest.php`, `PatchComponentRequest.php`, `Workshop/Bundle/Presentation/Controllers/BundleComponentController.php`
- Modify factories: `database/factories/ServiceBundleComponentFactory.php`, `database/factories/Workshop/WorkOrderLineFactory.php`

- [ ] **Step 1: Expand any stored columns** with the recipe (add `*_excl_tax decimal(15,3)`, backfill). For `service_bundle_components.override_unit_price` add `override_unit_price_excl_tax`; add a dual-write boot hook on the model.
- [ ] **Step 2: Rename DTO props + presentation keys** (`unit_price` → `unit_price_excl_tax`, `override_unit_price` → `override_unit_price_excl_tax`), update factories, regen TS (`php artisan typescript:transform`).
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Workshop tests/Feature/Workshop && ./vendor/bin/phpstan`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Workshop apps/api/database/migrations/tenant/2026_06_03_100002_rename_workshop_unit_price.php apps/api/database/factories packages/shared/types/generated.d.ts
git commit -m "feat(workshop): rename unit_price/override_unit_price -> *_excl_tax"
```

### Task 10: Billing

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_03_100003_add_unit_price_excl_tax_to_billing_invoice_items.php`
- Modify: `apps/api/app/Modules/Billing/Domain/InvoiceItem.php`, `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php`

- [ ] **Step 1: Expand** `billing_invoice_items` (add `unit_price_excl_tax decimal(15,3)`, backfill). Add dual-write boot hook on `InvoiceItem`; add `unit_price_excl_tax` to `$fillable`/casts (`decimal:3`).
- [ ] **Step 2: Rename reads/writes** in `InvoiceItem` (e.g. `lineSubtotal()` using `$this->unit_price` → `$this->unit_price_excl_tax`) and `InvoiceService`.
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Billing tests/Feature/Billing`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Billing apps/api/database/migrations/tenant/2026_06_03_100003_add_unit_price_excl_tax_to_billing_invoice_items.php
git commit -m "feat(billing): rename invoice item unit_price -> unit_price_excl_tax"
```

### Task 11: Catalog cart

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_03_100004_add_unit_price_excl_tax_to_catalog_cart_items.php`
- Modify: `Cart/Domain/Models/CatalogCartItem.php`, `Cart/Application/DTOs/CatalogCartItemData.php`, `Cart/Application/Services/CartService.php`, `CartConversionService.php`, `MarketplaceCheckoutService.php`, `Cart/Presentation/Controllers/CatalogCartController.php`, `database/factories/CatalogCartItemFactory.php`

- [ ] **Step 1: Expand** `catalog_cart_items` (add `unit_price_excl_tax decimal(15,3)`, backfill) + dual-write boot hook.
- [ ] **Step 2: Rename** DTO prop, model fillable/casts, service reads/writes. **Critical seam:** in `CartConversionService`, the `DocumentLine::create([... 'unit_price' => ...])` write must become `'unit_price_excl_tax' => $item->unit_price_excl_tax` (net→net document line). Regen TS.
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Cart tests/Feature/Cart`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Cart apps/api/database/migrations/tenant/2026_06_03_100004_add_unit_price_excl_tax_to_catalog_cart_items.php apps/api/database/factories/CatalogCartItemFactory.php packages/shared/types/generated.d.ts
git commit -m "feat(cart): rename catalog cart unit_price -> unit_price_excl_tax"
```

### Task 12: Marketplace

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_03_100005_add_unit_price_excl_tax_to_marketplace_order_lines.php`
- Modify: `Marketplace/Domain/Models/MarketplaceOrderLine.php`, `Marketplace/Application/DTOs/MarketplaceOrderData.php`, `Marketplace/Application/Services/MarketplaceOrderService.php`

- [ ] **Step 1: Expand** `marketplace_order_lines` (add `unit_price_excl_tax decimal(15,3)`, backfill) + dual-write boot hook.
- [ ] **Step 2: Rename** the model fillable/casts, DTO, and `MarketplaceOrderService` line builders (`'unit_price' => $listing->price` → `'unit_price_excl_tax' => $listing->price`). `MarketplaceListing.price` keeps its name (it is a listing price, not a `unit_price`).
- [ ] **Step 3: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Marketplace tests/Feature/Marketplace`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/app/Modules/Marketplace apps/api/database/migrations/tenant/2026_06_03_100005_add_unit_price_excl_tax_to_marketplace_order_lines.php
git commit -m "feat(marketplace): rename order line unit_price -> unit_price_excl_tax"
```

### Task 13: Tax / pricing helpers

**Files:**
- Modify: `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:123,206`
- Modify: `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php`

- [ ] **Step 1: Inspect each callsite.** For each, determine whether the `unit_price` it consumes is a document/net value (→ `unit_price_excl_tax`) or a POS value (→ defer to Phase 2). Rename the net ones; for any POS-fed one, leave a `// Phase 2` note and handle in Task 24.
- [ ] **Step 2: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Taxation tests/Feature/Pricing`. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/Taxation apps/api/app/Modules/Pricing apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php
git commit -m "feat(tax,pricing): net unit_price -> unit_price_excl_tax"
```

### Task 14: Web (B2B) — types mirror + document features

**Files:**
- Modify: `apps/web/src/types/document.ts`
- Modify: `apps/web/src/features/documents/components/DocumentLineEditor.tsx`, the quote/invoice/PO detail pages, `CreateCreditNotePage.tsx`, `CreateReturnNotePage.tsx`, `components/PurchaseOrderLandedCostBreakdown.tsx`, `apps/web/src/features/purchases/GoodsReceiptListPage.tsx`
- Modify fixtures: `apps/web/src/features/documents/__fixtures__/*`, `apps/web/src/features/inventory/__fixtures__/productDocuments.ts`
- Modify e2e: `apps/web/e2e/sales/margin-warnings.spec.ts`, `apps/web/e2e/purchasing/additional-costs.spec.ts`

- [ ] **Step 1: Rename** `unit_price` → `unit_price_excl_tax` in the manual `document.ts` mirror and every document feature/fixture/e2e reference. (The generated `App.Modules.*` types are already correct from prior regens.)
- [ ] **Step 2: Typecheck + tests.** Run: `cd apps/web && pnpm typecheck && pnpm test && pnpm lint`. Expected: PASS. Fix any remaining references the compiler flags.
- [ ] **Step 3: Commit.**
```bash
git add apps/web/src/types/document.ts apps/web/src/features/documents apps/web/src/features/purchases apps/web/src/features/inventory/__fixtures__ apps/web/e2e
git commit -m "feat(web): document features use unit_price_excl_tax"
```

### Task 15: Phase 1 gate

- [ ] **Step 1: Full gate.** Run: `./scripts/preflight.sh` and `cd apps/api && ./vendor/bin/phpunit --filter CanonicalUnitPriceUnchangedTest` and `bash apps/pos/scripts/check-fiscal-fixture-parity.sh`. Expected: all PASS (canonical untouched).
- [ ] **Step 2: Residual scan.** Run: `git grep -nIw "unit_price" -- 'apps/api/app/Modules/Document' 'apps/api/app/Modules/Billing' 'apps/api/app/Modules/Cart' 'apps/api/app/Modules/Marketplace' 'apps/api/app/Modules/Workshop' ':!*test*'`. Expected: only intentional canonical reads (facture bridge) remain bare. Investigate any other hit.
- [ ] **Step 3: Open the Phase 1 PR** (`feat/unit-price-rename-spec` → `dev`).

---

## Phase 2 — Backend POS (inclusive) → `unit_price_incl_tax` + the seam

### Task 20: POS storage expand migrations (`pos_order_lines`, `pos_receipt_lines`)

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_03_200001_add_unit_price_incl_tax_to_pos_lines.php`

- [ ] **Step 1: Expand both tables.**
```php
public function up(): void
{
    Schema::table('pos_order_lines', function (Blueprint $t): void {
        $t->decimal('unit_price_incl_tax', 15, 3)->nullable()->after('unit_price');
    });
    DB::statement('UPDATE pos_order_lines SET unit_price_incl_tax = unit_price');

    Schema::table('pos_receipt_lines', function (Blueprint $t): void {
        $t->decimal('unit_price_incl_tax', 15, 3)->nullable()->after('unit_price');
    });
    DB::statement('UPDATE pos_receipt_lines SET unit_price_incl_tax = unit_price');
}
```
(Down: drop both columns.) **Do not** touch the existing CHECK constraints yet — they reference `unit_price`, which still exists during the dual-write window. Constraint replacement happens in the Phase 4 contract migration.
- [ ] **Step 2: Migrate + test.** Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant --database=tenant_testing`. Expected: clean.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/database/migrations/tenant/2026_06_03_200001_add_unit_price_incl_tax_to_pos_lines.php
git commit -m "feat(pos): expand pos_order_lines/pos_receipt_lines with unit_price_incl_tax"
```

### Task 21: Projection seam (TDD) — canonical `unit_price` → `unit_price_incl_tax`

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
- Modify: `apps/api/app/Modules/POS/Domain/ReceiptLine.php` (fillable/casts + dual-write hook)
- Create: `apps/api/tests/Feature/POS/ReceiptProjectionSeamTest.php`

- [ ] **Step 1: Write the failing seam test.**
```php
public function test_projection_maps_canonical_unit_price_to_incl_tax_column_verbatim(): void
{
    // Arrange: a canonical SALE_RECEIPT payload with line_items[0].unit_price = '12.000'
    $payload = $this->saleReceiptPayloadWithLineUnitPrice('12.000');
    // Act: run PosCoreReceiptProjection
    $receiptLine = $this->projectAndFetchFirstLine($payload);
    // Assert: stored under the new column, value preserved
    $this->assertSame('12.000', $receiptLine->unit_price_incl_tax);
}
```
- [ ] **Step 2: Run — expect FAIL** (column read returns null / property missing). Run: `cd apps/api && ./vendor/bin/phpunit --filter ReceiptProjectionSeamTest`.
- [ ] **Step 3: Implement.** In `PosCoreReceiptProjection`, change the write `'unit_price' => $line->unitPrice` (canonical read, key unchanged) → `'unit_price_incl_tax' => $line->unitPrice`. Add a seam comment: `// Zone-2 seam: canonical 'unit_price' (inclusive) -> backend 'unit_price_incl_tax'.` Add `unit_price_incl_tax` to `ReceiptLine` fillable/casts + dual-write hook.
- [ ] **Step 4: Run — expect PASS.** Run: `cd apps/api && ./vendor/bin/phpunit --filter ReceiptProjectionSeamTest tests/Feature/POS`.
- [ ] **Step 5: Commit.**
```bash
git add apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Domain/ReceiptLine.php apps/api/tests/Feature/POS/ReceiptProjectionSeamTest.php
git commit -m "feat(pos): seam maps canonical unit_price -> unit_price_incl_tax (receipt projection)"
```

### Task 22: `OrderLineData` DTO + `OrderLine` model

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/DTOs/OrderLineData.php`, `apps/api/app/Modules/POS/Domain/OrderLine.php`

- [ ] **Step 1: Rename** DTO prop `unit_price` → `unit_price_incl_tax`; `OrderLine` fillable/casts + dual-write hook. Regen TS.
- [ ] **Step 2: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/POS tests/Feature/POS`. Expected: PASS (some will fail until Task 23 maps the wire — that's fine if they're request/response tests; otherwise keep green).
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/POS/Application/DTOs/OrderLineData.php apps/api/app/Modules/POS/Domain/OrderLine.php packages/shared/types/generated.d.ts
git commit -m "feat(pos): rename OrderLineData/OrderLine unit_price -> unit_price_incl_tax"
```

### Task 23: Wire-contract mapping (TDD) — HTTP keeps `unit_price`

> The over-the-wire JSON key stays `unit_price` (device + clients unchanged). The backend ingress maps wire→`unit_price_incl_tax`, and resources map storage→wire.

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php`, `HoldOrderRequest.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/OrderLineResource.php`
- Create: `apps/api/tests/Feature/POS/OrderLineWireContractTest.php`

- [ ] **Step 1: Write the failing round-trip test.**
```php
public function test_wire_unit_price_persists_to_incl_tax_and_returns_as_unit_price(): void
{
    $resp = $this->postJson('/api/pos/orders/{id}/lines', ['product_id' => $pid, 'quantity' => '1', 'unit_price' => '9.500']);
    $resp->assertOk()->assertJsonPath('data.unit_price', '9.500'); // wire key unchanged
    $this->assertSame('9.500', OrderLine::latest('id')->first()->unit_price_incl_tax); // storage renamed
}
```
- [ ] **Step 2: Run — expect FAIL.** Run: `cd apps/api && ./vendor/bin/phpunit --filter OrderLineWireContractTest`.
- [ ] **Step 3: Implement the mapping.** Keep the request rule key `unit_price`; in the controller map validated `unit_price` → `unit_price_incl_tax` when building the DTO/model. In `OrderLineResource` emit `'unit_price' => $this->unit_price_incl_tax`. Add seam comments both directions.
- [ ] **Step 4: Run — expect PASS.** Run: `cd apps/api && ./vendor/bin/phpunit --filter OrderLineWireContractTest tests/Feature/POS`.
- [ ] **Step 5: Commit.**
```bash
git add apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php apps/api/app/Modules/POS/Presentation/Requests/HoldOrderRequest.php apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php apps/api/app/Modules/POS/Presentation/Resources/OrderLineResource.php apps/api/tests/Feature/POS/OrderLineWireContractTest.php
git commit -m "feat(pos): wire keeps unit_price, backend maps to unit_price_incl_tax"
```

### Task 24: POS read/compute/report/print surfaces

**Files:**
- Modify: `OrderManagementService.php:245`, `HeldOrderService.php:99`, `HeldOrder.php:195` (snapshot — see note), `OrderToReceiptService.php:49`, `ReceiptController.php:314`, `ReceiptReturnService.php:799`, `ReceiptCreationService.php`, `ReceiptFinalizationService.php`, `StoreReceiptRequest.php`, `Nf525DataProvider.php:1069`, `resources/views/pos/receipt.blade.php:398`
- Modify any Phase-1-deferred POS callsite in `TaxCalculationService`/`PricingController` (Task 13 notes)

- [ ] **Step 1: Reclassify each read.** For storage reads → `unit_price_incl_tax`. For values arriving over the wire (held-order `cart_snapshot`, `StoreReceiptRequest`) keep the wire key `unit_price` and map at ingress (same rule as Task 23). For the print view + NF525 export, read `unit_price_incl_tax` from storage. **HeldOrder snapshot note:** if held-order snapshots are stored verbatim JSON with `unit_price`, keep the snapshot key `unit_price` (it is a device wire snapshot, Zone-2 wire contract) and map only when materializing into `pos_order_lines`.
- [ ] **Step 2: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS tests/Unit/POS` plus any NF525/Z-report suite. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/POS apps/api/resources/views/pos/receipt.blade.php
git commit -m "feat(pos): read/report/print use unit_price_incl_tax (wire snapshots unchanged)"
```

### Task 25: Coupon / discount / exchange (POS, inclusive)

**Files:**
- Modify: `apps/api/app/Modules/Coupon/Presentation/Requests/ValidateCouponRequest.php`, `Coupon/Presentation/Controllers/CouponController.php`, `POS/Presentation/Controllers/DiscountController.php`, `POS/Application/DTOs/ExchangeRequestInput.php` (+ its controller/service)
- Modify: `apps/web/src/features/coupons/api/couponApi.ts`

- [ ] **Step 1: Apply the wire-contract rule.** These accept POS cart lines over the wire — keep the request/JSON key `unit_price`; treat the value as inclusive internally. No storage column here, so no rename of the wire key; add a doc comment that these are inclusive POS values. For `ExchangeRequestInput.newSaleItems[].unit_price`, keep the wire key and map to `unit_price_incl_tax` when it persists to `pos_order_lines`/receipt.
- [ ] **Step 2: Test.** Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Coupon tests/Feature/POS --filter "Discount|Exchange|Coupon"` and `cd apps/web && pnpm test --filter coupon`. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/api/app/Modules/Coupon apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php apps/api/app/Modules/POS/Application/DTOs/ExchangeRequestInput.php apps/web/src/features/coupons/api/couponApi.ts
git commit -m "feat(pos): coupon/discount/exchange documented inclusive; wire unit_price preserved"
```

### Task 26: Web POS consumes renamed inclusive fields

**Files:**
- Modify: `apps/web/src/features/pos/**` (anything reading `OrderLineData`/order/receipt responses)

- [ ] **Step 1: Update reads.** Where web POS consumed the backend `OrderLineData.unit_price` directly (not the wire response, which still returns `unit_price`), update to the new generated type field `unit_price_incl_tax`. Where it reads the HTTP response JSON, keep `unit_price` (wire contract).
- [ ] **Step 2: Typecheck + test.** Run: `cd apps/web && pnpm typecheck && pnpm test && pnpm lint`. Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add apps/web/src/features/pos
git commit -m "feat(web-pos): consume unit_price_incl_tax where reading typed DTOs"
```

### Task 27: Phase 2 gate

- [ ] **Step 1: Full gate.** Run: `./scripts/preflight.sh`, `cd apps/api && ./vendor/bin/phpunit --filter CanonicalUnitPriceUnchangedTest`, and `bash apps/pos/scripts/check-fiscal-fixture-parity.sh`. Expected: all PASS — **canonical bytes still unchanged** and the POS device (`apps/pos`) was not modified at all.
- [ ] **Step 2: Backend bare-`unit_price` audit.** Run: `git grep -nIw "unit_price" -- 'apps/api/app' ':!*test*'`. Expected hits: ONLY the Zone-1 canonical-mirror keep-list (`LineItemDTO`, `SaleReceiptCanonicalView`, `CanonicalPayloadReader`, `SaleReceiptPayload.php`, `AccountChargePayload.php`, `FiscalPayloadConstraintValidator`) and documented wire-contract ingress/egress points. Investigate anything else.
- [ ] **Step 3: Open the Phase 2 PR.**

---

## Phase 3 — Documentation

### Task 30: Update the contract docs

**Files:**
- Modify: `apps/erp/docs/architecture/precision-contract.md` (the `unit_price` section)
- Modify: `apps/erp/CLAUDE.md` (§19 `unit_price` bullet)
- Modify: monorepo `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` (path confirmed in Task 0)

- [ ] **Step 1: Rewrite the precision-contract `unit_price` section** from "deferred" to the resolved three-zone contract: backend has no bare `unit_price` (it is `unit_price_incl_tax` / `unit_price_excl_tax`); the canonical signed bytes keep `unit_price` (inclusive for SALE_RECEIPT, net for ACCOUNT_CHARGE pending its finalization correction); the POS↔backend boundary is the seam.
- [ ] **Step 2: Update CLAUDE.md §19** bullet to the same one-paragraph rule.
- [ ] **Step 3: Add a REALIGNMENT-LOG entry** recording the backend column/DTO renames (canonical DB shape change) so the platform-integration side is notified.
- [ ] **Step 4: Commit.**
```bash
git add apps/erp/docs/architecture/precision-contract.md apps/erp/CLAUDE.md
git commit -m "docs: resolve unit_price contract to the three-zone model"
```

---

## Phase 4 — Deferred contract migrations (drop old columns)

> Run only AFTER every tenant DB has deployed Phases 1–2 and read-switch is confirmed in production (spec §4 expand/contract). Separate PR.

### Task 40: Drop old columns + replace CHECK constraints

**Files:**
- Create per-table contract migrations under `apps/api/database/migrations/tenant/`, e.g. `2026_06_17_100001_drop_legacy_unit_price_columns.php`

- [ ] **Step 1: For each renamed table** (`document_lines`, `billing_invoice_items`, `catalog_cart_items`, `marketplace_order_lines`, workshop tables, `pos_order_lines`, `pos_receipt_lines`): drop the old `unit_price`/`override_unit_price` column. For `pos_receipt_lines` (and `pos_order_lines` if it has them), FIRST drop the CHECK constraints referencing `unit_price` (defined `2026_01_08_190638`, replaced `2026_03_09_200000`) and recreate them against `unit_price_incl_tax` (e.g. `line_total = (unit_price_incl_tax * quantity) - discount_amount`).
- [ ] **Step 2: Remove the dual-write boot hooks** from every model touched in Phases 1–2 (they are no longer needed once the old column is gone).
- [ ] **Step 3: Test + gate.** Run: `cd apps/api && ./vendor/bin/phpunit` and `./scripts/preflight.sh`. Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add apps/api/database/migrations/tenant apps/api/app/Modules
git commit -m "feat(db): drop legacy unit_price columns; constraints reference *_incl_tax"
```

---

## Out of scope (handed off)

- **ACCOUNT_CHARGE value correction (net → inclusive)** + facture-bridge net derivation — owned by the charge-to-account finalization (see that handoff). This plan only renames the bridge's write target; the canonical value stays net.
- **`product.sale_price` net-vs-gross** latent concern — log separately (spec §6/§10).

---

## Self-review

**Spec coverage:** Phase 1 covers every §7 Zone-3-net surface (Document, facture-bridge seam, Workshop incl. presentation, Billing, Catalog cart, Marketplace, Tax/Pricing, Web B2B). Phase 2 covers every §7 Zone-3-inclusive surface (POS order/receipt storage, projection seam, wire contract, read/report/print, coupon/discount/exchange, Web POS). Zone 1 is protected by Task 1 + the parity script run at every gate. Docs (Phase 3) and deferred drops (Phase 4) covered. Disposition items (generated TS regen per task; MigrationWizardService left as-is; baselines/diagnostics) are folded into the per-task TS regen + the residual-scan gates (Tasks 15/27).

**Placeholder scan:** no "TBD/implement later"; each task has concrete files, code, commands, and expected results. Migration scales are explicit `decimal(15,3)`.

**Type consistency:** the new names are used identically everywhere — `unit_price_excl_tax` (net), `unit_price_incl_tax` (inclusive), `override_unit_price_excl_tax` (workshop), wire key `unit_price` (unchanged). Dual-write boot hook pattern is identical across models. `DraftLineModifiedV3.unitPriceExclTax` matches its `toAudit()` key `unit_price_excl_tax`.

**Known soft spots to resolve at execution:** exact tenant-test migrate command (Task 0 Step 1 discovers it); the precise ACCOUNT_CHARGE golden fixture key (Task 1 Step 2 resolves it); whether `pos_order_lines` actually carries a CHECK constraint (Task 40 Step 1 verifies before recreating).
