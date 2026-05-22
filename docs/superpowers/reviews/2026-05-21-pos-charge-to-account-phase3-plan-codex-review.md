# Codex Self-Adversarial Review — Phase 3 Implementation Plan

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`  
**Spec:** `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`  
**Reviewer:** Codex first-pass adversarial review  
**Verdict:** APPROVE

## Checks Performed

- **Spec coverage:** PASS. The plan maps spec sections to tasks:
  - Payload contract and drift gates: Tasks 1-2.
  - Customer mirror credit fields: Task 3.
  - Credit rules engine: Task 4.
  - Device authoring and printable: Task 5.
  - POS-core projection: Task 6.
  - AR GL posting: Tasks 7-8.
  - B2B/web Facture routing: Task 9.
  - Full-flow/POS-only/chokepoint closure: Task 10.
  - Roadmap/handoff/memory closure: Task 11.
- **Placeholder scan:** PASS. `rg -n "TBD|TODO|placeholder|\\.\\.\\.|similar to|appropriate|Use the actual|if .* before implementation|maybe"` returned no matches.
- **Type/path consistency:** PASS. Paths match current code layout. The plan corrected the Document/B2B bridge module token to `Sales`, because `Vertical::defaultModules()` exposes the invoice/document operational surface as `Sales`, not `Document`.
- **D16 guard:** PASS. POS-core projection remains always active and forbids Treasury/Accounting/Document/Partner imports. Treasury and Document/Sales effects are module-gated projectors.
- **D8 lock:** PASS. The plan proceeds on the spec recommendation: POS authors `ACCOUNT_CHARGE`; Document/Sales creates a Facture draft; POS does not author a Tax Invoice.
- **AR GL correctness:** PASS. The plan requires AR, ProductRevenue, VatCollected, SalesDiscount, partner attribution, idempotency, and no Payment/ReceiptPayment/payment-line side effects.
- **Cross-tenant FK safety:** PASS. Treasury and Document bridge tasks require tenant/company scoped lookups and fail-loud tests for cross-company customers/partners.
- **Fail-loud over silent downgrade:** PASS. Bridge tasks require typed failures for dependency and invariant breaks.
- **Dead-path rebuild:** PASS. Every new DTO/service/projector/script has tests and registration/wiring tasks.
- **Discriminated-union matrix:** PASS. Credit rules use a discriminated union and tests cover all rejection variants named in the plan.
- **Contract drift:** PASS. Payload tasks require TS/PHP registry parity, canonical golden bytes, strict-parser exact key sets, and chokepoint sentinels.
- **Constructor injection only:** PASS. The plan includes no production service-locator calls and carries the rule into review gates.
- **CI PG filter:** PASS. Projector tasks that add PG-sensitive fiscal feature tests explicitly update `.github/workflows/ci.yml` in the same commit.

## Findings

No BLOCKER, P1, or P2 findings.

## Residual Notes

- The plan is intentionally implementation-heavy. It is acceptable for later tasks to refine exact method names during TDD, but any scope or contract change must update the plan/spec docs and go through the same dual-review loop.
- Task 9 uses `requiresModule() === 'Sales'` while placing the service in `Document` because current code names the module provider `DocumentServiceProvider` but the activation token in `Vertical::defaultModules()` is `Sales`. The test matrix pins that translation.

