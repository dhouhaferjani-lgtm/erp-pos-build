# Inventory Transfer (T1 Phase 2 — intracompany scope)

**Status:** shipping
**Date:** 2026-05-28
**Branch:** `feat/inventory-transfer`
**Scope:** intracompany stock transfer between locations of one company, document-based (draft → in_transit → completed | cancelled), with optional transfer-cost capitalization into the company-wide weighted average cost (WAC).
**Spec reference:** `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md` (T1 Phase 2 subset)
**Memory anchor:** `project_inventory_costing.md` (WAC = company-wide; `recordCostAdjustment` is the seam)

---

## Why this PR exists

The repo already had a single-call atomic `StockAdjustmentService::transfer()` primitive (one product, one location pair, no document, no cost capitalization). The user-visible product needs more than that:

- a **document** that lives between draft and receipt at the destination so logistics can plan,
- a **multi-line** transfer with one freight invoice attached,
- a **cost capitalization** rule that lifts the company-wide WAC by `additional_cost / company_on_hand_qty` per product — never per-location.

This PR ships those three pieces inside the existing Inventory module, reusing every existing seam (`StockAdjustmentService`, `WeightedAverageCostService`, `stock_movements` audit table) and adds exactly one new entry point on the WAC service so that any future WAC-affecting event (rebate, duty adjustment, revaluation, write-down) can call the same method.

---

## What was built

### Backend (Laravel)

**Schema** — `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php`

- `stock_transfers` (the aggregate)
  - `id`, `tenant_id`, `company_id`
  - `transfer_number` (unique per company; auto-generated `TR-YYYY-NNNNN`)
  - `transfer_type` (`intracompany` | `intercompany` — only Intracompany accepted today)
  - `status` (`draft` | `in_transit` | `completed` | `cancelled`)
  - `source_location_id`, `destination_location_id` (both must belong to `company_id`)
  - `notes`, `transfer_cost` (decimal 15,4), `transfer_cost_label`, `transfer_cost_distribution`
  - `idempotency_key` (unique per company)
  - `initiated_by_user_id` / `completed_by_user_id` / `cancelled_by_user_id`
  - `initiated_at` / `completed_at` / `cancelled_at` / `cancellation_reason`
  - CHECK constraints (PG only): distinct locations, `transfer_cost >= 0`, line `quantity > 0`
- `stock_transfer_lines` (one row per product on the transfer)
  - `unit_cost_snapshot` (captured at initiate, used for cost allocation)
  - `allocated_transfer_cost` (filled at complete)

**Migration placement:** kept in `database/migrations/` (not `migrations/tenant/`) to match the **current row-level tenancy reality** (see `project_tenancy_model_truth.md` and ERP CLAUDE.md). The T1 spec calls for `migrations/tenant/` after T6's DB-per-tenant flip lands — move it then.

**Enums** (`Domain/Enums/`)

- `TransferStatus`: Draft / InTransit / Completed / Cancelled, with `canBe*` predicates
- `TransferType`: Intracompany / Intercompany (Intercompany rejected by the service for now)
- `TransferCostDistribution`: ProRataValue (default) / ProRataQuantity / EqualPerLine

**Models** (`Domain/StockTransfer.php`, `Domain/StockTransferLine.php`) — Eloquent with strict types, `HasUuids`, scopes `forTenant` / `forCompany` / `withStatus`.

**Events** (`Domain/Events/`)

- `StockTransferInitiated`
- `StockTransferCompleted`
- `StockTransferCancelled`

All carry tenant_id + company_id + transfer_number; consumers see no internal IDs they couldn't reconstruct.

**Application service** — `Application/Services/StockTransferService.php`

- `initiate(InitiateTransferData): StockTransfer`
  - Idempotency-key short-circuit (same key returns existing transfer; safe to retry).
  - Validates: at least one line, distinct source/destination, both locations belong to `company_id`, all products belong to `company_id`, no duplicate product rows, `quantity > 0`.
  - Inside a `DB::transaction()`:
    - locks source `StockLevel` row + `Product` row;
    - throws `InsufficientStockException` if the requested quantity exceeds available stock;
    - calls `StockAdjustmentService::issue()` to decrement source — then re-labels the resulting `stock_movement` to `TransferOut` and anchors it to the StockTransfer aggregate via `reference_type` + `reference_id`;
    - snapshots `unit_cost_snapshot` per line from the product's current `cost_price`;
    - flips status to `in_transit`; dispatches `StockTransferInitiated` via `DB::afterCommit`.
- `complete(transferId, userId): StockTransfer`
  - Locks transfer; enforces status `in_transit`.
  - Per line: `StockAdjustmentService::receive()` at destination, re-label to `TransferIn`.
  - If `transfer_cost > 0`: allocate proportionally per `TransferCostDistribution`, then call `WeightedAverageCostService::recordCostAdjustment` once per product.
  - Status → `completed`; dispatches `StockTransferCompleted`.
- `cancel(transferId, userId, reason?): StockTransfer`
  - From `draft` = no stock motion. From `in_transit` = `receive()` back to source per line; re-label movements to `TransferIn` with a `-CANCEL` reference.
  - Status → `cancelled`; dispatches `StockTransferCancelled`.

**New WAC seam** — `WeightedAverageCostService::recordCostAdjustment(Product, additionalCost, reason, reference?, referenceType?, referenceId?)`

This is the canonical method any future WAC-affecting event must call. Behavior:

