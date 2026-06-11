VERDICT: REQUEST-CHANGES — confidence HIGH

## Summary
Spec v3.1 resolves the r2 ACCOUNT_CHARGE safety claim by deferring the value correction, and it adds the previously missed POS order/held-order/receipt/refund/NF525/print surfaces. The requested CHECK-A scan still found backend `unit_price` surfaces that are neither in the Zone-1 keep-list nor in a rename/disposition list. The largest remaining risk is internal inconsistency: the spec says ACCOUNT_CHARGE stays net during this rename, while the top-level Zone-1/canonical-contract wording still says device-authored canonical `unit_price` is always inclusive. No signed-byte surface appears to have been accidentally put in a rename list.

## Resolution Check of r2 Findings
- B1 — RESOLVED: §2 line 39, §4 lines 83-89, §7 line 135, and §11 line 205 all state the ACCOUNT_CHARGE value correction is out of this rename and the facture bridge only renames its document write target while the value stays net.
- M1 — PARTIALLY RESOLVED: §4 lines 73-74 and §7 lines 148-149 add the r2 POS order/held-order/receipt/refund/NF525/print surfaces, but CHECK-A found additional backend POS-inclusive surfaces at `apps/api/app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:75` and `apps/api/app/Modules/POS/Application/DTOs/ExchangeRequestInput.php:31`.
- M2 — RESOLVED: §4 line 76 and §7 lines 128 and 151 define the wire-contract rule, and `rg "unit_price" apps/pos/src/api` found only `apps/pos/src/api/holdApi.ts:7`, `:27` and `apps/pos/src/api/reportApi.ts:106`, `:336`, `:554`.
- m1 — RESOLVED: §4 line 79 calls out replacing the `pos_receipt_lines` CHECK constraints; source constraints exist at `apps/api/database/migrations/tenant/2026_01_08_190638_create_pos_receipt_lines_table.php:77`, `:83` and `apps/api/database/migrations/tenant/2026_03_09_200000_add_return_fields_to_pos_receipts.php:75`, `:119`, `:125`. `pos_order_lines` has `unit_price` columns at `apps/api/database/migrations/tenant/2026_03_11_400001_create_pos_order_lines_table.php:25` and scale handling at `2026_05_29_100001_align_pos_orders_to_scale_3.php:52`, but no CHECK constraint referencing `unit_price` was found.
- m2 — RESOLVED for the r2 named generated/baseline/diagnostic/archive classes: §7 lines 155-160 accounts for `packages/shared/types/generated.d.ts`, `apps/api/phpstan-baseline.neon`, `TestTaxRecoverability`, `TestE2EGLPosting`, `apps/api/backup_before_phase0.sql`, and ACCOUNT_CHARGE fixtures.
- n1 — RESOLVED: §0 line 5 and §11 line 205 now describe the prior r1 blocker count as 2.

## New Findings

### BLOCKER
None.

### MAJOR

M1 — ACCOUNT_CHARGE net deferral conflicts with "always inclusive" canonical wording
- Spec section or file:line: §1 lines 19 and 23; §4 lines 83-89; §4 line 93.
- Description: §4 correctly says ACCOUNT_CHARGE canonical `unit_price` remains net during this rename, but §1 says Zone-1 signed canonical bytes for SALE_RECEIPT and ACCOUNT_CHARGE are "Always inclusive" and line 93 says "a device-authored canonical `unit_price` is always tax-inclusive." Those statements are false for ACCOUNT_CHARGE until the separate finalization work changes the value semantics.
- What to change: Qualify the rule as "SALE_RECEIPT is inclusive; ACCOUNT_CHARGE currently remains net until charge-to-account finalization" or explicitly carve ACCOUNT_CHARGE out of the "always inclusive" canonical wording for this rename.

M2 — Backend CHECK-A scan still finds unclassified rename surfaces
- Spec section or file:line: §7 lines 120, 133-153; see the file:line list in "Still-Unclassified Backend unit_price."
- Description: The table says every backend `unit_price` outside tests/archives must have a zone/phase/evidence entry, but the source still has unclassified backend net and POS-inclusive surfaces: document create/tolerance/draft docs, inventory landed-cost/goods-receipt services, billing/document Blade views, loyalty receipt scoring, POS exchange input, migration import synonyms, SmartPayment seed data, and several workshop request/controller/service/domain files not named by the current Workshop row.
- What to change: Add these files to Phase 1/Phase 2/disposition lists, or state a concrete reason they are intentionally excluded. For wire/API aliases such as import synonyms or exchange input, name the compatibility boundary and the backend mapping.

