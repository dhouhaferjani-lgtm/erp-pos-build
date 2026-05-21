# Phase 2 Spec v1 — Codex Self-Adversarial Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`  
**Verdict:** APPROVE.

## BLOCKER

None.

## REQUEST-CHANGES

None.

## MINOR

None.

## Clean Findings

1. **SoT v3 alignment:** The spec keeps `ACCOUNT_PAYMENT` device-authored, sealed locally, synced through the Phase 1 fiscal-event ingest path, and projected afterward. It does not classify ACCOUNT_PAYMENT as server-authored and correctly keeps the §11.0 server-only carve-out limited to company-integrity events.

2. **D16 bounded-modules guard:** The POS-core work is standalone. Treasury `Payment` creation and FIFO allocation live only in a `FiscalEventProjector` bridge gated by `ModuleActivationResolver`; POS-core projections are forbidden from importing Treasury/Accounting/Customer/Contact/B2B operational modules. The customer mirror is treated as inbound reference data, matching the asymmetric seam.

3. **Reconciliation classification:** The spec correctly marks event authoring/printing as `offline_authoritative` and Treasury allocation as `server_reconciles`. It also handles pending offline customer creation without mutating or rejecting the sealed event: POS-core can project from the sealed snapshot, while Treasury waits/dead-letters until a tenant/company-scoped Partner alias exists.

4. **Payload contract completeness:** The ACCOUNT_PAYMENT payload is compliance-rich and follows the SALE_RECEIPT precedent: seller block, operator/terminal/timestamps, payment block, customer snapshot, balance snapshot, staleness evidence, references, and regime extension space. The spec avoids guessing TN/FR strict tax-number formats and keeps Phase 1.5 task 2 visible as a launch gate.

5. **Codebase reality traceability:** The spec cites existing reserved enum cases, payload registries, `Partner` balance fields, absence of POS customer mirror, Phase 1 `payments.origin` / `fiscal_event_id`, and the `PaymentAllocationService` explicit-context refactor need.

6. **Cross-tenant FK safety:** The spec requires `(tenant_id, company_id)` scoping for customer mirror rows, server customer aliases, Partner/payment-method/repository/actor lookups, and idempotency checks. It explicitly forbids Treasury Payment creation against unresolved or cross-company customers.

7. **Fail-loud posture:** Missing references, cross-tenant references, conflicting idempotency rows, allocation failures, and malformed payloads are specified as typed projection errors/dead letters, not silent skips.

8. **Dead-path rebuild:** The spec's implementation-plan inputs require live callers and tests for every new DTO, registry branch, projection, UI path, sync route, and bridge.

9. **Discriminated-union matrix:** The required test matrix includes synced vs pending customer, stale vs fresh balance, local vs foreign currency payment, and nullable vs populated references.

10. **Contract drift prevention:** TS/PHP byte parity and registry drift gates are required before implementation.

11. **CLAUDE.md rule 13:** The spec explicitly forbids `Auth::user()` for replay context and forbids `app()`, `App::make()`, and `resolve()`; it requires constructor-injected services plus an explicit command DTO for allocation replay.

12. **Skip / citation rules:** The spec adds no test skips. It avoids legal/tax regex assertions that require accountant confirmation.

## Residual Risks

- Phase 1.5 task 2 remains blocked on accountant confirmation for TN matricule fiscal and FR SIRET strict validation. This is a deployment gate, not a spec blocker.
- Production Treasury-inactive deployments still depend on the later config-model reality noted in Phase 1 spec v7 §18; the spec still correctly requires POS-only behavior and tests the resolver seam.

Final verdict: APPROVE. Send for Opus-equivalent second-pass review.

