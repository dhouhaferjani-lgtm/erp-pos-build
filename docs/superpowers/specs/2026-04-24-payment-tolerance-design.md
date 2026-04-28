# Payment Tolerance — Design Specification

**Status:** Design (approved for plan writing)
**Date:** 2026-04-24
**Author:** Brainstorming session with project owner
**Related documents:**
- Interface contract (v1.1): [`docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md`](../coordination/2026-04-24-payment-tolerance-shift-interface.md)
- Other-session feedback: [`docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface-v1.1-feedback.md`](../coordination/2026-04-24-payment-tolerance-shift-interface-v1.1-feedback.md)
- Parallel work: cash-counting / shift-close session

---

## 1. Problem statement

Two real-world scenarios expose a gap in AutoERP's payment-handling:

**Scenario A — rounding-size shortfall.** A customer owes €2.52 and hands the cashier €2.50 (or 2.520 TND and hands 2.500 TND). Physical coins don't exist at that precision. Today the POS rejects the tender, blocking the transaction.

**Scenario B — mark-an-invoice-paid-within-tolerance.** A B2B customer has an open invoice with a tiny residual balance (€0.30 on a €3,254.70 invoice, caused by a bank-fee deduction on the wire). The collections team wants to close the invoice cleanly instead of leaving the balance open indefinitely.

In both cases, the right fiscal treatment is a **non-VAT accounting write-off** (GL account 658, `PaymentToleranceExpense`) — not a discount (which would reduce VAT base) and not a credit note (which is reserved for post-posting adjustments that legally reduce taxable amount).

The codebase partially supports this: `PaymentToleranceService`, `CountryPaymentSettings`, GL accounts 658/758, and `GeneralLedgerService::createPaymentToleranceJournalEntry()` all exist. But the wiring is incomplete — POS rejects short-pay entirely, and the B2B side only applies tolerance through the `SmartPaymentController::applyAllocation` path, with a latent bug where the `payment_allocations.tolerance_writeoff` column is never populated.

This spec finishes the wiring and adds two related deliverables: a B2B "Close with write-off" action for scenario B, and an anti-abuse rule preventing sub-tolerance discounts from being used to launder skimming patterns.

## 2. Scope and non-scope

### In scope (this spec → one implementation plan)

