# Plan gate r1 — request hygiene Phase A

## Verdict: REJECT

The plan is not executable as written. Tasks 2–8, 10, and 12–14 contain compile errors, invalid test harnesses, tests that can pass for the wrong reason, or uncovered behavioral regressions. The claim that every Phase A task is safe alongside Tenant #1 onboarding is also false.

No files were modified.

## Blocking findings

1. **Task 2 — pagination test cannot pass and filtering becomes misleading.**  
   Evidence: [OffsetPagination.tsx:56](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/ui/OffsetPagination.tsx:56), [StockMovementsPage.tsx:127](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/StockMovementsPage.tsx:127), [plan:358](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:358).

   - `OffsetPagination` renders a `div`, not a `navigation` role, so `findByRole('navigation', {name:/pagination/i})` still fails after implementation.
   - The existing page test mocks `useQuery`; the proposed `api.get` mock and undefined `wrapper` cannot simply be appended.
   - Required imports—`useEffect`, `keepPreviousData`, `OffsetPagination`, and the pagination-meta type—are not specified.
   - `write_off` and `transfer` filtering/counts become page-local, but only write-off receives a warning.
   - The new i18n key is not supplied for all three locales.

   The plan must specify a working test harness, test the actual pagination UI, enumerate imports and `en/fr/ar` translations, and either implement server-side movement filters/counts or clearly label every page-scoped filter/count.

2. **Task 3 — `PaymentListPage` would silently truncate payment history.**  
   Evidence: [PaymentController.php:295](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:295), [PaymentListPage.tsx:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentListPage.tsx:66), [PaymentListPage.search.test.tsx:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentListPage.search.test.tsx:57), [plan:420](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:420).

   `PaymentListPage` does not send `page` and has no pagination controls. “Verify only” is therefore insufficient: after the controller cap, users see only the first 25 payments. Its tests also assert exact `/payments` URLs and will fail once paging parameters are added. The dashboard’s `sort=-created_at` parameter is currently ignored by the controller.

   The plan must make `PaymentListPage` a required modification, add page/per-page state and `OffsetPagination`, update query keys/tests, and explicitly remove the unsupported dashboard `sort` parameter.

3. **Task 4 — audit validation can throw, types/imports are incomplete, and existing tests are guaranteed red.**  
   Evidence: [AuditController.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:38), [AuditService.php:117](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Compliance/Services/AuditService.php:117), [AuditTrailTest.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/AuditTrailTest.php:264), [ComplianceCrossTenantHardeningTest.php:310](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:310).

   - The proposed `LengthAwarePaginator` needs the correct import.
   - `AuditService` currently accepts `Illuminate\Support\Carbon`; the proposed FormRequest exposes `CarbonImmutable`. Passing that through is type-incompatible unless the service accepts `CarbonInterface` or converts explicitly.
   - Rules using `date` do not enforce the promised ISO `Y-m-d` contract.
   - A `withValidator` callback that parses malformed dates can throw after basic validation and return 500.
   - Existing Compliance tests assert that default responses contain `payload`; the plan runs them without updating them to request `include=payload`.
   - The proposed `include=payload` test already passes before the change and is not a red test.
   - The document-limit test creates only three records and checks only `meta.per_page`, so it can pass while the query remains unbounded.

   The plan must use guarded `date_format:Y-m-d` validation, resolve the Carbon type, import the paginator, update all existing payload assertions, and create more than 100 documents while asserting the response contains exactly 100 rows.

4. **Task 5 — the debounce test is not a valid red test.**  
   Evidence: [LineItemEntryBar.tsx:69](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx:69), [plan:658](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:658).

   `baseProps` is not an existing helper, and the proposed render setup does not match the current test harness. Three synchronous `fireEvent.change` calls inside one `act` can be React-batched into one render, allowing the test to pass before debounce exists. The implementation also requires unlisted `keepPreviousData` and `useDebouncedValue` imports.

   The plan must use the existing wrapper/mocks and separate user events or timer advances, asserting zero search requests before 250 ms and exactly one afterward.

