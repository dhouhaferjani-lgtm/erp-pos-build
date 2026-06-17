# Plan Review — Web Variant Authoring Implementation Plan (Codex r1 + adjudication)

**Date:** 2026-06-15
**Plan reviewed:** `docs/superpowers/plans/2026-06-15-web-variant-authoring.md`
**Codex verdict (as returned):** REVISE — 3 BLOCKER, 4 HIGH.

> **Provenance note:** The Codex review agent reported writing this file but did not (no file was produced on disk), and its findings cited several task IDs and symbols that **do not exist in the plan** (`Task A6/A7/A8`, `StoreVariantRequest`, `OnboardingStep::VARIANTS_CONFIGURED`, `markComplete`, an `excluded` response array, `apiPost` in the hook). It appears to have partly reviewed a hallucinated/generic model of the plan. Per the project's verify-before-accept discipline, every finding was checked against the actual plan text and the real repo before action. This file is the maintainer's adjudication, not a verbatim Codex transcript.

---

## Adjudication

| Codex finding | Severity (claimed) | Verified against plan/repo | Outcome |
|---|---|---|---|
| **B1** — service/controller reference the `attributeValues` relation + DTO `attribute_values` defined in a later milestone (forward dependency / TDD ordering failure) | BLOCKER | **TRUE.** A3 reads `$variant->attributeValues` (relation = Task B1) and A4 uses `loadMissing('attributeValues')` + the DTO field (Tasks B1+B2), all sequenced after them. | **FIXED** — added a dependency-correct execution order (`A1→A2→B1→A3→B2→A4→…`) + `Depends on:` lines on A3 and A4. |
| **B2** — a barcode-uniqueness test in one task depends on a `Rule::unique` added in a later task (`StoreVariantRequest`) | BLOCKER | **FALSE.** No `StoreVariantRequest` exists (the file is `CreateVariantRequest`). The uniqueness rule and its test live in the **same** task (C1). | No change. |
| **B3** — `withTrashed()->lockForUpdate()->find()` applies the lock after the query executes (no-op) | BLOCKER | **FALSE PREMISE.** Laravel's builder retains the `FOR UPDATE` clause through `->find()`, so the lock IS applied. | **Adopted cosmetically** — switched to the unambiguous `->where('id',$id)->lockForUpdate()->first()` form. |
| **H1** — constraint-name string-matching is fragile; use SQLSTATE; names truncate at 63 bytes | HIGH | **Partly TRUE.** SQLSTATE-first is correct; but the 4 `product_variants` index names are all < 63 bytes (verified), so truncation does not apply. Some name matching is unavoidable to route barcode-vs-sku. | **TIGHTENED** — C2 now gates on `errorInfo[0]==='23505'` first, then exact (verified-short) index-name match; non-barcode violations fall through unchanged. |
| **H2** — `OnboardingStep::VARIANTS_CONFIGURED` + `markComplete()` don't exist | HIGH | **FALSE (wrong design).** Plan adds `OnboardingStep::ProductOptions` in E1 (before use in E2) and uses **computed** completion (`checkProductOptions()`), not a mark-complete API. | No change. |
| **H3** — test setup not runnable; no attribute/variant factory exists | HIGH | **Partly FALSE, spirit TRUE.** The factories **do exist** (`ProductAttributeFactory`, `ProductAttributeValueFactory`, `ProductVariantFactory`, `ProductVariantAttributeValueFactory`) and `ProductVariantServiceMatrixTest.php` is a seeding pattern to mirror. But the plan's setup comments were too vague. | **FIXED** — added a "Test fixtures" block pointing at the real factories + existing test pattern, with a concrete seeding snippet. |
| **H4** — `useGenerateMatrix` still uses `apiPost`, double-unwrapping and losing the `excluded` array | HIGH | **FALSE.** F2's hook delegates to the API client function (switched to `api.post` in F1); it never calls `apiPost`. There is no `excluded` array in the response (counts are in `meta`). | No change. |

## Net result
- **2 genuine findings** (B1 ordering, H3 fixtures) fixed; **2 cosmetic tightenings** (B3, H1) applied.
- **3 findings did not apply** to the actual plan (B2, H2, H4) — based on symbols/structure not present.
- The plan is executable as revised. The remaining risk surface is ordinary implementation risk, not plan defects.

## Lesson recorded
Plan-level Codex reviews in this session hallucinated task IDs/symbols and a written-file claim. Treat such reviews as *leads to verify*, never as authoritative — grounding each finding against the real plan + repo is what separated the 2 real issues from the 4 noise ones.
