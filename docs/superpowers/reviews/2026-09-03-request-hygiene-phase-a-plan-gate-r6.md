# Plan gate r6 — request hygiene Phase A

## Verdict: REJECT

Revision 6 genuinely resolves all three r5 blockers and retains its non-blocking safeguards. The full rerun found two new blocking gaps:

1. Task 9 omits the repository’s fourth runtime entrypoint.
2. Task 13’s PostgreSQL test avoids the dual-constraint collision produced by real requests, allowing legitimate idempotent retries to remain 500s.

## Blocking findings

### 1. Task 9 — WebSocket runtime bypasses fail-closed cache validation

**Evidence:** The plan says the helper belongs in “every runtime entrypoint,” but enumerates only web, worker, and scheduler at [plan:2064](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2064), [plan:2075](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2075), and [plan:2105](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2105). The image has a separate WebSocket target at [Dockerfile:179](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/Dockerfile:179). Its entrypoint tolerates `config:cache` failure and starts Reverb without `cache:verify-store` at [entrypoint-websocket.sh:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:28) and [entrypoint-websocket.sh:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:38).

**What is wrong:** `CACHE_STORE=database` or another non-taggable effective configuration can still boot the WebSocket process. Its Redis TCP wait proves that a Redis host is reachable, not that Laravel’s effective cache store is Redis or tag-capable. This contradicts the task’s permanent fail-closed claim.

**What the plan must say instead:** Add `apps/api/docker/entrypoint-websocket.sh` to Task 9’s Modify list. Give it the same `--check-only` branch, source `verify-cache-store.sh` after `config:cache`, include it in both shell-harness loops and `sh -n`, and require a separate WebSocket-container Redis configuration/capability/write-read-delete promotion probe. The promotion list must name web, worker, scheduler, WebSocket, and CLI.

### 2. Task 13 — collision test masks the real generated-number collision

**Evidence:** Production generates transfer numbers with `COUNT()+1` at [StockTransferService.php:1095](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:1095). The HTTP controller supplies an idempotency key but no transfer number at [StockTransferController.php:173](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:173); the request exposes only `idempotency_key` at [StoreStockTransferRequest.php:83](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:83). The schema has both company-number and idempotency uniqueness at [2026_05_28_120000_create_stock_transfers_table.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66).

The proposed catch only accepts an exception naming `stock_transfers_idempotency_unique` at [plan:2766](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2766). But the proposed PostgreSQL test supplies a special loser number at [plan:2959](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2959), while its writer inserts `$transferNumber.'-WINNER'` at [plan:3060](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3060).

**What is wrong:** Two real same-key requests can both miss the pre-check and generate the same transfer number. The loser then conflicts with both unique indexes. PostgreSQL is not contractually required to report the idempotency index rather than `stock_transfers_company_number_unique`. The proposed test deliberately assigns different transfer numbers, guaranteeing an idempotency-only collision and concealing this path. If PostgreSQL reports the company-number constraint, the proposed catch rethrows and a legitimate retry still returns 500.

**What the plan must say instead:**

- When `idempotencyKey !== null`, catch any `UniqueConstraintViolationException` after rollback.
- Re-read by tenant, company, and idempotency key.
- Return the committed row when found; otherwise rethrow the original exception.
- Keep the different-index/no-row test: it still proves unrelated unique failures are rethrown.
- Change the real PostgreSQL collision test to omit the explicit `transferNumber` and have the writer insert the exact same `$transferNumber`, not a suffixed value.
- Exercise this actual dual collision at transaction levels 0 and 1.

## Non-blocking findings

