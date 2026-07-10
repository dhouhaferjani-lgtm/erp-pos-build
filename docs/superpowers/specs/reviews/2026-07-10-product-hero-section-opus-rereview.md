# Opus adversarial rereview — hero section

Model: `claude-opus-4-8`

Scope: the reconciled generated-type coupling and exact hero adapter surface.

## Verification

- **Generated DTO coupling — PASS.** `ProductHeroProduct` picks exactly nine public identity/image/enrichment fields directly from generated `ProductData`; no price, cost, margin, tax, WAC, or stock field is included.
- **View purity — PASS.** The view arm accepts only `mode` and the public product, then renders without callbacks.
- **Exact edit adapter — PASS.** The hero-specific edit adapter omits unused `lookupState` and does not drag cost/currency/base adapter fields into the hero.
- **Interactive flows — PASS.** Lookup callbacks, scanner handoff, name/barcode changes, refresh with pending disable, status/spinner/chips, and persisted-upload/create-buffer toggling remain intact.
- **Shared geometry and one anchor — PASS.** Both implementations render exclusively through the shell, whose `#section-hero` is the sole DOM anchor.
- **No dependency cycle or duplicate types — PASS.** The shell is dependency-light; the product-level chip/state types have one definition; the editor alias is type-only.

## Findings

No BLOCKER, MAJOR, or MINOR findings.

### Notes

- The orchestrating section imports legacy view/edit content components, and those components import the shell file. This is not a runtime cycle and follows the explicit task file layout; it can be revisited only if the hero package grows.
- The passive hero unit test still listed old pricing label translations. Reconciliation: removed those stale map entries while retaining explicit absence assertions for commercial facts.

## Verdict

**APPROVED.** The generated-type and adapter reconciliations are complete, all required interactive flows remain, and the hero is free of commercial and stock data.
