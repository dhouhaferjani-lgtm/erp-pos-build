# Inventory Transfer (PR #147) — Opus Adversarial Review

**Verdict:** APPROVE-WITH-MINOR-EDITS
**Confidence:** high
**Date:** 2026-05-28

## Summary

The PR ships a clean, focused implementation of T1 Phase 2 (intracompany stock-transfer document with WAC capitalization). The design choices hold up well to adversarial poking:

- The "relabel most recent movement" pattern is **actually safe under concurrency** because the WHERE-predicates are tight enough (`reference = $transfer_number` + `product_id` + `location_id` + `movement_type` + `whereNull('reference_id')` + `orderByDesc('created_at')->limit(1)`). Each transfer's `transfer_number` is unique per company, so two parallel transfers of the same product cannot race the relabel into picking the wrong row.
- WAC math is correct end-to-end: `new_avg = current_avg + transfer_cost / on_hand_qty`, and `on_hand_qty` after the destination receive is unchanged from the pre-transfer total — the math reaches the spec's intent.
- Tenant + company scoping is consistent: the controller scopes every read, the service re-validates locations and products inside the transaction.
- `recordCostAdjustment` is a clean seam — it locks the product + stock-level aggregation, no-ops on zero on-hand, writes an audit `stock_movements` row with `quantity=0` and a non-zero avg-cost delta.
- Backend tests (12, all green) cover the critical paths. PHPStan level 8 clean on the three core files I scanned. Pint clean.

What I want fixed before merge (not blockers, but real):

1. **Spec drift on `complete` idempotency** — the spec explicitly requires `complete` to be idempotent via an `idempotency_key`; the PR relies on the status guard instead. The design note is honest about deferring it; the spec was not. Add an `idempotency_key` parameter to `complete()` (and `cancel()`) so retries are spec-compliant, OR explicitly mark the spec item deferred in the design note with a date/owner.
2. **Frontend does not generate an `idempotency_key`** for the `store` call. With a flaky network, the user can double-decrement source stock. The backend supports the column; the frontend should always send `crypto.randomUUID()` for the POST, even if it has no UI surface.
3. **Pagination UI is non-existent** — the list page sets `page` and `per_page` in filters, the API supports both, but the user has no controls. The footer just says `{total} / {total}`. This is a real UX gap, not a nit, because the API caps at 100 per page.
4. **`CreateStockTransferPage` swallows server errors** — `catch { toast.error(t('create.error')) }` discards `INSUFFICIENT_STOCK` and `INVALID_TRANSFER` messages. Users will not know *why* the transfer was rejected.
5. **Quantity-precision mismatch between the transfer line and the underlying stock movement** — the line stores `decimal(15,4)`, the API accepts `min:0.0001`, the form uses `step="0.0001"`, but `StockAdjustmentService::SCALE = 2` truncates the actual stock-level write to 2 decimal places. This is pre-existing tech debt in `StockAdjustmentService`, not introduced by this PR, but it surfaces visibly here.

Otherwise — the PR is well-scoped, the seams for next-PR work (intercompany rejection, `idempotency_key` already on the column, `TransferType::Intercompany` enum case) are in place. Migration placement is documented and consistent with the rest of the row-level-tenancy reality.

---

## BLOCKERS (must fix before merge)

None.

---

## P1 (strong concerns)

