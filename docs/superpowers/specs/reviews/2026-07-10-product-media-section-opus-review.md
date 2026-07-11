# Opus adversarial review — media section

Model: `claude-opus-4-8`

Scope: the dedicated shared media section, image query/gallery presentation, and create/edit/view integrations.

## Requirement verification

- **Create buffers `File[]` — PASS.** Before persistence, the shared edit branch binds `CreateModeImageBuffer` to the existing form buffer and setter.
- **Persisted edit management — PASS.** Persisted edit renders the image section without `readOnly`, retaining upload, set-primary, and delete behavior.
- **Read-only view over the same query — PASS.** View passes the same product id into the tenant-scoped `product-images` query and renders the same gallery read-only. Upload, delete, set-primary, and reorder controls are absent.
- **One media anchor per page — PASS.** The shared card owns `#section-media`; it renders once in each page.
- **Shared order — PASS.** Media follows suppliers and precedes edit-only variants or trailing view metadata.
- **Edit hero flows preserved — PASS.** Barcode, scanner, refresh, image upload/buffer, and enrichment wiring in the existing edit hero were not altered.
- **Backward-compatible image section API — PASS.** New `readOnly` and `embedded` props default to false.

## Findings

No BLOCKER or MAJOR findings.

### MINOR — stale barrel mocks remained beside the new media boundary mock

The two legacy ProductForm media tests had added the new `ProductMediaSection` mock but retained their old `../../products/components` barrel mocks. Those mocks no longer represented the media section and incompletely mocked symbols imported by the hero.

Reconciliation: removed both stale barrel mocks. The legacy tests now mock the actual shared media integration boundary, while the new section/component tests cover buffering, management, query, and read-only behavior directly.

### MINOR — touched image-section lines retained hardcoded color utilities

The new conditional wrappers touched lines with hardcoded gray, border, and white color classes.

Reconciliation: migrated those touched colors to `textColors`, `borderColors`, and `colors` from the design-token module.

### Notes

- Create mode temporarily exposes the same buffer through the existing toggle-gated hero flow and the dedicated media section. They share one state and the user explicitly requires preserving the edit hero flow for the upcoming hero-shell milestone.
- The legacy create integration includes a positive assertion for its media buffer boundary; the dedicated section tests carry the behavior-level assertions.

## Verdict

**APPROVED.** All dedicated media guardrails pass. Both non-blocking cleanup findings were reconciled before commit.
