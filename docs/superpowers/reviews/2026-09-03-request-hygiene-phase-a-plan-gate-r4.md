# Plan gate r4 — request hygiene Phase A

## Verdict: REJECT

Revision 4 genuinely resolves most r3 findings, including Task 1 for the explicitly supported database-per-tenant topology. It is not executable as written because five blocking defects remain. No files were modified.

## Blocking findings

1. **Task 2 — the deterministic-order regression can pass before the tie-breaker exists.**

   Evidence: [plan:537](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:537), [StockMovementController.php:70](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:70).

   The test checks count, uniqueness, and set equality using `assertEqualsCanonicalizing()`. Databases commonly return the same repeatable physical order for both page queries, so current `ORDER BY created_at` can yield two non-overlapping pages and satisfy every assertion without `id DESC`.

   **The plan must say instead:** compute the expected IDs ordered by `created_at DESC, id DESC` and use `assertSame($expectedIds, $actualIds)`, as Task 3 already does.

2. **Task 4 — the translated validation test asserts the wrong response path.**

   Evidence: [plan:1350](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1350), [bootstrap/app.php:323](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/bootstrap/app.php:323).

   The global validation renderer nests errors under `error.errors`. The proposed `assertJsonPath('errors.to.0', ...)` therefore fails after the implementation.

   **The plan must say instead:** assert `error.errors.to.0`, and assert the `VALIDATION_ERROR` envelope so the test cannot pass through an unrelated 422.

3. **Task 4 — the core audit pagination/cap contract has no red test.**

   Evidence: [synthesis:71](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/audits/2026-09-02-request-hygiene/05-synthesis.md:71), [plan:1325](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1325), [AuditController.php:67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:67).

   The new tests cover payload omission and dates, but none seeds more than 50 audit events or asserts pagination metadata. The main S-3 outcome—replacing an unbounded read with a default-50 bounded read—could regress while all proposed Task 4 tests remain green.

   **The plan must say instead:** add a test with at least 51 events asserting 50 rows, `meta.total`, `meta.current_page`, `meta.last_page`, and page-two traversal; also prove `per_page=101` is rejected.

4. **Task 9 — “boot validation” is installed only for the main container entrypoint.**

   Evidence: [plan:1806](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1806), [entrypoint.sh:214](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:214), [entrypoint-worker.sh:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-worker.sh:39), [entrypoint-scheduler.sh:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-scheduler.sh:29).

   Worker and scheduler images have separate entrypoints and start after their own `config:cache`, without running `cache:verify-store`. The one-time promotion checklist does not provide permanent fail-closed boot behavior after a later configuration regression.

   **The plan must say instead:** modify every runtime entrypoint—or invoke one shared validation script—after `config:cache`, including worker and scheduler. Add container/shell verification proving each role exits non-zero with `CACHE_STORE=database`.

5. **Task 12 — the prescribed SplitPaymentForm handler violates the mandatory monetary-precision rule.**

   Evidence: [CLAUDE.md:71](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71), [plan:2285](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2285), [SplitPaymentForm.tsx:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:101), [decimal.ts:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/lib/decimal.ts:50).

   The exact replacement retains `parseFloat(line.amount)` and float subtraction for currency. Its integer-only test cannot expose `0.1 + 0.2` drift.

   **The plan must say instead:** calculate and validate totals with `bcadd`, `bcsub`, and `bccomp` using strings, and add a fractional three-decimal split-payment test. Do not introduce or preserve `parseFloat` in the rewritten handler.

## Non-blocking findings

- Task 13’s citation is stale: `ReplenishmentFulfillmentService.php:75` builds line data; the actual `initiate()` call is at [ReplenishmentFulfillmentService.php:102](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:102).
- Task 13’s statement that its new subclass “does not compile” before seam extraction is false. PHP permits a subclass to introduce protected methods; the test instead fails because current production code never calls them. The regression remains genuinely red.
- Task 13 proves a top-level rollback reaches transaction level zero. Replenishment calls `initiate()` inside an outer transaction, where rollback returns to a clean savepoint at level one. Add a nested-transaction collision case for stronger coverage.
- Task 7 removes the duplicate request between `AvailabilityCell` and `TransferSourceSuggestion`, but it does not eliminate one request per distinct product. The bulk endpoint remains deferred to B-8, so Phase A must not claim S-6 is completely closed.
- Task 9’s `method_exists($store, 'tags')` capability check is correct, but the infrastructure-free CI artisan job at [ci.yml:2618](/Users/houssamr/Projects/syneriva/apps/erp/.github/workflows/ci.yml:2618) does not pin `CACHE_STORE`. Its current commands do not access cache, but explicitly setting `array` would protect that assumption.
- Task 14’s tail recovers from API rejection. A callback that itself throws from `onError` could still reject the stored tail and prevent later `.then(run)` work; chaining from a swallowed tail would make the recovery guarantee unconditional.
- The claimed 16 provisioned staging tenant databases cannot be verified from this checkout; the Phase 0 deployment preflight must remain binding.
- External POS/mobile consumers remain absent. The proposed external-owner contract evidence or rollout block is necessary.

### r3 disposition audit

