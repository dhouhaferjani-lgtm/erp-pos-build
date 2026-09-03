# Plan gate r3 — request hygiene Phase A

## Verdict: REJECT

Revision 3 genuinely resolves eight of r2’s nine blocking findings. The Task 1 lifecycle finding remains unresolved, and fresh code-level defects in Tasks 2 and 13 make the plan unsafe to execute as written.

No files were modified.

## Blocking findings

1. **Task 1 — compatibility-mode requests and queued jobs still do not establish the tenant cache context.**

   Evidence: [plan:66](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:66), [plan:144](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:144), [TenancyResolver.php:81](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:81), [TenancyServiceProvider.php:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/TenancyServiceProvider.php:49), [QueueTenancyBootstrapper.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:45), [Stancl TenancyServiceProvider.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/TenancyServiceProvider.php:48).

   In the live `db_per_tenant=false` path, `TenancyResolver::initializeIfProvisioned()` returns `false` before calling `tenancy()->initialize()`. Therefore ordinary compatibility-mode requests never emit `TenancyInitialized`, and the proposed permission listener never rekeys the registrar.

   The queue test also cannot pass after the proposed implementation. Stancl registers job-processing listeners statically, but installs `Queue::createPayloadUsing()` only when `QueueTenancyBootstrapper` is instantiated. The application resolves that bootstrapper only inside the `db_per_tenant=true` listener. Consequently the payload created at plan lines 150–156 lacks `tenant_id`.

   Directly calling `tenancy()->initialize()` in the test does emit `TenancyInitialized`, but that proves an artificial path which live compatibility-mode requests do not take.

   **The plan must say instead:** define an explicit tenant-context lifecycle for single-schema requests, independent of database switching, and test it through the real resolver/middleware. Ensure `QueueTenancyBootstrapper` is instantiated at application boot—or install an equivalent payload hook—regardless of `db_per_tenant`, then prove a real queued payload contains `tenant_id` and that consecutive worker jobs restore central context.

2. **Task 2 — the proposed literal wildcard search fails under the actual SQLite test harness.**

   Evidence: [plan:398](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:398), [plan:459](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:459).

   `addcslashes($search, '\\%_')` produces `\%`, but SQLite does not treat backslash as a default `LIKE` escape character. The proposed request searching for literal `%` therefore returns zero rows after the change instead of one.

   **The plan must say instead:** use a chosen escape character and an explicit portable `ESCAPE` clause in bound raw predicates, with equivalent product-name/SKU subqueries. Keep the literal `%`, literal `_`, normal search, and 121-character rejection tests.

3. **Task 2 — its frontend fixture rewrite breaks an existing test.**

   Evidence: [plan:545](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:545), [StockMovementsPage.test.tsx:74](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/StockMovementsPage.test.tsx:74), [StockMovementsPage.test.tsx:114](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/StockMovementsPage.test.tsx:114).

   The plan replaces the two-row Alpha/Beta fixture with one Alpha row, but the existing test still requires both. The named focused suite fails after implementation.

   **The plan must say instead:** retain both movements and add the six-field pagination metadata, or explicitly rewrite the row-count assertion with a justified new fixture.

4. **Tasks 2 and 3 — request validation and ordering do not meet the stated repository contract.**

   Evidence: [plan:434](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:434), [plan:490](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:490), [StockMovementController.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:34), [PaymentController.php:259](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:259), [PaymentController.php:295](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:295).

   Task 2 adds more inline controller validation but leaves `product_id`, `page`, and `per_page` outside validation. Task 3 retains unvalidated partner, status, search, page, and per-page inputs. This contradicts the supplied FormRequest rule.

   Both paginators also lack deterministic tie-breaking:

   - Task 2 orders only by `created_at`.
   - Task 3 orders only by `payment_date`, a date-level field on which many rows tie.

   This makes page traversal nondeterministic; `allPaymentIds()` can duplicate or omit tied rows across requests. Task 2 also introduces an explicit `mixed` callback at plan line 499 despite its global “avoid mixed” constraint.

   **The plan must say instead:** add dedicated list FormRequests covering every accepted parameter, consume only validated values, avoid the explicit `mixed` annotation, and order by the business timestamp/date plus `id DESC`. Add a page-boundary regression with tied primary sort values.

5. **Task 4 — the new validation message is hardcoded and lacks an i18n key.**

   Evidence: [plan:848](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:848), [plan:859](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:859).

   The literal `"Date range may not exceed 92 days."` is user-facing validation output. No translation key is proposed.

   **The plan must say instead:** add the message to the supported backend validation locales and use a translated message with the maximum span as a parameter.

