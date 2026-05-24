# Codex Self-Adversarial Review — Phase 3 Spec v1

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`  
**Reviewer:** Codex first-pass adversarial review  
**Verdict:** APPROVE

## Scope

Reviewed the Phase 3 `ACCOUNT_CHARGE` spec against:

- Source-of-truth v3 §1, §9, §11, §13.6, D16.
- Roadmap v2 §Phase 3.
- Codebase reality audit §2.1-§2.2, §3.1, §5.1-§5.2.
- Phase 1 spec v7 §11.0-§11.4 and §14.3 standing chokepoint semantics.
- Phase 2 account-payment spec and implementation precedent.
- Phase 1.5.2 tax-number validation and synthesis v5 payload/validator precedents.
- Handoff §4 standing patterns.

## Findings

No BLOCKER, P1, or P2 findings remain.

## Checks Performed

- **D16 bounded-module guard:** PASS. The spec keeps Fiscal/POS-core free of Treasury, Accounting, B2B, Partner, Customer, and Contact operational imports. Treasury and B2B effects are projector bridges gated by `requiresModule()` / module activation. The Accounting availability note is explicitly Treasury-owned and does not add a Fiscal/POS dependency.
- **Device authority:** PASS. `ACCOUNT_CHARGE` is device-authored and excluded from the Phase 1 §11.0 server-authoring carve-out.
- **No dual chain:** PASS. The spec places `ACCOUNT_CHARGE` on the same fiscal chain with `event_version: 1`.
- **Settlement-vs-payment-line split:** PASS. `ACCOUNT_CHARGE` explicitly forbids a `payments` block; later money received remains `ACCOUNT_PAYMENT`.
- **AR GL path correctness:** PASS. The spec forbids routing charge-to-account through `ReceiptPaymentService` / `createPOSPaymentEntry()` and requires a new AR command/service path.
- **B2B D8 boundary:** PASS. The spec surfaces the owner question and recommends the web-B2B-aggregates lock: POS authors `ACCOUNT_CHARGE`; web B2B consumes and creates Facture draft; POS does not author Tax Invoice.
- **Codebase claim traceability:** PASS. Claims about `Partner`, `CustomerCategory`, POS lack of local Treasury writes, and existing cash-only POS GL path trace to the codebase reality audit or current code paths.
- **Category drift check:** PASS after self-fix. The draft originally treated customer category as strictly `individual|business`; grep showed POS-local tests still use `retail` / `para-pharmacy`. The spec now treats exact `business` as B2B and all other values as non-B2B without destroying existing local values.
- **Contract drift prevention:** PASS. The spec requires TS/PHP payload key parity, golden canonical bytes, exact key-set parser coverage, and no extras.
- **Cross-tenant FK safety:** PASS. Treasury and B2B bridge sections require tenant/company-scoped FK lookups and typed failures on missing/cross-company dependencies.
- **Fail-loud over silent downgrade:** PASS. Projection dependency failures must throw typed exceptions, dead-letter, or record explicit server reconciliation status; no silent skip.
- **Dead-path rebuild:** PASS. Implementation-plan inputs require live callers and tests for each DTO, service, projector, route, and UI path.
- **Discriminated-union matrix:** PASS. Test matrix calls out B2C/B2B, synced/pending, stale/fresh, credit-limit present/absent, training/production, and POS-only/Treasury/B2B-active outcomes.
- **CLAUDE.md rule 13:** PASS. Spec explicitly forbids production `app()`, `App::make()`, and `resolve()`.
- **Per-method skips / skip-citation accuracy:** PASS. Spec carries forward the standing pattern.
- **R2-fix hazard:** PASS. Any future R2 changes are required to receive fresh Codex and Opus review.

## Residual Notes

- The exact B2B module activation token is intentionally left for the implementation plan to verify by grep before writing the bridge. This is not a spec blocker because the architectural requirement is the bridge boundary, not a guessed token.
- Owner D8 remains the one surfaced decision. The spec recommends locking web-B2B aggregation and proceeds on that basis unless the owner overrides.