- **B1 Task 1:** resolved for the expressly limited production topology. The registrar is pre-resolved in the test, and `initializeCache()` rereads the configured key. In compatibility mode, an unprovisioned tenant produces no event; a provisioned tenant can emit `TenancyInitialized`, but both bootstrap and permission listeners intentionally no-op while `db_per_tenant=false`.
- **B2 portable wildcard search:** resolved.
- **B3 Alpha/Beta frontend fixture:** resolved.
- **B4 FormRequests and stable implementation ordering:** resolved, but Task 2’s proof remains inadequate as finding 1 above.
- **B5 localized Task 4 message:** implementation resolved; test envelope is broken as finding 2 above.
- **B6 Task 13 false-positive race:** resolved with the instrumented two-connection collision.
- **R3 non-blockers:** key fixtures, W4 last-page proof, obsolete payment comment removal, reactive Task 7 scope, exact-log suites, and Task 14 debounce coverage are genuinely addressed. Task 9’s reachability promotion gate is present, but permanent role boot validation remains incomplete.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | [QueueTenancyBootstrapper.php:82](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:82) | Workers initialize directly from payload rather than through `TenancyResolver`; the proposed true-mode listeners still run correctly. |
| 2 | [InventoryTenantIsolationTest.php:713](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php:713) | Unpaged test calls now receive only page one plus metadata; current fixtures appear below the cap. |
| 3 | [TreasuryCompanyIsolationTest.php:415](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:415) | Search calls inherit mandatory pagination and case-sensitive database behavior. |
| 4 | [onboarding.campaign.ts:293](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/campaign/onboarding.campaign.ts:293) | Five live onboarding reads use `documents?limit=100`; they remain within the new cap but directly exercise the edited controller. |
| 5 | [CreateCountingPage.tsx:407](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:407) | Counting product search acquires the visible 250 ms delay. |
| 6 | [DocumentForm.tsx:697](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:697) | All shared document-line pricing waits 250 ms; Task 6’s WAIT is therefore necessary. |
| 7 | [GoodsReceiptListPage.tsx:282](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/GoodsReceiptListPage.tsx:282) | Existing `stock-levels` namespace invalidation now also matches the new shared query. |
| 8 | [DashboardLayout.tsx:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47) | Every authenticated route suppresses repeat reconnect recovery for 30 seconds. |
| 9 | [entrypoint-worker.sh:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-worker.sh:41); [entrypoint-scheduler.sh:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint-scheduler.sh:31) | Misconfigured roles can boot without the proposed tag-capability check. |
| 10 | [ReceiptReturnServiceTest.php:292](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:292) | This and many other unlisted log spies can observe the global `lazy-load` warning; the full CI suite is essential. |
| 11 | None found | New hook has no existing consumer. |
| 12 | [InvoiceDetailPage.tsx:895](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895); [SalesOrderDetailPage.tsx:780](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780); [PurchaseOrderDetailPage.tsx:709](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709) | All active record-payment hosts inherit the new key and synchronous lock. |
| 13 | No additional direct backend caller found | Replenishment is the sole additional `StockTransferService::initiate()` caller and is named, although its cited line is wrong. |
| 14 | [DocumentForm.tsx:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:277) | Every document type using the shared form receives serialized autosave behavior. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | **Conditional yes** | Correct for verified `db_per_tenant=true` environments; staging topology and queue-drain facts must be confirmed. |
| 2 | **No as written** | Runtime design is plausible, but deterministic pagination is not proven and external capped clients remain unknown. |
| 3 | **Conditional yes** | No protected file, but mandatory pagination requires external client evidence. |
| 4 | **No as written** | Directly edits `DocumentController`, used by live onboarding, and contains a broken test plus missing cap proof. |
| 5 | **Conditional** | Shared component changes document, transfer, replenishment, and live counting UX. |
| 6 | **No until WAIT clears** | Directly edits `DocumentLineEditor`; the explicit PO-lane wait must remain. |
| 7 | **Conditional** | Does not touch `ProductController` or counting, but edits the live stock-transfer page. |
| 8 | **Conditional** | Global authenticated-layout behavior; testers can observe stale data after a second reconnect within 30 seconds. |
| 9 | **No as written** | Worker and scheduler boot validation is absent. |
| 10 | **Conditional** | Production is unchanged; staging and every PHP test gain global warning logging. |
| 11 | **Yes** | Isolated new hook. |
| 12 | **No as written** | High-impact payment paths plus a direct monetary-precision rule violation. |
| 13 | **Conditional** | Core transfer and replenishment transaction behavior changes; collision design itself is sound. |
| 14 | **Conditional** | Does not edit `DocumentForm`, but changes observable autosave timing and lifecycle across that live form. |

## Things verified correct

- `PermissionRegistrar::initializeCache()` rereads configuration and correctly rekeys a singleton resolved before tenancy initialization.
- Stancl queue processing initializes from `tenant_id` and restores central context after each job.
- Task 2’s `ESCAPE '!'` search is portable across PostgreSQL and SQLite; aliases, FormRequest types, imports, and frontend fixture are correct.
- Task 3’s FormRequest, six-field metadata, exact frontend keys, dashboard request, deterministic assertion, and page iterator are coherent.
- Task 4 adds the required en/fr/ar key and preserves aggregate/range ascending order.
- Tasks 5 and 6 use the existing hook, mocks, response shape, and controlled component patterns.
- Task 7’s key is tenant/company scoped, reactive, and compatible with existing `stock-levels` invalidations.
- Task 8’s nullable epoch-zero sentinel and 30-second cooldown test are correct.
- In this Laravel version, `method_exists($store, 'tags')` distinguishes `DatabaseStore` from taggable Redis/array stores. PHPUnit pins `CACHE_STORE=array`.
- Laravel’s lazy-loading callback logs and returns, allowing the relation to load; Task 10’s proportional query fixture detects the current N+1.
- Task 13’s PostgreSQL unique violation reaches the outer catch after rollback, and the top-level reread runs on a clean connection.
- Task 14 preserves debounce, serializes saves, forwards the first draft ID, handles StrictMode replay, and blocks queued work after reset/unmount.
- No migration is proposed, so the migration self-guard requirement is not implicated.