### P1-1 — `complete` and `cancel` do not honor `idempotency_key` (spec drift)

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:157-221` (`complete`), `:229-286` (`cancel`).

**Spec drift:** `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md` line 138 explicitly says `POST /api/v1/stock-transfers/{id}/complete` is "idempotent via idempotency_key in body", and line 202 acceptance criterion says "retrying `complete` with the same idempotency_key produces no duplicate movements or events". The implementation does not accept any idempotency_key on `complete`/`cancel`; it relies on the status guard (`canBeCompleted` returns false once status is `Completed`).

**Failure scenario:** Client POSTs `/stock-transfers/{id}/complete`. The server commits — destination stock is incremented, the StockTransferCompleted event is dispatched, the response is `200 OK`. The response is lost mid-flight (Wi-Fi blip, mobile handoff). The client's request library retries the exact same POST. The second call:
- Locks the transfer (now `completed`),
- Throws `TransferStateException` with `code = INVALID_TRANSFER_STATE`,
- The controller responds 422.

The destination got incremented exactly once (good — no duplicate stock motion), but the client sees a 422 even though the operation actually succeeded. UI then has to differentiate between "I tried twice and it really failed" and "I tried twice and the first one already succeeded". That's a real UX paper cut for the cashier on a flaky terminal.

**Recommended fix:** Either
- Add an optional `idempotency_key` body parameter to `complete` and `cancel`; if present, store on the StockTransfer; if the same key arrives again, look up the existing terminal-state transfer and return it with `200 OK` and the SAME response shape.
- Or update the design note + the spec to formally defer this; do not silently move a spec checkbox.

The status-guard approach is *defensible* (the spec was probably overdrafted) but should be a conscious decision recorded somewhere.

### P1-2 — Frontend never sends `idempotency_key` on create

**Files:** `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:95-106`, `apps/web/src/features/stock-transfers/api/stockTransferApi.ts:41-43`.

**Failure scenario:** User opens the create page on a Tunisian shop terminal with a flaky uplink. Fills out a header + 10 lines. Hits Submit. The request times out at the load balancer (mobile 3G). The mutation hook does NOT retry (`useMutation` default is no retry), so the user sees a `t('create.error')` toast. They hit Submit again. Either:
- The first request actually succeeded server-side and dispatched stock; the second creates a SECOND transfer with the SAME line set, decrementing source again (potentially driving it negative or to `InsufficientStockException`). Source stock is now wrong by 2× the line totals.
- Both requests reach the server. Without `idempotency_key`, `generateTransferNumber` allocates two distinct numbers and both transactions proceed — same outcome as above.

The backend already has the `idempotency_key` column and unique constraint (`stock_transfers_company_number_unique` on `(tenant_id, company_id, idempotency_key)`, migration:62) and `initiate()` has a working short-circuit (`StockTransferService.php:88-99`). The frontend just needs to emit one.

**Recommended fix:** In `CreateStockTransferPage.tsx`, generate a `crypto.randomUUID()` once when the form mounts (or right before submit) and include it in `CreateStockTransferInput`. The `CreateStockTransferInput` type already supports `idempotency_key?: string` (`types/index.ts:71`).

---

## P2 (worth fixing this PR)

### P2-1 — List page has no pagination controls

**Files:** `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:140-144`.

The filter state includes `page: 1, per_page: 25`. The API supports both. The controller validates `1 <= per_page <= 100` (`StockTransferController.php:62`). But the rendered footer says only `{total} / {total}` — there are no next/prev buttons and no way to flip pages. For a company that runs hundreds of transfers a month, this means everything after row 25 is invisible.

**Recommended fix:** Either add Prev/Next buttons + a page-N-of-M label, or set `per_page` to a higher value (50/100) by default. The footer text `{total} / {total}` is also misleading — drop it.

### P2-2 — Create page discards structured server-error messages

**Files:** `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:112-114`.

```ts
} catch {
  toast.error(t('create.error'))
}
```

The backend returns structured 422 responses:
- `INSUFFICIENT_STOCK` with `details.product_id / location_id / requested / available` (controller line 267-281).
- `INVALID_TRANSFER` with `error.message` for "duplicate product on transfer lines", "products do not belong to the current company", etc.

The user only ever sees a generic "Could not create the transfer" toast. With the existing axios error shape (the `apiPost` helper throws on non-2xx), the code should at least surface the server's `error.message` or even the structured `INSUFFICIENT_STOCK` details so the user knows *which* product was short and by how much.

**Recommended fix:** `catch (e) { toast.error(extractServerMessage(e) ?? t('create.error')) }`. Same applies to `StockTransferDetailPage.tsx:39-41, 55-57` for complete/cancel.

### P2-3 — Service does not re-scope `lockTransfer` by tenant+company

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:466-475`.

