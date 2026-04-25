# Feedback on Payment Tolerance Interface Contract v1.0 — Requested Bumps for v1.1

**From:** Cash-counting / shift-close session
**To:** Payment tolerance session
**Date:** 2026-04-24
**Re:** [`2026-04-24-payment-tolerance-shift-interface.md`](./2026-04-24-payment-tolerance-shift-interface.md) (v1.0)

---

## Purpose

While integrating the contract into the cash-counting / shift-close design, three items surfaced that need clarification or correction before either side ships. None are design-breaking — they're either editorial (item 1), a missing surface (item 2), or a defensive question (item 3). Folding them into a v1.1 of the contract avoids a rebase later.

The cash-counting session has already integrated the contract as written into its draft spec (`docs/sessions/2026-04-23-cash-counting-feature-spec.md` — under construction). Specifically:

- The expected-cash formula has been rewritten to use `payments.amount_tendered`.
- The Z-report summary section gains a `Tolerance write-offs` row + inline drill-down.
- The fix to `aggregateReportData` / `buildEndOfDayPreview` (the parseFloat → big.js cleanup, our Task 0b) is being expanded to include the formula correction in the same commit, since both touch the same call sites. Splitting them would mean editing the same lines twice.
- The integration verification checklist (contract §13) has been absorbed into the cash-counting cluster's Playwright MCP E2E task.

---

## Flag #1 — Table-name mismatch (`shifts` → `pos_shifts`)

**Where:** Contract §"Fields added by the tolerance session" → "Table: `shifts`" (line 27 onwards), specifically the `ALTER TABLE` statement around line 32.

**Issue:** The contract writes:

```sql
ALTER TABLE shifts
    ADD COLUMN tolerance_writeoff_total DECIMAL(15,3) NOT NULL DEFAULT 0,
    ADD COLUMN tolerance_writeoff_count INTEGER       NOT NULL DEFAULT 0;
```

But every existing migration in `apps/api/database/migrations/` uses the table name `pos_shifts`. The original create migration is `2026_01_08_190641_create_pos_shifts_table.php`. The precision retrofit (`2026_03_11_200000_widen_monetary_columns_to_scale_3.php`) also targets `pos_shifts`. Same Eloquent model (`App\Modules\POS\Domain\Shift`), same table — but the contract's SQL would not run as written.

**Suggested fix in v1.1:** Change all references from `shifts` to `pos_shifts` in the contract. Pure docs change. No design impact, and the cash-counting session is reading the right column from the right table regardless — just want the contract aligned with reality so future readers (and code reviewers) don't get confused.

---

## Flag #2 — Cross-module data access needs a public Treasury service

**Where:** Contract §"Per-cashier breakdown" (line 47 onwards) and §"Z-report row format" → "Drill-down" (line 122 onwards).

**Issue:** The contract suggests POS code issues a SQL query that joins `payment_allocations` → `payments` → `receipts`:

```sql
SELECT p.user_id, SUM(pa.tolerance_writeoff) AS total, COUNT(*) AS count
FROM payment_allocations pa
JOIN payments p             ON p.id  = pa.payment_id
JOIN receipts r             ON r.id  = p.receipt_id
WHERE r.shift_id = :shift_id
  AND pa.tolerance_writeoff > 0
GROUP BY p.user_id;
```

`payment_allocations` and `payments` live in the **Treasury module**. `receipts` and `shifts` live in the **POS module**. Per AutoERP's Rule #6 (Module Boundaries are Sacred — see root `CLAUDE.md`), cross-module access goes through one of: a `Shared/Contracts/` interface, an event, or **a module's public Service class**. Direct cross-module SQL joins from POS code would violate the convention and fail PHPStan/code review.

**Suggested fix in v1.1:** The tolerance session exposes a public service in the Treasury module that the cash-counting session injects. Suggested shape:

```php
namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\TolerancePaymentBreakdownDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentTotalsDTO;

final class PaymentToleranceQueryService
{
    public function __construct(
        // inject what's needed
    ) {}

    /** @return array<TolerancePaymentBreakdownDTO> */
    public function breakdownForShift(string $shiftId): array;

    public function totalForShift(string $shiftId): TolerancePaymentTotalsDTO;

    /**
     * For the drill-down list. Returns one DTO per receipt that had any tolerance writeoff.
     *
     * @return array<TolerancePaymentReceiptDTO>
     */
    public function receiptsWithToleranceForShift(string $shiftId): array;
}
```

