# Frontend (F1/F2/G1–G5/H1) — Codex Adversarial Review + Adjudication

**Date:** 2026-06-15
**Under review:** commits `2aec8cd49` (F1), `e8b961a53` (F2), `d2f149550` (G1–G5), `184a50ccd` (H1). Fixes in `502a05a9b`.
**Codex verdict:** REVISE — 0 BLOCKER, 1 HIGH, 1 MED, 2 LOW.
**Spec-compliance review (separate subagent):** MOSTLY COMPLIANT — confirmed Rules-of-Hooks isolation, comboCount/cap math, orphan-subset logic (not inverted), 422 envelope handling, delete/deactivate flow, i18n key coverage, no hardcoded colors, scope clean (apps/web only). Flagged the same `seedAxisValues` memoization + `ConfirmDialog` height as the Codex review.

> Codex returned findings inline (sandbox blocks worktree writes); this is the maintainer's adjudication. Each finding checked against code; the two reviewers were cross-referenced where they disagreed.

## Findings & resolutions

| ID | Sev | Finding | Verified? | Resolution |
|----|-----|---------|-----------|------------|
| H1 | HIGH | Selection seeding overwrites user choices: `seedAxisValues` not memoized ⇒ child effect re-fires every render; guard keys on `length>0` ⇒ deselecting ALL of an axis refills it. (Codex also claimed post-generate refetch clobbers selection — the spec reviewer correctly showed `hydratedRef` one-shot prevents that; only the refill sub-bug is real.) | TRUE (refill); the refetch-clobber part is FALSE (hydratedRef guards it) | **FIXED** (`502a05a9b`) — `useCallback(seedAxisValues, [])` + a `seededAxesRef` Set so each axis seeds exactly once on first value-load; hydrated axes are marked seeded so hydration and default-seed don't fight. Regression test added (deselect-all stays empty, comboCount 0). |
| M1 | MED | `t('actions.processing')` used by `ConfirmDialog` busy button but key missing ⇒ raw key rendered. | TRUE (missing from `common.actions`) | **FIXED** — added `actions.processing` to en ("Processing…") + fr ("Traitement…"). |
| L1 | LOW | `ConfirmDialog` content-driven height resizes on the delete→deactivate copy swap (fixed-modal UX rule). | TRUE (pre-existing component, surfaced by G5) | **FIXED** — `min-h-[4.5rem]` floor on the dialog body so the copy swap doesn't shrink it; min-h never shrinks other dialogs' content. |
| L2 | LOW | `aria-label={\`value ${v.label}\`}` hardcodes English (rule 11, screen-reader-facing). | TRUE | **FIXED** — `t('catalog:variants.valueLabel', {label})` + en/fr keys. |

## Cross-reviewer note
The two reviewers disagreed on whether hydration was safe. Resolution: the **hydration** path (`hydratedRef` one-shot) is correct and was left unchanged; the real defect was the **default-seed** path refilling an emptied axis. Fixing only the seed path (not hydration) was the precise correction — illustrating why a disagreement between reviewers was worth resolving rather than blanket-accepting either.

## Net
All findings resolved; 17 editor tests + variantApi test green, typecheck + lint clean. The 2 failing `CompositeItemSearchSelect` tests are pre-existing and unrelated (verified via stash). Frontend milestone complete.