```php
private function lockTransfer(string $transferId): StockTransfer
{
    /** @var StockTransfer $transfer */
    $transfer = StockTransfer::query()
        ->with('lines')
        ->lockForUpdate()
        ->findOrFail($transferId);

    return $transfer;
}
```

Today the only callers are `StockTransferController::complete/cancel`, both of which do a controller-side `where('tenant_id', ...)->where('company_id', ...)->findOrFail($transfer)` upfront, so the right transfer is pre-validated. But the service is a public application service — if any future caller (a job, a console command, an event handler, an automated test) invokes `complete($id, ...)` with an `id` that escapes its caller's scope, the service will happily lock and mutate a transfer in another company.

Defense-in-depth: scope `lockTransfer` by the calling user's company. The simplest way is to accept `tenantId` + `companyId` parameters to `complete` and `cancel` (mirror the existing pattern in `loadAndVerifyProducts`).

This is the same kind of finding that landed the tenant-isolation sweep cluster (`project_tenant_isolation_sweep.md`); the new service is one missing predicate away from being a regression.

### P2-4 — No cross-tenant product rejection test

**Files:** `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`.

`test_cross_company_locations_are_rejected` covers a cross-COMPANY location. There is no equivalent test for a product UUID that belongs to a different tenant or a different company (with same tenant). `loadAndVerifyProducts` (`StockTransferService.php:510-525`) does scope by `tenant_id + company_id`, so the rejection should be enforced, but a regression test would catch a future change that loosens the predicate.

**Recommended fix:** Add a test that creates a Product in `$this->otherCompany`, attempts to put it on a transfer in `$this->company`, asserts `InvalidArgumentException` with "Products do not belong to the current company".

### P2-5 — Quantity-precision drift between transfer line and stock movement

**Files:** `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:76` (`stock_transfer_lines.quantity decimal(15,4)`), `apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php:21,38` (`stock_levels.quantity`, `stock_movements.quantity` both `decimal(15,2)`), `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:23` (`private const SCALE = 2`).

**Failure scenario:**
1. User submits a transfer line with `quantity = 7.1234`. The FormRequest validates with `min:0.0001` (`StoreStockTransferRequest.php:43`) so this passes.
2. The service writes `stock_transfer_lines.quantity = 7.1234` (the column is `decimal(15,4)` — the precision survives).
3. The service calls `StockAdjustmentService::issue(quantity: '7.1234')`. The service does `bcadd($before, '7.1234', 2)` and writes to `stock_levels.quantity` (a `decimal(15,2)` column) — PostgreSQL silently rounds `7.1234 → 7.12`. The actual stock decremented is 7.12, not 7.1234.
4. `stock_movements.quantity` is also `decimal(15,2)` — same truncation.
5. Result: the transfer line says "moved 7.1234 units" and the underlying stock_levels say "decremented 7.12 units". A 0.0034-unit ghost.

This is pre-existing tech debt in `StockAdjustmentService` (its `SCALE = 2` predates this PR). The transfer PR makes it visible because its DTOs and migration expose 4-decimal precision.

**Recommended fix (minimal for this PR):** Either:
- Tighten the FormRequest to `regex:/^\d+(\.\d{1,2})?$/` so the API rejects sub-cent qty.
- Or document the constraint in the design note: "Transfer-line precision is bounded to 2 decimals by the underlying StockAdjustmentService."
- Or — the right fix, separately — bump `StockAdjustmentService::SCALE` to 4 plus the underlying columns. That's out of this PR's scope but a real follow-up.

