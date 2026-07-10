# Opus adversarial review — hero section

Model: `claude-opus-4-8`

Scope: the shared hero shell, passive view content, interactive edit content, and page integrations.

## Requirement verification

- **One shared geometry — PASS.** Both view and edit render exclusively through `ProductHeroShell`, which owns the responsive image slot, identity column, enrichment box, helper spacer, and optional after-content.
- **View purity — PASS.** The view adapter accepts no callbacks. Primary-image variant resolution, name/SKU/barcode/status, brand source, and category remain read-only.
- **Edit flow preservation — PASS.** Lookup callbacks, scanner `onScan` handoff, name/barcode writes, manual refresh with pending disable, persisted upload/create buffer toggle, and all enrichment states/chips remain wired.
- **No commercial or stock facts — PASS.** Hero props and render paths contain no price, cost, margin, tax, WAC, or stock quantity.
- **One hero anchor — PASS.** The shell is the sole owner of `#section-hero` and each page renders one shared section instance.
- **Canonical hero types — PASS.** Enrichment state and chip types live in one product-level module and the legacy editor type is an alias.
- **Generated view type — PASS after reconciliation.** See the finding below.

## Findings

No BLOCKER or MAJOR findings.

### MINOR — passive hero product shape hand-copied generated DTO fields

The first implementation declared a loose `ProductHeroProduct` interface with optional copies of generated fields. A future DTO rename could therefore silently remove enrichment rendering without a compile-time error.

Reconciliation: `ProductHeroProduct` is now a `Pick` directly from generated `ProductData`, covering only the nine public identity/image/enrichment fields the passive hero consumes. The unit test now builds from the generated-type fixture.

### Notes reconciled

- The hero-specific edit adapter no longer requires and ignores `lookupState`; it declares the exact callback/state subset it consumes. Search state remains internally derived by the lookup hook.
- Empty enrichment/helper geometry in view is intentional: the shared shell reserves identical slots in both modes and will receive browser visual verification.
- Mobile image sizing and dark-band geometry intentionally converge on the shared shell and will be checked in the browser trace.

## Verdict

**APPROVED.** No blocker or major was found. The generated-type coupling minor and the unused adapter-field note were reconciled before the required rereview.
