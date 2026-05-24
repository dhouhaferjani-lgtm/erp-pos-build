# Phase 2 Implementation Plan — Codex Self-Adversarial Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`  
**Spec:** `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`  
**Verdict:** APPROVE.

## BLOCKER

None.

## REQUEST-CHANGES

None.

## MINOR

None.

## Clean Findings

1. **Spec coverage:** The plan maps every spec deliverable to tasks: ACCOUNT_PAYMENT payload and drift gates, POS customer mirror, search/create/attach UX, device authoring/printing, POS-core projection, allocation DTO refactor, Treasury bridge, and full-flow closure.

2. **Phase 1.5 gate visibility:** The plan keeps strict TN/FR tax-number validation as a non-negotiable deployment gate and does not guess accountant-confirmed regexes.

3. **D16 bounded modules:** POS-core projection is always-active and explicitly forbidden from Partner/Treasury/Accounting imports. Treasury behavior is isolated in a `FiscalEventProjector` bridge with `requiresModule() === 'Treasury'` and priority after POS-core.

4. **Reconciliation classification:** Device authoring, local printing, and POS-core projection remain `offline_authoritative`. Pending customer alias and FIFO allocation are handled as `server_reconciles` in Task 7.

5. **Cross-tenant FK safety:** The plan requires tenant/company on POS mirror rows, server customer sync, Partner lookups, Payment creation, repository/payment method resolution, and idempotency conflict checks.

6. **Fail-loud posture:** Missing/malformed payloads, cross-company customers, unresolved pending customers, allocation exceptions, and conflicting idempotency rows are specified as typed errors/dead letters, not silent downgrades.

7. **Dead-path rebuild:** Every created component has a live caller and a test target in the task where it lands.

8. **Discriminated-union matrix:** Task 1 covers synced vs pending customer, stale vs fresh balance, local vs foreign currency, nullable vs populated references, and negative validator cases.

9. **Contract drift prevention:** Task 1 locks TS/PHP registries and canonical parity before authoring/projection work. Later tasks depend on that contract.

10. **Constructor injection / no container helpers:** Plan forbids `app()`, `App::make()`, and `resolve()` in code and review axes; allocation replay is routed through an explicit command DTO.

11. **Plan hygiene:** Placeholder scan was run and cleaned. Paths and commands are concrete. Migration filename is fixed. The POS checkout and print integration points are named: `paymentStore.ts`, `HomePage.tsx`, `buildReceiptData.ts`, `printing.ts`, and `CheckoutSuccessModal.tsx` if needed.

## Residual Risk

The plan is large enough that implementation should stay strictly task-by-task with review commits between tasks. Do not batch Task 1 and Task 2; payload contract defects would cascade into mirror/UI/projection work.

Final verdict: APPROVE. Send for Opus-equivalent second-pass review.