1. Locks the product row + every `stock_levels` row for that product+company (`lockForUpdate()` on the aggregation).
2. Computes company on-hand qty = sum of `stock_levels.quantity` for that product within `tenant_id` + `company_id`.
3. If on-hand qty is zero, no-ops with `null` (caller surfaces — by design; you cannot capitalize cost into an empty bucket).
4. `new_avg = current_avg + additional_cost / on_hand_qty`.
5. Writes a `stock_movements` row with `movement_type = adjustment`, `quantity = 0`, `quantity_before == quantity_after`, but `avg_cost_before != avg_cost_after` — so the audit trail shows what actually changed (the average, not the quantity).
6. Updates `products.cost_price` and `cost_updated_at`; fires `ProductCostPriceUpdated` via `DB::afterCommit` if the margin service ends up adjusting sale price.

**Why a single shared method:** the memory note says — and the user reconfirmed — that branches share one company-wide accounting, and the only thing the transfer-cost path needs to do is capitalize a known dollar amount into the WAC. Reusing this seam for the next inventory revaluation / rebate / write-down is one line of `recordCostAdjustment(...)` away.

**Exception** — `Domain/Exceptions/TransferStateException` (carries `transferId`, `currentStatus`, `attemptedAction`).

**Presentation layer**

- `Presentation/Requests/StoreStockTransferRequest` — Form-request with `ScopedExists` on locations + products against the current company, line validation, idempotency_key.
- `Presentation/Controllers/StockTransferController` — `index` / `show` / `store` / `complete` / `cancel`. Tenant + company scoping on every read. Paginated index preserves the `meta` wrapper (no `apiGet` antipattern). UUID format validated before any DB lookup.
- Routes mounted under the existing module group at `/api/v1/stock-transfers`, with the standard `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory']` middleware chain and `can:inventory.transfers.*` gates.

**Permissions** added to `RolesAndPermissionsSeeder`:

- `inventory.transfers.view`
- `inventory.transfers.create`
- `inventory.transfers.complete`
- `inventory.transfers.cancel`

Granted to the same admin roles that already hold `inventory.transfer` (the older primitive transfer permission stays untouched).

### Frontend (React + Vite)

Feature lives at `apps/web/src/features/stock-transfers/`.

- `types/` — hand-written TS types matching the PHP DTOs (drop and regenerate when `php artisan typescript:transform` covers this module).
- `api/stockTransferApi.ts` — list / show / create / complete / cancel.
- `api/queries.ts` — TanStack Query hooks with tenant-scoped keys; mutations invalidate `stock-transfers`, `stock-levels`, and `stock-movements` namespaces on success.
- `components/StockTransferStatusBadge.tsx` — atomic wrapper around the existing `Badge` atom.
- `pages/`:
  - `StockTransferListPage` — status filter + paginated table, EmptyState molecule on no-data.
  - `CreateStockTransferPage` — header (source/destination/notes) + lines editor reusing the existing `ProductPicker` molecule + costs section with distribution selector. Validation via inline checks (linesRequired, differentLocations, quantityPositive). On success: invalidates queries and navigates to the detail page.
  - `StockTransferDetailPage` — summary card + lines table + complete/cancel actions guarded by status. Cancel modal collects an optional reason.
- `__tests__/StockTransferListPage.test.tsx` — Vitest smoke test (mocks the query hook, asserts row + empty-state rendering and key translation usage).
- `locales/en/stock-transfers.json` + `locales/fr/stock-transfers.json`; namespace wired into `lib/i18n.ts` in all three resource blocks (en/fr/ar) and the `ns:` array.
- Routes registered under `/inventory/stock-transfers`, `/inventory/stock-transfers/new`, `/inventory/stock-transfers/:id` with `RequirePermission`.

**Atomic-design adherence**: pages compose existing atoms (`Button`, `Badge`) and molecules (`EmptyState`, `ProductPicker`) and a single feature-local presentational component (`StockTransferStatusBadge`). No duplication of `DocumentLines` / `AdditionalCostsForm` — those components are tightly coupled to the `Document` aggregate (purchase orders / invoices) and re-wrapping them here would have leaked the Document type into the transfer feature. We kept the contract narrow and lifted only `ProductPicker`, the genuinely shared piece.

---

## Test results

- Backend: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` — **12 tests, 38 assertions, all green**. Covers: cross-company rejection, same-source-and-dest rejection, empty-lines rejection, intercompany-type rejection, atomic decrement + TransferOut audit, insufficient-stock guard, idempotency, two-leg movement on complete, double-complete rejection, WAC company-wide recompute on complete-with-cost, cancel-from-in-transit returns stock, cancel-from-completed rejection.
- Backend regression: full `tests/Feature/Inventory/` suite green (**133 tests**).
- PHPStan: clean on all new files (level 8).
- Pint: clean on all new files.
- Frontend: `pnpm test -- src/features/stock-transfers` green; `pnpm typecheck` clean repo-wide.

## What is out of scope (and tracked elsewhere)

- **Scenario B (intercompany)** — auto sales-invoice + purchase-order pair, journal posting, super-admin dashboard. T1 spec phase 3; rejected at the `TransferType` seam today so the contract is honest.
- **Per-location tax_id / branch_code / legal_name** — T1 spec phase 1.
- **InTransitAvailability per-company setting** — T1 spec Scenario A enforcement at POS.
- **Batch preservation** (`inventory_batch_movements` rows on transfer legs) — T1 spec phase 1; deferred to a follow-on PR.
- **Tauri POS receipt rendering of transfer impact** — Wave 2 POS deltas.

When T6 Phase 0b ships the DB-per-tenant flip, the migration at `database/migrations/2026_05_28_120000_create_stock_transfers_table.php` should be moved to `database/migrations/tenant/` per the migration topology contract.