- **A1** — POS cash-sale tolerance: wire `ReceiptPaymentService` to `PaymentToleranceService`.
- **A2** — B2B "Close with write-off" action on partially-paid invoices within tolerance.
- **A3** — Bug fix: persist `payment_allocations.tolerance_writeoff` on `PaymentAllocationService::applyAllocation`.
- **Anti-abuse rule** — discounts ≤ tolerance margin are blocked (`DiscountToleranceBoundary` domain service).
- **`PaymentToleranceQueryService`** — public Treasury service + DTOs for cross-module reporting (required by cash-counting session per interface contract v1.1, Flag #2).
- **Shift aggregation** — two new columns on `pos_shifts` maintained by a `PaymentRecorded` event listener.
- **Receipt rendering** — "Rounding −€0.02" line when tolerance applies.
- **Permission** — new `pos.tolerance.apply` permission; granted to Cashier role by seeder.
- **Offline POS** — rule evaluator works offline using cached country settings; server re-validates on sync.

### Out of scope (explicit, with successor)

| Deferred | Why | Successor |
|---|---|---|
| **A4** — On-account POS payment method (Odoo-style) | Larger feature; captured as direction-doc appendix §10 | Separate spec when prioritized |
| **B2B pre-posting discount UX parity** | User scoped as audit-only; backend supports, frontend has zero UX | `b2b-discount-ux-parity` spec |
| **Structured write-off reason codes** (SAP-style) | Not needed today; would require distinct GL sub-accounts | Deferred indefinitely |
| **Manager-override for over-tolerance short-pay** | Reject-at-till is correct for v1 | Revisit with A4 |
| **Per-cashier z-score fraud dashboard** | Raw data populated by this spec; dashboard is its own feature | Future LP dashboard |
| **Credit-limit enforcement in POS** | Dependency of A4 | With A4 |
| **Shift-close UI / Z-report rendering** | Owned by parallel cash-counting session | Coordinated via interface contract v1.1 |
| **Auto-migration of existing sub-tolerance discounts** | Silent data mutation is unacceptable | Pre-deploy data audit step in plan |
| **Hash-chain extension for tolerance events** | Tolerance is a non-VAT accounting adjustment, not a fiscal event (per NF525 research) | Not planned |
| **Unification of the three B2B payment controllers** | Current shape is correct (partial-payment invoices stay open) | Not planned |

### Explicit non-promises

- No changes to the three B2B payment controllers' core routing (`PaymentController`, `MultiPaymentController`, `SmartPaymentController`).
- No renaming of `tolerance_writeoff` column or GL accounts 658/758.
- No changes to POS `DiscountCalculationService`'s application logic beyond adding the boundary check (§7).

## 3. What already exists (reuse, don't rebuild)

- **`PaymentToleranceService`** (`apps/api/app/Modules/Treasury/Application/Services/`) — `checkTolerance()` and `applyTolerance()` already work end-to-end via `SmartPaymentController::applyAllocation`.
- **`CountryPaymentSettings`** table — per-country thresholds (FR €0.50 / TN 0.100 TND / 0.5%) with company override.
- **GL accounts** — `SystemAccountPurpose::PaymentToleranceExpense` (658) and `PaymentToleranceIncome` (758).
- **`GeneralLedgerService::createPaymentToleranceJournalEntry()`** — posts the B2B write-off entry (Dr 658 / Cr AR; requires non-null partner).

**Intentionally NOT reusable for POS:** the existing tolerance-journal method is B2B-AR-shaped and requires a non-null `$partnerId`. POS walk-in sales don't have a partner, and POS is direct-to-revenue (no AR). A new POS-specific method is required — see §4.
- **`payment_allocations.tolerance_writeoff`** column — exists since migration `2025_12_10_100004`, just not persisted by the allocation service (A3).
- **Frontend displays**:
  - `ToleranceSettingsDisplay.tsx` (`apps/web/src/features/treasury/`) — shows threshold config.
  - `AllocationPreview.tsx` — surfaces tolerance in preview responses.
- **Offline POS settings cache** — `country_payment_settings` is already replicated to SQLite for offline tolerance evaluation (per prior audit).

## 4. A1 — POS cash-sale tolerance

### Current behavior (reject)

`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:79` hard-rejects short-pay:

```php
if (bccomp($totalPaid, $receipt->total, self::SCALE) < 0) {
    throw new \InvalidArgumentException(
        "Total paid ({$totalPaid}) is less than receipt total ({$receipt->total})"
    );
}
```

### Target behavior

1. When `totalPaid < receipt.total`, call `PaymentToleranceService::checkTolerance($receipt->total, $totalPaid, $companyId)` (totals, not a difference — the service computes the diff internally) to determine whether the shortfall is within the applicable threshold.
2. If within threshold **and** the authenticated user has `pos.tolerance.apply` → proceed; seal the receipt as fully paid; write off the difference to GL 658.
3. If outside threshold → throw existing `ShortPaymentException` (unchanged reject behavior — cashier sees the "Complete" button disabled with inline reason, no manager override in v1).

### Why A1 does NOT call `PaymentToleranceService::applyTolerance()`

The existing `applyTolerance()` method takes a non-nullable `$partnerId` and is wired to `GeneralLedgerService::createPaymentToleranceJournalEntry()` which composes a B2B-shaped entry (Dr 658 / Cr AR). POS receipts are direct-to-revenue (there is no AR, and walk-in sales have no partner). A1 therefore:

- Uses `checkTolerance()` for the threshold check (the checker is partner-agnostic; safe to call).
- Calls a new POS-specific GL method for the journal posting (see below) — **does not** route through `applyTolerance()`.

### Change-due arithmetic fix (subtle but necessary)

`ReceiptPaymentService.php:86` currently computes:

```php
$changeDue = bcsub($totalPaid, $receipt->total, self::SCALE);
```

This is correct only because today `totalPaid >= receipt.total` is guaranteed by the reject above. Once A1 admits short-pay, it can produce a negative `change_due`. The implementation must branch:

| Condition | `change_due` | `tolerance_writeoff` |
|---|---|---|
| `totalPaid > receipt.total` | `totalPaid − receipt.total` | 0 |
| `totalPaid == receipt.total` | 0 | 0 |
| `totalPaid < receipt.total` **AND** within tolerance | 0 | `receipt.total − totalPaid` |
| `totalPaid < receipt.total` **AND** outside tolerance | — (rejects before reaching here) | — |

Post-A1, `pos_receipts.change_due` is guaranteed ≥ 0.

### GL posting approach

A1 emits two journal entries inside the same transaction:

**Entry 1 — existing `GeneralLedgerService::createPOSPaymentEntry()`**, called with `totalPaid` (the tendered amount). This method's existing composition (per `GeneralLedgerService.php:915–974`) is Dr Cash / Cr Revenue — no VAT line at this layer. VAT on POS receipts is composed elsewhere (receipt posting / document VAT details) and is not touched by the tolerance path. **Do not pass `receipt.total` here** — the cash debit must match drawer reality.

**Entry 2 — new `GeneralLedgerService::createPOSPaymentToleranceEntry()`** (new method, to be added by this spec). Signature (draft):

```php
public function createPOSPaymentToleranceEntry(
    string $companyId,
    string $receiptId,
    string $amount,          // decimal string, scale 3
    string $currency,
    DateTimeImmutable $date,
): JournalEntry;
```

Composition: Dr 658 PaymentToleranceExpense for `$amount`, Cr Revenue for `$amount`. No AR, no partner required. POS-shaped mirror of the B2B method.

**Net effect of the two entries:** revenue credited for the full `receipt.total` (entry 1 credits `totalPaid`, entry 2 credits `receipt.total − totalPaid`), cash debited for `totalPaid`, 658 absorbs the shortfall. **VAT on `receipt.total` remains untouched.** This is the fiscally correct treatment — confirmed by NF525 / SAP / NetSuite / Odoo research; tolerance is not a VAT-reducing event.

### Tolerance persistence and shift aggregation (no event modification)

Rule #8 says events are immutable — no renames, no restructure. To avoid any interpretation risk, **we do not modify `PaymentRecorded`**. Instead:

- New column `pos_receipts.tolerance_writeoff DECIMAL(15,3) NULL`, populated inline by `ReceiptPaymentService` when tolerance applies (null otherwise).
- Shift aggregation (`pos_shifts.tolerance_writeoff_total` / `_count` — see §9) is incremented **synchronously inside the same DB transaction** as the receipt/payment creation, via a pessimistic lock on the shift row. No event listener, no eventual-consistency window.
- B2B side: `payment_allocations.tolerance_writeoff` (existing column) is the persistent store for B2B tolerance — already populated for A2 and now persisted correctly by A3.
- `PaymentToleranceQueryService` (current scope: POS-shift queries only, per interface contract v1.1) reads from `pos_receipts.tolerance_writeoff` joined to `pos_shifts`. B2B tolerance reporting is not part of this service's v1 surface; if a future feature needs partner-scoped tolerance reports, the canonical source is the `journal_entries` table (filtering on GL 658 with `source_type = 'payment_tolerance'`) and/or the `InvoiceClosedWithTolerance` event log.

### Authorization

`StoreReceiptPaymentsRequest::authorize()` gains a conditional check: if the request's computed `totalPaid < receipt.total`, require `pos.tolerance.apply`. Exact-tender and overpay flows are unchanged.

### Files touched (A1)

- `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php` (replace lines 79–86 with tolerance branch + corrected change-due arithmetic; add tolerance GL entry creation; in-transaction shift increment)
- `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php` (authorize)
- `apps/api/app/Modules/POS/Domain/Receipt.php` (fillable + casts for new `tolerance_writeoff` column)
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (new method `createPOSPaymentToleranceEntry`)
- `apps/api/database/migrations/<new>_add_tolerance_writeoff_to_pos_receipts_table.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (new permission)
- `apps/web/src/features/pos/components/Receipt*.tsx` (rendering — identified in plan)
- Translation files (see §12)

## 5. A2 — B2B "Close with write-off"

### Entry point (UX)

On the Invoice detail page (`apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx`), a new button appears in the header actions when:

- `invoice.status == Posted`, AND
- `invoice.balance_due > 0`, AND
- `invoice.balance_due ≤ tolerance_margin` (computed per the partner's company + country settings)

Button label: **"Close with write-off €X.XX"** (NetSuite / SAP terminology). Currency formatted via existing `useCurrency` hook.

### Dialog

Pure confirmation modal (no free-text reason field — per user decision that write-off-is-write-off; structured reason codes deferred per §2):

```
Close invoice INV-2026-0042
Remaining balance:    €0.30 will be written off
GL account:           658 Payment Tolerance Expense

[Cancel]  [Close invoice]
```

Submit is irreversible at the UI layer, consistent with posting semantics.

### Endpoint

```
POST /api/v1/invoices/{invoice}/close-with-tolerance
Body: {}   (no body; action is invoice-scoped)
Response: { data: { invoice: InvoiceData } }  (standard response envelope)
```

Lives in `InvoiceController` (Document module) — not Treasury — because the action is invoice-scoped. Controller delegates to a new service.

### Service: `CloseInvoiceWithToleranceService`

Location: `apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php` (Treasury owns the action because tolerance logic is Treasury-owned; Document calls in via constructor injection per Rule #13).

Algorithm:

1. Open DB transaction.
2. Pessimistically lock the invoice (`lockForUpdate` on `documents` row — matches the invoice-posting pattern in `.claude/context/architecture.md`).
3. Re-read `balance_due` AND `status` (guards against race with concurrent payment).
4. **Idempotency check**: if `status == Paid` OR `balance_due == 0` → 422 `ALREADY_PAID`. No side effects.
5. Call `PaymentToleranceService::checkTolerance($invoice->total, $invoice->total - $balance_due, $companyId)` to re-verify the remaining balance is within threshold. If over → 422 `TOLERANCE_EXCEEDED` with `remaining_balance` + `threshold` in details.
6. Call `GeneralLedgerService::createPaymentToleranceJournalEntry($invoice, $balance_due, ...)` (existing method; B2B-shaped; partner derived from invoice). This posts Dr 658 / Cr AR — AR is reduced, cash isn't touched (no cash movement in a pure write-off).
7. Update `invoice.balance_due` and `invoice.status` — **implementation note:** `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:114` mentions a Postgres trigger that updates `balance_due` from `payment_allocations`. A2 does NOT create an allocation (there is no Payment / allocation — just a journal entry), so the trigger will not fire. Update `balance_due = 0` and `status = Paid` explicitly from A2. Plan must verify the trigger's exact firing conditions and confirm no collision.
8. Emit a new event `InvoiceClosedWithTolerance { invoiceId, amountWrittenOff, closedBy, occurredAt }` — immutable from day one, no prior version to restructure. Contents hashable for audit-chain inclusion consistent with other payment-adjacent events.
9. Commit transaction.

**No Payment row, no PaymentAllocation row.** The B2B tolerance close is purely a journal-entry + invoice-state adjustment. This avoids the existing `Payment.amount > 0` invariant (enforced implicitly by branches at `PaymentAllocationService.php:164, 184`) and the `PaymentAllocation.payment_id` non-null FK. Audit trail comes from the journal entry + the `InvoiceClosedWithTolerance` event. No `PaymentType` enum change is required in A2.

### Idempotency

A second call on the same invoice (now `status = Paid`) returns 422 `ALREADY_PAID`. No side effects.

### Permission

Reuses existing `payments.allocate`. No new permission. Rationale: anyone trusted to allocate payments in B2B is trusted to close with tolerance; audit trail + GL visibility + Z-report aggregation are the controls.

### Files touched (A2)

- `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php` (new action)
- `apps/api/app/Modules/Document/routes.php` (new route)
- `apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php` (new)
- `apps/api/app/Modules/Treasury/Domain/Events/InvoiceClosedWithTolerance.php` (new event — immutable from v1)
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx` (button + dialog)
- `apps/web/src/features/documents/invoices/api/` (new mutation hook)
- Translation files (see §12)

## 6. A3 — Persist `payment_allocations.tolerance_writeoff`

### Current state

Migration `apps/api/database/migrations/2025_12_10_100004_add_tolerance_to_payment_allocations_table.php` created the column. `PaymentAllocationService::applyAllocation` calls `PaymentToleranceService::applyTolerance()` when a write-off is due — the GL entry gets created, but the allocation row itself never records the `tolerance_writeoff` amount. This is a latent bug that A2 relies on (A2's queries read this column for reporting).

### Fix

In `PaymentAllocationService::applyAllocation()`, the `PaymentAllocation::create([...])` call (currently ~line 99–103) must include:

```php
'tolerance_writeoff' => $allocation['tolerance_writeoff'] ?? null,
```

A regression test asserts the column is populated when `applyTolerance` runs.

### Backfill

Not attempted. Existing rows with `tolerance_writeoff = NULL` remain as-is (these are from the SmartPayment path and any write-off amount is already reflected in the GL journal — the column was a redundant denormalization). Forward-going rows will be correct. The cash-counting session is informed via the interface contract that `tolerance_writeoff` is authoritative post-fix.

### Files touched (A3)

- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`
- `apps/api/tests/Unit/Treasury/PaymentAllocationServiceTest.php` (regression test)

## 7. Anti-abuse rule — discount/tolerance boundary

### Rule

A discount (line-level or header-level) is invalid if its computed absolute amount is less than or equal to the applicable payment-tolerance margin for the same transaction:

```
tolerance_margin(subtotal, settings) = max(settings.max_amount, subtotal × settings.percentage)
discount valid iff discount_amount > tolerance_margin(subtotal, settings)
```

### Rationale

Without this rule, a cashier or collector can use a sub-tolerance discount to hide skimming while also illegitimately reducing VAT base. The rule closes the sub-tolerance arbitrage specifically; it does not prevent all discount abuse (above-tolerance fake discounts require a different control — per-cashier discount-density metrics, captured by future LP dashboard work).

### Application service (public, cross-module-callable)

```php
namespace App\Modules\Treasury\Application\Services;

final class DiscountToleranceBoundary
{
    public function __construct(
        private readonly ToleranceSettingsResolver $settingsResolver,
    ) {}

    public function assertDiscountAboveTolerance(
        string $discountAmount,      // decimal string, scale 3
        string $subtotal,            // decimal string, scale 3
        string $companyId,
    ): void;
    // throws DiscountBelowToleranceException
}
```

**Placement rationale:** Per architecture.md §"Cross-Module Communication", cross-module access is via `Shared/Contracts/` interfaces, Events, or **a module's public Service class**. The Application layer is where a module's public callable surface lives (Domain is internal to the module by convention). Placing `DiscountToleranceBoundary` in `Treasury/Application/Services/` — consistent with `PaymentToleranceQueryService` (§8) and `CloseInvoiceWithToleranceService` (§5) — gives us one coherent pattern for cross-module entry points into Treasury. No `Shared/Contracts/` interface is introduced here; one can be added later if mocking at the boundary becomes useful for POS-side tests.

Internally the service does not call `PaymentToleranceService::checkTolerance` — that method returns an array (not a typed DTO), which would pull `mixed` into our validator. The boundary computation is a simple comparison: `max($settings->maxAmount, bcmul($subtotal, $settings->percentage, 3)) < $discountAmount`. No coupling to the existing tolerance-check return shape.

### Call sites

- `apps/api/app/Modules/POS/Application/Services/DiscountCalculationService.php` — injects the service via constructor (Rule #13), asserts before applying each discount (line and transaction).
- `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php` and `UpdateDocumentRequest.php` — new Laravel validation rule class `DiscountAboveTolerance` that injects the Treasury service and invokes it on every line discount and the header discount.

### Activation scope across DocumentTypes

The rule fires only where a **payment is actually becoming due**. Quotes are still negotiation-stage, credit notes move money outward (no skimming vector), delivery notes carry no payment obligation:

| DocumentType | Rule applies? | Reason |
|---|---|---|
| **Quote** | ❌ No | Pure negotiation; no payment due; no skimming vector |
| **SalesOrder** | ✅ Yes | Payment becomes due on confirmation |
| **Invoice** (Draft / Confirmed) | ✅ Yes | Payment obligation; primary abuse surface |
| **CreditNote** | ❌ No | Money flows outward; a tiny discount on a credit note would reduce the refund amount (opposite direction — no skimming vector) |
| **DeliveryNote** | ❌ No | Delivery-only, no payment implication |

Posted documents are read-only per architecture.md; the rule cannot fire on them.

### Document-conversion behavior (catches the Quote loophole)

Quotes skip the rule at creation time, so a Quote **could** be created with a sub-tolerance line discount. When that Quote converts into a SalesOrder (or directly to an Invoice, depending on workflow), the conversion path must handle it:

**Chosen behavior: auto-convert with explicit message** (not block-and-force-manual).

On conversion, for each line with `discount_amount ≤ tolerance_margin(line_subtotal, settings)`:
1. Set the target document's line `discount_amount = 0` and `discount_percent = 0` (the discount is removed).
2. The residual (originally intended as a discount) will naturally flow through as an amount due — and will be absorbed by the payment-time tolerance flow (A1 at the POS / SmartPayment allocation on B2B) when the customer tenders.
3. Surface a user-visible notification on the conversion result, listing affected lines with original values, like:

   > "2 line discounts under the €0.50 tolerance margin were removed during conversion. The residual will be handled as payment tolerance at settlement time. Lines: A1-203 (€0.02), A2-105 (€0.30)."

4. The conversion audit event (existing document-to-document linkage via `source_document_id`) logs the adjustment for traceability.

**Why auto-convert instead of block:** blocking would force the user to open the Quote, fix each tiny discount, and retry — bad UX for a case where the intent is obvious (small discount meant as a goodwill concession, will be honored automatically via payment tolerance). Auto-conversion preserves intent while steering data to the correct field. The notification ensures the user isn't surprised.

**Where this lives:** in the `DocumentConversionService` (or equivalent in `Document/Application/Services/` — plan author verifies exact service name). The conversion service injects `DiscountToleranceBoundary` and strips offending discounts before persisting the target document. The notification payload is surfaced in the conversion response and displayed by the frontend conversion action.

**Edge case:** if the Quote→Invoice conversion path bypasses SalesOrder (direct conversion), the same stripping applies there too. Plan author confirms all conversion entry points.

Future B2B discount UX (out of scope for this spec) plugs into the same validation point; no duplicated rule logic.

### Frontend mirror

A TypeScript function `isDiscountAboveTolerance(discount, subtotal, settings)` in `apps/web/src/features/pos/lib/discountValidation.ts` — 2-line comparison using the `ToleranceSettings` type (generated from backend DTO). Used for inline UX feedback before the API round-trip. Backend re-validates authoritatively.

### Error messages

Two locale keys:

- `pos.discount.below_tolerance` (cashier context): "Discounts this small must be handled as payment tolerance at the till."
- `documents.discount.below_tolerance` (B2B context): "Discounts this small must be handled via write-off when closing the invoice."

### Data audit

The implementation plan includes a one-time audit step to scan seeders (`DemoTenantSeeder`, `CoffeeShopSeeder`, etc.) and any existing fixture data for `discount_amount` values that would fail the new rule. Violations are either updated to round-number discounts or flagged for the data owner. No silent migration of production/staging data.

## 8. `PaymentToleranceQueryService` — public Treasury service

Required by the cash-counting / shift-close session per interface contract v1.1 Flag #2. Per Rule #6, cross-module data access goes through a public Service class.

### Location

`apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php`

### Public methods

```php
public function totalForShift(string $shiftId): TolerancePaymentTotalsDTO;
public function breakdownForShift(string $shiftId): array;         // TolerancePaymentBreakdownDTO[]
public function receiptsWithToleranceForShift(string $shiftId): array;  // TolerancePaymentReceiptDTO[]
```

### DTOs (all annotated `#[TypeScript]`)

- `TolerancePaymentTotalsDTO` — `{ totalAmount: string, currencyCode: string, writeoffCount: int }`
- `TolerancePaymentBreakdownDTO` — adds `userId`, `userName` to the above
- `TolerancePaymentReceiptDTO` — per-receipt drill-down: `receiptNumber`, `userId`, `userName`, `writeoffAmount`, `currencyCode`, `occurredAt` (ISO 8601)

All monetary fields are decimal strings at scale 3 (TND parity). Exact signatures frozen in interface contract v1.1 §"Public Treasury service".

### Performance

First implementation uses direct Eloquent joins inside the service (cross-module SQL stays encapsulated). If Z-report latency becomes an issue, a materialized view can replace the implementation without consumer impact.

### Files touched

- `apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php` (new)
- `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentTotalsDTO.php` (new)
- `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentBreakdownDTO.php` (new)
- `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php` (new)
- `apps/api/tests/Unit/Treasury/PaymentToleranceQueryServiceTest.php` (new)

Running `php artisan typescript:transform` after landing the DTOs publishes the types to `packages/shared/types/`.

## 9. Shift aggregation

Fields added to `pos_shifts`:

```sql
ALTER TABLE pos_shifts
    ADD COLUMN tolerance_writeoff_total DECIMAL(15,3) NOT NULL DEFAULT 0,
    ADD COLUMN tolerance_writeoff_count INTEGER       NOT NULL DEFAULT 0;
```

**Maintenance: synchronous, in-transaction.** `ReceiptPaymentService` (A1), at the moment it creates the tolerance GL entry, acquires a `lockForUpdate` on the relevant `pos_shifts` row and increments both columns before committing the surrounding transaction. No event listener, no eventual consistency. Lock contention risk is low (shifts are per-user-per-terminal, so simultaneous writes to the same row are rare).

Reporting access is through `PaymentToleranceQueryService` — the cash-counting session does not query `pos_shifts.tolerance_writeoff_*` directly; the columns exist as fast aggregates alongside the canonical source (`pos_receipts.tolerance_writeoff` per receipt). Either data source would yield the same number; the shift columns are optimization + convenience for shift-close UX.

### Files touched

- `apps/api/database/migrations/<new>_add_tolerance_to_pos_shifts_table.php`
- `apps/api/app/Modules/POS/Domain/Shift.php` (fillable + casts; add a domain method `Shift::applyToleranceWriteoff(string $amount): void` that encapsulates the atomic increment — called from `ReceiptPaymentService` inside the locked transaction)
- (No listener file needed — handled inline by `ReceiptPaymentService`)

## 10. A4 direction appendix (non-binding)

Captured separately so that on-account POS work (the real answer to "untangling B2B/B2C in POS") has a documented starting point informed by industry research, without being committed to this spec.

### Proposed model

**"On Account" as a POS payment method** (Odoo pattern — cleanest fit for existing `ReceiptPaymentService` shape). Treats the deferred-payment choice as a method selected at checkout rather than a fork in the sale type. Cashier UX stays identical; only the resulting journal differs.

### Gating conditions (all must hold)

- Receipt has `partner_id != null` (walk-in cannot use on-account)
- **Partner entity type is company/business** (not individual contact) — per user clarification: on-account applies to companies first; later extensible to user accounts when credit lines to individuals are granted
- `partner.payment_terms != Immediate`
- `partner.credit_balance + on_account_amount ≤ partner.credit_limit` (reuse existing `Partner::hasActiveCreditLimit()`)

### Mixed tendering

Supported. €2,520 receipt tendered as €500 cash + €2,020 on-account:
- Cash portion through today's direct-to-revenue path
- On-account portion: Dr AR (411 or country-equivalent per `SystemAccountPurpose`), Cr Revenue + VAT
- Receipt still sealed with full `total`; hash chain unchanged
- A1 tolerance applies to cash portion only; on-account portion has no tolerance (it's AR, settled later via A2-class flows)

### Permission

New `pos.on-account.apply`, distinct from `pos.tolerance.apply` and `can_discount` (three philosophically different financial decisions).

### Open questions (deferred to A4's own spec)

1. Does "On Account" require the partner's primary contact on the receipt (for fiscal receipt-addressed compliance), or is the partner record alone sufficient?
2. How does terminal type (IziPOS vs Otospex) affect allowed payment methods — should on-account default-off for small-retail terminals?
3. Customer PIN / signature at the till for on-account authorization?
4. Race condition when partner crosses credit limit mid-sale across two terminals — reservation pattern on checkout start?
5. Later: extending on-account to individual user accounts — policy gate, underwriting flow, credit-line ceiling.

### Not in this spec

No code, no migration, no permission, no UX. Strictly a direction doc for the next brainstorming session to pick up.

## 11. Compliance & fiscal posture

- **VAT treatment**: tolerance write-offs do **not** reduce taxable base. GL entry posts to 658 (expense) or 758 (income) — separate from revenue and VAT accounts. This aligns with NF525 ISCA principles (inalterability of sealed fiscal events) and with SAP/NetSuite/Odoo industry practice.
- **Hash chain**: tolerance write-offs are **not** new fiscal events. They are accounting adjustments logged to the audit chain via the existing `PaymentRecorded` / `PaymentAllocated` events (TimescaleDB side, not the SHA-256 fiscal chain). Hash chain is untouched.
- **Legal short-pay on POS ticket**: NF525 research confirms a POS ticket can be sealed with an outstanding residual going to AR (the basis for A4). The current POS "must tender in full" constraint is a product choice, not a compliance requirement. A1 preserves the seal — the receipt is sealed as fully paid with the €0.02 gap absorbed by 658.
- **Credit note path remains unchanged**: any post-posting adjustment that should legally reduce VAT still goes through `CreditNoteService` (`PRICE_ADJUSTMENT` reason). Tolerance is not an alternative to credit notes — it's for a different class of adjustment (small rounding / bank-fee-scale residuals, never a negotiated discount).

## 12. Internationalization

Per Rule #11 (no hardcoded frontend strings), all new user-facing text uses `t()` keys:

- `pos.receipt.rounding` — "Rounding" (FR: "Arrondi", AR: "تقريب", IT: "Arrotondamento", etc.)

  > **Implementation note (Phase 2):** the receipt label shipped under the
  > `pos.receiptLabel.rounding` namespace, alongside every other receipt
  > label (`receiptLabel.changeDue`, `receiptLabel.payments`, …). The
  > `receiptLabel.*` grouping is the established pattern for ESC/POS thermal
  > receipt labels and is internally consistent. This spec entry is kept as
  > the canonical key name for documentation purposes; treat the
  > `receiptLabel.*` placement as the de-facto convention for any future
  > thermal-receipt label additions.
- `pos.tolerance.short_pay_accepted` — optional toast-style feedback (used if a cashier-visible acknowledgement is wanted — per §4 the primary UX is silent accept; deferred to implementation whether a toast is included)
- `pos.discount.below_tolerance` — discount boundary error at till
- `documents.discount.below_tolerance` — discount boundary error in B2B
- `documents.invoice.close_with_writeoff.button` — "Close with write-off"
- `documents.invoice.close_with_writeoff.dialog.title`
- `documents.invoice.close_with_writeoff.dialog.confirm`
- `documents.invoice.close_with_writeoff.dialog.cancel`

Any i18n-namespace addition follows `add-i18n-namespace.md` procedure (imports + resources + ns array in three places — per the pitfall noted in project memory).

## 13. Offline-first considerations

- POS tolerance evaluation runs client-side in Tauri/SQLite when offline, using cached `country_payment_settings` (already replicated per existing offline architecture).
- Client-side `DiscountToleranceBoundary` mirror is a 2-line comparison in TypeScript, same thresholds.
- Server re-validates on sync — authoritative. If the client approved a tolerance write-off using stale cached settings (rare — settings rarely change), the server will re-evaluate; if it fails server-side, the receipt is rejected on sync and surfaced to the cashier per existing offline-sync error-handling patterns.
- No change needed to the sync protocol; tolerance write-off data rides with the existing receipt payload (the `ReceiptPaymentService` is already called by the sync handler).

## 14. Permission model

| Permission | Who it gates | Seeder behavior |
|---|---|---|
| `pos.tolerance.apply` | A1 — short-pay within tolerance at the till | Granted to `Cashier` role by default (industry norm per research; separate permission for future tightening) |
| `payments.allocate` | A2 — B2B close-with-writeoff (existing permission, reused) | No change to seeder |
| (no permission) | Anti-abuse rule validation (§7) | Rule runs for all callers regardless of permission |

Permission added to `apps/api/database/seeders/RolesAndPermissionsSeeder.php`.

## 15. Testing strategy

### Unit tests (Domain + Application services)

- **`DiscountToleranceBoundary`** — boundary cases: equal-to-margin rejects, just-above accepts, per-currency scale (TND/EUR), percentage-based threshold, zero inputs. ~6 cases.
- **`PaymentAllocationService::applyAllocation`** (A3 regression) — verify `payment_allocations.tolerance_writeoff` is now persisted on the allocation row when `applyTolerance()` fires. 1 case.
- **`ReceiptPaymentService`** — short-pay within tolerance accepts + creates dual GL entries; over tolerance rejects with `ShortPaymentException`; exact tender unchanged; overpay unchanged; permission-denied path. ~5 cases.
- **`CloseInvoiceWithToleranceService`** — balance within threshold succeeds; balance over threshold rejects (422 `TOLERANCE_EXCEEDED`); balance equal to threshold rejects (strict inequality); already-paid invoice rejects (422 `ALREADY_PAID`); lock-contention path. ~5 cases.
- **`PaymentToleranceQueryService`** — shift with multiple cashiers and mixed tolerance receipts; shift with no tolerance (returns zero totals); non-existent shift (empty result). ~4 cases.

### Feature tests (controllers)

- **POS payment flow**: `POST /api/v1/pos/receipts/{id}/payments` with short-pay within tolerance → 200 + dual journal entries; over tolerance → 422; missing `pos.tolerance.apply` → 403; exact tender unchanged.
- **B2B close with write-off**: `POST /api/v1/invoices/{id}/close-with-tolerance` — happy path, over-threshold, already-paid idempotency, missing permission.
- **Discount-boundary rule on B2B**: `POST /api/v1/invoices` with line `discount_amount = 0.01` (EUR) → 422 with `discount_below_tolerance` error; above-threshold discount succeeds.
- **Discount-boundary rule DOES NOT fire on out-of-scope types**: `POST /api/v1/quotes` with a sub-tolerance line discount → 200 (allowed); `POST /api/v1/credit-notes` same → 200; only SalesOrder/Invoice trigger the rule.
- **Document conversion — auto-strip sub-tolerance discounts**: Quote with a €0.02 line discount → convert to SalesOrder → resulting SalesOrder line has `discount_amount = 0`; conversion response includes a notification payload listing the stripped discounts; downstream payment flow absorbs the €0.02 via tolerance at settlement.
- **Shift aggregation listener**: after a tolerance-using POS receipt is persisted, `pos_shifts.tolerance_writeoff_total` and `_count` are incremented.

### Frontend component tests

- **`ReceiptRender`** — renders "Rounding −€0.02" only when `tolerance_amount > 0`; hidden at zero; locale-aware currency formatting.
- **`CloseWithWriteoffDialog`** — renders invoice + balance + acct 658 labeling; confirm disabled when balance over threshold; calls endpoint on confirm.
- **`DiscountInput` (POS)** — inline error at sub-threshold discount; preset buttons disabled when they would compute sub-threshold for the current subtotal.

### E2E (Playwright)

- Happy-path short-pay within tolerance: cashier adds €2.52 item → tenders €2.50 → transaction completes → receipt shows "Rounding −€0.02" → shift aggregate increments.
- Happy-path B2B close-with-writeoff: AR clerk opens invoice with residual balance → clicks "Close with write-off" → confirms → invoice status becomes Paid → shown in invoice history.
- Guardrail: cashier tenders over-tolerance amount → "Complete" button stays disabled with clear messaging.
- Permission guardrail: cashier without `pos.tolerance.apply` attempts short-pay → reject with explicit permission error.
- Discount-boundary: cashier tries to apply €0.02 discount → inline error; 0.10% discount on a €1 sale → rejected.

### Pre-deploy data audit

Single console command under the Treasury module at `apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php`:

```bash
php artisan tolerance:audit-discounts --dry-run
```

Reports any `document_lines.discount_amount` or `documents.discount_amount` values ≤ the applicable tolerance margin (resolved per the owning document's company/country settings). Zero violations required before the anti-abuse validation rule (§7) is activated. Registered in the Treasury `ServiceProvider` via `$this->commands([...])`.

### Coverage expectations

- PHPStan level 8 clean on all touched files (no `mixed`).
- `RefreshDatabase` + `RolesAndPermissionsSeeder` in every feature test (per testing memory).
- No mocks for API flow tests; real DB + seeded permissions.

## 16. Implementation ordering hints (for plan author)

Recommended task order (not prescriptive — plan author finalizes):

1. **A3 first** (smallest, unblocks everything else). Write regression test → fix `PaymentAllocationService` → run test.
2. **`DiscountToleranceBoundary` domain service** (pure, testable in isolation; used by A1, A2, and anti-abuse rule).
3. **`PaymentToleranceQueryService` + DTOs** (new Treasury Application service; required by cash-counting session's consumption).
4. **A2 backend** (`CloseInvoiceWithToleranceService` + endpoint). No UI yet.
5. **A1 backend** (`ReceiptPaymentService` rewire + change-due fix + event enrichment + permission seeder + shift listener).
6. **A2 frontend** (invoice detail button + dialog + mutation hook).
7. **A1 frontend** (receipt "Rounding" line + inline sub-threshold error in discount input).
8. **Anti-abuse rule backend** (`CreateDocumentRequest` / `UpdateDocumentRequest` validation; POS `DiscountCalculationService` integration).
9. **Anti-abuse rule frontend** (mirror TS function + inline UX).
10. **Translations** (en, fr, ar, it — all at once to avoid drift).
11. **Data audit** script.
12. **E2E tests**.

Every step is a separate PR candidate in dev-branch workflow (per `feedback_dev_branch_workflow.md`), merged into `dev` not `main`.

## 17. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Existing fixture data has sub-tolerance discounts that will break seeders | Medium | Low (test-only) | Data-audit step before anti-abuse rule ships |
| `pos_receipt_payments.amount` is actually scale 2, not 3, inconsistent with TND needs post-tolerance | Low | Medium (TND precision loss) | Plan verifies scale; widens column if needed (a scale-3 retrofit migration `2026_03_11_200000_widen_monetary_columns_to_scale_3.php` already exists in codebase) |
| The three B2B payment-recording controllers drift — A2 fixes one path, others silently diverge | Low | Low | A2 uses the same existing `GeneralLedgerService::createPaymentToleranceJournalEntry` (B2B-shaped) as the SmartPayment path; no divergence introduced by this spec |
| Offline POS caches stale tolerance settings, approves a receipt server will reject | Low | Low | Standard sync-error UX surfaces the reject; settings rarely change; cache is refreshed at login |
| Interface contract with cash-counting session drifts during implementation | Low | Medium | v1.1 contract explicitly versioned; breaking-change discipline documented; either session bumps + notifies if needed |

## 18. Open questions (for plan author to resolve before coding)

These are intentionally left for the plan author since they require hands-on code verification rather than design decisions:

1. **`balance_due` trigger exact firing condition** — `PaymentAllocationService.php:114` comments that Postgres updates `balance_due` automatically. Plan author verifies the trigger's definition, confirms it does not fire on the A2 direct-update path (since A2 creates no allocation), and decides whether the manual update in A2 step 7 is redundant or essential.

2. **`DocumentType` exhaustive list for anti-abuse rule activation (§7)** — plan author greps `DocumentType` enum cases and confirms which carry discountable lines. DeliveryNote is the case most likely to be a no-op.

3. **Scale of `pos_receipt_payments.amount`** — `decimal(12,2)` per the create migration. A later retrofit `2026_03_11_200000_widen_monetary_columns_to_scale_3.php` may or may not have touched it. Plan author verifies; if still scale 2, adds a widen step (symmetric with the pattern already in use).

4. **Refund / receipt-reversal interaction with tolerance** — if a receipt with a tolerance write-off is later voided/refunded, what happens to the 658 entry and the `pos_receipts.tolerance_writeoff` value? Almost certainly needs an inverse journal entry on refund. Plan author either scopes this in or documents it as a follow-up ticket.

5. **`createPaymentToleranceJournalEntry` signature compatibility with A2** — the existing method (per prior code audit) takes a `Payment` + `PaymentAllocation` combined shape. Plan author verifies the exact signature and either (a) reuses the method if it accepts the A2-shaped call (zero-amount Payment + PaymentAllocation — see §5), or (b) introduces a small variant that accepts those zero-amount rows cleanly.

6. **POS tolerance GL account verification** — this spec defaults to the existing `SystemAccountPurpose::PaymentToleranceExpense` (acct 658) for both POS and B2B paths. Some accounting jurisdictions prefer a dedicated subaccount for POS cash-rounding losses (e.g. French PCG 6588, Nordic "öresdifferens" account) to separate them from B2B miscellaneous write-offs in analytical reporting. Plan author confirms with the accounting team before A1 ships. If a dedicated account is wanted, add a new `SystemAccountPurpose` value (e.g. `POSCashRoundingExpense`) and wire it via the `createPOSPaymentToleranceEntry` method from §4 — trivial change, affects only GL account lookup. Default behaviour (reuse 658) is shippable if no accountant feedback by deploy date.

Any question that arises during plan writing or implementation AND affects both sessions goes into the interface contract's "Open coordination questions" section with a notification to the cash-counting session.

## 19. References

- Interface contract (v1.1): `docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md`
- Other-session feedback: `docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface-v1.1-feedback.md`
- Architecture rules: `apps/erp/.claude/context/architecture.md`
- Compliance context: `apps/erp/.claude/context/compliance.md`
- Smart payment spec (historical): `docs/_archive/specs/SMART-PAYMENT-FEATURES-SPEC-V2.md`
- Project memory — monetary precision: `~/.claude/projects/.../memory/project_monetary_precision.md`
- Project memory — architecture placement check: `~/.claude/projects/.../memory/feedback_architecture_placement_check.md`

---

**End of design specification.**
