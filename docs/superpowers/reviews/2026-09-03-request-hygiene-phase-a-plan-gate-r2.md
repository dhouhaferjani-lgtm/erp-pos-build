# Plan gate r2 — request hygiene Phase A

## Verdict: REJECT

Revision 2 fixes four of the prior eleven blockers outright, but seven remain partially unresolved. Several tests can still pass for the wrong reason, two existing exact-key tests will fail, one existing audit contract assertion was missed, and Task 14 introduces a React StrictMode regression.

No files were modified.

## Blocking findings

1. **Task 1 — the “queue lifecycle” test never exercises queue tenancy.**  
   Evidence: [plan:138](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:138), [Dispatcher.php:93](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php:93), [QueueTenancyBootstrapper.php:62](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:62).

   The probe implements `ShouldQueue` but not `Queueable`, so `Bus::dispatchSync()` takes `dispatchNow()` and emits no `JobProcessing`/`JobProcessed` events. Tenancy is also initialized before dispatch, so the assertion only observes the already-rekeyed singleton.

   The plan must test central → tenant job processing → central restoration, preferably for two different consecutive tenants, using a real queued payload or explicit queue lifecycle events with a tenant-stamped payload.

2. **Task 2 — the changed query key breaks an existing tenant-scope test.**  
   Evidence: [plan:474](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:474), [inventory tenantScope.test.tsx:248](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:248), [tenantScope.test.tsx:273](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:273).

   The existing assertion expects `['stock-movements', '', 'all', {locScope:'all'}]`; revision 2 inserts `page` and `perPage`. The plan neither modifies nor runs that test.

   The plan must list and update this test to expect the new page-aware key, and include it in verification.

3. **Task 3 — payment pagination breaks an existing exact-key test and an all-payments helper.**  
   Evidence: [plan:579](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:579), [TreasuryTenantScope.test.tsx:181](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:181), [w5c-support.ts:209](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w5c-support.ts:209).

   `TreasuryTenantScope` expects `['payments', '', tenant, company]`, while the new key includes page/per-page. `allPaymentIds()` still calls `/payments` once and assumes it received the complete collection; after the cap it silently sees at most 25.

   The plan must update/run the exact-key test and make whole-collection helpers iterate pages or explicitly request an adequate bounded page size.

4. **Task 4 — not all payload contracts were updated, and negative legacy limits remain unsafe.**  
   Evidence: [plan:783](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:783), [plan:819](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:819), [ComplianceCrossTenantHardeningTest.php:265](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:265), [ComplianceCrossTenantHardeningTest.php:275](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:275), [DocumentController.php:104](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:104).

   Revision 2 updates the AuditTrail and same-tenant URLs but misses the aggregate-branch test, which still requests the default response and asserts `payload`. It will fail after payload omission.

   Also, `min((int) $limit, 100)` permits negative limits. SQLite treats negative limits as effectively unbounded, while PostgreSQL can reject them.

   The plan must append `include=payload` to the aggregate test and validate/clamp legacy limits to `1..100`, with negative/zero coverage.

5. **Task 7 — the feature-level request-count assertion counts unrelated variant traffic.**  
   Evidence: [plan:1074](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1074), [CreateStockTransferPage.availability.test.tsx:11](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx:11), [CreateStockTransferPage.tsx:461](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:461), [useProductVariants.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/catalog/hooks/useProductVariants.ts:10).

   Selecting a product triggers both stock-level and variant `apiGet` traffic. After deduplication, the test sees two calls, not one; before it sees three.

   The plan must count only `/products/{id}/stock-levels` calls or mock `useProductVariants`. The browser assertion must likewise say “two stock-level requests,” not “two total requests.”

6. **Task 9 — the tests do not prove the configured default changed.**  
   Evidence: [plan:1203](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1203), [plan:1255](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1255), [cache.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/cache.php:18), [phpunit.xml:43](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/phpunit.xml:43).

   Both proposed tests explicitly set `cache.default`; they pass even if the fallback remains `database`. PHPUnit itself pins `CACHE_STORE=array`, so ordinary tests cannot detect omission of Step 3.

   The plan must add an isolated check with `CACHE_STORE` absent that asserts the fallback resolves to `redis`, or an equivalent executable configuration test.

7. **Task 10 — the “flat query count” test deliberately hides the per-row document query.**  
   Evidence: [plan:1345](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1345), [plan:1353](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1353), [StockMovementController.php:98](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:98), [StockMovementController.php:139](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:139).

   Only one of fifty rows is document-linked. Therefore the large page adds only one query and satisfies `large <= small + 4`, even though production performs one `Document` query for every linked movement.

   The plan must either seed linked documents proportionally at both page sizes and fix the controller with a bulk lookup, or stop calling this a flat-query guard and explicitly record the known N+1.

   Additionally, the global non-production handler affects every PHPUnit process, while verification runs only focused suites. Existing exact log expectations such as [InventoryGlPostingSeamTest.php:513](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryGlPostingSeamTest.php:513) require a full backend-suite gate.

