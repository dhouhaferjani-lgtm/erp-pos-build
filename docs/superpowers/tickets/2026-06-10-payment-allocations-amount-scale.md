# Ticket: widen `payment_allocations.amount` to the currency storage scale

**Filed:** 2026-06-10 (surfaced by the Phase 5 DEPOSIT_RECEIPT Codex review, P1-2)
**Severity:** P1 — money precision for 3-decimal currencies (TND, etc.)
**Status:** OPEN — deferred (pre-existing, Treasury-wide, out of the deposit feature's scope)

## Problem

`payment_allocations.amount` is declared `decimal(15, 2)` in the original Treasury
migration (`database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php`),
but:
- the `PaymentAllocation` model casts `amount` as `decimal:4`, and
- `PaymentAllocationService` accumulates and inserts allocation amounts at scale 4.

The 2026-03-11 monetary-scale widening
(`2026_03_11_200000_widen_monetary_columns_to_scale_3.php`) widened `payments.amount`
to scale 3 but did **not** include `payment_allocations.amount`.

For a 3-decimal currency (TND — a primary market) PostgreSQL coerces the allocation
into the 2-decimal column, so a sub-cent settlement can round. Repro: TND open
invoice/balance `1.005`, deposit `1.005` → `payment_allocations.amount` stored as
`1.01` → a settled-vs-credited summary can report `settled=1.010`, `credited=0.000`
for a `1.005` deposit.

This affects **every** allocation path (device `ACCOUNT_PAYMENT` + back-office
`DEPOSIT_RECEIPT` + manual payment allocation), not just deposits — it is a
pre-existing Treasury-schema defect that the Phase 5 `DepositAllocationSummaryService`
merely surfaces (it trusts the persisted column).

## Why deferred from the deposit feature

- Pre-existing and Treasury-wide; fixing it is a schema migration on a shared table
  plus a broad allocation/GL regression — outside the DEPOSIT_RECEIPT feature scope.
- The deposit feature is correct for scale-2 currencies (EUR/USD/GBP) and the
  pure-advance path (no allocations) used in production smoke today.

## Fix

1. Tenant migration widening `payment_allocations.amount` to `decimal(15, 4)` (match
   the model cast + service math) — or at least the per-currency storage scale.
2. Align the `balance_due` trigger and any generated casts to the same scale.
3. Regression: a 3-decimal partial/full settlement (e.g. TND `1.005`) through the
   real `PaymentAllocationService` asserting no rounding, plus a Phase 5 HTTP test
   recording a TND deposit that settles an open invoice at sub-cent precision.
