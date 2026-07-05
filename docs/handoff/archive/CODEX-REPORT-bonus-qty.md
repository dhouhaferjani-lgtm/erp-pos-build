# CODEX Report: Purchase Bonus Quantity Phase 1

Date: 2026-07-02
Branch: `feat/purchase-bonus-qty`
Spec: `docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md` Revision 2

## Checkpoint State

Implemented a coherent backend checkpoint for Phase 1:

- Additive document-line schema for same-product purchase bonus quantities.
- Backend module/country gating and request-level field gating for PO create/update.
- PO line entry persistence for `free_quantity` and `price_entry_mode`, including total-mode authoritative `line_total` and scale-6 `landed_unit_cost`.
- Goods receipt paid/free split, including free-only receipts, free-first two movements, independent over-receive guards, free counters, WAC dilution, and zero-cost `last_purchase_cost` guard.
- Supplier invoice shape (b) support: `is_bonus_line` matcher skip, free-side quantity validation, posting to `free_quantity_invoiced`, and paid 408 clearing unchanged.
- Company config exposes `purchase_bonus_enabled`; module vocabulary includes `PurchaseBonus`.

Not completed in this checkpoint:

- Frontend PO line editor/receiving UI.
- PDF gratuité sub-row.
- Supplier credit-note bonus return path.
- PO revision-after-receipt invariant.
- Buyer bonus KPI queries.
- Existing-tenant reconcile migration/command audit.
- Generated TypeScript transform.
- FR/EN/AR translation keys beyond backend validation strings.

## Spec Section Map

- §3 / §14 schema: `2026_07_02_100000_add_purchase_bonus_fields_to_document_lines.php`, `DocumentLine` fillable/casts, `DocumentLineData`, `PriceEntryMode`.
- §4 / §5 PO entry and total mode: `CreateDocumentRequest`, `UpdateDocumentRequest`, `PurchaseOrderController::normalizePurchaseLine`.
- §6 receiving: `GoodsReceiptService`, `PurchaseOrderController::receive`, `WeightedAverageCostService` zero-cost last-purchase guard.
- §9a supplier invoice shape (b): `SupplierInvoiceMatcher`, `SupplierInvoicePostingService`.
- §10 gating: `ModuleName::PurchaseBonus`, `config/verticals.php`, `config/procurement.php`, `PurchaseBonusGate`, `CompanyConfigController`, `CompanyConfig` DTO, `apps/web/src/lib/modules.ts`.

## Test List And Status

Run and passing:

- `php artisan test tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Unit/Enums/ModuleNameTest.php`
  - 11 passed, 460 assertions.
- `php artisan test tests/Feature/Procurement/SupplierInvoiceMatcherTest.php`
  - 18 passed, 28 assertions.
- `php artisan test tests/Feature/Accounting/SupplierInvoiceGlTest.php`
  - 15 passed, 117 assertions.
- `php artisan test tests/Feature/Inventory/GoodsReceiptTest.php`
  - 25 passed, 83 assertions.
- `./vendor/bin/pint --dirty`
  - Fixed formatting/import order in touched PHP files; focused tests were rerun afterward.
- PHP syntax checks:
  - `PurchaseOrderController.php`
  - `GoodsReceiptService.php`
  - `SupplierInvoiceMatcher.php`
  - `SupplierInvoicePostingService.php`
  - `CreateDocumentRequest.php`
  - `UpdateDocumentRequest.php`
  - `PurchaseBonusGate.php`

Not run:

- Full backend suite.
- `php artisan typescript:transform`.
- `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm typecheck`.
- Frontend Vitest/E2E.

## Notes

- TDD red/green was followed for the implemented backend slices:
  - PO entry/gating tests failed first, then passed.
  - Receiving tests failed first on ignored `free_quantities`, then passed.
  - Shape-(b) matcher failed first as `quantity_variance`, then passed.
  - Shape-(b) posting failed first on paid over-clear `21 > 20`, then passed.
- `recordPurchase` remains string-based. No float casts were introduced.
- `recordCostAdjustment` was not touched.
- `ProportionalMoneyAllocator` was not touched because landed-cost allocation behavior was not changed.