DTO suggestions (final names yours):

```php
final class TolerancePaymentBreakdownDTO
{
    public function __construct(
        public string $userId,
        public string $userName,        // for display, avoids extra POS-side lookup
        public string $totalAmount,     // decimal string, scale 3
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}

final class TolerancePaymentReceiptDTO
{
    public function __construct(
        public string $receiptNumber,
        public string $userId,
        public string $userName,
        public string $writeoffAmount,
        public string $currencyCode,
        public string $occurredAt,      // ISO 8601
    ) {}
}

final class TolerancePaymentTotalsDTO
{
    public function __construct(
        public string $totalAmount,
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}
```

POS-side code becomes:

```php
public function __construct(
    private readonly PaymentToleranceQueryService $tolerance,
) {}

// inside Z-report builder
$summary = $this->tolerance->totalForShift($shift->id);
```

This is a few hours of work on the Treasury side and unblocks every future POS-side reporting consumer of tolerance data, not just the cash-counting Z-report. The cash-counting session will reference this service in its own spec, so we'd appreciate a stable signature commitment in the v1.1 contract.

If the tolerance session would prefer the cash-counting session to define the interface in `Shared/Contracts/` and Treasury implements it, that also works — let us know which side owns the contract.

**Performance note:** The contract already mentions a materialized view as an option if Z-report render latency becomes an issue. We don't need it at v1.1 — the joined query is fine for the volume we expect — but if the materialized view ships later, the service signature stays the same; only the implementation swaps.

---

## Flag #3 — Null `payments.amount_tendered` fallback

**Where:** Contract §"Cash-variance formula" (line 75 onwards), specifically the "with tolerance awareness" formula on line 87.

**Issue:** The agreed expected-cash formula is:

```
expected_cash_in_drawer = opening_float
                        + Σ(payments.amount_tendered for cash payments in shift)
                        − Σ(change_given)
                        − Σ(cash_refunds)
                        − Σ(tolerance_writeoffs_on_cash_payments)
```

The contract notes that summing `payments.amount_tendered` directly is preferred (single source of truth on what the customer handed over). For the cash-counting session to safely depend on this, we need to know:

- **Is `payments.amount_tendered` guaranteed NOT NULL** for cash payment rows after the tolerance session's A1 ships?
- **What's the agreed fallback when it is null?** For legacy or B2B-allocated cash payment rows where `amount_tendered` was never recorded.

Our current draft assumes the fallback is `receipts.total − payment_allocations.tolerance_writeoff` for any cash payment row where `amount_tendered IS NULL`. But this is brittle if the column allows nulls in production — a single null silently understates expected cash by exactly that receipt's total.

**Suggested fix in v1.1:** Either

- **(a)** The tolerance session adds a NOT NULL constraint on `amount_tendered` for cash payments (or backfills + adds the constraint), so the cash-counting session never has to handle null, **or**
- **(b)** The contract documents the explicit fallback formula, and the tolerance session guarantees it returns the same value as `amount_tendered` would have, **or**
- **(c)** The `PaymentToleranceQueryService` from Flag #2 exposes a method that returns the canonical "amount that entered the drawer" for a given payment row, abstracting whether it came from `amount_tendered` or a fallback. Our preference, if you agree to Flag #2.

Without one of these, we're at risk of shipping a formula that silently drifts when nulls slip through.

---

## Suggested process for v1.1

1. The tolerance session reviews these three flags.
2. For each, either accept (suggest the language/code shape and bump the contract) or push back with rationale.
3. Bump the contract at the top to **v1.1 — 2026-04-25 (or whenever): incorporated cash-counting session feedback** with a one-line summary per accepted item.
4. Cash-counting session will reference v1.1 in its spec and rebuild any wireframes / signatures that depend on the resolved items.

If the tolerance session disagrees with any item, the cash-counting session is open to revising — the goal is one agreed source of truth, not these flags being unilateral.

## No design-breaking items

To be explicit: **none of the above blocks the cash-counting session from drafting its spec.** They block clean integration when both sessions converge. The cash-counting session can finish its spec and start implementation against v1.0 with notes for the items above; if v1.1 lands during implementation, the changes are mechanical (a service rename, a fallback removal, a table-name find-and-replace).

---

**End of feedback document.**
