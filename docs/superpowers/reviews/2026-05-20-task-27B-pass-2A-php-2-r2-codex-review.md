# Codex Round-2 Review — Task 27B Pass 2A.PHP.2 R2 Fix

**Date:** 2026-05-20
**Reviewer:** Codex (transcribed by controller — sandbox-write workaround per project memory standing pattern). agentId `a60add641618b7620`.

## Executive Summary

R2 closes 6 of 8 R1 Codex findings cleanly but introduces 1 BLOCKER in the very fix that documented the contract. The new `EloquentPaymentMethodResolver` violates the docblock-locked transient-failure contract (catches `QueryException` and returns null instead of propagating). 2 secondary findings (P2 buyer-deletion test value-overlap; P3 cross-tenant product test missing sealed-snapshot reload assertion) weaken assertion power but are not blocking individually.

## Phase 1 — R2 Closure Verification

| Finding | Verdict | Evidence |
|---|---|---|
| BLOCKER-1 cross-tenant product FK | CLOSED | `resolveProductFk()` now scopes by `tenant_id` before the `id` match. |
| BLOCKER-2 REFUND/VOID fail-loud | CLOSED | `OriginalReceiptUnresolvableException extends ProjectionDependencyMissingException`; thrown at `PosCoreReceiptProjection.php:494-501` BEFORE any projection writes; carries all 4 forensic fields (eventType, upstreamFiscalEventId, originalReceiptUuid, tenantId). |
| `ProjectionDependencyMissingException` un-finalized | CLOSED | `final` removed; ApplyFiscalEventProjectionJob still catches via `Throwable`, retry semantics unaffected per Task 23 R2 contract. |
| Cross-tenant product test | PARTIAL | Test exists and asserts `product_id IS NULL` on projected line, but never reloads `fiscal_events.payload` to verify the sealed snapshot still holds the foreign UUID. See P3 below. |
| REFUND/VOID unresolvable tests | CLOSED | Both REFUND + VOID variants exist with forensic-context assertions + atomic rollback (verified across `pos_receipts`, `pos_receipt_lines`, `pos_receipt_payments`, `pos_receipt_vat_details`). |
| Buyer-deletion regression test | PARTIAL | Test exists, manually cascades NULL (SQLite parity), but Partner row and payload share identical name/tax_number values so the test cannot prove the INITIAL projection reads from payload rather than from Partner table. See P2 below. |
| D16 grep guard new pattern | CLOSED | `use App\Shared\Contracts\Treasury\` added to forbidden patterns; projector file does not contain it. |
| PaymentMethodResolver null contract docblock | CLOSED | Interface now distinguishes "code not found in tenant" (return null) from "transient failure" (must propagate). **This clarification exposed BLOCKER-3 below.** |

## Phase 2 — New Defects

### BLOCKER

**BLOCKER-3 (N-07): `EloquentPaymentMethodResolver` violates its own new contract.**

The R2 interface docblock at `App\Shared\Contracts\Fiscal\PaymentMethodResolver` explicitly says transient DB failures must propagate as exceptions; null means "code does not exist in this tenant." The implementation at `EloquentPaymentMethodResolver.php:30-39` still wraps `QueryException` in a catch block and returns `null`. POS-core treats null as "payment method not found" and throws a `RuntimeException` with a misleading forensic message. A DB timeout or deadlock gets silently mislabelled as a missing payment method code instead of propagating for retry/forensics. **No test for transient-failure propagation exists.**

**Fix:** Remove the `catch (QueryException)` guard from the impl. Add a test asserting `QueryException` propagates and the projection job records retryable failure (ApplyFiscalEventProjectionJob already handles via Task 23 R2 catch-Throwable contract).

### P2

**P2-N-05/N-08: buyer-deletion test value overlap.**

The Partner row and payload share identical name + tax_number values, so the assertion `assertEquals($projected->customer_name, "Sealed Customer Name")` cannot distinguish payload-read from runtime-Partner-read during INITIAL projection. The test does correctly cover the post-deletion case (Partner gone → projection idempotent uses payload), but the initial-projection assertion is weak.

**Fix:** Make Partner row values differ from the sealed payload values (e.g. Partner.name = "Partner Display Name", payload.buyer.name = "Sealed Customer Name"). Assert the projected columns equal the payload values, NOT the Partner values.

### P3

**P3-N-03/N-08: cross-tenant product test missing payload-snapshot reload.**

Test asserts `pos_receipt_lines.product_id IS NULL` on cross-tenant — correct. But missing: reload `fiscal_events.payload` after apply and assert `line_items[0].product_id` STILL equals the foreign product UUID (sealed snapshot survives the FK resolution failure).

**Fix:** Add reload + assertion. ~3-5 LOC.

### CLEAN

- N-01 (retry semantics): `Throwable` catch in ApplyFiscalEventProjectionJob covers the new `OriginalReceiptUnresolvableException` subclass via polymorphism. CONFIRMED.
- N-02 (`final` removal API change): no other code relies on `final` constraint; reflection/mock libraries unaffected. CONFIRMED.
- N-04 (rollback completeness): tests assert atomic rollback across all 4 POS tables. CONFIRMED.
- N-06 (repository_id determinism deferral): docblock at TreasuryReceiptBridge cites Phase 1.5 roadmap; commit body acknowledges the non-determinism. CONFIRMED.
- N-08 (test count): 4 new tests verified, each meaningful (not stubs). CONFIRMED.

## Verdict

R2 closes 6 of 8 R1 findings cleanly but the R2 itself introduces a BLOCKER: the EloquentPaymentMethodResolver impl contradicts the interface docblock the same commit added. Plus 2 weaker-than-needed test assertions. Fix all 3, re-run targeted fiscal slice, re-submit.

VERDICT: BLOCK