### MINOR

m1 — Stale open item contradicts the resolved wire-contract rule
- Spec section or file:line: §4 line 76; §7 line 151; §10 line 195.
- Description: §4/§7 say the wire contract has been decided: HTTP keeps `unit_price` and backend maps to/from `unit_price_incl_tax`. §10 still says to "Decide the held-order / order-resource / receipt-API external JSON contract," which reopens an already-set phase boundary.
- What to change: Replace §10 item 5 with a narrower implementation task, e.g. "Confirm all clients accept the kept wire `unit_price` contract and add mapping tests."

### NIT

n1 — "Only bare unit_price left" overstates the post-rename state
- Spec section or file:line: §1 line 23; §7 lines 125 and 128.
- Description: Line 23 says the only bare `unit_price` left is inside immutable signed bytes, but §7 intentionally keeps bare `unit_price` in POS device-local code and POS-backend wire API files.
- What to change: Reword line 23 to say the only bare backend queryable/storage field is gone, while POS local state, wire JSON, and canonical bytes keep `unit_price`.

## Still-Unclassified Backend unit_price
- `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:109` — document create request validates net `lines.*.unit_price`, but §4/§7 name `UpdateDocumentRequest` only.
- `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:128` — document create request validation message still bare/unclassified.
- `apps/api/app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:67` — document request concern computes tolerance from net `unit_price`.
- `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:50` — draft API example still documents bare `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:154` — goods-receipt landed cost fallback reads document line net `unit_price`; spec only names web goods-receipt.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:207` — backend landed-cost math reads document line net `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:387` — backend landed-cost docblock names bare `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:410` — backend landed-cost fallback reads document line net `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:425` — backend landed-cost return shape exposes bare `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:436` — backend landed-cost payload returns bare `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:440` — backend landed-cost fallback reads bare `unit_price`.
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:475` — backend landed-cost docblock defines line total using bare `unit_price`.
- `apps/api/resources/views/billing/invoice.blade.php:311` — billing invoice Blade view renders net invoice-item `unit_price`, but §7 Billing lists model/service only.
- `apps/api/resources/views/documents/components/line_items.blade.php:29` — document Blade line-items component renders net document-line `unit_price`, but §7 Document/Web lists do not include backend views.
- `apps/api/app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:75` — loyalty receipt listener reads POS receipt-line inclusive `unit_price`; not in Phase 2.
- `apps/api/app/Modules/POS/Application/DTOs/ExchangeRequestInput.php:31` — exchange new-sale item DTO accepts inclusive `unit_price`; not in Phase 2.
- `apps/api/app/Modules/Import/Services/MigrationWizardService.php:168` — import wizard treats source `unit_price` as a `base_price` synonym; not classified as external/import compatibility.
- `apps/api/database/seeders/SmartPaymentTestDataSeeder.php:102` — document net seed data still bare; §7 seed disposition names `DemoTenantSeeder` only.
- `apps/api/database/seeders/SmartPaymentTestDataSeeder.php:132` — document net seed data still bare; §7 seed disposition names `DemoTenantSeeder` only.
- `apps/api/database/seeders/SmartPaymentTestDataSeeder.php:162` — document net seed data still bare; §7 seed disposition names `DemoTenantSeeder` only.
- `apps/api/database/seeders/SmartPaymentTestDataSeeder.php:192` — credit-note/document net seed data still bare; §7 seed disposition names `DemoTenantSeeder` only.
- `apps/api/app/Modules/Workshop/Bundle/Application/Services/BundleAuthoringService.php:164` — writes `override_unit_price`; Workshop row names DTO/factories but not authoring service.
- `apps/api/app/Modules/Workshop/Bundle/Application/Services/BundleAuthoringService.php:165` — reads command `override_unit_price`; Workshop row does not name authoring service.
- `apps/api/app/Modules/Workshop/Bundle/Application/Services/BundleAuthoringService.php:227` — checks `override_unit_price_provided`; Workshop row does not name authoring service.
- `apps/api/app/Modules/Workshop/Bundle/Application/Services/BundleAuthoringService.php:228` — writes `override_unit_price`; Workshop row does not name authoring service.
- `apps/api/app/Modules/Workshop/Bundle/Application/Services/BundleAuthoringService.php:230` — formats command `override_unit_price`; Workshop row does not name authoring service.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Controllers/BundleComponentController.php:55` — maps request `override_unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Controllers/BundleComponentController.php:118` — maps patch `override_unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Controllers/BundleComponentController.php:119` — maps patch `override_unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Controllers/BundleComponentController.php:121` — checks `override_unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/AddComponentRequest.php:28` — validates `override_unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/AddComponentRequest.php:42` — validation message for `override_unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/PatchComponentRequest.php:37` — validates `override_unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/PatchComponentRequest.php:51` — validation message for `override_unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/Bundle/Domain/ServiceBundleComponent.php:28` — model property `override_unit_price`; Workshop row names DTO/factories but not model.
- `apps/api/app/Modules/Workshop/Bundle/Domain/ServiceBundleComponent.php:63` — fillable `override_unit_price`; Workshop row does not name model.
- `apps/api/app/Modules/Workshop/Bundle/Domain/ServiceBundleComponent.php:85` — cast `override_unit_price`; Workshop row does not name model.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Controllers/WorkOrderLineController.php:54` — maps request `unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Controllers/WorkOrderLineController.php:109` — maps request `unit_price`; Workshop row does not name controller.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/AddLineRequest.php:32` — validates work-order `unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/AddLineRequest.php:48` — validation message for work-order `unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/UpdateLineRequest.php:25` — validates work-order `unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/UpdateLineRequest.php:41` — validation message for work-order `unit_price`; Workshop row does not name request.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Commands/AddLineCommand.php:27` — command carries work-order `unit_price`; Workshop row does not name command.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Commands/UpdateLineCommand.php:20` — command carries work-order `unit_price`; Workshop row does not name command.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderLineService.php:69` — service writes work-order `unit_price`; Workshop row does not name service.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderLineService.php:123` — service checks update `unit_price`; Workshop row does not name service.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderLineService.php:124` — service writes update `unit_price`; Workshop row does not name service.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderLineService.php:256` — service reads work-order `unit_price`; Workshop row does not name service.
- `apps/api/app/Modules/Workshop/WorkOrder/Domain/WorkOrderLine.php:40` — model property `unit_price`; Workshop row names DTO/factories but not model.
- `apps/api/app/Modules/Workshop/WorkOrder/Domain/WorkOrderLine.php:98` — fillable `unit_price`; Workshop row does not name model.
- `apps/api/app/Modules/Workshop/WorkOrder/Domain/WorkOrderLine.php:128` — cast `unit_price`; Workshop row does not name model.
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:168` — adapter reads work-order `unit_price` to generate document lines; Workshop row does not name adapter.
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:195` — adapter writes document `unit_price`; Workshop row does not name adapter.

## Zone-1 Keep-List Audit
The keep-list is structurally correct for signed-byte safety: device canonical builders and PHP canonical-mirror DTOs are kept, and I did not find those signed-byte surfaces listed in a rename phase. The POS wire-contract files are correctly separated from device-local state in §7 line 128, and `apps/pos/src/api` has no other `unit_price` API files beyond `holdApi.ts` and `reportApi.ts`. The anomaly is semantic rather than structural: Zone 1 says ACCOUNT_CHARGE canonical `unit_price` is always inclusive (§1 line 19, §4 line 93), while the ACCOUNT_CHARGE deferral says its current canonical value stays net (§4 lines 87-89).

## Reviewer Notes
This review was intentionally targeted to the r2 resolution baseline plus the requested CHECK-A/B/C scans, not a full v1-style rebuild of the entire inventory. I was unable to verify the post-T2 inventory because this worktree is the spec branch snapshot, and §3/§10 correctly require re-running against post-T2 `dev` before execution. Generated/baseline/archive files named in r2 are now accounted for; the remaining coverage issue is other backend runtime/source files and `SmartPaymentTestDataSeeder`, not the original generated/baseline/archive set.
