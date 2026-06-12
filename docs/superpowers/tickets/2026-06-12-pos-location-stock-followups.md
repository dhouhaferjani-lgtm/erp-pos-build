# POS Location-Aware Stock — Follow-up Tickets (2026-06-12)

Source: review trail from the `feat/pos-location-aware-stock` implementation.

---

## FU-1: Batch-FEFO deduction ignores `pos_stock_policy`

**Context:** FEFO batch deduction (`ReceiptCreationService` ~:1349-1363) throws `InsufficientBatchStockException` when a sale would exceed batch-tracked stock. This throw path does not consult `pos_stock_policy`; there is no `warn`/`off` equivalent for batch-tracked products.

**What:** decide whether batch-tracked F&B products should be subject to policy treatment. A `warn` or `off` policy on a batch-tracked product currently still hard-blocks when FEFO exhausts batches. Likely correct for batch-tracked perishables, but needs an explicit decision before a batch-tracked Menu tenant is onboarded.

**Where:** `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` ~:1349-1363 + policy lookup in `PosStockPolicy`.

---

## FU-2: ESLint no-restricted-syntax rule banning raw `cartStore.addItem` / `updateQuantity` outside `lib/stock/`

**Context:** stock-guard logic now lives in `apps/pos/src/lib/stock/` and is called at cart-ingress callsites (HomePage tile, barcode resolver, quantity stepper). The guard must not be bypassed by future callers who add directly via the store.

**What:** add an ESLint `no-restricted-syntax` rule that bans raw `cartStore.addItem(` and `cartStore.updateQuantity(` calls outside `apps/pos/src/lib/stock/`. Precedent: the `no-parsefloat-on-money` rule added in the precision contract. Long-term this replaces the code-review source-pin.

**Where:** `apps/pos/.eslintrc.cjs` (or equivalent ESLint config), modeled on the existing `no-parsefloat-on-money` rule.

---

## FU-3: `resolveSellerIdentity` `source` discriminant + `buildEscPosReceiptData` options-object signature

**Context:** `resolveSellerIdentity` returns a plain `SellerIdentity` object with no discriminant indicating whether the identity came from the location or the company. Display callers (`Header.tsx` handlePrintZReport, `buildReceiptData.ts` header block) re-derive completeness independently — if the resolver's logic ever drifts from the re-derivation, the display and the signed payload will disagree.

**What:** (a) consider adding a `source: 'location' | 'company'` discriminant to `SellerIdentity` so display callers consume the same decision; (b) `buildEscPosReceiptData` options object signature should be reviewed for the same drift risk.

**Where:** `apps/pos/src/lib/fiscal/sellerIdentity.ts`, `apps/pos/src/components/Header.tsx` (handlePrintZReport), `apps/pos/src/lib/buildReceiptData.ts` (header identity block).

---

## FU-4: `cleanupStuckReceipts` (>90d) deletion resurrects phantom availability

**Context:** `offlineReceiptRepository.cleanupStuckReceipts()` deletes receipts older than 90 days that never synced. These receipts subtract from `effectiveAvailable` (the selector reads unsynced receipts directly — there is no separate deductions store). Deletion without a corresponding stock re-pull can transiently resurrect `effectiveAvailable` — showing stock as available that was sold but never confirmed to the server.

**What:** this is an accepted residual; document the known bound (90-day receipts are already an edge case; the server does not reflect the sale anyway). If a stock re-pull gate is added to `cleanupStuckReceipts`, the deductions should be cleared atomically with the pull completing.

**Where:** `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` (`cleanupStuckReceipts`, `getUnsyncedReceiptLineBlobs`), `apps/pos/src/lib/stock/availability.ts`.

---

## FU-5: Menu cross-category composite-id alias miss in pending-line matching

**Context:** in `apps/pos/src/lib/stock/availability.ts`, pending-line matching uses a composite product ID. For Menu tenants, cross-category composite IDs may use an alias that is not the canonical ID stored in `location_stock`. This is inert while Menu tenants have `pos_stock_policy = off` (stock skipped entirely), but becomes live if a Menu tenant is ever given stock enforcement.

