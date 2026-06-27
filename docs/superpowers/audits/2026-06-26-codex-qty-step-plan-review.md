## BLOCKING Issues

1. Task 1 will silently return fallback `4` unless the shared document relation lists are changed, not just scattered controller loads.

   `DocumentData::fromModel()` serializes only the already-loaded `$document->lines` relation and calls `DocumentLineData::fromModel($line, $scale)` for each line at `apps/api/app/Modules/Document/Application/DTOs/DocumentData.php:74` and `apps/api/app/Modules/Document/Application/DTOs/DocumentData.php:75`. The shared loaders currently load only `lines`: `defaultRelations()` returns `['lines']` at `apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php:96`, and `detailRelations()` returns `['lines', 'allocations.payment.paymentMethod']` at `apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php:111`.

   That affects the normal show/store/update paths the plan names: invoice show uses `detailRelations()` at `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:142`, invoice store refreshes with `defaultRelations()` at `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:280`, and invoice update refreshes with `defaultRelations()` at `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:407`. The same pattern exists for quotes at `apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:127`, `apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:265`, and `apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:390`; sales orders at `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php:127`, `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php:265`, and `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php:390`; purchase orders at `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:136`, `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:274`, and `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:401`; return notes at `apps/api/app/Modules/Document/Presentation/Controllers/ReturnNoteController.php:123`, `apps/api/app/Modules/Document/Presentation/Controllers/ReturnNoteController.php:273`, and `apps/api/app/Modules/Document/Presentation/Controllers/ReturnNoteController.php:406`; delivery notes at `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:132`, `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:270`, and `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:339`.

   Correct fix: put `lines.product.unitOfMeasure` in both shared relation lists or prove every consumer overrides them. Otherwise a `DocumentLineData::fromModel()` implementation that checks `relationLoaded('product')` / `relationLoaded('unitOfMeasure')` will fall back to `4` on these production responses.

2. Task 1 misses credit-note and generic document response paths even though the frontend line editor handles `credit_note`.

   `DocumentLineEditor` explicitly maps `credit_note` in `DOCUMENT_TYPE_MAP` at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:27`. Credit-note show loads only `['partner', 'sourceDocument', 'lines']` at `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:76`, confirm reloads only that same relation set at `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:295`, and post serializes `DocumentData::fromModel($postedCreditNote)` at `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:359` without showing any `product.unitOfMeasure` reload. Generic `GET /api/v1/documents/{document}` also loads only `['lines', 'vehicleContext']` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:147` before calling `DocumentData::fromModel()` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:160`.

   These are real response paths, not theoretical. If they are left out, a saved credit note or generic document detail response will still serialize line quantity precision as fallback `4`.

3. The plan's AuthorizationException ability claim is not implementable as written.

   `UomController` calls `Gate::authorize('uom.view')` directly at `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:36`, `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:68`, and `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:102`. Laravel's `Gate::authorize()` delegates to `inspect(...)->authorize()` at `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:396` and `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:398`. When the gate result is a bare false, `inspect()` creates `Response::deny()` with no ability code at `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:419`. `Response::authorize()` throws an `AuthorizationException` carrying only the response message/code at `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/Response.php:151` and `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/Response.php:152`. `AuthorizationException` itself has response/status accessors, but no `ability()` property or method at `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/AuthorizationException.php:43` and `apps/api/vendor/laravel/framework/src/Illuminate/Auth/Access/AuthorizationException.php:99`.

   Therefore a global handler cannot reliably recover `'uom.view'` from the exception thrown by the current `Gate::authorize('uom.view')` calls. The reliable fix is to stop using raw `Gate::authorize()` for permissions whose ability must be surfaced: add a small app helper/trait/custom exception that receives the ability string, calls `Gate::inspect($ability)`, and when denied throws an exception with an explicit ability field or a `Response::deny(..., $ability)` set on the exception. Then the handler can read that explicit field/code. Without that, Task 13's test asserting `error.ability === 'uom.view'` is a false requirement against the current framework behavior.

4. The plan underestimates frontend type-source mismatches; regenerating `packages/shared/types` will not update the document editor/reload path.

   The generated `DocumentLineData` lives in `packages/shared/types/generated.d.ts:635`, but `DocumentForm` imports a local hand-written `Document` type at `apps/web/src/features/documents/DocumentForm.tsx:24`, and that local line interface has no `quantity_decimals` field at `apps/web/src/types/document.ts:18`. `DocumentLineEditor` also declares local `Product` and `DocumentLine` interfaces at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:31` and `apps/web/src/features/documents/components/DocumentLineEditor.tsx:56`. Adding the PHP DTO field and running `typescript:transform` will update `packages/shared/types/generated.d.ts`, but it will not make `DocumentForm.tsx`'s `l.quantity_decimals` compile unless `apps/web/src/types/document.ts` is also updated.

