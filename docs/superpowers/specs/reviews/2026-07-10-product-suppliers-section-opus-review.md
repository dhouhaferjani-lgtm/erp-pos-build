# Opus adversarial review — suppliers section

Model: `claude-opus-4-8`

Scope: the shared suppliers informational section and its page integrations.

## Requirement verification

- **One shared id, text, and route in both modes — PASS.** Both modes render `section-suppliers`, the existing translated managed-in-Purchases explanation, and `/purchases/suppliers`.
- **No invented supplier model or API — PASS.** The section contains no fetch, mutation, form control, or unsupported product field.
- **Edit behavior preserved — PASS.** The shared edit instance reproduces the removed inline title, hint, link, and scroll anchor.
- **View order and automotive gating preserved — PASS.** Suppliers follows inventory and the data-presence-gated automotive block, matching the edit ordering.
- **No duplicate surface — PASS.** Each mode contains one shared suppliers instance.
- **Existing i18n and route — PASS.** The keys exist across supported catalog locales and the purchases suppliers route already exists.

## Findings

No BLOCKER or MAJOR findings.

### MINOR — hardcoded registry filters could silently drop future view sections

The first integration split the legacy view registry into filters for the literal component names `automotive` and `metadata`. Although correct for the current two entries, a future registry entry could silently disappear.

Reconciliation: derive the visible registry once, split it around the metadata marker, and render every entry on either side of the shared suppliers section. New contextual or trailing entries can no longer be dropped merely because their component name is new.

### Note

The `mode` field is intentionally not used to branch because Task 5 requires byte-identical content in view and edit. Retaining the mode-shaped adapter keeps the section contract consistent with its siblings.

## Verdict

**APPROVED.** The shared suppliers section meets the task without inventing product supplier data. The one non-blocking registry maintainability concern was reconciled before commit.
