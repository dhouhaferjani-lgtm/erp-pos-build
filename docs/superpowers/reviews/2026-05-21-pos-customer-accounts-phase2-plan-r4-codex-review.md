# Phase 2 Implementation Plan R4 — Codex Self-Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Prior review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r3-opus-review.md`  
**Verdict:** APPROVE.

## R3 Finding Rechecked

1. **Canonical DTO sequencing is now executable.**

   Task 1 now creates and stages:

   - `AccountPaymentView.php`
   - `AccountPaymentCustomerDTO.php`
   - `AccountPaymentPaymentDTO.php`
   - `AccountPaymentBalanceSnapshotDTO.php`
   - `AccountPaymentStalenessDTO.php`

   This aligns the early `CanonicalPayloadReader::forAccountPayment()` test with the files required to compile it.

2. **Task 8 no longer stages the wrong canonical paths.**

   Task 8 now scopes its explicit staging to POS-core projection files, the receipt model, migration, provider registration, and projection tests. It no longer references nonexistent `Domain/DTOs/AccountPaymentCustomer.php`, `AccountPaymentPayment.php`, `AccountPaymentBalanceSnapshot.php`, or a wrong `Domain/Services/CanonicalPayloadReader.php` path.

## Standing Pattern Recheck

- **Cross-tenant FK safety:** Still explicit for customer aliases, Partner lookup, payment method, repository, Payment, Document, allocation, and projection writes.
- **Fail-loud behavior:** Still required for stale/missing aliases, missing Partner, cross-company Partner, missing method/repository, idempotency conflicts, and allocation failures.
- **Dead-path rebuild:** Canonical view DTOs now have a live Task 1 reader/test caller; server alias rows have API and Treasury replay callers.
- **Contract drift:** Task 1 still introduces PHP/TS fixtures, strict parser, canonical reader, and parity gates before dependent tasks.
- **D16:** POS-core projection stays independent of Treasury/Partner/Customer/Contact/B2B/Accounting and container helpers.
- **Constructor injection:** No plan step introduces `app()`, `App::make()`, `resolve()`, or `Auth::user()` in fiscal replay paths.
- **Explicit staging:** Every task has `git add` with named files; no `git add -A`.
- **Phase 1.5 gate:** The unresolved per-country tax-number validation gate remains visible and deployment-blocking.

## Residual Risks

- Task 10 implementation must verify the actual Treasury Payment actor column name and company-scoped user-membership lookup. This is an implementation review item, not a plan blocker.
- Task 5 implementation must decide whether FK constraints are appropriate on `pos_customer_aliases` in addition to controller-level tenant/company replay validation.

## Verdict

APPROVE. The R3 request-changes item is resolved; send to Opus-equivalent R4 review before committing the plan.