### P2-6 — `cancel` from `in_transit` writes a confusing `TransferIn` movement at the source

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:241-260`.

When cancelling a transfer that is already in_transit, the service returns stock to source via `receive()` and then relabels the resulting movement to `MovementType::TransferIn` with reference `${transfer_number}-CANCEL`. A bookkeeper looking at `stock_movements` filtered for the source warehouse will see:

| reference | movement_type | qty |
|---|---|---|
| TR-2026-00001 | transfer_out | -5 |
| TR-2026-00001-CANCEL | transfer_in | +5 |

The story is reconstructable, but `transfer_in` at the source location reads weirdly — normally a `TransferIn` means "stock arrived from somewhere else". A separate enum case like `TransferReversed`, or just keeping the `Receipt` type with the `-CANCEL` reference (no relabel), would read more honestly.

**Recommended fix:** Either add `TransferReversed` to `MovementType` enum (preferred — gives an honest audit category), or skip the relabel for the cancel-from-in_transit path so the movement stays as `Receipt` with the `-CANCEL` reference. Add a test that reads `stock_movements` after a cancel-from-in_transit and asserts the expected movement_type.

### P2-7 — Translation namespace claims "ar wired" but the AR locale file does not exist

**Files:** `apps/web/src/lib/i18n.ts:262` (`ar.stock-transfers = enStockTransfers`), `apps/web/src/locales/ar/` (no `stock-transfers.json`), design note line 126.

The design note says: "namespace wired into `lib/i18n.ts` in all three resource blocks (en/fr/ar)". The wiring is real, but the AR block points to `enStockTransfers` — there is no AR translation file. For an automotive shop in Tunisia, this means the UI will show English strings when the user's locale is Arabic.

This is consistent with several other namespaces in `i18n.ts` (channels, refund-policies, etc., that also fall back to EN under AR), so it's a known pattern in this codebase rather than a bug — but the design note is misleading. Either:
- Add `apps/web/src/locales/ar/stock-transfers.json` (a 116-key file, mechanical translation).
- Or correct the design note to say "AR falls back to EN at the i18n level until a translation file is added."

I lean toward the second for this PR — adding 116 Arabic translations should be its own pass.

---

## P3 (nits, future)

### P3-1 — `cancel` from `draft` leaves `stock_transfer_lines` with `unit_cost_snapshot = NULL` and `quantity > 0`

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:229-286` (no draft-cancel cleanup of lines).

A user creates a draft (no current path actually creates a draft — `initiate()` immediately moves to `in_transit`, so this is purely structural), or — more realistically — once a future PR adds a "save draft" button, cancelled drafts will leave lines around. The lines will have `unit_cost_snapshot = NULL`, `quantity > 0`, and `allocated_transfer_cost = 0`. Downstream reports that join `stock_transfers` + `stock_transfer_lines` on `status != 'cancelled'` will skip them; reports that don't filter risk treating the qty as "pending". Worth a comment in the model that `unit_cost_snapshot` is only filled at initiate time and that consumers should filter `status` accordingly.

Note: this is *only* a problem once draft transfers can exist without immediately moving to in_transit. Today the service forces the immediate transition. P3 only — file as a comment for the next PR.

### P3-2 — `generateTransferNumber` is racy under high concurrency

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:527-537`.

Two concurrent initiates for the same tenant+company will both compute `count(*) + 1 = N`, both insert `TR-2026-0000N`, and the unique constraint catches the second one with a SQLSTATE 23505. The whole transaction rolls back; the client retries — at which point the count is now larger so the second attempt picks N+1.

Functionally safe (no corrupt data, no double-decrement) but produces noisy `UniqueConstraintViolationException` in logs on busy days. A `SELECT ... FOR UPDATE` on a per-(tenant,company,year) sequence row, or just using a `STRING_AGG`-based generator from a `transfer_number_sequences` table, would be cleaner. P3 because the spec already calls for a real sequence table in a later phase.

### P3-3 — `bcformat` not used for WAC math

**Files:** `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:493-495`.

```php
$currentCostPrice = (float) ($product->cost_price ?? 0);
$delta = $additionalCost / $onHandFloat;
$newAvgCost = round($currentCostPrice + $delta, $this->scale());
```

The memory note (`project_monetary_precision.md`) says monetary values should use `CurrencyScale::bcformat` rather than `(float) … round(...)`. The surrounding `recordPurchase` / `recordReturn` / `recordSale` methods all use the same `(float)` pattern — this is pre-existing tech debt, not introduced by this PR. But since `recordCostAdjustment` is being added as "the canonical seam for future WAC-affecting events", it would be a good place to convert to bcmath for new code. P3 because matching the surrounding code style is also a reasonable defense.

### P3-4 — `relabelLatestMovement` is a workaround for a missing `StockAdjustmentService` capability

**Files:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:443-464`.