5. **Task 6 — wrong test helper, wrong payload value, and another unreliable red test.**  
   Evidence: [DocumentLineEditor.tsx:379](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/molecules/line-items/DocumentLineEditor.tsx:379), [MoneyInput.tsx:86](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/atoms/MoneyInput/MoneyInput.tsx:86), [plan:740](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:740).

   - `propsWithOneProductLine` does not exist.
   - The component is controlled, so static props will not reflect successive price edits.
   - `MoneyInput` supplies the raw string; entering `125` produces `unit_price: '125'`, not the asserted `'125.000'`.
   - The mocked response uses `lines`, while the pricing response shape uses `items`.
   - Synchronous changes can be batched, so the test may pass before debouncing.
   - `keepPreviousData` and `useDebouncedValue` imports are missing.

   The plan must adapt the existing controlled-editor harness, use the real response/payload types, and use fake timers with distinct committed renders.

6. **Task 7 — the new query root breaks existing invalidation.**  
   Evidence: [stock-transfers/api/queries.ts:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/api/queries.ts:31), [CreateStockTransferPage.tsx:419](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:419), [TransferSourceSuggestion.tsx:17](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/components/TransferSourceSuggestion.tsx:17).

   Transfer mutations invalidate `['stock-levels']`. The proposed shared hook uses a new `['product-stock-levels', …]` root, so newly cached availability survives transfer create/complete/cancel and can be stale.

   The plan must retain a `stock-levels` root compatible with existing prefix invalidation, or update and test every stock mutation invalidator. Its page test path must also use the existing `features/stock-transfers/__tests__/CreateStockTransferPage.*.test.tsx` layout.

7. **Task 8 — one test is a placeholder and the reference allowlist undermines reconnect recovery.**  
   Evidence: [WebSocketReconnectProvider.tsx:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/providers/WebSocketReconnectProvider.tsx:29), [plan:967](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:967).

   - The cooldown test contains only comments and references an undefined `spy`; it is not executable.
   - `lastInvalidationAt = 0` suppresses the first reconnect when fake time begins at epoch zero.
   - The reference-root list is incomplete and ad hoc.
   - Excluding `locations`, `company-config`, payment methods, and similar data means missed websocket changes can remain fresh and therefore never be recovered by React Query’s ordinary stale-only reconnect behavior.

   The plan must provide a complete test, use a nullable “never invalidated” sentinel, and define a repository-backed transaction/reference classification with tests for actual query keys. Alternatively, invalidate all active queries with only the cooldown.

8. **Task 10 — query listeners leak and the task has no red test for the production change.**  
   Evidence: [plan:1080](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1080), [AppServiceProvider.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:155).

   `DB::listen()` cannot be removed. The closure is not “inert once count is read”; every invocation leaves another listener incrementing retained state for the rest of the process. Repeated tests accumulate listeners and memory.

   The query-budget test is explicitly expected to pass before the provider change, and no test verifies that a lazy load logs without throwing. Its fixtures may also omit document-linked movements, allowing the test to miss the relevant relation path.

   The plan must use a scoped query-log mechanism or restoreable dispatcher, add a red test for the lazy-load handler, and include fixtures that exercise referenced source documents.

9. **Task 12 — proposed PaymentForm harness is invalid and an existing split-payment test will break.**  
   Evidence: [PaymentForm.tsx:265](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentForm.tsx:265), [SplitPaymentForm.tsx:89](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:89), [SplitPaymentForm.test.tsx:147](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.test.tsx:147), [plan:1241](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1241).

   `PaymentForm` accepts no props, and `fillValidPayment()` does not exist. The existing split test asserts an exact body containing only `splits`; adding the key fails it. Checking `isPending` in a render closure also does not absolutely prevent two synchronous submit calls before React rerenders.

   The plan must use the existing form setup, update exact payload assertions, and use a synchronous ref lock if “second submit is a no-op” is a hard invariant.