5. Task 7's "batch DTO/resource" language is too vague and will send an implementer down the wrong path if they look for a DTO.

   The expiry write-off page calls `getExpiredBatches()` at `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:68`, and that helper calls `GET /batches/expired` at `apps/web/src/features/batches/api/batches.ts:169`. The route is `GET /api/v1/batches/expired` at `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:15`, served by `BatchController::expired()` at `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:234`, which returns `BatchResource::collection($batches)` at `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:242`. The resource is `BatchResource`, not a DTO, at `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:15`, and it currently emits only product `id`, `name`, and `sku` at `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:45`.

   The product chain is valid: `Batch::product()` exists at `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:58`, and `Product::unitOfMeasure()` exists at `apps/api/app/Modules/Product/Domain/Product.php:306`. But the service currently eager-loads only `['product', 'batchStock']` at `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:303`. The concrete backend fix is `FEFOInventoryService::getExpiredBatchesWithStock()` plus `BatchResource`, and the frontend type to update is the hand-written `ExpiredBatch.product` object at `apps/web/src/features/batches/types.ts:297`.

## SHOULD-FIX Issues

1. Task 1 includes `DraftPersistenceService` in the response audit, but the draft endpoint does not build `DocumentData`.

   `DraftController::autoSave()` calls the service at `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:72`, but the response contains only `draft_id`, `saved_at`, and `line_count` at `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:80`, `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:82`, and `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:83`. `DraftPersistenceService::createNewDraft()` returns `$document->load('lines')` at `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:144`, but that is not serialized through `DocumentData` on this endpoint. Do not spend Task 1 effort there unless another caller is found.

2. Several conversion endpoints return Eloquent documents with only `lines`, not `DocumentData`.

   Quote-to-order returns `$order->load(['lines', 'partner', 'vehicleContext'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:36`, order-to-invoice returns `$invoice->load(['lines', 'partner', 'vehicleContext'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:75`, order-to-delivery returns `$delivery->load(['lines', 'partner', 'vehicleContext'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:104`, invoice-to-credit-note returns `$creditNote->load(['lines', 'partner', 'sourceDocument'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:216`, delivery-notes-to-invoice returns `$invoice->load(['lines', 'partner', 'vehicleContext'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:275`, and purchase-order receive conversion returns `$updatedPurchaseOrder->load(['lines', 'partner', 'vehicleContext'])` at `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:349`.

   These are outside the plan's `DocumentData` framing, but they are response paths that can feed document screens. If the acceptance bar is "reload path keeps unit-aware qty step", the plan should either scope them out explicitly or add `lines.product.unitOfMeasure`.

3. Task 2's product-list premise is correct, but the local product type is still the gate.

   `GET /products` eager-loads `unitOfMeasure` in the list query at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:73` and `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:90`. `ProductData` already has `quantity_decimals` at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:39`, and derives it from a loaded unit relation at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:103`. The document editor's local `Product` interface lacks the field at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:31`, so the plan's local-interface edit is required regardless of generated types.

4. Task 2's `getByLabelText(/quantity/i)` works for a single quantity input, but not for multi-line tests.

   `DocumentLineEditor` passes the quantity label as `ariaLabel` at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:361`, `QuantityCell` forwards that to `aria-label` at `apps/web/src/components/molecules/line-items/LineItemsTable.tsx:199`, and `QuantityInput` spreads that prop to the `<input>` at `apps/web/src/components/atoms/QuantityInput/QuantityInput.tsx:76`. So one-line tests can use `getByLabelText(/quantity/i)`. A test with multiple document lines will have multiple identical labels and should use `getAllByLabelText`.

5. `parseFloat` will not break the step-attribute test, but it remains precision debt.

   The quantity cell still parses changes with `parseFloat(value) || 0` at `apps/web/src/features/documents/components/DocumentLineEditor.tsx:359`, and `DocumentForm` reloads saved quantities with `parseFloat(l.quantity)` at `apps/web/src/features/documents/DocumentForm.tsx:251`. However, the rendered input value is `String(value)` at `apps/web/src/components/molecules/line-items/LineItemsTable.tsx:195`, and the `step` attribute comes only from `decimalPlaces` at `apps/web/src/components/atoms/QuantityInput/QuantityInput.tsx:56`. So number-stringification should not cause a step mismatch in the proposed tests.

6. Task 5/6 should not add a local `StockLevel` interface field in `StockLevelsPage`.

   The backend index currently eager-loads product and location at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php:32`, but it does not eager-load `product.unitOfMeasure`. `StockLevel::product()` exists at `apps/api/app/Modules/Inventory/Domain/StockLevel.php:86`, and `Product::unitOfMeasure()` exists at `apps/api/app/Modules/Product/Domain/Product.php:306`, so the relation chain is loadable. On the frontend, `StockLevelsPage` imports `StockLevel` from `./types` at `apps/web/src/features/inventory/StockLevelsPage.tsx:34`, and `apps/web/src/features/inventory/types.ts:12` aliases it directly to generated `App.Modules.Inventory.Application.DTOs.StockLevelData`. The selected row is stored without remapping at `apps/web/src/features/inventory/StockLevelsPage.tsx:186` and `apps/web/src/features/inventory/StockLevelsPage.tsx:187`. After the DTO and generated types are updated, `selectedStock` will carry the field; a local interface edit is the wrong instruction here.

