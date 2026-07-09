# Codex adversarial review — precision/input/view-unify handover + audits (2026-07-09)

Read-only Codex review of `CODEX-TASK-precision-and-pricing-unify.md` + the two audit inventories, BEFORE dispatch. **Verdict: NOT safe to dispatch as-is.** All findings code-verified with `file:line`.

## BLOCKERs
1. **`eco_tax_rate` is a FRACTION, not a percent-VALUE** — `DocumentLine.php:54`, `ReceiptLine.php:48` + migrations `2026_05_01_000004…:27`, `…000005…:27` document it as a decimal fraction; no writer proves otherwise. → REMOVE from the percent-VALUE/`decimal(2)` migration scope; keep `decimal(N,4)`. (My audit's "verify" was too soft — it would have been narrowed and corrupted.)
2. **POS receipt VAT `tax_rate` is NOT presentation-only** — it is concatenated into the receipt hash (`ReceiptHashService.php:117`), reloaded for verification (`:429`), and drives the V3 canonical payload `vat_breakdown.rate` (`V3ReceiptHashComputer.php:104`). Any change to its serialization/storage is a **canonical-byte change** → golden fixture parity required.
3. **Z-report VAT rate type change alters the hash** — device/API write numbers via `parseFloat(rate)` (`zReportService.ts:811`, `reportApi.ts:451`); the Z-report hash is `JSON.stringify(normalized_report_data)` (`zReportHashService.ts:119`, server `ZReportHashService.php:60`). Changing `tax_rate: number`→`string` changes the hashed bytes. Track A4 is fiscal-canonical, not cosmetic.
4. **Narrowing workshop `tax_rate` 3dp→2dp is unsafe as-is** — validators accept 3dp (`StoreBundleRequest.php:30`, `AddLineRequest.php:33`), writer formats scale 3 (`WorkOrderLineService.php:70`) → existing rows may hold real 3dp. Needs a tenant preflight (detect `rate != round(rate,2)`) + validators/writers changed first.
5. **Track B: the edit/view section registry is NOT shared** — edit uses local `EDITOR_SECTIONS`/`HERO_BLOCKS` inside `ProductForm` (`:174,186`); view uses a SEPARATE `PRODUCT_DETAIL_SECTIONS` from `viewSections.ts:20`. The plan's "reuse the existing registry" is false; this is a real shared-section EXTRACTION refactor.

## MAJORs
- **`formatPercentage` (`format.ts:186`) is not a safe canonical formatter** — it `parseFloat`s (launders through float!) and emits fixed 2dp via Intl → `19.00%`, not trimmed `19%`. A0 must BUILD/FIX a string-safe trimmed formatter, not "adopt existing unchanged."
- **Service & Product-margin narrowing also need a preflight** — casts are 3dp (`Service.php:102`, `Product.php:166`), factories emit `19.000`/3dp (`ServiceFactory.php:48`); down-migration can't restore discarded decimals.
- **`services.base_price` is a MISSED polymorphic percent/money field** — `PricingType.php:12` includes percentage pricing; `ServiceData.php:46` formats it as currency but UI appends `%` (`ServiceListPage.tsx:298`, `ServiceDetailPage.tsx:262`). Treat like Coupon/Promotion, not a raw-display nit.
- **Coupon/Promotion split needs an API-compat design** — readers use `discount_value` (`CouponValidationService.php:75`, `PromotionEvaluationService.php:418`); a column split needs dual-read/write + DTO/request migration + backfill, or defer.
- **FacturX is a legal-artifact serialization boundary** (`FacturXService.php:248`) — add XML golden/regression tests even though it's not hash-chained.
- **Track C: the 3 `Blur*Input` differ** (`VariantEditor.tsx:27` `min=-999999`; `RecipeLineEditor.tsx:13` min/max; `ModifierGroupFormPage.tsx:45` placeholder + `min=0`) → shared component must pass through `min/max/placeholder/error/disabled/className`. AND they init `useState(initialValue)` once (`RecipeLineEditor.tsx:23` etc.) → **no resync when `initialValue` changes**; the extracted component needs focused-aware prop sync or table rows show stale drafts after mutation/refetch.
- **Track B coupling** — edit sections use `register`/`Controller`/`watch`/`setValue` throughout (`ProductForm.tsx:274,1281,1333`); the edit hero owns barcode-lookup + image-upload (`ProductEditHero.tsx:93,198`); view vertical-gating differs from edit (`ProductDetailPage.tsx:213` renders automotive only when data exists vs edit `ProductForm.tsx:1451` by vertical; edit has parapharmacy/module-gated sections absent from view). All bigger than the plan implied.
- **Sequencing** — `ProductForm.tsx` is touched by ALL THREE tracks (A `:934`, C `:766`, B `:1064`); do NOT parallelize. Central formatter + DTO type fixes first; Track B consumes the fixed components last.

## Recommended order (Codex)
1. Correct plan/audits (eco-tax fraction, formatter, workshop/service/product preflight, Z-report fiscal, coupon/promotion + services.base_price polymorphism, Track B = extraction refactor).
2. A0 formatter/type-contract guardrails.
3. Backend A1/A2 with tenant preflight migrations + fiscal tests.
4. C1–C4 draft inputs.
5. A3 web display sweep.
6. POS fiscal changes as a SEPARATE gated phase with canonical fixture parity.
7. Track B last, only after owner-approved design/refactor proposal.
