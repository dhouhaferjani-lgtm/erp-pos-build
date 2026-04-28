# Shift ↔ Payment Tolerance Interface Contract

**Purpose:** This document is the interface contract between two parallel development sessions working on adjacent features. It is designed to be self-contained — a reader does not need access to the full tolerance spec to implement against it.

**Version:** 1.1 (2026-04-24) — incorporated cash-counting session feedback
**Producer:** Payment tolerance session (spec: `docs/superpowers/specs/2026-04-24-payment-tolerance-design.md`)
**Consumer:** Cash-counting / shift-close session

## Changelog

- **v1.1 (2026-04-24)**
  - Fixed table name `shifts` → `pos_shifts` throughout (Flag #1 accepted).
  - Added `PaymentToleranceQueryService` as the public Treasury service for cross-module reporting, with stable DTOs (Flag #2 accepted).
  - Simplified cash-variance formula: removed the `− Σ tolerance_writeoffs` term. Persistent column is `pos_receipt_payments.amount` (NOT NULL), which already stores the tendered amount — no null fallback needed and no tolerance subtraction needed. Replaced fictitious `payments.amount_tendered` references (Flag #3 resolved with a simpler truth than either session initially articulated).
  - Noted subtlety in A1: `ReceiptPaymentService` change-due calculation must branch on short-pay-within-tolerance vs. overpay to avoid negative `change_due`.
- **v1.0 (2026-04-24)** — initial.

## Why this contract exists

The tolerance session adds payment-tolerance write-offs to both POS and B2B flows. Tolerance write-offs affect shift-close reporting and GL posting. Without a clear contract between the tolerance session and the parallel cash-counting / shift-close session, the Z-report and expected-cash math could either duplicate work or drift out of sync.

Both sessions proceed in parallel. This contract captures the interface so the shift-close session can design its UI and math against a stable spec, and the tolerance session has a clear boundary for what it delivers.

## Feature summary for context

The tolerance session implements:

- **A1** — POS cash-sale tolerance. Cashier tenders €2.50 on a €2.52 receipt. If the shortfall is within the per-country tolerance threshold (France €0.50 / Tunisia 0.100 TND / 0.5%), the transaction completes, with €0.02 written off to GL account 658 (`PaymentToleranceExpense`). VAT is not reduced — the write-off is a non-VAT accounting loss.
- **A2** — B2B "Close with write-off" action on partially-paid invoices when remaining balance is within tolerance. Clicking the button on an invoice detail page closes the invoice and books the shortfall to the same GL account.
- **A3** — Bug fix: persist the `tolerance_writeoff` column on `payment_allocations` rows (the column exists in a prior migration but was never written).

Out of scope for A1–A3 (covered elsewhere): on-account POS payment methods (deferred, separate spec), B2B discount UX parity (separate spec), structured reason codes, credit-limit enforcement.

## Fields added by the tolerance session

### Table: `pos_shifts` (existing, POS module)

Two new columns:

```sql
ALTER TABLE pos_shifts
    ADD COLUMN tolerance_writeoff_total DECIMAL(15,3) NOT NULL DEFAULT 0,
    ADD COLUMN tolerance_writeoff_count INTEGER       NOT NULL DEFAULT 0;
```

Scale 3 for parity with Tunisian Dinar (ISO 4217). Defaults to zero so existing shifts remain valid after migration.

**Maintenance:** Incremented synchronously inside the same DB transaction that creates the tolerance GL entry, via `lockForUpdate` on the `pos_shifts` row. No event listener, no eventual-consistency window. Owned by the tolerance session (inline inside `ReceiptPaymentService`). Rationale: avoids modifying the `PaymentRecorded` event (Rule #8 — events are immutable); atomic update is simpler and safer than a listener-based increment.

### Table: `payment_allocations` (existing, Treasury module)

No new columns. The existing `tolerance_writeoff DECIMAL` column (from migration `2025_12_10_100004_add_tolerance_to_payment_allocations_table`) will finally be populated by the A3 fix. The cash-counting session can rely on this column being accurate post-tolerance-session, but should not query it directly — use `PaymentToleranceQueryService` below.

### Per-cashier breakdown

Derivable via the public Treasury service — see next section. **Not** accessed via direct cross-module SQL joins.

## Public Treasury service: `PaymentToleranceQueryService`

Per AutoERP Rule #6 (Module Boundaries are Sacred), the POS module cannot issue SQL that joins `payment_allocations` → `payments` → `receipts` — that spans Treasury and POS. The tolerance session exposes a public Application-layer service that the cash-counting session injects via constructor (Rule #13).

### Location

`apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php`

### Signature

```php
namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\TolerancePaymentBreakdownDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentReceiptDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentTotalsDTO;

final class PaymentToleranceQueryService
{
    public function __construct(
        // internal repository dependencies — not part of the public contract
    ) {}

    /**
     * Aggregate totals for a shift — intended for the Z-report summary row.
     */
    public function totalForShift(string $shiftId): TolerancePaymentTotalsDTO;

    /**
     * Per-cashier breakdown for a shift — for multi-cashier shift reports.
     *
     * @return array<int, TolerancePaymentBreakdownDTO>
     */
    public function breakdownForShift(string $shiftId): array;

    /**
     * Receipt-level list for drill-down. One DTO per receipt that had any tolerance writeoff.
     *
     * @return array<int, TolerancePaymentReceiptDTO>
     */
    public function receiptsWithToleranceForShift(string $shiftId): array;
}
```

### DTOs

```php
namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TolerancePaymentTotalsDTO extends Data
{
    public function __construct(
        public string $totalAmount,      // decimal string, scale 3
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}

#[TypeScript]
final class TolerancePaymentBreakdownDTO extends Data
{
    public function __construct(
        public string $userId,
        public string $userName,
        public string $totalAmount,      // decimal string, scale 3
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}

#[TypeScript]
final class TolerancePaymentReceiptDTO extends Data
{
    public function __construct(
        public string $receiptNumber,
        public string $userId,
        public string $userName,
        public string $writeoffAmount,   // decimal string, scale 3
        public string $currencyCode,
        public string $occurredAt,       // ISO 8601 UTC
    ) {}
}
```

TypeScript types are generated via `php artisan typescript:transform` and published to `packages/shared/types/`.

### Stability commitment

The three method signatures and three DTO shapes above are **stable as of contract v1.1**. Any changes require a v1.x bump with changelog entry. The tolerance session may add additional methods or optional DTO fields without a bump (non-breaking additions only).

### Performance posture

Current implementation uses direct Eloquent joins under the service. If Z-report render latency becomes a concern, a materialized view can be introduced under the same service signature — consumers won't notice. The cash-counting session has agreed the joined query is acceptable for current volumes.

## Receipt rendering (for awareness)

Added by the tolerance session to the customer-facing receipt. Documented here for awareness only; the shift-close session does not render receipts.

```
Total due           2.52
Cash tendered       2.50
Rounding           -0.02           ← printed only when tolerance_amount > 0
─────────────────────────
Total paid          2.52
```

Translation key: `pos.receipt.rounding`. Localized per country (French "Arrondi", Arabic "تقريب", etc.).

## Cash-variance formula

### Canonical formula (v1.1 — simplified)

```
expected_cash_in_drawer = opening_float
                        + Σ(pos_receipt_payments.amount for cash method rows in shift)
                        − Σ(pos_receipts.change_due for cash-paid receipts in shift)
                        − Σ(cash refunds in shift)
```

**No tolerance term needed.**

### Why no tolerance subtraction

The `pos_receipt_payments.amount` column stores **what the customer physically handed over** for that payment method (set at line 147 of `ReceiptPaymentService.php`, sourced from the cashier's tender input). It is NOT the receipt-total-attribution. Constraints on the column:

- NOT NULL (no null-handling needed)
- `CHECK (amount > 0)` (no zero-amount rows, except via future explicit schema change)

Illustration:

| Scenario | receipt.total | payment.amount | change_due | tolerance_writeoff | Cash in drawer delta |
|---|---|---|---|---|---|
| Exact tender | 2.52 | 2.52 | 0 | 0 | +2.52 |
| Overpay | 2.52 | 3.00 | 0.48 | 0 | +3.00 − 0.48 = +2.52 |
| Tolerance short-pay (new) | 2.52 | 2.50 | 0 | 0.02 (to GL 658) | +2.50 |

Summing `pos_receipt_payments.amount` and subtracting `change_due` yields the correct drawer delta in all three cases. Tolerance write-off is a GL-side adjustment and has no arithmetic effect on cash drawer math.

### Subtle A1 implementation note

In `ReceiptPaymentService.php:86`, the current line:

```php
$changeDue = bcsub($totalPaid, $receipt->total, self::SCALE);
```

...works only because short-pay is currently rejected (guaranteeing `totalPaid >= receipt.total`). Once A1 admits short-pay-within-tolerance, this line produces a negative `change_due`. The tolerance session must branch:

- If `totalPaid > receipt.total` → overpay: `change_due = totalPaid − receipt.total; tolerance_writeoff = 0`
- If `totalPaid < receipt.total` and within tolerance → short-pay: `change_due = 0; tolerance_writeoff = receipt.total − totalPaid`
- If equal → exact: `change_due = 0; tolerance_writeoff = 0`

This is tracked in the tolerance session's implementation plan. Mentioned here only so both sides understand that `pos_receipts.change_due` is always ≥ 0 post-A1.

## Z-report row format

Added to the Z-report (owned by the cash-counting session):

```
Cash sales:                    €487.50
Card sales:                    €1,240.00
...
Tolerance write-offs:          €0.84 (12 transactions)     ← NEW row
Discounts:                     €45.00 (7 transactions)
Refunds:                       ...
```

### Data source

Call `PaymentToleranceQueryService::totalForShift($shiftId)` for the summary row. Call `receiptsWithToleranceForShift($shiftId)` for the drill-down.

### Drill-down

From the tolerance row, the user can drill into a list of receipts with:

- Receipt number (`TolerancePaymentReceiptDTO::$receiptNumber`)
- Cashier name (`::$userName`)
- Time (`::$occurredAt`)
- Write-off amount (`::$writeoffAmount` — string, scale 3)

### Layout / formatting

Layout placement, row styling, drill-down UX, and print formatting are owned by the cash-counting session. The tolerance session guarantees the data is populated and queryable via the public service.

## Ownership split

| Concern | Owner | Coordination required? |
|---|---|---|
| `pos_shifts.tolerance_writeoff_total` + `tolerance_writeoff_count` migration | Tolerance session | No |
| In-transaction increment of shift fields on tolerance write-off | Tolerance session | No |
| `payment_allocations.tolerance_writeoff` population (A3 fix) | Tolerance session | No |
| `PaymentToleranceQueryService` + DTOs | Tolerance session | Yes — signatures stable per this contract |
| POS receipt "Rounding" line render + translation | Tolerance session | No |
| Expected-cash formula using `pos_receipt_payments.amount` | Cash-counting session | No — formula finalized in v1.1 |
| Z-report "Tolerance write-offs" row render | Cash-counting session | Queries the public service |
| Z-report drill-down UI | Cash-counting session | Queries the public service |
| Per-cashier z-score fraud metric | Future LP dashboard (out of both sessions) | — |

## Permission model (for awareness)

Tolerance session adds one new permission: `pos.tolerance.apply`. Granted to the default Cashier role. The shift-close session does not need to gate behind this permission — seeing tolerance totals on a Z-report is part of standard shift reporting, not applying tolerance.

## GL treatment (for awareness)

Tolerance write-offs create a journal entry:

- **Underpayment:** `Dr 658 PaymentToleranceExpense / Cr Revenue` (in POS, the entry is composed so cash is debited for the tendered amount, 658 debited for the shortfall, revenue credited for the full nominal amount, VAT unchanged).
- **Overpayment:** `Dr Revenue / Cr 758 PaymentToleranceIncome` (overpayment path; primarily relevant to B2B payment allocation, not POS where overpayment becomes change).

The tolerance session does not change existing GL posting for non-tolerance payments.

## Breaking-change discipline

If either session needs to modify this contract during implementation:

1. Edit this file.
2. Bump the version at the top (e.g. "v1.2 — 2026-04-25: added X, why").
3. Notify the other session with the diff.
4. Integration rebase when either branch merges.

Both sessions should pull this contract's latest version before starting any integration work.

## Integration verification checklist

Before merging either session's work, verify:

- [ ] `pos_shifts.tolerance_writeoff_total` increments after a tolerance-using POS receipt
- [ ] `payment_allocations.tolerance_writeoff` is populated for B2B "Close with write-off" invocations
- [ ] Expected-cash formula uses `pos_receipt_payments.amount` (not `receipt.total`)
- [ ] Z-report row appears when `PaymentToleranceQueryService::totalForShift` returns non-zero
- [ ] Z-report row hidden or shows zero when no tolerance writeoffs
- [ ] Drill-down returns the correct per-cashier attribution
- [ ] Receipt "Rounding" line renders in the correct locale
- [ ] Multi-cashier shifts attribute per-cashier tolerance totals correctly via `breakdownForShift`
- [ ] `pos_receipts.change_due` is never negative post-A1

## Open coordination questions

None at v1.1. Either session should add items here and notify the other if new questions arise during implementation.

---

**End of contract v1.1.**
