# Plan Review (Opus, adversarial) — Web Variant Authoring Implementation Plan

**Date:** 2026-06-15
**Reviewer:** Opus 4.8 (1M), adversarial pass, grounded against the real repo at `apps/erp.web-variant-authoring` (branch `feat/web-variant-authoring`).
**Plan reviewed:** `docs/superpowers/plans/2026-06-15-web-variant-authoring.md`
**Spec:** `docs/superpowers/specs/2026-06-15-web-variant-authoring-design.md` (v3, owner-approved)
**Prior review:** `docs/superpowers/reviews/2026-06-15-web-variant-authoring-plan-codex-review.md` (Codex r1 + maintainer adjudication)

---

## Overall verdict: **APPROVE-WITH-MINOR-EDITS**

The plan is well-grounded: the execution-order block (`A1→A2→B1→A3→B2→A4→…`) correctly resolves the forward-dependency that the prior Codex pass flagged, the constraint names / partial-index predicates / route param names / `api.post` unwrapping / cross-module contract precedent (`LocationStockReader`) all check out against the real files, and the restore-on-regenerate + junction-keyed idempotency algorithm is sound under the schema (hard `UNIQUE(product_id, variant_code)` ⇒ ≤1 row per combo, so restore is unambiguous; `ksort` in `comboKey` makes the key order-independent on both sides). It is executable largely as-is.

Two issues are worth fixing before execution: **(1)** the Task A2 cap test is written for A3's *new* signature but A2 is sequenced *before* A3, so the test input shape doesn't match the method that exists when A2 runs; **(2)** the `VariantStockReaderService` returns a bare `(string) ->sum()` that will not produce the scale-4 string (`'5.5000'`) the D1 test asserts, and violates the project precision contract — use `QuantityScale::round(...)` like the existing Inventory precedent. Neither blocks the design; both are localized edits.

---

## Findings

| # | Sev | Location | Finding | Recommendation | Verified against |
|---|-----|----------|---------|----------------|------------------|
| 1 | HIGH | Task A2 (plan L257-305), execution order L18 | A2 is sequenced **before** A3, so when A2 runs `generateMatrix` still has the **legacy** signature `generateMatrix(string $productId, array $attributeIds)` (loads ALL values of each attribute). A2's test calls `$service->generateMatrix($productId, $axesOf201)` and is described as "Build **axes** representing 201 combos" — that is A3's new axes shape, not a list of attribute IDs. Against the legacy method the same data is mis-interpreted as `$attributeIds`. To trigger gross=201 on the legacy path you must seed attributes whose **all-values** cross-product is 201 (e.g. 3×67 = ~70 ProductAttributeValue rows). The test as written is incoherent with the signature that exists at A2's execution point. | Either (a) move A2 **after** A3 (so the new axes signature exists), or (b) rewrite A2's test+fixture against the legacy `attribute_ids` shape (seed attributes whose total value product is 201). Step-4's guard snippet (`array_product(array_map('count', $axes))`) is itself fine — the legacy local var is also `$axes` — only the test input shape is wrong. | `ProductVariantService.php:182` (legacy sig `array $attributeIds`), L195/L210 (`$axes`, loads all `listForAttribute`); plan L18 order, L264 test |
| 2 | MED | Task D1 (plan L873-932), `VariantStockReaderService` | `variantOnHandQuantity` does `(string) StockLevel::query()->...->sum('quantity')`. `->sum()` is a DB aggregate and does **not** apply the model's `decimal:4` cast, so for `3.0000 + 2.5000` PostgreSQL returns numeric `5.5` → `(string)` yields `'5.5'`, **not** the `'5.5000'` the D1 test asserts (L877). It also bypasses the project precision contract (CLAUDE.md rule 19 / `QuantityScale`). The existing Inventory precedent (`LocationStockQueryService`) never trusts raw `->sum()` — it wraps every quantity in `QuantityScale::round((string) ..., self::QTY_SCALE, FLOOR)` / `bcadd(..., 4)`. | Format the result: `return QuantityScale::round((string) $sum, 4, QuantityScale::FLOOR);` (or `bcadd((string) $sum, '0', 4)`). The zero-stock test (`'0'`) then needs updating to `'0.0000'`. The D2 delete guard (`bccomp(..., '0', 4)`) is unaffected — it works regardless of formatting. | `StockLevel.php:60` (`quantity => decimal:4`); `LocationStockQueryService.php:160,179,242` (QuantityScale precedent); plan L877, L922-931 |
| 3 | LOW | File map L48 + spec §3.1 vs plan A3 (L452) | Spec §3.1 prose says exclusions are passed "**via `cartesian()`'s `$excluded`**", but the plan's A3 `generateMatrix` calls `$this->matrixGenerator->cartesian($codeAxes)` with **no** `$excluded` argument and instead does skip/restore/create per-combo in the loop via `$byComboKey`. This is the **correct** choice (cartesian's `$excluded` can only *skip*; the loop must also *restore*), so behavior is right — but the stale spec prose could mislead an executor who reads §3.1 literally. The `$excluded` parameter remains unused dead capability. | No code change required. Optionally add one line to A3 noting "we do NOT use `cartesian($excluded)` because we must additionally detect restore candidates; matching is done in the loop." Keeps the spec-prose contradiction from confusing a mechanical executor. | `ProductVariantMatrixGenerator.php:23` (`$excluded` exists, only filters/skips); plan L452 (no `$excluded` passed); spec L94 |
| 4 | LOW | Task G1 (plan L1172) | "For each checked axis, fetch values with `useAttributeValues(axisId)`" — `useAttributeValues` is a `useQuery` hook (`useVariants.ts:50`). Calling it inside a `.map()` over the dynamic set of checked axes risks a Rules-of-Hooks violation if axis membership changes between renders. The current editor only renders flat checkboxes (no per-axis value fetch), so this is new. | Render each axis (and its `useAttributeValues` call) in a stable child component (`<AxisValueChips attributeId=...>`), one hook per mounted child — not a hook-in-loop in the parent. A mechanical executor following the prose verbatim could write an invalid hook loop. | `useVariants.ts:50-57` (hook); `ProductVariantMatrixEditor.tsx:84,173` (current flat render) |
| 5 | LOW | Task C2 (plan L848) | Plan says route controller `update` through a new `variantService->updateVariant($id, $validated)` so the 23505 catch covers updates, but does not specify the method body (today the controller does `$variant->fill($request->validated())->save()` inline, `ProductVariantController.php:156`). "register or route" leaves two options. | Pick one concretely: add `ProductVariantService::updateVariant(string $id, array $attrs): ProductVariant` that loads (company-scoped) → `fill` → save-in-try/catch, and have the controller call it. Otherwise the executor must invent the method shape. The catch logic itself (SQLSTATE-first + exact index name) is correct and matches the verified index names. | `ProductVariantController.php:139-160` (inline fill/save); migration index names confirmed (finding context) |

