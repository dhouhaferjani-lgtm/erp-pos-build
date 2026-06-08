Inventory Transfer (PR #147) — Codex Adversarial Review

Verdict: REQUEST-CHANGES
Confidence: high
Date: 2026-05-28

Summary

The core document lifecycle is coherent and the focused backend suite passes, but I would not merge this as-is. The main problem is that transfer-cost capitalization is order-dependent when more than one in-transit transfer for the same product completes in different orders. That is a financial correctness issue, not a UI nit. Idempotency is also incomplete: create supports an idempotency key but the frontend never sends one, `complete`/`cancel` have no idempotency contract, and two parallel create requests with the same key can still surface a database exception instead of returning the winning transfer.

BLOCKERS (must fix before merge)

1. WAC capitalization is not deterministic when concurrent in-transit transfers for the same product complete in different orders.

   Code path: `complete()` receives destination stock first at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:174`, then capitalizes transfer cost at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:193`, and `recordCostAdjustment()` divides by current `stock_levels` quantity only at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:480`.

   Two-process scenario:
   - Start with product X: 100 units in warehouse, WAC 5.00.
   - Process A initiates transfer A: 10 units, transfer_cost 100.
   - Process B initiates transfer B: 10 units, transfer_cost 50.
   - After both initiations, `stock_levels` on-hand is 80 because both transfers have decremented source stock and there is no in-transit stock bucket.
   - If A completes first, destination receipt makes on-hand 90, so WAC becomes `5 + 100/90 = 6.1111`. Then B completes, on-hand is 100, so WAC becomes `6.1111 + 50/100 = 6.6111`.
   - If B completes first, WAC becomes `5 + 50/90 = 5.5556`. Then A completes, WAC becomes `5.5556 + 100/100 = 6.5556`.

   Same business facts, different final product cost. The source `lockForUpdate()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:313` serializes the source decrement, and `recordCostAdjustment()` locks product/stock rows at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:470` and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:480`, but those locks only serialize the chosen completion order; they do not make the denominator stable. This needs a policy decision: either include in-transit owned quantity in the capitalization denominator, explicitly accept order-dependent WAC, or defer transfer-cost capitalization until the in-transit availability/quantity model lands.

2. `complete()` is not retry-safe in the way the spec requires.

   The spec requires completion retry safety at `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:202`. The route and API do not accept an idempotency key for completion: frontend posts `{}` at `apps/web/src/features/stock-transfers/api/stockTransferApi.ts:45`, controller calls the service with only transfer ID and user ID at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:171`, and the service signature has no key at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:157`.

   Two-process/retry scenario:
   - Client sends `POST /stock-transfers/{id}/complete`.
   - Server receives stock at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:174`, relabels the movement at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:183`, applies cost at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:195`, and saves `completed` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:198`.
   - The response is lost.
   - Client retries the same request.
   - `lockTransfer()` reloads the row at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:466`; `canBeCompleted()` is now false, so the retry returns `INVALID_TRANSFER_STATE` from `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:162`.

   The status guard prevents duplicate movements, but it does not give the caller a successful idempotent result or a way to distinguish "already completed by my previous request" from "some other actor completed/cancelled it." That is not equivalent to the spec's idempotency requirement.

P1 (strong concerns)

1. Parallel create requests with the same `idempotency_key` can still throw instead of returning the existing transfer.

   The migration does define the expected unique index at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:62`, and the service checks for an existing key at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:89`. But the check is a read-before-insert without a catch/reload path.

   Two-process scenario:
   - Process A and B both call `initiate()` with `idempotency_key = k`.
   - Both enter the transaction and both find no existing row at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:90`.
   - Both proceed to `StockTransfer::create()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:114`.
   - One insert wins; the other hits the unique index at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:62`.

   There is no `QueryException` handling that catches the unique violation and reloads the winning row. A retry-safety feature should not leak a 500/SQL exception under the exact parallel retry pattern it exists to handle.

2. Transfer number generation has the same race shape.

   `generateTransferNumber()` counts existing rows and returns `count + 1` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:527`. The unique constraint is only enforced later by the DB at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:61`.

   Two-process scenario:
   - Process A and B both initiate any transfer for the same company.
   - Both count the same number of rows at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:530`.
   - Both generate the same `TR-YYYY-NNNNN` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:536`.
   - One insert wins; the other fails at the unique index.

   This happens before the source `stock_levels` lock at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:313`, so the source-row lock does not protect numbering.

3. The movement re-label pattern is brittle and silently accepts audit corruption.

   `StockTransferService` calls `StockAdjustmentService::issue()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:334` or `receive()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:174`, then updates the "latest" movement at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:451`. The update has no tenant/company predicate and does not assert that exactly one row changed.

   Two-process scenario for same source transfer:
   - If two transfer initiations for the same product/source both reach the stock loop with distinct transfer numbers, the source `stock_levels` lock at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:313` plus the primitive lock at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:118` serializes the issue/re-label section strongly enough that those two transfer processes should not re-label each other's rows.
   - That protection does not cover a non-transfer caller using the same human reference. `StockMovementController::issue()` accepts arbitrary `reference` at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:101` and passes it through at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:121`. An admin can issue with `reference = "TR-2026-00007"`.
   - If that manual issue has the same product/location/type and `reference_id IS NULL`, the re-label query at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:451` depends on `created_at` ordering only at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:457`. A timestamp tie is not deterministically broken.
   - If the `update()` affects 0 rows, the transaction still succeeds because the return value is ignored at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:459`.

   The cleaner refactor is small: add optional `MovementType $movementType = MovementType::Issue|Receipt`, `?string $referenceType`, and `?string $referenceId` parameters to `StockAdjustmentService::issue()`/`receive()`, thread them into `recordMovement()`, and delete the post-hoc update. That touches the two public primitive methods plus `recordMovement()` and the new transfer tests; it is not a broad rewrite.

4. `StockMovementRecorded` events are emitted as `issue`/`receipt`, even after the DB row is re-labeled to `transfer_out`/`transfer_in`.

   `StockAdjustmentService::issue()` snapshots and dispatches `movementType: 'issue'` at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:168`, while the transfer service re-labels the row later at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:343`. `receive()` similarly dispatches `movementType: 'receipt'` at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:79`, while completion re-labels the row at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:183`.

   Any event consumer sees a generic issue/receipt event for a transfer movement. The legacy primitive emits `transfer_out`/`transfer_in` directly at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:277` and `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:292`, so the new document workflow is less accurate than the existing primitive.

P2 (worth fixing this PR)

1. The frontend create flow never sends an `idempotency_key`.

   The TS type includes `idempotency_key` at `apps/web/src/features/stock-transfers/types/index.ts:64`, but `CreateStockTransferPage` builds a payload without it at `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:95`. The backend accepts the field at `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:40` and passes it to the service at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:136`.

   A browser retry or "submit succeeded but response lost" creates a second transfer because the user-facing client does not use the backend idempotency seam.

2. Reusing an `idempotency_key` with a different payload silently returns the first transfer.

   The service returns the existing transfer at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:96` without comparing source location, destination location, lines, quantities, transfer cost, or distribution. A client bug can submit payload A with key K, then payload B with the same key K, and receive A as if B had succeeded. At minimum, persist a request fingerprint or compare the incoming DTO to the existing transfer and return a 409 on mismatch.

3. Public service methods do not carry tenant/company scope for `complete()` and `cancel()`.

   The controller does a scoped prefetch before calling the service at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:166` and `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:194`, but `StockTransferService::lockTransfer()` itself reloads by ID only at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:469`. If another caller uses the application service directly, the service contract does not enforce the stated `(tenant_id, company_id)` write boundary from `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:44`.

4. `ProRataValue` with zero value falls back to equal-per-line, but I could not find that behavior in the spec.

   The spec defines `ProRataValue` as a distribution mode at `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:78`, and the enum documents value weighting at `apps/api/app/Modules/Inventory/Domain/Enums/TransferCostDistribution.php:12`. The implementation computes value weights at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:428`, then falls back to equal-per-line when total weight is zero at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:396`. That fallback may be reasonable, but it should be explicit in the contract and covered by tests.

5. The batch-preservation follow-up will have to change the current line/flow model.

   `stock_transfer_lines` has no `batch_id` or `variant_id` column at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:69`, and it enforces one row per transfer/product at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:82`. The DTO carries only product and quantity at `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferLineData.php:15`, and the service rejects duplicate product lines at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:496`.

   The existing primitive can record batch movement rows when a `batchId` is supplied to `issue()`/`receive()` at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:114` and `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:37`, but the new service never passes one at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:334` or `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:174`. If a future transfer quantity spans multiple FEFO batches, the current single product-line/single issue-call shape has to be extended or split.

6. Migration placement conflicts with the T1 topology contract.

   The T1 spec says all T1 migrations go to `database/migrations/tenant/` at `docs/superpowers/specs/2026-05-24-t1-stock-transfer.md:55`, while this migration is in root `database/migrations` and FKs `tenant_id` to the central tenants table at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:31`. The author note intentionally chose row-level reality at `docs/superpowers/coordination/2026-05-28-inventory-transfer.md:45`, and current tests run in that mode, so I am not calling this a current-runtime blocker. But `tenants:migrate` is configured to run only `database/migrations/tenant` at `apps/api/config/tenancy.php:195`, so T6 has to move this migration before tenant DB creation can include these tables.

7. Create error handling discards actionable insufficient-stock details.

   The backend returns `error.details.product_id`, `requested`, and `available` at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:267`. The create page catches all errors and shows only `create.error` at `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:112`. Users cannot see which line to correct.

8. Test coverage misses the cost modes and idempotency edge cases most likely to regress.

   The existing WAC test only covers the default pro-rata-value mode at `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:399`. I did not find coverage for `ProRataQuantity` or `EqualPerLine`. The idempotency test covers same key/same payload serial reuse at `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:312`, but not same key/different payload or parallel same-key inserts. There is no cancelled-to-cancelled rejection test; the suite covers completed-to-cancelled at `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:453`.

P3 (nits, future)

1. `cancel()` uses a reference that still matches transfer-number prefix filters.

   Cancellation from in-transit uses `TR-...-CANCEL` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:239`. It is still anchored to the original transfer via `reference_type`/`reference_id` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:252`, so this is not an internal data integrity issue. But any export/report using `reference LIKE 'TR-%'` will see cancellation receipts as transfer-looking rows unless it also filters `movement_type` or `reference_id`.

2. The cancel modal duplicates modal structure because `ConfirmDialog` does not accept a reason field.

   The detail page uses shared `ConfirmDialog` for complete at `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:224`, then hand-builds the cancel modal at `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:236`. This is acceptable for the PR, but extending `ConfirmDialog` with a body slot would avoid drift.

3. SQLite skips the CHECK constraints; service/request validation currently carries the load.

   CHECK constraints are PostgreSQL-only at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:86`. Request validation covers locations, positive quantities, and non-negative costs at `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:34`. The service also guards positive line quantities at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:131`, but it does not independently reject a negative `transferCost` passed directly via DTO at `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php:33`.

What I verified by reading code

- Spec and author scope: Scenario B, per-location tax IDs, InTransitAvailability, batch preservation, and variant-aware scoping are explicitly deferred in the author note at `docs/superpowers/coordination/2026-05-28-inventory-transfer.md:141`.
- Migration schema, indexes, and PostgreSQL-only checks at `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:29`.
- Status predicates match `draft -> in_transit -> completed | cancelled`: `canBeInitiated()` at `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:27`, `canBeCompleted()` at `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:32`, and `canBeCancelled()` at `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:37`.
- Source/destination locations are company-scoped on create via `loadLocationOrFail()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:477`; products are tenant+company scoped via `loadAndVerifyProducts()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:510`.
- Controller reads are tenant+company scoped for index/show/complete/cancel prefetch at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:35`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:86`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:166`, and `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:194`.
- Permissions are declared at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:126` and granted to admin/all permissions plus manager at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:375` and `apps/api/database/seeders/RolesAndPermissionsSeeder.php:393`. Routes use matching `can:inventory.transfers.*` middleware at `apps/api/app/Modules/Inventory/Presentation/routes.php:78`.
- Frontend routes use matching `RequirePermission` keys at `apps/web/src/routes/index.tsx:1092`, `apps/web/src/routes/index.tsx:1102`, and `apps/web/src/routes/index.tsx:1112`.
- `stockTransferApi.list()` intentionally preserves pagination meta with `api.get` at `apps/web/src/features/stock-transfers/api/stockTransferApi.ts:30`; `apiGet`/`apiPost` unwrap `response.data.data` at `apps/web/src/lib/api.ts:197`.
- English/French `stock-transfers` locale keys match. I verified this with a Node key-flatten diff; both `en-only` and `fr-only` were empty.
- Focused backend tests pass when the sandbox allows Laravel/PHPUnit to write logs/cache: `./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php` returned `OK (12 tests, 38 assertions)`.

What I could not verify

- I did not run true two-session database concurrency tests. The concurrency findings above are code-path analyses against transaction order, locks, and unique constraints.
- The first sandboxed PHPUnit run failed because Laravel could not append to `apps/api/storage/logs/laravel.log` or `.phpunit.result.cache`; rerunning the same command with write permission passed.
- I did not run frontend Vitest, typecheck, Playwright, PHPStan, or full backend feature suite.
- I did not verify PR metadata beyond local git: `git fetch` completed, branch head is `cd90310c7`, and `git log --oneline origin/dev..origin/feat/inventory-transfer` shows `cd90310c7` at the tip.