10. **Task 13 — wrong DTO, wrong test paths, impossible reset location, and the race test does not exercise the race path.**  
    Evidence: [StockTransferService.php:85](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:85), [stock transfer migration:67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:67), [CreateStockTransferPage.tsx:717](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:717), [plan:1390](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1390).

    - The real DTO is `InitiateTransferData`, not `CreateStockTransferData`.
    - The real constraint is `stock_transfers_idempotency_unique`.
    - Both frontend types already contain `idempotency_key`; no generated DTO change is needed.
    - Proposed frontend test paths do not exist; tests live under feature `__tests__` directories.
    - The mutation `onSuccess` callbacks live in each feature’s `api/queries.ts`, not in the pages. The page should reset after successful awaited `mutateAsync`, or the hook API must be changed.
    - Calling a public `replayOnUniqueViolation()` helper directly never proves `initiate()` wraps its `DB::transaction`. The test can pass while the production race remains unfixed.
    - The test does not verify rethrowing no-key collisions or collisions on a different unique index.

    The plan must use `InitiateTransferData`, invoke `initiate()` through a controllable collision seam or assert the wrapper integration, test both rethrow paths, and use the actual frontend test locations.

11. **Task 14 — wrong hook invocation and serialization fails for three callers and on unmount.**  
    Evidence: [useDraftAutoSave.ts:121](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useDraftAutoSave.ts:121), [existing autosave tests:17](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx:17), [plan:1459](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1459).

    - The real signature is `useDraftAutoSave(data, config)`; the proposed test passes one object.
    - The payload property is `type`, not `document_type`.
    - The existing test suite is under `hooks/__tests__`, so the plan’s target misses it.
    - With three overlapping calls, callers 2 and 3 both wait on caller 1 and then start concurrently. Each `finally` also unconditionally clears the shared ref.
    - A queued call does not re-check `isUnmountedRef` after waiting and can start a network save after unmount.
    - `reset()` must clear the synchronous draft-id ref and define what happens to queued/in-flight work.

    The plan must implement a promise tail/mutex or explicit queue, use identity-safe cleanup, abort queued work after unmount/reset, and test three callers, failure recovery, reset, and unmount.

## Non-blocking findings

- **Task 1:** The main mechanism is sound. `initializeCache()` rereads both key and store, including when the registrar singleton was resolved before tenancy initialization. Stancl fires `TenancyInitialized` even with `db_per_tenant=false`, and queue tenancy initialization uses the same event path. Evidence: [PermissionRegistrar.php:67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/PermissionRegistrar.php:67), [Tenancy.php:32](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Tenancy.php:32), [QueueTenancyBootstrapper.php:82](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:82). Add a queue lifecycle regression test anyway. Step 7 is inaccurate: the found flush callers are recurring expenses, batch expiry, and `TreasuryAlertRecipients`; no cited Fiscal command was found, and `forEachTenant` does not initialize tenancy in compatibility mode.

- **Task 4:** Changing aggregate/range audit ordering from ascending to descending is an undocumented API behavior change. Preserve existing order or call it out explicitly.

- **Task 9:** `method_exists($store, 'tags')` is correct for this Laravel version: `DatabaseStore` lacks tags; Redis and Array stores provide them. PHPUnit pins `CACHE_STORE=array`. The boot command checks capability, not Redis connectivity, so it cannot prove Redis is reachable.

- **Task 10:** In this Laravel version, registering `handleLazyLoadingViolationUsing()` does log instead of throwing; relation loading continues. No existing test was found that asserts the proposed exact `lazy-load` warning, although global `Log` spies/mocks increase regression risk.

- **Task 11:** The hook API is coherent and the module-not-found red test is valid. A key remains stable until `reset()`, matching the stated contract.

- **Task 13:** A `UniqueConstraintViolationException` escaping `DB::transaction()` is caught after Laravel rolls back. On PostgreSQL the subsequent reread occurs on a clean transaction state, so the proposed outer-catch placement is conceptually correct.

- **Task 14:** Serializing saves does not inherently defeat debounce. The defect is the proposed queue implementation and missing lifecycle guards, not the overall approach.

