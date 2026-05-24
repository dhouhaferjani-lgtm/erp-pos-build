# Phase 2 Implementation Plan R3 — Codex Self-Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Prior review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-plan-r2-opus-review.md`  
**Verdict:** APPROVE.

## Scope Reviewed

R3 was reviewed specifically against the R2 Opus-equivalent findings and the standing Phase 1 patterns:

- Server-side pending-customer alias persistence.
- Treasury Payment actor metadata.
- Explicit staging commands for every task commit.
- Cross-tenant FK safety.
- Fail-loud projection behavior.
- Dead-path rebuild risk.
- D16 bounded-module guard.
- Contract drift between spec, plan, TS, and PHP.
- Constructor injection and no container helper calls.
- Phase 1.5 tax-number launch gate visibility.

## R2 Findings Rechecked

1. **Server alias persistence is now executable.**

   Task 5 adds `pos_customer_aliases`, `PosCustomerAlias`, and the API resource/controller contract. The alias row is keyed by `(tenant_id, company_id, client_customer_uuid)` and points at `server_partner_id`. The controller rules now create Partner and alias in the same transaction, replay through the alias table, and verify that a replayed Partner still belongs to the same tenant/company. This removes the previous dead path where pending customer aliases existed only on the POS device.

2. **Treasury bridge lookup now has a durable replay target.**

   Task 10 now requires pending client UUIDs to resolve through server `PosCustomerAlias` before Payment creation, with typed projection exceptions for missing aliases and cross-company alias targets. This gives fiscal replay a stable server-side mapping independent of mutable Partner fields.

3. **Treasury Payment actor metadata is specified.**

   Task 10 now includes `created_by => $resolvedActorUserId`, a test for resolution from sealed operator identity, and documented nullable behavior when no tenant/company-scoped user can be resolved. The bridge is forbidden from using `Auth::user()`.

4. **Explicit staging is present.**

   Tasks 1 through 11 now include explicit `git add` commands before `git commit`. No task instructs `git add -A`.

## Standing Pattern Review

- **Cross-tenant FK safety:** Partner, alias, payment method, repository, Payment, Document, and allocation lookups are specified as tenant/company-scoped.
- **Fail-loud behavior:** Missing aliases, missing Partner, cross-company Partner, missing method, missing repository, idempotency conflicts, and allocation failures are typed projection failures, not silent downgrades.
- **Dead-path rebuild:** Pending customer alias has both POS and server persistence plus Treasury replay usage. ACCOUNT_PAYMENT canonical parser/reader is introduced before projectors and bridge tasks.
- **Discriminated-union matrix:** POS sync and service tests enumerate success, stale, pending, conflict, invalid-tenant/company, and failure states where relevant.
- **D16 guard:** POS-core projection forbids Treasury, Accounting, Partner, Customer, Contact, B2B, and container helper calls; Treasury bridge remains module-gated through projector registration.
- **Contract drift:** Task 1 creates PHP and TS fixtures, strict parser, canonical reader, and ACCOUNT_PAYMENT drift gates before downstream implementation.
- **Skip pattern:** The plan does not introduce class-level `markTestSkipped` or uncited skip exceptions.
- **Constructor injection:** The plan keeps bridge/projector dependencies injectable and forbids `app()`, `App::make()`, and `resolve()` in guarded paths.
- **Phase 1.5 gate:** Task 11 still requires roadmap status to state that Phase 2 is not customer-facing deployment-ready until per-country tax-number validation is implemented, reviewed, and pushed.

## Residual Risks

- The exact Treasury `Payment` actor column name must be verified against implementation reality during Task 10. The plan uses `created_by` because the spec requires explicit actor metadata; if the model uses a different audit field, Task 10 must map the sealed operator to the real column and update tests and docs in the same commit.
- Phase 1.5 per-country tax-number validation remains blocked on accountant-confirmed TN/FR formats. This blocks customer-facing deployment readiness, not plan approval.

## Verdict

APPROVE. The R2 blocker and request-changes items are resolved in the plan artifact. Send to Opus-equivalent R3 review before committing the plan.