**What:** document the C2 note in `availability.ts` as a known gap; if Menu tenants are ever granted stock enforcement, this alias resolution must be fixed first.

**Where:** `apps/pos/src/lib/stock/availability.ts` (C2 comment block).

---

## FU-6: Hoist duplicated `applyAllMigrations` test helper (6 copies)

**Context:** six separate SQLite test files each define their own local `applyAllMigrations` helper that runs all migrations against the in-memory SQLite DB. These are byte-for-byte or near-identical duplicates.

**What:** extract into a shared SQLite test adapter (`apps/pos/src/test/sqliteTestHelpers.ts` or similar) and import from each test file. Reduces maintenance burden when new migrations are added (currently requires updating all six copies).

**Where:** any file matching `apps/pos/src/**/__tests__/*.test.ts` that contains `applyAllMigrations`.

---

## FU-7: `location_stock` zero-value scale inconsistency (`'0'` vs `'0.0000'`)

**Context:** the server endpoint (`GET /pos/stock-levels`) returns `available_qty` as a `decimal(14,4)` formatted string (`'0.0000'`), but some legacy paths or test fixtures produce `'0'`. The client availability selector uses `bccompare` which handles both, but display logic that does string-equality checks may treat them differently.

**What:** decide: document the tolerance (bcmath-aware code is fine with either) or normalize at the API boundary to always emit `'0.0000'`. Not a precision bug — cosmetic, but creates a false test-failure risk if display code is written with string equality.

**Where:** `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php` (DTO mapping) + `apps/pos/src/lib/db/repositories/locationStockRepository.ts` ('0' defaults; interface doc already warns against string equality).

---

## FU-8: `PER_PAGE` server constant (500) and client paging have no shared constant

**Context:** the `GET /pos/stock-levels` endpoint uses a hardcoded server-side page size (500 items) to batch the full location stock set. The client's `pullLocationStock` paginates by iterating pages. If the server page size changes, the client must change in sync; there is no shared constant enforcing parity.

**What:** track this as a drift risk. When the endpoint is next touched, extract the page size to a config constant or expose it in the response `meta` so the client can self-configure.

**Where:** `apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php` (`PER_PAGE`) + `apps/pos/src/lib/sync/syncService.ts` (`pullLocationStock` page loop — paginates by `meta.pagination.last_page`, so a server page-size change is actually self-adapting; the drift risk is only payload-size expectations).

---

## FU-9: `approvalFiscalSync` drains receipts without an immediate post-drain stock pull

**Context:** when an offline approval flow completes (e.g. manager-PIN approval for a discount), `approvalFiscalSync` drains the offline receipt queue. It does not immediately trigger a stock pull; the 60-second tick covers the gap. If multiple approval flows fire in rapid succession, stock may be stale for up to 60 seconds.

**What:** flag for review if approval flows need instant stock refresh (e.g. high-volume quick-service). Current cadence is acceptable for the Tier-A parapharmacy and coffee-shop profiles. If a faster refresh is needed, `pullLocationStock` can be called inline after the drain completes.

**Where:** `apps/pos/src/lib/operatorApproval/approvalFiscalSync.ts` + `apps/pos/src/lib/sync/syncService.ts` (`pullLocationStock`).

---

## FU-10: `StockFreshness` stale-on-error keeps previous timestamp

**Context:** `StockFreshness` reads `stock_last_sync` from `sync_metadata` and displays the last successful pull time. If a pull errors, the timestamp is not updated — the hint shows the time of the last *successful* pull. A long run of errors (e.g. server down for 30 minutes) shows an increasingly stale but still-displayed timestamp rather than a degraded indicator.

**What:** decide whether "fail toward hiding" (current: shows last-good timestamp, which at least communicates staleness via increasing age) or "fail toward warning" (show an explicit "stock data unavailable" state on error) is the correct UX. Current behavior is conservative and acceptable for launch.

**Where:** `apps/pos/src/components/atoms/StockFreshness/StockFreshness.tsx` (catch keeps previous timestamp) + `apps/pos/src/lib/sync/syncService.ts` (`STOCK_LAST_SYNC_KEY` write path).