8. **Task 12 — the ref-lock tests can pass because React disables the button, and PaymentForm’s stated pending behavior is not present.**  
   Evidence: [plan:1488](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1488), [plan:1524](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1524), [plan:1591](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1591), [PaymentForm.tsx:1353](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentForm.tsx:1353), [SplitPaymentForm.tsx:308](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:308).

   Separate `fireEvent.click()` calls are individually wrapped in `act`; the first may rerender the button disabled before the second. This is especially definite for SplitPaymentForm’s `isPending` button. The test therefore does not isolate the synchronous ref lock.

   The plan also says PaymentForm “remains `isSubmitting || createMutation.isPending`,” but the actual button uses only `isSubmitting`, contrary to the synthesis requirement.

   The plan must fire both submissions inside one `act`/pre-rerender boundary or submit the form twice directly, and explicitly change PaymentForm’s disabled/loading condition to include `createMutation.isPending`.

9. **Task 14 — the proposed unmount guard disables autosave under React StrictMode.**  
   Evidence: [plan:2060](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2060), [plan:2131](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2131), [main.tsx:19](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/main.tsx:19), [useDraftAutoSave.ts:266](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useDraftAutoSave.ts:266).

   StrictMode replays effect setup/cleanup in development. Cleanup sets `isUnmountedRef.current = true`, but setup never restores it to false. The proposed entry guard then makes every subsequent save a no-op.

   The plan must set the ref false in effect setup and add a `<StrictMode>` regression test. Its failure-recovery test must also hold the first request pending and assert the second has not started; as written, that test already passes with the current concurrent implementation.

## Non-blocking findings

- Task 2 continues sending `search`, but the proposed controller does not validate or apply it. The search control remains misleading. Evidence: [plan:477](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:477), [StockMovementController.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:38).
- Several existing PaymentList tests supply only `meta.total`; after pagination they render undefined pager values. Update their fixtures to the six-field meta shape. Evidence: [PaymentListPage.test.tsx:53](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentListPage.test.tsx:53).
- Task 10’s query-log helper restores the enabled flag correctly, but destroys any pre-existing query-log contents. Document that it must not be nested.
- Task 13’s backend seam, constructor dependencies, DTO, constraint names, and outer catch are correct. However, the adjustment test has no `vi.clearAllMocks()` despite the plan claiming it does; reset the new spy explicitly in `beforeEach`. Evidence: [CreateStockAdjustmentPage.test.tsx:83](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx:83), [plan:1958](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1958).
- Task 9’s capability check remains insufficient to prove Redis connectivity; its promotion condition is therefore necessary, not optional.

## Blast-radius table