6. **Task 13 — the principal race regression passes before the implementation.**

   Evidence: [plan:2106](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2106), [StockTransferService.php:103](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:103).

   The test persists `$winner` using the same `race-key` before calling the colliding service. Current production code performs its own initial database lookup, finds that winner, and returns it without invoking the proposed `findExistingTransfer()` or `insertTransfer()` seams. The assertion therefore already succeeds for the wrong reason.

   The outer catch itself is valid: Laravel rolls back the transaction/savepoint before rethrowing the unique violation, so the reread is reachable on a usable connection. What is missing is an executable proof that this path ran.

   **The plan must say instead:** instrument the colliding subclass and assert that the insert seam was invoked once, the lookup was invoked before and after the collision, and the second lookup occurred after the inner transaction/savepoint had rolled back. The test must fail against the current direct-precheck implementation.

## Non-blocking findings

- **R2 disposition:** r2 finding 1 remains open. Findings 2–9 are genuinely addressed in revision 3, rather than merely acknowledged: exact frontend keys, payment page iteration, all audit payload contracts and negative limits, URL-filtered Task 7 counts, isolated cache fallback proof, proportional linked-document query proof plus CI suite gate, all three payment surfaces, and StrictMode/failure-recovery autosave coverage are present.

- Task 2’s claim that both exact stock-movement keys are at current lines 248 and 273 is stale; the material page-aware key assertion is present at [tenantScope.test.tsx:273](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:273).

- The stock-adjustment tenant-scope fixture still seeds an old-shaped stock-movement key at [queries.test.tsx:64](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx:64). It remains green because prefix invalidation still matches, but it no longer models the real key.

- Task 2’s W4 helper requests only page 1 at 100 rows. The “below 100” premise is not executable. Assert `meta.last_page === 1` or make it a page iterator.

- Task 3 should remove the obsolete no-page explanation at [expenses-lifecycle.spec.ts:245](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/expenses-lifecycle.spec.ts:245).

- Task 7’s shared hook relies on its host page to subscribe to tenant/company changes. Either document that precondition explicitly or make the hook obtain and gate its own tenant/company context.

- Task 9’s capability command proves that the selected store supports tags, not that Redis is reachable. Its environment-by-environment promotion condition is mandatory.

- Task 10 affects tests with exact log expectations, including [InventoryGlPostingSeamTest.php:513](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryGlPostingSeamTest.php:513), [TreasuryMovementServiceRecordTest.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php:252), and [RefundReportingFieldsTest.php:134](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/POS/RefundReportingFieldsTest.php:134). The CI-only full backend gate is therefore necessary.

- Task 14’s promise-tail implementation preserves the existing debounce, but an explicit fake-timer test proving rapid data changes still coalesce into one request would protect that contract.

