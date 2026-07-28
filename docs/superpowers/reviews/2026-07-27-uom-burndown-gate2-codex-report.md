# Gate 2 report — UoM quantity-display baseline burn-down

## Scope and branch

- Plan: `docs/handoff/CODEX-uom-baseline-burndown-2026-07-22.md`
- Branch: `chore/uom-baseline-burndown`
- Gate 1 controller base: `b209afd68`
- Wave 2 implementation tip reviewed: `e8a9dc19914eafe1e30df6262cfbd74d8e5ae208`
- Review range: `b209afd68..e8a9dc199`, 16 commits
- Diff: 109 files, 2,418 insertions, 151 deletions

## Sites burned down

- Quantity-display baseline keys: 19 -> 0.
- Scanner result: 0 raw sites, 0 baselined, 0 new, and 0 stale entries.
- Batch 4A: POS cart, historical-sale, customer-display, kitchen-order, and web-POS sites.
- Batch 4B: discount breakdown aggregate, regrouped only by product identity so same-named products can carry their own UoM precision.
- Batch 5: landed cost, dashboards, billing, recipe cost, opening preview, bundles, and work orders.
- `BundleComponentFormModal` uses `QuantityInput`; no editable value was formatter-wrapped.

## Payloads and controllers touched

- POS device/read paths: cart, shift receipt history, customer display, offline receipt enrichment, and the Rust/Tauri bridge. Existing SQLite `products.quantity_decimals` is reused; no POS migration was added.
- POS order resources: line `quantity_decimals` plus batched product/UoM serialization.
- POS analytics: product-grained discount aggregation with UoM precision and legacy snapshot fallback.
- Workshop bundles: component and expansion precision, money/quantity scale separation, complete-graph paginator loading, and work-order expansion serialization.
- Miscellaneous resources: landed cost, owner dashboard stock alerts/top SKUs, billing invoice items, recipe costing, and inventory-opening preview.
- Gate 1 condition: StockTransfer serialization now applies the required `?? 4` wire fallback.

## Precision invariants covered

- Formatter inputs remain decimal strings at boundaries; POS number-backed quantities use `String(quantity)`, never `parseFloat`.
- Current product/UoM metadata supplies display precision when available; deleted/missing historical products retain the accepted scale-4 fallback.
- Historical receipt storage, fiscal hashes, snapshots, and signed bytes are not mutated; enrichment is presentation-only.
- Bundle quantity arithmetic uses unit precision and bcmath/string operations independently from monetary currency precision.
- USD scale-2 money with scale-3 quantity `1.234` remains `1.234`.
- Central billing quantity remains the explicit fixed scale 2 non-product contract.
- Discount chart semantics change only by splitting distinct products that share a name.

## Fresh Gate 2 verification

- API targeted suites: 94 tests, 390 assertions passed. Existing environment/file deprecation warnings remain non-failing.
- POS targeted Vitest: 3 files, 19 tests passed. Existing network-fixture and React `act(...)` stderr remains non-failing.
- Web full lint: exit 0, 0 errors; design-system audit 743 acknowledged, 0 new, 0 stale.
- Web typecheck: passed.
- POS full lint: exit 0, 0 errors; custom quantity RuleTester passed. Existing warnings remain.
- POS typecheck: passed.
- PHPStan level 8 over `app/Modules` plus `DocumentAdditionalCostController.php`: no errors with a 1 GB limit.
- Pint `--test` over every Wave 2 PHP file: passed.
- TypeScript transformer: 454 types regenerated; generated declaration diff remained empty.
- Quantity audit: exactly `[]`, 0 total/new/stale sites.
- React Doctor on the final-fix range: 90/100, no findings.
- `git diff --check` and worktree status: clean.
- The three known inventory failures named in the Gate 1 approval were not chased or changed.

## Review history

- Every Wave 2 batch received implementation review and scoped follow-up review.
- The whole-wave adversarial review raised six Important and two Minor findings. Commit `e8a9dc199` addressed historical-sale metadata, bundle quantity/currency scale separation, order and bundle query growth, three PHPStan findings, the stale design baseline, analytics string assertions, and the DTO example.
- The one permitted final scoped re-review confirmed all of those areas except the repeated-product online historical-receipt case below.

## Open Important finding

`ShiftController` computes a receipt line's display precision and then unsets the eager-loaded product's `unitOfMeasure` relation inside the line loop. Laravel reuses the same eager-loaded `Product` model instance for receipt lines sharing a `product_id`. The first line therefore receives the real precision, while a later line for the same product falls back to scale 4. A read-only reproduction yielded `[2, 4]` for two scale-2 lines. The current regression covers one current-product line and does not catch repetition.

The required correction is narrow: derive all presentation precisions before relation cleanup (or defer cleanup until enrichment completes) and add a repeated-product regression. No stored or signed receipt data needs to change.

## Gate status

**CHANGES REQUIRED — HARD STOP.** The quantity baseline is empty and all mechanical gates pass, but Gate 2 cannot be represented as approved while the repeated-product precision defect remains. No second fix wave was started, and Wave 3 remains untouched. Await controller review/adjudication before any further work.

## Controller-adjudicated correction — 2026-07-28

The controller confirmed the repeated-product defect and authorized one narrow correction on top of `d3b35367d`.

- RED: the expanded endpoint regression created two lines for one scale-2 product, one line for a distinct scale-3 product, and one deleted-product snapshot. Before the correction, the endpoint emitted `[2, 4, 3, 4]`; the repeated line failed at scale 4 exactly as reviewed.
- Fix: `ShiftController` now builds the complete `product_id -> quantity_decimals` presentation map before mutating any eager-loaded relation, enriches every receipt line from that map, and only then removes nested `unitOfMeasure` relations.
- Contract coverage: the regression requires `[2, 2, 3, 4]`, preserves the distinct-product precision and deleted-product scale-4 fallback, and reasserts that quantities remain unchanged in storage with no persisted `quantity_decimals` attribute.
- Scope: presentation-only. No receipt snapshot, signed field, fiscal hash, migration, inventory test, or Wave 3 file changed.

### Correction verification

- Targeted PHPUnit: `ShiftReceiptQuantityPrecisionTest.php` — 1 test, 22 assertions passed. The existing non-failing environment warning remains.
- PHPStan level 8 on `ShiftController.php`: no errors.
- Pint `--test` on the controller and regression test: passed.
- Quantity audit: `0 total (0 baselined, 0 new, 0 stale baseline entries)`; baseline remains exactly `[]`.
- Diff hygiene: `git diff --check` passed before commit.

## Current Gate 2 status

**CORRECTION COMPLETE — HARD STOP.** The controller-confirmed Important finding is covered and corrected, but Gate 2 is not self-approved. Wave 3 remains untouched. Await the controller's full external fiscal-POS and frontend-conventions review on the final branch tip.
