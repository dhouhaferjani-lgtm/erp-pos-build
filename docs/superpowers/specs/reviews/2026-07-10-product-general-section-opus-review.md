# Adversarial Review — Shared General Section

**Date:** 2026-07-10
**Reviewer:** `claude -p --model claude-opus-4-8`
**Scope:** General-section diff since M1 commit `b034c2f91`.

## Verdict: PASS WITH FIXES

The extraction is sound: one `EditorSectionCard id="section-general"` renders both modes; RHF validation, prefill highlighting/clearing, unit/category controls, and status/e-commerce fields are preserved; no cost fields are read; no duplicate DOM ids were found; and both modes are tested.

## BLOCKER

None.

## MAJOR

### Category label uses a non-existent i18n key

`ProductGeneralSection.tsx` used `t('catalog:products.category')` in both modes. The catalog namespace has no `products.category`, while `inventory:products.category` exists. The previous edit implementation also carried a broken dotted lookup, so the extraction preserved a defect instead of correcting it.

**Remediation:** Use `t('inventory:products.category')` in both branches.

## MINOR

1. The i18n echo mock masked the incorrect namespace. Add an assertion that only passes for the real category key.
2. Edit coverage did not exercise prefill highlighting or `clearPrefilledField`. Add a highlighted-name change assertion and spy.
3. General view repeats active status already present in the page header. Confirm whether it is intentional.
4. View label styling now follows the editor label token rather than the old uppercase detail style; visually verify during the final browser pass.

## Reconciliation

- Replaced both category labels with the existing `inventory:products.category` key.
- Strengthened the component test so that key resolves to `Category`; an incorrect namespace now fails.
- Added assertions for the prefilled success class and the clear-on-change callback.
- Retained active/e-commerce values in General intentionally: view/edit parity requires the same field set, even though the page header also summarizes active state.
- Retained editor label tokens intentionally so the shared card does not change typography between modes; final browser verification will check the visible transition.