- The r5 Task 2 blocker is genuinely fixed: Revision 6 has one parse-valid fixture initializer with both rows and all six metadata fields at [plan:742](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:742).
- The r5 Task 4 blocker is genuinely fixed: `sometimes` is gone, all four fields begin with `required_with`, and the four counterpart-error tests cover the correct envelope at [plan:1194](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1194).
- The r5 Task 12 blocker is genuinely fixed: both required-total props become strings; callers, bcmath operations, precision tests, and type guards are inventoried at [plan:2483](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2483) and [plan:2673](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2673).
- Task 3 misses older Playwright payment fixtures. For example, [payments.spec.ts:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/payments.spec.ts:20) supplies only `meta.total`. Its row assertion can pass while `OffsetPagination` renders `Page undefined of undefined`. These mocks should receive all six metadata fields and one pagination assertion.
- Five W8 calls use `per_page=100` without traversing pages, e.g. [w8-isolation.spec.ts:71](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w8-isolation.spec.ts:71). They remain valid for their current bounded fixtures, but are not whole-set helpers.
- Task 10’s four named log contracts are not exhaustive; many additional tests install global log spies. The mandatory CI-only whole-backend gate remains necessary.
- RecordPaymentModal’s float totals at [RecordPaymentModal.tsx:210](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:210) are pre-existing and correctly deferred.
- Task 7 remains honestly partial: one request per distinct product/variant remains.
- Task 9’s Redis default can break ad-hoc Artisan/bootstrap jobs that neither set `CACHE_STORE` nor provide Redis. PHPUnit pins `array`, and Revision 6 pins the infrastructure-free types job; this is otherwise the intended fail-closed behavior.
- Task 12’s SplitPaymentModal line anchors are approximate: the actual property, example conversion, and pass-through are lines 42, 73, and 122, not exactly 18, 65, and 120. The cited ranges still identify the correct regions.
- Task 13 does not fix different-key `COUNT()+1` transfer-number races. A failed idempotency re-read should still rethrow that collision; sequence allocation remains separate S-19 debt.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | None beyond the plan’s permission-reset and queue inventory | Pre-resolved singleton and queue-worker paths are covered. |
| 2 | [ProductMovementsTab.tsx:115](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/components/ProductMovementsTab.tsx:115) | Already sends page/per-page and remains compatible with mandatory pagination. |
| 3 | [payments.spec.ts:15](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/payments.spec.ts:15) | Incomplete metadata can display broken pagination while the row-only test passes. |
| 4 | None found outside the named audit tests and document consumers | Payload opt-in and cap inventory is otherwise complete. |
| 5 | [DocumentLineEditor.tsx:1168](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/DocumentLineEditor.tsx:1168); [CreateCountingPage.tsx:407](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:407) | Document and live counting product searches acquire a visible 250 ms delay. |
| 6 | [DocumentForm.tsx:697](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:697); [CreateCreditNotePage.tsx:628](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/CreateCreditNotePage.tsx:628) | All shared line-price recalculation waits 250 ms. |
| 7 | None beyond the named prefix invalidators | Existing `stock-levels` invalidations continue to match. |
| 8 | [DashboardLayout.tsx:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47) | Every authenticated route suppresses a repeat reconnect refresh inside 30 seconds. |
| 9 | [entrypoint-websocket.sh:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-websocket.sh:28) | Reverb can boot with an incompatible effective cache store. Blocking. |
| 10 | [ImportJobClaimConcurrencyTest.php:203](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Import/ImportJobClaimConcurrencyTest.php:203), among many log spies | A lazy-load warning may alter global log expectations. |
| 11 | None | New hook has no existing consumer until Tasks 12/13. |
| 12 | None beyond the three named RecordPaymentModal hosts | SplitPaymentModal has no active repository caller. |
| 13 | [StockTransferController.php:174](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:174); [ReplenishmentSettlementTest.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Replenishment/ReplenishmentSettlementTest.php:219); [TransferLineQueryServiceTest.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/TransferLineQueryServiceTest.php:39) | HTTP requests use generated numbers and expose the masked dual collision; additional direct service tests should remain green. |
| 14 | None | The sole consumer, DocumentForm, is explicitly named. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | Conditional yes | Requires verified DB-per-tenant topology, tenant migrations, queue drain, and staged worker proof. |
| 2 | Conditional yes | Does not touch ProductController/counting; external client pagination evidence remains mandatory. |
| 3 | Conditional yes | Mandatory pagination changes client behavior; external POS/mobile ownership gate remains binding. |
| 4 | Conditional | Directly edits DocumentController used by onboarding; coordinate with the live document lane. |
| 5 | Conditional | Changes visible search timing in documents, transfer, replenishment, and counting. |
| 6 | No until WAIT clears | Directly edits DocumentLineEditor; the post-PO-merge rebase is mandatory. |
| 7 | Conditional | Edits the live stock-transfer page and only partially reduces fan-out. |
| 8 | Conditional | Global authenticated-layout behavior; testers can observe stale data after rapid reconnects. |
| 9 | No as written | WebSocket boot remains fail-open. |
| 10 | Conditional | Production behavior is unchanged; staging/tests gain global lazy-load warnings. |
| 11 | Yes | Isolated hook with no current consumer. |
| 12 | Conditional | High-impact payment paths, but Revision 6 now respects the decimal-string boundary. |
| 13 | No as written | A genuine same-key transfer retry may still 500 on the company-number constraint. |
| 14 | Conditional yes | Serialization preserves debounce, but changes live DocumentForm autosave and must rebase after the PO lane. |

## Things verified correct

- **Task 1:** `PermissionRegistrar::initializeCache()` rereads the configured key even when its singleton was previously resolved at [PermissionRegistrar.php:67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/PermissionRegistrar.php:67). Stancl emits `TenancyInitialized` from every direct initialization at [Tenancy.php:52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Tenancy.php:52), including compatibility mode; the proposed listener correctly returns when DB-per-tenant is false. Queue processing initializes and restores tenancy through real events at [QueueTenancyBootstrapper.php:62](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:62).
- **Tasks 2–4:** FormRequest signatures, enum imports, escaping, stable ordering, pagination metadata, payload opt-in, four paired-field failures, and en/fr/ar custom key design are coherent. Their proposed regressions are genuinely red against current behavior and green after the described changes.
- **Tasks 5–8:** Existing debounce/query/store APIs and test helpers match. The tests exercise real pre-change failures. Tenant-scoped keys and the epoch-zero-safe reconnect sentinel are correct.
- **Task 9:** In this Laravel version, `DatabaseStore` is not taggable at [DatabaseStore.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:18), while Redis and array stores extend `TaggableStore` at [RedisStore.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Cache/RedisStore.php:14) and [ArrayStore.php:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Cache/ArrayStore.php:9). `method_exists($store, 'tags')` is valid here.
- **Task 10:** Laravel’s lazy-loading violation callback returns instead of throwing at [HasAttributes.php:600](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:600). Explicit timestamps remove the r5 nondeterminism.
- **Tasks 11–12:** Hook signature, UUID lifecycle, mutation APIs, synchronous ref locks, pending state, decimal helpers, and all local split-payment callers match current code.
- **Task 13:** The exception does escape `DB::transaction()` only after rollback at [ManagesTransactions.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:41). Top-level rereads occur on level 0; nested calls can reread on a usable level-1 transaction after savepoint rollback. The defect is constraint filtering and the masked fixture, not transaction reachability.
- **Task 14:** The promise tail preserves debounce coalescing, serializes manual and debounced saves, forwards the first draft ID, recovers from API or `onError` rejection, and prevents reset/unmount generations from launching queued work.
- No migration is proposed, so the migration self-guard rule is not implicated.