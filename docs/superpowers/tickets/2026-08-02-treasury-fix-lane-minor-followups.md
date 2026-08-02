# Ticket: minor follow-ups from the treasury money-campaign fix-lane gate (N2-N4 + round-1 minors + dev-hygiene)

From the two-round adversarial gate on the treasury fix lane (2026-08-02,
docs/superpowers/reviews/2026-08-02-treasury-money-campaign-fixes-review.md — REJECT then
APPROVE-WITH-FIXES). All verdicts and evidence live in that record; this ticket is the parked
non-blocking residue. None block the lane's merge (N1 was the only required fix and was applied
in its own commit).

## N2 (MINOR) — I5 transaction guard inert under RefreshDatabase

`InstrumentLifecycleService::cancelForPaymentReversal()`'s `DB::transactionLevel() < 1` guard is
unverifiable in tests (RefreshDatabase keeps level ≥ 1), and the same probe showed the public port
can cancel a `Received` instrument while its payment is still `completed` — prevented today only
by convention (the sole caller flips the payment in the same tx). Harden: assert the payment's
in-flight reversal state inside the port, or move the guard to something testable.

## N3 (MINOR) — port loads instrument without tenant/company scope

`InstrumentLifecycleService.php:670` resolves the instrument by id with no tenant/company scoping
on a now-public `Shared/Contracts` port. Currently safe (db-per-tenant connection isolation +
single internal caller), but a scope guard is cheap insurance for future callers.

## N4 (MINOR) — reverse-after-partial leaves an orphan refund payment

`reversePayment()` after a partial refund leaves the refund-child payment with a real cash
movement + GL entry but no allocations (pre-existing `reversePayment()` shape; now bounded by the
whole-lineage allocation delete). Decide whether the refund child should be voided/reversed as
part of reversing the original.

## Round-1 M3/M4 (minors, abridged in the round-1 record — see review file §round-1)

Carried as recorded; re-read the record before scheduling.

## Dev-hygiene (pre-existing, PROVEN not from this lane, currently masking future gates)

- Deptrac ratchet reports 98/FAIL on clean dev base `b967dc133` (baseline.json stale at 97/34) —
  same family as project_hexagonal_soc_audit's "97 vs baseline 61" drift. Re-baseline + ticket per
  that project's pending recommendation; until then every treasury gate must hand-prove +0 edges.
- `DeferredTenderGuardsTest::test_supplier_traite_keeps_cash_payment_shape_and_registers_outbound_instrument`
  red on dev — **INVESTIGATED 2026-08-02: STALE TEST, not a bug.** `9ae7db934` (2026-07-18)
  deliberately moved supplier deferred tenders to portfolio-issuance at creation (Dr 401 / Cr
  ChecksToPay, `GeneralLedgerService.php:778-801`) with movement + bank GL only at
  `OutboundInstrumentService::clear()` (`:123-155`, same tx — GL parity holds); it added
  `DeferredSupplierPaymentTest` for the new design but never updated this old test. FIX (test
  only): rewrite + rename the stale test to assert 0 movements / unchanged balance at creation,
  `source_type='instrument'` JE with portfolio credit line; optionally drive clear() and assert
  the movement lands there. No product change.
