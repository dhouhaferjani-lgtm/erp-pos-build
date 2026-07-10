# Adversarial Review — Product View/Edit Unification Final Integration

**Date:** 2026-07-10
**Reviewer:** `claude -p --model claude-opus-4-8`
**Scope:** `dev...HEAD` plus the browser-discovered authentication and translation fixes in the working tree.

## Bottom line

The shared section stack is correct, the pre-existing cost leak is closed, and the frontend consumes the server permission list for the confidentiality permission without regressing legacy route-map access. The confidentiality contract is defense-in-depth: server redaction, public-product stripping, and explicit frontend gates.

## BLOCKER

None.

## MAJOR

1. The security-critical auth-payload plumbing, server-authoritative `pricing.view_cost_prices` gate, and pricing translation namespace fix were still uncommitted at review time. They must be committed before handoff.
2. The branch was two documentation-only commits behind `dev`. Rebase and repeat the frontend gates before promotion.

## MINOR

1. Fail `pricing.view_cost_prices` closed while an older persisted session has not yet received the server permission list.
2. The parity test mocks the six components and is structural; the shared stack and page integration tests provide the integration guarantee, but a non-mocked smoke test could harden it later.
3. `PricingIntelligencePanel` remains as an unused exported component after removal from production composition.
4. The adapter type has a small aspirational surface not consumed by the shared hero.

## Guardrail verification

- Shared order `hero → general → pricing → inventory → suppliers → media`: pass.
- Exactly one pricing section in both modes: pass.
- Hero contains no price, cost, margin, or stock facts: pass.
- One HT-canonical blur-only pricing controller owns margin/HT/TTC writes: pass.
- Cost confidentiality covers view, edit, hero, pricing, discount-policy fetching, and stock valuation: pass.
- API permissions drive the confidentiality decision while legacy route-map compatibility remains: pass.
- Pricing translation namespaces resolve to real inventory keys: pass.
- Pharmacy, loyalty, and variants remain edit-only; automotive view remains data-presence-gated: pass.
- Holder/non-holder tests assert both positive and negative paths: pass.

## Verdict

**Approve for merge after the two major process items are completed.** No blocking defect was found in the implementation.
