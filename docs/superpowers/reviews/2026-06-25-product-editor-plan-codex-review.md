## Executive summary

The plans are directionally strong, but they are not yet safe as implementation specs because several “no backend change” and “all fields survive” claims conflict with current source. The highest risk is regression from existing edit-mode image and variant functionality: the staged plan defers media while the current form already renders product images and variant matrix controls. The barcode single-field/YAGNI decision is technically compatible with the current schema, but only if the actual `BarcodeLookupInput` behavior, not just a visual input, is moved into the hero. Units and `type`/`is_physical` both need backend invariants, because the current API accepts divergent values and the DTO does not expose enough state for reliable selects. Overall verdict: proceed only after tightening the plan around response contracts, legacy mirrors, edit-mode regression guards, and backend consistency rules.

## 1. NO-REGRESSION RISK

**HIGH — Edit-mode media and variants are at risk.** Plan §5 says Media & Files are deferred or placeholder-only (`2026-06-25...` lines 123-126), and the staged plan says the editor ships without media (`2026-06-24...` lines 52-60). Actual `ProductForm` already renders `ProductImageSection` in edit mode and a `ProductVariantMatrixEditor` when editing (`apps/web/src/features/inventory/ProductForm.tsx:847`, `:855`). This is a no-regression miss unless Stage 1 explicitly preserves those existing edit-mode sections or states that only create-mode ships.

**MEDIUM — Automotive expansion is acknowledged but underspecified for Otospex go-live.** The field-model plan lists the backend automotive fields and defers the section (`2026-06-25...` lines 131-132, 149). Actual schema/DTO support article number, supplier brand, product group, tire/glass specs, vehicles, criteria, and cross references (`apps/api/database/migrations/tenant/2026_03_09_300000_create_automotive_product_metadata_tables.php:22`, `:70`, `:88`, `:108`; `apps/api/app/Modules/Product/Application/DTOs/AutomotiveProductMetadataData.php:28`). If Otospex is in launch scope, “follow-on wave” is not no-regression; if it is not, call that out as a launch exclusion.

**LOW — CIP/ACL-specific parapharmacy fields were not found.** The actual parapharmacy table has generic `regulatory_code`, plus certifications/claims/components (`apps/api/database/migrations/tenant/2026_01_05_105259_create_parapharmacy_product_metadata_table.php:34`). Searches for CIP/ACL-specific columns found no dedicated fields, so the plan’s generic regulatory-code coverage is acceptable unless product has a separate external regulatory source not in this repo. Unverified — needs manual check against imported parapharmacy source files or customer data mappings, not the app schema.

## 2. BARCODE SINGLE-FIELD / YAGNI DECISION

**LOW — The schema does not block future central minting or format-based lookup.** The plan’s final decision keeps one nullable `barcode` and no uniqueness constraint (`2026-06-25...` lines 17-21, 187). Current schema has `barcode` nullable with a non-unique tenant index (`apps/api/database/migrations/tenant/2025_11_30_052910_create_products_table.php:27`, `:39`), while SKU is the unique tenant key (`:37`). POS lookup already treats the scan token as barcode-or-SKU and allows multiple matches for chooser resolution (`apps/pos/src/api/productApi.ts:17`). Nothing here blocks format-based resolution later.

**HIGH — The hero must embed lookup behavior, not duplicate visual state.** Plan §4 correctly says to replace the hero plain input with existing `BarcodeLookupInput` logic and remove the General copy (`2026-06-25...` lines 66-70). Current branch has `BarcodeHero` as a controlled visual input (`apps/web/src/features/products/editor/components/BarcodeHero.tsx:78`) while the real lookup/scanner/debounce lives in a second `BarcodeLookupInput` still mounted in General (`apps/web/src/features/inventory/ProductForm.tsx:559`; `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx:14`, `:35`, `:45`). Shipping this state leaves two barcode inputs and drops lookup from the hero.

## 3. UNITS

**HIGH — The mirror plan needs server-side enforcement.** Plan §2 says saving `unit_id` must also keep writing legacy `unit` (`2026-06-25...` line 45). Current create/update simply splats validated payload into `Product::create()` / `update()` after resolving only the opposite direction, free-text `unit` to `unit_id` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:321`, `:348`, `:459`, `:477`, `:724`). If a new `UnitSelect` sends only `unit_id`, legacy readers using `Product::getSellableUnit()` will still read stale/null `unit` (`apps/api/app/Modules/Product/Domain/Product.php:231`). Add a backend mirror from selected Unit code/symbol into `unit`, plus tests for create, update, and partial PATCH.

**MEDIUM — ProductData does not expose `unit_id`.** The plan wants a select that preselects by `unit_id` and falls back to raw string (`2026-06-25...` lines 45-46), but current `ProductData` returns `unit` and `quantity_decimals`, not `unit_id` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:33`, `:65`). The existing migration intentionally keeps both columns (`apps/api/database/migrations/tenant/2026_01_09_095108_add_unit_id_to_products_table.php:13`), and a backfill logs unmapped legacy strings (`2026_01_09_100552_map_product_units_to_uom_ids.php:115`). Require DTO/TS updates and a migration report path for unmapped unit strings.