- External POS/mobile consumers are not present in this checkout. Their behavior against newly capped endpoints cannot be verified from this repository.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | [GenerateRecurringExpensesCommand.php:186](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:186); [TreasuryAlertRecipients.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:23) | Compatibility-mode commands can continue using the central permission cache. |
| 2 | [ProductMovementsTab.tsx:95](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/components/ProductMovementsTab.tsx:95); [w4-support.ts:651](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w4-support.ts:651) | Product tab is compatible; W4 silently truncates above 100. |
| 3 | [PartnerDetailPage.tsx:197](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/partners/PartnerDetailPage.tsx:197); [w8-isolation.spec.ts:71](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w8-isolation.spec.ts:71) | Existing bounded callers remain compatible; ordering must be stable for page iteration. |
| 4 | [Dashboard.tsx:137](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/dashboard/Dashboard.tsx:137) | Five-document dashboard request remains compatible. |
| 5 | [DocumentLineEditor.tsx:1168](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/components/DocumentLineEditor.tsx:1168); [CreateCountingPage.tsx:407](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:407); [CreateStockTransferPage.tsx:935](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:935); [ReplenishmentCapturePage.tsx:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/replenishment/pages/ReplenishmentCapturePage.tsx:97) | Every shared product-entry surface gains the 250 ms delay. |
| 6 | [DocumentForm.tsx:697](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:697); [CreateCreditNotePage.tsx:628](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/CreateCreditNotePage.tsx:628) | Pricing behavior changes across document and credit-note flows. |
| 7 | [useProductVariants.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/catalog/hooks/useProductVariants.ts:10); [stock-transfer queries.ts:31](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-transfers/api/queries.ts:31) | Variant traffic is separate; existing stock-level prefix invalidation remains compatible. |
| 8 | [DashboardLayout.tsx:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47); [CompanySelector.tsx:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:41) | Every authenticated route receives cooldown behavior; company switching still invalidates globally. |
| 9 | [cache.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/cache.php:18) | Any web, worker, scheduler, or CLI process without `CACHE_STORE` now requires Redis. |
| 10 | [AppServiceProvider.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:155) | All non-production models and PHP tests receive the global violation logger. |
| 11 | None found | New hook has no pre-existing consumer. |
| 12 | [InvoiceDetailPage.tsx:895](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895); [SalesOrderDetailPage.tsx:780](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780); [PurchaseOrderDetailPage.tsx:709](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709) | Active record-payment modal behavior changes on three document detail surfaces. |
| 13 | [ReplenishmentFulfillmentService.php:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:75); [QuickStockAdjustmentModal.tsx:179](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:179) | Replenishment enters the refactored transaction; quick adjustment intentionally remains keyless. |
| 14 | [DocumentForm.tsx:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:277) | Every document type using the shared form receives the serialized autosave lifecycle. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | **No** | Live single-schema requests remain on the central permission key; queue payload test cannot pass. |
| 2 | **No as written** | Literal wildcard search and the focused frontend suite break; capped W4 coverage is assumption-based. |
| 3 | **Conditional** | No protected onboarding file, but nondeterministic pagination can appear as missing/duplicated payments. |
| 4 | **Conditional** | Directly edits `DocumentController` during live document work; coordinate ownership and localize the new error. |
| 5 | **Conditional** | Changes shared entry behavior in live document and counting workflows. |
| 6 | **No until WAIT clears** | Directly edits the shared document/PO line editor. |
| 7 | **Conditional** | Does not touch `ProductController` or counting, but changes stock-transfer availability behavior. |
| 8 | **Conditional** | Global authenticated-layout behavior; repeat reconnects intentionally defer recovery for 30 seconds. |
| 9 | **Conditional** | Safe only after all web/worker/scheduler/CLI roles prove reachable Redis configuration. |
| 10 | **Conditional** | Production remains unchanged, but staging and all PHP tests gain global warning logging. |
| 11 | **Yes** | Isolated new hook with no existing consumer. |
| 12 | **Conditional** | Implementation and same-act tests are sound, but this is a high-impact money-submission path. |
| 13 | **No as written** | Transaction design is sound, but the race proof is false-positive and the service is used by replenishment. |
| 14 | **Conditional** | Implementation is sound, but it changes autosave behavior on every live document form. |

## Things verified correct

- `PermissionRegistrar::initializeCache()` does re-read configuration and rekey a registrar singleton that was resolved before a genuine tenancy initialization.
- Stancl’s job-processing listener initializes from `tenant_id` and restores prior/central state; the unresolved problem is payload-hook installation and the missing compatibility-mode event.
- Task 2’s enum names, imports, filter aliases, bulk document lookup, pagination response shape, and existing pagination locale keys are correct.
- Task 3 genuinely fixes r2’s list fixtures, exact key, dashboard request, and `allPaymentIds()` page iteration.
- Task 4 now updates all known payload-reading tests, preserves ascending aggregate/range ordering, and covers `0`, negative, and oversized legacy limits.
- Tasks 5 and 6 use valid harnesses and preserve distinct committed-change behavior; Task 6’s WAIT gate is explicit.
- Task 7 now counts only stock-level URLs, and its cache root remains compatible with existing transfer invalidators.
- Task 8’s tested 30-second cooldown is coherent.
- In this Laravel version, `method_exists($store, 'tags')` correctly distinguishes `DatabaseStore` from Redis/array taggable stores. PHPUnit pins `CACHE_STORE=array`, and Task 9’s isolated fallback check genuinely detects the config-default change.
- Laravel’s lazy-loading violation handler logs and then permits relation loading instead of throwing. Task 10’s proportional linked-document fixture now detects the current N+1.
- Task 11’s stable UUID-ref hook is valid.
- Task 12 applies the key/ref-lock contract to PaymentForm, SplitPaymentForm, and the active RecordPaymentModal; its same-act submissions genuinely test the synchronous lock.
- A unique violation thrown inside `DB::transaction()` reaches Task 13’s outer catch only after rollback; PostgreSQL’s aborted transaction/savepoint state is cleared before the reread.
- Task 14’s promise tail serializes saves without removing the debounce, recovers after rejection, resets correctly under StrictMode replay, and prevents queued/unmounted work from mutating state.
- No migration is proposed, so the staging self-guard requirement is not implicated.