# A3+A4 Matrix Generation — Codex Adversarial Review + Adjudication

**Date:** 2026-06-15
**Under review:** commits `97f531290` (A3 service rewrite) + `150544395` (A4 controller wiring)
**Codex verdict:** REVISE — 1 HIGH (F-1), 5 MED.
**Spec-compliance review (separate subagent):** ✅ COMPLIANT (7 A3 PG tests + A4 response test + migrated ProductVariantServiceMatrixTest + ProductVariantApiTest all green; no scope creep into C/D/E).

> Codex ran sandboxed and could not write into this worktree, so this file is the maintainer's transcription + adjudication. Each finding was checked against the actual code before action.

## Findings & resolutions

| ID | Sev | Finding | Verified? | Resolution |
|----|-----|---------|-----------|------------|
| F-1 | HIGH | Concurrent generate race: existing variants read BEFORE the transaction ⇒ two simultaneous calls both see a combo missing → one inserts, the other hits an uncaught `variant_code` 23505 (500); two empty-product calls can both set `is_default=true`. | TRUE | **FIXED** — acquire `Product…->lockForUpdate()` at the top of the `DB::transaction`, then read existing variants inside the lock. Serializes per-product generation (matches the restore path which already row-locks). |
| F-5 | MED | A4 response calls `loadMissing('attributeValues')` per-variant inside `.map()` ⇒ up to 200 queries on a full generate. | TRUE | **FIXED** — single `$affected->loadMissing('attributeValues')` on the collection before mapping. |
| F-2 | MED | Duplicate axes (same attribute_id twice) silently collapse via `$codeAxes[$code]` overwrite; no distinct validation. | TRUE | **FIXED** — `GenerateMatrixRequest` now rejects duplicate `attribute_id` across axes (422). |
| F-3 | MED | Legacy `attribute_ids` path expands without ownership/existence checks; bad id → RuntimeException (500). | TRUE but | **ACCEPTED** — mirrors the pre-change behavior of the legacy path (it already threw RuntimeException for missing attributes); the legacy shape is back-compat for a soon-deprecated caller. Not a regression. The new `axes` path (what the frontend uses) is fully validated by GenerateMatrixRequest. |
| F-4 | MED | Values materialized before the cap on the legacy path. | TRUE but | **ACCEPTED** — bounded by actual attribute-value rows (not the cartesian product); the gross cap still throws before `cartesian()`/writes. No DoS vector; new path is capped at the FormRequest. |
| F-6 | MED (speculative) | Corrupted junction rows (extra/missing) would make `comboKeyFromJunction` mismatch → re-insert → uncaught 23505. | Speculative | **ACCEPTED as theoretical** — junction rows are written atomically with the variant inside `createVariant`; the system does not produce the corrupt state. The F-1 product lock further narrows the window. A defensive create-path 23505→skip is YAGNI here. |

## Net
F-1 (real concurrency 500), F-5 (real N+1), F-2 (real minor) fixed in a follow-up commit. F-3/F-4/F-6 documented as accepted/low. The 25 existing PG tests remain green after the fixes; a regression note for the concurrency fix is in the follow-up commit (a deterministic two-connection race test is impractical in PHPUnit, so the fix is covered by the lock + the existing idempotency tests).