The "issue then re-label" pattern works under concurrency (the `reference + product_id + location_id + movement_type + reference_id IS NULL` predicate scopes the update tightly), but it's two SQL round-trips where one would do. The right fix is to add optional `movementType` / `referenceType` / `referenceId` parameters to `StockAdjustmentService::issue` and `::receive` so the transfer service can pass them in directly. Out of scope for this PR — but worth filing as a tech-debt ticket so the next time this pattern is reached for (returns? consignment?) we don't duplicate.

### P3-5 — Migration placement is in `migrations/` not `migrations/tenant/`

**Files:** `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php`.

The design note acknowledges this (line 45, line 149). Channel migrations live under `migrations/tenant/` (e.g. `migrations/tenant/2026_05_24_120000_create_channels_table.php`). The transfer migration is in the central folder. Per the current row-level tenancy reality (one shared DB, every table has `tenant_id` + `company_id`), the central folder is correct *today*. Once T6 Phase 0b ships DB-per-tenant, the `tenant/` folder runs against each tenant DB and the central folder runs against the central / public DB. At that point this migration MUST be moved to `tenant/`, otherwise:
- The transfer table will exist only in the central DB.
- All tenant-scoped queries (every controller call, every service call) will fail at runtime against the tenant DB connection.

The design note commits to moving it then. A failing test that asserts `Schema::hasTable('stock_transfers')` after running `tenants:migrate` against a freshly seeded tenant DB would catch this regression at T6 Phase 0b time. P3 because the timing is the next-PR's problem, not this one's.

### P3-6 — `tokens.card.hoverPrimary` row hover may look "blue on blue"

**Files:** `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:112`.

The list table uses `tokens.card.hoverPrimary` for row hover. Other list pages in the codebase use a softer gray hover. I haven't actually rendered this to compare, but the prompt flagged it as a possible mismatch — worth a visual smoke check by the author.

### P3-7 — Detail page custom cancel modal duplicates `ConfirmDialog` styling

**Files:** `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:236-276`.

The cancel flow needs a textarea inside the confirmation, which `ConfirmDialog` doesn't support. The page hand-rolls a backdrop + container using `tokens.modal.*`. The right fix is to extend `ConfirmDialog` to accept `children`. Out of scope here, but the comment in the design note ("`ConfirmDialog` does not accept children") should turn into a real ticket — otherwise the next four PRs will duplicate this pattern again.

---

## What I verified by reading code