- Minor citation drift: Task 4’s controller method starts at line 38, Task 6’s query block begins before its cited range, and Task 7’s suggestion query begins at line 17 rather than 18.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | [TreasuryAlertRecipients.php:32](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:32) | Its permission flush becomes tenant-key scoped; should be included in the audit. |
| 2 | [ProductMovementsTab.tsx:108](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/components/ProductMovementsTab.tsx:108) | Already sends page parameters and should remain compatible. Numerous backend tests call the endpoint without `page`. |
| 3 | [PaymentListPage.tsx:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentListPage.tsx:66) | Silent first-page truncation unless promoted from “verify” to required work. |
| 3 | [PartnerDetailPage.tsx:197](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/partners/PartnerDetailPage.tsx:197) | Already paginated; expected to remain compatible. No POS/mobile GET consumer was found. |
| 4 | [AuditTrailTest.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/AuditTrailTest.php:264), [ComplianceCrossTenantHardeningTest.php:310](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:310) | Existing API-contract consumers fail when payload is omitted by default. No web consumer was found. |
| 5 | [CreateStockTransferPage.tsx:935](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:935), replenishment capture, counting page | All product-search surfaces gain the debounce delay, including the live counting workflow. |
| 6 | [CreateCreditNotePage.tsx:628](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/credit-notes/pages/CreateCreditNotePage.tsx:628) | Credit-note pricing requests change along with document/PO entry. |
| 7 | [stock-transfers/api/queries.ts:37](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/api/queries.ts:37) | Existing mutation invalidation no longer reaches the new cache root. |
| 8 | [DashboardLayout.tsx:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47) | Reconnect behavior changes for every authenticated route. |
| 8 | [CompanySelector.tsx:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:45) | Another intentional global invalidator remains; the plan should distinguish reconnect from company-switch behavior. |
| 9 | [cache.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/cache.php:18) | Every web, worker, scheduler, and CLI process without `CACHE_STORE` now depends on Redis. |
| 10 | [AppServiceProvider.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:155) | Every non-production Eloquent access and every PHPUnit process receives the global handler/listener behavior. |
| 11 | — | No existing consumer; Tasks 12 and 13 are the initial consumers. |
| 12 | [SplitPaymentForm.test.tsx:147](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.test.tsx:147) | Existing exact request-body assertion fails. |
| 13 | [QuickStockAdjustmentModal.tsx:124](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:124) | Shares the adjustment mutation hook; changing hook-level success behavior can affect the modal. |
| 14 | [DocumentForm.tsx:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/DocumentForm.tsx:277) | Every document autosave, status indicator, manual save, and unmount flow uses the changed hook. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | Yes, with queue regression coverage | Permission-cache behavior is isolated and the event mechanism works in HTTP and queue tenancy lifecycles. |
| 2 | No | Inventory movement filters/counts become page-local and can be reported as missing movements. |
| 3 | No | Treasury payment history is silently capped without mandatory list pagination. |
| 4 | Conditional | Known document consumers use limits ≤100, but it edits `DocumentController` and currently breaks Compliance contracts. Coordinate with document lanes. |
| 5 | Conditional | It changes the shared product selector used by live counting, transfer, replenishment, and document flows. |
| 6 | No alongside the PO lane | It edits `DocumentLineEditor`, a shared document/PO component. The plan’s own WAIT gate contradicts the blanket safety claim. |
| 7 | No as written | Transfer availability can remain stale after mutations because invalidation uses the old cache root. |
| 8 | No | It changes reconnect recovery globally for every authenticated screen and can leave reference data stale. |
| 9 | Conditional | Safe only after confirming staging and worker environments explicitly use a reachable taggable Redis store. |
| 10 | Conditional | Production behavior is unchanged, but staging and the full PHP test process receive global lazy-load/logging behavior. |
| 11 | Yes | New isolated utility with no current consumers. |
| 12 | Yes after test correction | Treasury-only submission behavior; no named onboarding lane collision. |
| 13 | Conditional | No direct ProductController/counting edit, but stock transfer/adjustment manual flows require race and retry coverage first. |
| 14 | No | The sole consumer is the live shared `DocumentForm`; the proposed queue can save after unmount and race with three callers. |

## Things verified correct

- Task 1’s re-keying works for a pre-resolved registrar singleton and queue workers; tenancy events fire with `db_per_tenant=false`.
- The stock-movement and payment controller branches cited by Tasks 2 and 3 are current and genuinely unbounded.
- `method_exists(..., 'tags')` correctly distinguishes the configured stores in this installed Laravel version.
- The lazy-loading violation handler logs instead of throwing in this Laravel version.
- Payment, split-payment, transfer, and adjustment backend interfaces already accept idempotency keys.
- Catching the transfer unique violation outside `DB::transaction()` permits a clean post-rollback reread.
- Task 11’s shared key-hook design is valid.
- No migrations are proposed, so the staging migration self-guard rule is not implicated.