# Phase 2 Implementation Plan R2 — Codex Self-Adversarial Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Trigger:** Opus-equivalent R1 returned REQUEST-CHANGES with one BLOCKER: pending customer create / alias reconciliation was a dead path.  
**Verdict:** APPROVE.

## R1 Findings Addressed

1. **Pending customer create / alias dead path:** Resolved. R2 adds Task 5 with a local `pending_customer_outbox`, local `customer_aliases`, `pendingCustomerRepository`, `pendingCustomerSyncService`, `PosPendingCustomerController`, `PosCustomerAliasResource`, server idempotency tests, local alias conflict tests, and Treasury bridge alias-resolution tests. Valid offline-created customers now have an executable path from local pending customer to server Partner alias before Treasury Payment creation.

2. **Canonical byte parity and strict parser coverage too late:** Resolved. Task 1 now includes `StrictCanonicalParser`, `CanonicalPayloadReader`, PHP parser/reader tests, and TS/PHP golden canonical byte parity before mirror/UI/device/projection work.

3. **Treasury bridge missing FK/payment-method/repository coverage:** Resolved. Task 10 now requires `payment_method_id`, `repository_id`, scoped resolution, missing method/repository fail-loud tests, conflicting `fiscal_event_id` idempotency tests, allocation exception tests, and pending-customer alias resolution before Payment creation.

4. **Task boundaries too broad around customer work:** Resolved. R2 splits the customer work into POS mirror repository, server pull endpoint, POS pull sync/cursor, pending-create alias reconciliation, and UI attach/search/detach.

5. **Stale alias conflict missing from device authoring:** Resolved. Task 7 now includes `rejects stale alias conflicts before sealing`.

6. **D16 static guard incomplete:** Resolved. Task 8 guard now includes Contact and B2B alongside Treasury, Accounting, Partner, Customer, and container helper calls.

7. **Roadmap wording not strict enough:** Resolved. Task 11 now requires explicit `implementation complete, but NOT customer-facing deployment-ready` wording if Phase 1.5 tax-number validation is unresolved.

## Adversarial Checks

- **Spec coverage:** R2 now covers every locked spec requirement, including the pending customer alias loop and parser/canonical reader gates.
- **D16:** POS-core projectors still do not import Partner/Treasury/Accounting/Contact/B2B operational modules. Treasury remains isolated to a projector-gated bridge.
- **Reconciliation:** Pending customer aliasing and FIFO allocation are explicitly `server_reconciles`; sealed events remain factual and are not rewritten.
- **Cross-tenant FK safety:** R2 adds tenant/company alias scope and fail-loud cross-company alias/payment tests.
- **Fail-loud:** R2 expands bridge failure coverage for missing Partner, missing payment method, missing repository, allocation exception, and idempotency conflict.
- **Dead-path rebuild:** Pending customer create no longer dead-ends; each new route/table/repository/service has a caller and tests in the same task.
- **Contract drift:** Task 1 now gates TS/PHP canonical bytes and strict parser before downstream code depends on the contract.
- **Rule 13:** No new plan step introduces container helper resolution; allocation replay still uses explicit command DTO and constructor injection.

## Verification

- `git diff --check` passed after the R2 plan edits.
- Placeholder scan found no `TBD`, `TODO`, `<timestamp>`, `<touched>`, or unresolved "after locating" plan markers.

Final verdict: APPROVE. Send R2 for Opus-equivalent second-pass review.