---

## Notable points that CHECKED OUT (no action)

- **Constraint names** in C2 are exact: `product_variants_tenant_barcode_unique`, `product_variants_tenant_sku_unique`, `product_variants_default_unique` are literal `CREATE UNIQUE INDEX` names, and the `variant_code` one is the Laravel default `product_variants_product_id_variant_code_unique` from `$table->unique(['product_id','variant_code'])`. SQLSTATE-first gating (`errorInfo[0]==='23505'`) is the right primary check. *(migration `…100003` L32-43)*
- **Partial indexes are pgsql-only** (`if getDriverName()==='pgsql'`). The plan correctly marks A3 restore / C2 race as "needs PostgreSQL". On SQLite the barcode/sku partial uniques don't exist; only `variant_code` plain unique does. *(migration L37)*
- **`barcode max:100`** matches `string('barcode', 100)`. Tightening from 255 is correct and non-breaking. *(migration L21; requests currently `max:255` at `CreateVariantRequest.php:32`, `UpdateVariantRequest.php:31`)*
- **Route param `{id}`** for update/destroy ⇒ C1's `$this->route('id')` and D2's `$id` are both correct. *(routes.php L51-52)*
- **`api.post` vs `apiPost`:** `api` is the exported axios instance returning the full `AxiosResponse`; `response.data` is the whole body `{ data, meta }`. `apiPost` returns `response.data.data` (strips `meta`). F1's switch to `api.post` + `response.data` is correct and necessary. *(lib/api.ts:192, 205-208)*
- **Inline 422 shape** (`error.response.data.errors.barcode[0]`): `DuplicateBarcodeException::asValidation` uses `ValidationException::withMessages(['barcode'=>[...]])` → standard Laravel `{ message, errors:{ barcode } }`. Correct. (Note: `getErrorMessage`/`isApiError` only recognize `{ error }`-shaped bodies, so reading `errors.barcode` directly in G4 is the right call, not via `getErrorMessage`.) *(lib/api.ts:50-56)*
- **Cross-module contract:** `VariantStockReader` mirrors the existing `LocationStockReader` (Shared/Contracts, implemented by Inventory, bound in `InventoryServiceProvider::register`). Binding pattern and module-boundary cleanliness are correct. *(InventoryServiceProvider.php:29; Shared/Contracts/LocationStockReader.php)*
- **DTO `attribute_values` as last positional param + `fromModel`:** `ProductVariantData` is a positional Spatie `Data`; adding a defaulted `array $attribute_values = []` last and populating from the loaded relation only is consistent with the existing constructor/`fromModel`. Spatie `Data` does hold `array<int, VariantAttributeValueData>`. *(ProductVariantData.php:22-57)*
- **`attributeValues()` HasMany** on `variant_id` matches the junction model's `variant_id` column. *(ProductVariant.php; ProductVariantAttributeValue.php:32-44)*
- **`listForAttribute(...)->whereIn('id', ...)`:** returns `Collection<int, ProductAttributeValue>` (Support Collection); `->whereIn('id', $valueIds)` filters in-memory correctly. *(AttributeValueRepository.php:19)*
- **OnboardingStep exhaustive matches:** `isRequired()`/`label()`/`settingsPath()` are exhaustive `match` over all cases, and `OnboardingChecklistService::getStatus` likewise — adding `ProductOptions` requires arms in all four (plan E1 step 3 + E2 cover this; omission would throw `UnhandledMatchError`). *(OnboardingStep.php:16-46; OnboardingChecklistService.php:29-36)*
- **`DB::afterCommit` nesting preserved:** A3 still routes new combos through `createVariant()` (which keeps the `afterCommit` event dispatch inside the outer `DB::transaction`), so event-after-commit semantics are intact. Restores call `restore()` directly (no `ProductVariantCreated` — correct, it's a revival, not a creation). *(ProductVariantService.php:43-96)*
- **Default-variant logic:** `$hasExistingDefault` derived from active (non-trashed) variants; `$first && !$hasExistingDefault` passed to `commandFor`. A restore-first scenario sets `$first=false` after the restore, so a subsequent CREATE won't grab default — and a restored row keeps whatever `is_default` it had. Sane: re-running on a product that already has any active default never adds a second; the partial-unique `product_variants_default_unique` is the backstop. *(plan L450, L481-486)*
- **`variantsToggleSeededRef` hydration-guard pattern** referenced by G2 genuinely exists (`useRef(false)` + once-effect). *(ProductForm.tsx:196-199)*
- **Factories** named in the fixture block all exist. *(database/factories/Catalog/)*

---

## Spec-coverage check (§3.x → task)

| Spec requirement | Task(s) | Covered |
|---|---|---|
| §3.1 value-subset axes + 422 cap (HTTP) | A1 | yes |
| §3.1 service-boundary cap (defense in depth) | A2 | yes (see finding #1 on test shape) |
| §3.1 junction-keyed idempotency + restore-on-regenerate + FOR UPDATE | A3 | yes |
| §3.1 gross-cap semantics (∏\|value_ids\|, not net-new) | A1 (`$gross`) + A2 + A3 | yes |
| §3.1 restore sku/barcode-collision → 422 (not 500) | — | **partial** — A3 restores via `restore()` but the plan does not explicitly wrap the restore in the 23505→422 mapper for the rare active-sku/barcode collision the spec calls out (§3.1 "Restore edge case"). C2's catch covers create/update saves, not `restore()`. Low risk (sku deterministic), but the spec names it; recommend A3 route the restore through the same QueryException catch or note it as accepted residual. |
| §3.1 non-breaking `data` array + `meta` counts | A4 | yes |
| §3.2 DTO `attribute_values` + relation + eager-load + TS | B1, B2 | yes |
| §3.3 barcode uniqueness FormRequest + max:100 | C1 | yes |
| §3.3 race-safe 23505 mapper (create+update), constraint-specific | C2 | yes (see finding #5 on `updateVariant` body) |
| §3.3 best-effort conflict-name (tenant-scoped, generic fallback) | C2 | yes |
| §3.4 onboarding step (enum + check + i18n) | E1, E2, H1 | yes |
| §3.5 frontend rework (chips, count, warn/cap, hydrate, orphan, inline 422, delete confirm/deactivate) | G1-G5 | yes (see finding #4 on hook-in-loop) |
| §3.6 api.post + meta; useAttributeValues; type pickup | F1, F2, B2 | yes |
| §3.7 delete policy — block on on-hand stock via Inventory contract | D1, D2 | yes |

**One genuinely under-specified spec item:** §3.1's "Restore edge case" (reviving a soft-deleted variant whose sku/barcode now collides with an active row must surface 422, not 500) is not wired into any task's restore path. Everything else maps cleanly.

---

## Non-runnable / placeholder check

No blocking placeholders. Test bodies use `// ...` seeding comments but the plan adds a concrete "Test fixtures" block pointing at the real factories + the `ProductVariantServiceMatrixTest` pattern, which is sufficient for a competent executor. The one place an executor could go wrong mechanically is finding #4 (hook-in-loop) and finding #1 (A2 test shape vs ordering).