| Task | Unlisted or under-accounted consumer path:line | Consequence |
|---|---|---|
| 1 | [GenerateRecurringExpensesCommand.php:186](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:186), [BatchExpiryDailyCheckCommand.php:208](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:208), [TreasuryAlertRecipients.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:23) | Compatibility-mode commands remain on the central cache key; real queue restoration is untested. |
| 2 | [ProductMovementsTab.tsx:95](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/components/ProductMovementsTab.tsx:95), [w4-support.ts:651](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w4-support.ts:651) | Product tab is compatible; generic callers without paging now see only 25. |
| 3 | [PartnerDetailPage.tsx:197](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/partners/PartnerDetailPage.tsx:197), [w5c-support.ts:209](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w5c-support.ts:209) | Partner detail is compatible; complete-set helpers silently truncate. External/mobile clients are not present in this checkout and remain unverified. |
| 4 | [Dashboard.tsx:137](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/dashboard/Dashboard.tsx:137), [ComplianceCrossTenantHardeningTest.php:225](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:225) | Dashboard’s five-document request remains compatible; audit aggregate contract currently breaks. |
| 5 | [DocumentLineEditor.tsx:1168](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/DocumentLineEditor.tsx:1168), [CreateCountingPage.tsx:407](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:407), [ReplenishmentCapturePage.tsx:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/replenishment/pages/ReplenishmentCapturePage.tsx:97) | Every shared product-entry surface gains a 250 ms delay. |
| 6 | [DocumentForm.tsx:697](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:697), [CreateCreditNotePage.tsx:628](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/CreateCreditNotePage.tsx:628) | Pricing behavior changes across all document types and customer credit notes. |
| 7 | [useProductVariants.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/catalog/hooks/useProductVariants.ts:10), [stock-transfer queries.ts:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/api/queries.ts:31) | Variant traffic invalidates the proposed request-count test; root invalidation itself remains compatible. |
| 8 | [DashboardLayout.tsx:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47), [CompanySelector.tsx:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:41) | Every authenticated route receives cooldown behavior; company switching remains separately global. |
| 9 | [cache.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/cache.php:18) | Any web, worker, scheduler, or CLI environment lacking `CACHE_STORE` now depends on Redis. |
| 10 | [AppServiceProvider.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:155) | Every non-production Eloquent model and every PHP test process receives the global warning handler. |
| 11 | — | No pre-existing consumer; Tasks 12 and 13 are the first. |
| 12 | [SplitPaymentModal.tsx:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:120), [RecordPaymentModal.tsx:313](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:313) | Deprecated split modal inherits the change. The active RecordPaymentModal still posts `/payments` without a key and remains an uncovered duplicate-payment surface. |
| 13 | [ReplenishmentFulfillmentService.php:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:75), [QuickStockAdjustmentModal.tsx:124](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:124) | Replenishment also reaches the refactored transfer service; the quick-adjustment modal remains keyless by explicit scope choice. |
| 14 | [DocumentForm.tsx:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:277), [routes/index.tsx:650](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:650) | Quotes, orders, invoices, purchase orders, credit notes, and delivery notes all use the changed autosave lifecycle. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | Conditional | Mechanism is sound, but a real worker lifecycle regression is still missing. |
| 2 | No as written | Existing tenant-scope test fails; unpaged clients now report missing older movements. |
| 3 | No as written | Existing treasury test fails and whole-history helpers silently truncate. |
| 4 | Conditional | Edits `DocumentController` during live document work and currently breaks an audit contract. |
| 5 | Conditional | Changes the shared selector used by live counting and document workflows. |
| 6 | No until WAIT clears | Directly edits the shared document/PO line editor. |
| 7 | Conditional | Production design is sound, but it edits the transfer page and its proof is invalid. |
| 8 | Conditional | Global authenticated-layout behavior; repeat reconnects intentionally defer recovery for 30 seconds. |
| 9 | Conditional | Only after web, worker, scheduler, and CLI environments prove reachable Redis configuration. |
| 10 | Conditional | Production default is unchanged, but staging and the entire PHP test process gain global logging behavior. |
| 11 | Yes | Isolated new hook with no existing consumer. |
| 12 | No as written | P0 money submission; the lock is not proven and the PaymentForm button contract is misstated. |
| 13 | Conditional | Backend race recovery is sound, but transfer/adjustment forms are live operator workflows. |
| 14 | No | The proposed lifecycle guard disables autosave in StrictMode and affects every document form. |

## Things verified correct

Prior r1 blocker disposition:

| R1 finding | Result |
|---|---|
| 1 — Task 2 | Partially resolved; harness/filter design fixed, exact-key regression missed. |
| 2 — Task 3 | Partially resolved; list/dashboard fixed, exact-key and complete-set consumers missed. |
| 3 — Task 4 | Not resolved; one payload assertion remains unchanged. |
| 4 — Task 5 | Resolved. |
| 5 — Task 6 | Resolved, subject to its WAIT gate. |
| 6 — Task 7 | Partially resolved; cache root fixed, feature proof invalid. |
| 7 — Task 8 | Resolved. |
| 8 — Task 10 | Partially resolved; listener leak and lazy-load red test fixed, flat-query proof remains false. |
| 9 — Task 12 | Partially resolved; real harness/body fixed, synchronous-lock proof and button contract remain wrong. |
| 10 — Task 13 | Resolved; only minor test-spy cleanup remains. |
| 11 — Task 14 | Promise-tail mechanics resolved, but StrictMode creates a new blocking lifecycle defect. |

Also verified:

- `PermissionRegistrar::initializeCache()` rereads the configured key/store for a pre-resolved singleton.
- `tenancy()->initialize()` fires `TenancyInitialized` when `db_per_tenant=false`.
- Stancl queue processing initializes tenant context from `tenant_id` and restores it afterward.
- Task 2’s enum names, imports, server aliases, pager component, and existing `en/fr/ar` pagination keys are correct.
- Task 3’s controller already sorts newest payment dates first; removing the ignored dashboard sort is correct.
- Task 4’s `CarbonInterface`, paginator import, date guards, and ascending aggregate/range ordering are correct.
- Tasks 5 and 6 use valid harnesses, imports, response shapes, raw MoneyInput value, and genuinely distinct committed changes.
- Task 7’s `stock-levels` root remains reachable by existing transfer invalidators.
- `method_exists($store, 'tags')` correctly distinguishes DatabaseStore from RedisStore/ArrayStore in this installed Laravel version.
- Laravel’s lazy-load handler logs and then continues relation loading rather than throwing.
- Task 13’s unique violation escapes only after transaction rollback; the reread occurs on a clean PostgreSQL transaction state.
- Task 14’s promise tail serializes callers and preserves debounce outside the StrictMode defect.
- No migration is proposed, so the migration self-guard rule is not implicated.