- Migration schema and indexes (`apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php`): UUIDs, FKs to tenants/companies/locations/users/products, correct on-delete behavior (`cascadeOnDelete` on tenant/company, `restrictOnDelete` on locations/users/products, `nullOnDelete` on optional user FKs). PG CHECK constraints for distinct locations, non-negative cost, positive line quantity (lines 87-90). Unique constraints for transfer_number and idempotency_key both scoped to `(tenant_id, company_id, ...)`. Looks right.
- Domain enums (`TransferStatus`, `TransferType`, `TransferCostDistribution`): `canBeCompleted` allows only `InTransit`, `canBeCancelled` allows `Draft` or `InTransit` — matches lifecycle.
- `StockTransferService::initiate`: idempotency short-circuit happens FIRST in the transaction (line 89-99), so concurrent retries with the same key both see the existing row. The source `StockLevel` is `lockForUpdate()` before any qty arithmetic. The `InsufficientStockException` is thrown if `bccomp(qty, available, 4) > 0`. The relabel query is tight enough to survive concurrency from a second transfer of the same product (different `transfer_number` ⇒ different `reference` predicate).
- `StockTransferService::complete`: WAC capitalization happens AFTER the destination receive, so `recordCostAdjustment` sees the post-transfer on-hand. Math: `new_avg = current + transfer_cost / on_hand`. Verified algebraically against the test fixture (100 + 20 units of cost 5, transfer 10 with freight 60 → 5 + 60/120 = 5.50, matching `'5.5000'` assertion at line 417).
- `StockTransferService::cancel`: from `draft` skips stock motion (correct); from `in_transit` calls `receive()` back to source and relabels to `TransferIn` with a `-CANCEL` reference (functionally correct, audit semantics a little odd — P2-6).
- `WeightedAverageCostService::recordCostAdjustment`: locks product, sums all stock_levels for that product+company with `lockForUpdate()`, no-ops on `onHand <= 0`, writes a `MovementType::Adjustment` row with `quantity = 0` and the avg-cost-before/after delta, updates `product.cost_price` + `cost_updated_at`, fires `ProductCostPriceUpdated` event afterCommit. The auto-margin update is reused from the rest of the WAC service.
- `StoreStockTransferRequest`: `ScopedExists::company('locations', ...)` and `ScopedExists::tenantAndCompany('products', ...)` — correct factory choices given `locations.company_id` (no tenant_id column on locations) and `products.tenant_id + products.company_id`.
- Controller: every read scoped by `tenant_id + company_id` (lines 35-38, 87-89, 167-169, 195-197). UUID format validated with `Str::isUuid` before any DB lookup (lines 49, 56, 81, 162, 190). Paginated index preserves the `meta` wrapper (lines 67-74) — no `apiGet` antipattern on the frontend side either (`stockTransferApi.ts:30-34` uses `api.get` directly).
- Routes: under `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory']` (the full middleware chain), every endpoint gated by the appropriate `can:inventory.transfers.*` permission (lines 78-96). `show` IS gated by `can:inventory.transfers.view`.
- Permissions: `inventory.transfers.view/create/complete/cancel` added to `createPermissions()` (lines 126-129); admin gets all via `Permission::all()` sync; manager has them explicit (line 394). Cashier / viewer / technician / operator / accountant do NOT have transfer permissions — consistent with the existing `inventory.transfer` permission being admin+manager only.
- Backend tests: 12 tests, 38 assertions, all green when I ran `./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php`. Coverage includes cross-company locations, same-source-and-dest, no-lines, intercompany rejection, atomic decrement + audit row, insufficient stock, idempotency, two-leg movement on complete, double-complete rejection, WAC company-wide recompute, cancel-from-in-transit, cancel-from-completed rejection.
- PHPStan: clean (level 8) on `StockTransferService`, `WeightedAverageCostService`, `StockTransferController` after running `./vendor/bin/phpstan analyse <files>`.
- Frontend: `useStockTransferList` correctly preserves the paginated `meta` wrapper via `api.get` (avoiding the `apiGet` double-unwrap pitfall). Mutations correctly invalidate `stock-transfers`, `stock-levels`, `stock-movements` keys. Status filter is a typed union (no `as` cast). The `is*` type guards are well written.
- EN and FR translation files are in sync (116 keys each, matching structure).

## What I could not verify

- **Tauri POS** in-app rendering of the transfer impact on stock displays — out of scope per the design note, and there's no Tauri build I can spin up in this session.
- **Hash-chain / fiscal-audit** implications: stock movements feed the audit-log time-series (TimescaleDB) per the architecture docs. I did not trace whether the new `TransferOut` / `TransferIn` movements with `reference_type = StockTransfer::class` produce the right audit log entries downstream. The `StockMovementRecorded` events fire as before, so the existing audit pipeline should pick them up — but I didn't open the projector that consumes those events.
- **High-concurrency** behavior of `generateTransferNumber` (P3-2) under real load — I reasoned about it but did not run a stress test.
- **Visual** quality of the list/detail/create pages — the prompt flagged `tokens.card.hoverPrimary` as a possible "blue on blue" tint, but I didn't render the pages to check.
- **Inter-tenant cross-talk** at the auth layer — the controller relies on `CompanyContext::requireCompany()` which depends on the upstream auth + tenant middleware (sanctum + `EnforceTokenTenantClaim` + `SetPermissionsTeam`). I trust those because the rest of the codebase uses the same chain and there's a tenant-isolation-sweep cluster that audited it.
- **Frontend pnpm test** — I did not run the Vitest suite. The test file is straightforward and looks well written.