## 4. TYPE-SELECT DERIVES `is_physical`

**HIGH — Backend consistency is not guaranteed.** Plan §3 replaces the checkbox with a Type select deriving `is_physical` (`2026-06-25...` line 61), but current requests accept `type` and `is_physical` independently (`CreateProductRequest.php:90`; `UpdateProductRequest.php:91`). The model uses `is_physical` for stock tracking (`apps/api/app/Modules/Product/Domain/Product.php:236`), POS sync persists flips (`apps/pos/src/lib/sync/productDiff.ts:27`), and historical migration comments tie it to delivery-note/fiscal behavior (`2025_12_12_100000_add_is_physical_to_products_table.php:18`). Add server-side derivation/validation in create/update, preferably rejecting contradictory payloads.

**MEDIUM — Planned type options include unsupported values.** The plan lists “Storable/Consumable/Service/Part” (`2026-06-25...` lines 61, 89), but backend enum supports only `part`, `service`, `consumable` (`apps/api/app/Modules/Product/Domain/Enums/ProductType.php:7`). Sending `storable` will 422.

## 5. CONTROLS

**MEDIUM — Toggle reasoning is inconsistent.** Plan §3 says toggles imply instant effect (`2026-06-25...` line 55), then assigns `requires_batch_tracking` to a toggle (`:59`) even though the current form persists it on submit and only conditionally reveals shelf-life input (`apps/web/src/features/inventory/ProductForm.tsx:682`, `:697`). The better distinction is “mode/feature flag with dependent fields” versus “ordinary attribute,” not instant effect. The resulting controls are mostly reasonable, but the rationale should be corrected so future reviewers do not convert other save-on-submit flags by mistake.

## 6. SEQUENCING / DEPENDENCIES

**MEDIUM — Stage 1 can ship only if it preserves existing non-schema fields.** Stage 1 claims “all fields are EXISTING ones; no schema change” (`2026-06-24...` line 107), but the field-model plan’s Stage 1/1.7b sequence also includes `is_active_for_ecommerce`, `unit_id`, `purchase_price`, and `requires_batch_tracking` wiring (`2026-06-25...` lines 140-147). Some backend support exists, but current frontend payload lacks `unit_id`, `purchase_price`, and `is_active_for_ecommerce` (`apps/web/src/features/inventory/ProductForm.tsx:81`). Split pure layout from new field wiring or Stage 1 is not independently shippable.

**LOW — StockMovement reason dependency is real but partly already present.** The plan gates Stage 3 on in-flight reason work (`2026-06-24...` lines 201-207). Current schema already has `stock_movements.reason` (`2025_12_24_133827_extend_stock_movements_table.php:13`) and `MovementReason::OpeningBalance` (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:15`). Unverified — needs manual check of the parallel branch/PR, because this repo alone does not prove a future “reason-linking” contract beyond the existing string/enum.

## 7. GO-LIVE GAPS

**HIGH — Precision rule conflict remains in the editor.** The staged plan forbids `parseFloat` for money/qty (`2026-06-24...` line 27), but current WAC display uses `parseFloat(product.cost_price).toFixed(decimals)` (`apps/web/src/features/inventory/ProductForm.tsx:649`). Add this to Stage 1 cleanup or it will survive the redesign.

**MEDIUM — Permissions and module gates need acceptance tests across both layers.** The Stage 2 brand routes plan includes permissions (`2026-06-24...` lines 175-179), and loyalty gating is deferred to module ownership (`docs/handoff/HANDOFF-loyalty-product-fields.md:5`). The field-model plan says loyalty fields render visually now when active (`2026-06-25...` line 160), but there is no product-owned persistence decision. Keep them hidden until the Loyalty-owned read/write API exists, or add explicit placeholder tests proving no payload is submitted.

## Prioritized fix list

1. **HIGH:** Preserve or explicitly exclude existing edit-mode `ProductImageSection` and variant matrix before Stage 1 ships.
2. **HIGH:** Move real `BarcodeLookupInput` behavior into `BarcodeHero`; remove the duplicate General instance.
3. **HIGH:** Add backend unit mirror enforcement from `unit_id` to legacy `unit`, and expose `unit_id` in `ProductData`.
4. **HIGH:** Enforce atomic `type`/`is_physical` consistency server-side and remove unsupported `storable` option.
5. **HIGH:** Remove `parseFloat` money formatting from the editor.
6. **MEDIUM:** Split pure layout Stage 1 from new field wiring, or mark Stage 1 non-shippable until payload/DTO work lands.
7. **MEDIUM:** Correct the toggle-vs-checkbox rationale and add tests for dependent toggle UI.
8. **LOW:** Verify the external StockMovement reason branch and parapharmacy CIP/ACL data mappings manually.
