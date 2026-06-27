# Adversarial Review — fix/per-unit-quantity-step (2026-06-26)

Reviewed by: Codex (adversarial-review mode via codex-companion task)
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.qty-step`
Branch: `fix/per-unit-quantity-step`
Diff base: `origin/dev`

---

## BLOCKING

### B1 — `StockLevelData.resolveQuantityDecimals()` null-dereferences on soft-deleted products

**File:** `apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php:63`

`resolveQuantityDecimals()` calls `$stockLevel->product->relationLoaded(...)` without a null-guard. `Product` uses `SoftDeletes` (`apps/api/app/Modules/Product/Domain/Product.php:81`). Stock-level rows whose product has been soft-deleted will serialize with `product === null`, causing a fatal TypeError (property access on null) in the `StockLevelController` index/show response (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php:32`).

**Fix:** guard with `if ($stockLevel->product === null) { return 4; }` before the `relationLoaded` check.

---

## SHOULD-FIX

### S1 — Invoice post response: missing `unitOfMeasure` eager-load → silent `quantity_decimals = 4` fallback

**File:** `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:543` (post action) and `:581` (response)

`DocumentPostingService::post()` returns `$document->fresh(['lines'])` (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:88`). That `fresh()` does not load `lines.product.unitOfMeasure`. `DocumentLineData::fromModel()` then silently falls back to `quantity_decimals = 4` for every line. After a user posts an invoice and the editor rehydrates, all step controls revert to `0.0001`.

**Fix:** load `lines.product.unitOfMeasure` in the `fresh()` call, or re-add it explicitly in the controller before calling `DocumentData::fromModel()`.

### S2 — Invoice confirm-deliveries-and-post: same fallback

**File:** `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:609` (action) and `:677` (response)

Same root cause as S1. The confirm-deliveries-and-post path rehydrates from a service return that does not include `lines.product.unitOfMeasure`.

### S3 — Purchase order goods-receipt response: missing eager-load

**File:** `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:534` (receive action) and `:564` (response)

`GoodsReceiptService` returns `$document->fresh(['lines'])` (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:221`). Same fallback as S1/S2.

### S4 — `CreateCreditNotePage`: invoice-line mapping drops `quantity_decimals`

**File:** `apps/web/src/features/documents/CreateCreditNotePage.tsx:122`

When creating a credit note from an invoice, invoice lines are mapped into `DocumentLineEditor` props without forwarding `quantity_decimals`. The editor's `getQuantityDecimals` helper at `DocumentLineEditor.tsx:359` then falls through to the default `0.0001` step for every copied line, regardless of the product's actual unit precision.

**Fix:** spread `quantity_decimals` from each source invoice line into the mapped line object.

---

## NITS

### N1 — No test covers the `null` product path in `StockLevelData`

`apps/api/tests/Feature/Modules/Inventory/StockLevelQuantityDecimalsTest.php` always seeds a live product. A test with a soft-deleted product would have caught B1.

### N2 — `fresh(['lines'])` pattern repeated in three controllers

Consider extracting a `loadDocumentForResponse(Document $doc): Document` helper that always includes `lines.product.unitOfMeasure`, so adding a new eager-load requirement is a one-line change.

---

## No findings in the following areas

- **DTO construction sites outside `fromModel()`:** none found. `DocumentLineData` and `StockLevelData` are only instantiated via their respective `fromModel()` factory methods.
- **`bootstrap/app.php` 403 handler swallowing unrelated 403s:** no issue. Laravel 12 `abort(403)` throws `Symfony\Component\HttpKernel\Exception\HttpException` (not `AccessDeniedHttpException`). The new handler's `instanceof AccessDeniedHttpException` check does not match plain `abort(403)` calls, so those still fall through to the default error response unchanged. Confirmed by reading the handler at `apps/api/bootstrap/app.php:156-185` and `apps/api/app/Shared/Authorization/AuthorizesAbility.php`.
- **Seeder — missing `uom.view`:** no gap found. `RolesAndPermissionsSeeder.php:612-650` grants `uom.view` to all roles that fetch units (product editor, document editor, stock transfer).
- **ProductForm `decimalPlaces` casing:** no issue. `UnitData` emits camelCase `decimalPlaces`; `ProductForm` reads the same key. The camelCase vs snake_case split does not cause a runtime mismatch here.
- **False-green tests:** none found. New backend tests assert real JSON serialization of `quantity_decimals`; new frontend tests render the actual `QuantityInput` and assert the DOM `step` attribute value.

---

## Verdict

**HOLD**

One blocking null-dereference (B1) will 500 any stock-level list when any product is soft-deleted. Three should-fix items (S1–S3) mean that after the three most common document mutations the editor immediately loses the correct step and reverts to `0.0001`. S4 means credit-note creation never gets the right step from the copied lines. None of these are edge-case scenarios — B1 triggers the first time any product is archived; S1–S4 trigger on every post/receive/credit-note flow.

Fix B1 and S1–S4, then re-run the adversarial check on the delta.