7. Task 8 must import and reuse `useUnits()` in `ProductForm`; `UnitDropdown` does not expose its units list.

   `ProductForm` imports `UnitDropdown` at `apps/web/src/features/inventory/ProductForm.tsx:40` and renders it at `apps/web/src/features/inventory/ProductForm.tsx:671`. `UnitDropdown` owns the units query internally by calling `useUnits(categoryId)` at `apps/web/src/features/uom/components/UnitDropdown.tsx:27`. `ProductForm` currently watches `unit_id` inline at `apps/web/src/features/inventory/ProductForm.tsx:673`, but it has no `units` variable in scope. The hook to reuse is `useUnits()` from `apps/web/src/features/uom/hooks/useUnits.ts:87`.

8. Task 8's unit field naming needs to be precise.

   The generated backend `UnitData` uses camelCase `decimalPlaces` at `apps/api/app/Modules/Uom/Application/DTOs/UnitData.php:21`, and the generated TS type has `decimalPlaces` at `packages/shared/types/generated.d.ts:1699`. The local UOM API type is not the generated type: it declares required snake_case `decimal_places` at `apps/web/src/features/uom/api/uomApi.ts:22` and optional camelCase `decimalPlaces` at `apps/web/src/features/uom/api/uomApi.ts:23`. Since `useUnits()` returns that local `Unit[]` through `fetchUnits()` at `apps/web/src/features/uom/api/uomApi.ts:71`, implementation should read `selectedUnit?.decimalPlaces ?? selectedUnit?.decimal_places` unless the local API type is first normalized.

9. Task 12's seeder edit is structurally additive-safe, but role grants are explicit and security-sensitive.

   Permissions are created from a single array beginning at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:34` and inserted with `Permission::firstOrCreate()` at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:376`, so adding permission names is additive. Admin receives `Permission::all()` at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:389`. Non-admin roles use explicit `syncPermissions()` arrays: manager at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:394`, cashier at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:480`, viewer at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:514`, technician at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:549`, operator at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:575`, and accountant at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:606`. The plan's "broad read grant" should be implemented by role-by-role explicit additions, not by a blanket pattern.

10. Task 13 should match the existing exception registration style.

   The `withExceptions` block exists at `apps/api/bootstrap/app.php:108`. Existing handlers use `$exceptions->render(...)`, for example authentication at `apps/api/bootstrap/app.php:146`, model-not-found at `apps/api/bootstrap/app.php:158`, and validation at `apps/api/bootstrap/app.php:172`. `Exceptions` does have a `renderable()` method at `apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Exceptions.php:62`, but the local sibling pattern is `render()`, not `renderable()`.

## NITS

1. Task 0 is not a guaranteed RED step.

   `ProductData` already exposes `quantity_decimals` at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:39` and already falls back to `4` only when `unitOfMeasure` is not loaded at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:103` and `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:107`. The plan does acknowledge this may go green immediately, but the checkbox still says "Write the failing test".

2. Task 3 can become false-green if it extends the existing `DocumentForm.test.tsx` setup unchanged.

   That test file mocks `DocumentLineEditor` as a `<div data-testid="line-editor" />` at `apps/web/src/features/documents/DocumentForm.test.tsx:41` and `apps/web/src/features/documents/DocumentForm.test.tsx:42`. A saved-document reload test that uses that mock cannot assert the actual quantity input step. It must either not mock `DocumentLineEditor` or assert the props passed into a richer mock.

3. `DocumentLineData` relation assumptions are valid.

   `DocumentLine::product()` exists at `apps/api/app/Modules/Document/Domain/DocumentLine.php:141`, and `Product::unitOfMeasure()` exists at `apps/api/app/Modules/Product/Domain/Product.php:306`. The blocker is not relation availability; it is missing eager-loads.

4. The current `DocumentLineData` DTO has no `quantity_decimals` field yet.

   Its constructor currently ends at `designation_default_snapshot` at `apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php:28`, and `fromModel()` currently returns without any product/unit logic at `apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php:33`.

5. The expiry page's max bound really is storage-scale-specific today.

   `ExpiryWriteOffPage` defines `QUANTITY_SCALE = 4` at `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:29`, uses that for the max bound at `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:231`, and currently also uses it for `decimalPlaces` at `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:229`. The plan is right to leave `max` alone while changing only the step.

## Verdict (is the plan safe to execute as written?)

No. The direction is right, but the plan is not safe to execute as written.

The biggest risk is silent fallback to `4`: the plan relies on per-path eager loading, while the actual document response architecture centralizes many show/store/update responses through `defaultRelations()` and `detailRelations()` that currently load only `lines`. It also misses credit-note/generic document paths, gives an impossible instruction for recovering ability names from raw `Gate::authorize()` exceptions, and assumes generated TypeScript DTOs cover frontend surfaces that actually use local hand-written interfaces.

I would require a revised plan before implementation: update shared document relation lists, explicitly include credit-note/generic/conversion response paths or scope them out, replace the AuthorizationException ability strategy with an explicit ability-carrying helper/exception, and list every local frontend type that must change alongside generated DTOs.
