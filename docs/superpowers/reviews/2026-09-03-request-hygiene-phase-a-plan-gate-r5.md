# Plan gate r5 — request hygiene Phase A

## Verdict: REJECT

Revision 5 substantively resolves most r4 findings, but it remains non-executable as written. Three blocking defects remain. No files were modified.

## Blocking findings

1. **Task 2 — the prescribed frontend fixture is invalid TypeScript.**

   Evidence: [plan:756](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:756), [StockMovementsPage.test.tsx:74](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory/StockMovementsPage.test.tsx:74).

   The replacement contains two initializers:

   ```ts
   const mockReturn: { ... } = {
   } = {
   ```

   It will not parse, so the proposed pagination test cannot reach either its red or green state.

   **The plan must say instead:** provide one complete declaration with a single `= {`, retaining the two movement rows and six-field `meta` fixture.

2. **Task 4 — `sometimes` defeats the promised paired-filter validation.**

   Evidence: [plan:1161](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1161), [plan:1190](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1190), [Laravel Validator.php:823](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Validation/Validator.php:823), [Laravel Validator.php:865](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Validation/Validator.php:865), [AuditController.php:48](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:48).

   Laravel’s optional check skips every rule—including implicit `required_with`—when an attribute has `sometimes` and is absent. Consequently:

   - `aggregate_type` without `aggregate_id` is accepted.
   - `aggregate_id` without `aggregate_type` is accepted.
   - `from` without `to`, and vice versa, are accepted.
   - The controller silently falls back to a broader company/event-type listing instead of returning 422.

   None of the proposed tests covers a missing half of either pair.

   **The plan must say instead:** remove `sometimes` from these four paired fields, placing `required_with` first, for example:

   ```php
   'aggregate_type' => ['required_with:aggregate_id', 'string', 'max:120'],
   'aggregate_id' => ['required_with:aggregate_type', 'uuid'],
   'from' => ['required_with:to', 'date_format:Y-m-d'],
   'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
   ```

   Add four red tests—one for each missing counterpart—asserting `error.code=VALIDATION_ERROR` and the relevant `error.errors.*` path.

3. **Task 12 — the r4 monetary fix still lets a JavaScript number touch the required payment amount.**

   Evidence: [CLAUDE.md:71](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:71), [SplitPaymentForm.tsx:40](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:40), [SplitPaymentModal.tsx:40](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:40), [plan:2488](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2488), [plan:2577](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2577).

   The internal sum/subtraction is correctly converted to `bcadd`/`bcsub`/`bccomp`, but `totalAmount` remains `number` and is converted with `String(totalAmount)`. Stringification cannot recover decimal precision already lost at the numeric boundary. The fractional tests all pass small numeric literals, so they do not expose this defect. The verification grep only rejects `parseFloat` and `Math.abs`, not the `number` money prop.

   This means r4 blocker B5 is only partially resolved.

   **The plan must say instead:** make `totalAmount` a decimal string in `SplitPaymentFormProps` and `SplitPaymentModalProps`, update the deprecated wrapper and all tests/callers, and pass that string directly to bcmath helpers. Add a precision regression using a value that would be altered by IEEE-754 conversion, and forbid numeric money props in the changed path.

## Non-blocking findings

- Task 10 says the newest five movements contain “exactly three” linked rows, but it does not force distinct `created_at` values. On a driver that stores tied timestamps, UUID ordering can select another mix. The proportional assertion remains genuinely red before Task 2 and green afterward because the 50-row page contains 30 linked rows, but the wording should be corrected or timestamps forced. Evidence: [plan:2150](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2150), [StockAdjustmentService.php:2028](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:2028).
- The active `RecordPaymentModal` retains pre-existing float-based totals and validation. Task 12 does not introduce them, but its verification is narrower than the repository-wide precision rule. Track separately. Evidence: [RecordPaymentModal.tsx:210](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:210).
- Task 7 remains only partial S-6 closure: one request per distinct product/variant remains. Revision 5 now describes this honestly.
- Task 9’s command proves tag capability, not Redis connectivity. The independent web/worker/scheduler/CLI write-read-delete promotion probes remain mandatory.
- Task 10’s global lazy-load warning can affect additional log spies. The mandatory CI full-suite gate is therefore necessary.
- The Task 1 staging topology and Tasks 2/3 external POS/mobile contracts remain unverifiable from this checkout. Revision 5 correctly turns both into binding promotion conditions rather than repository facts.

## Blast-radius table

| Task | Unlisted consumer path:line | Consequence |
|---|---|---|
| 1 | None additional found | QueueTenancyBootstrapper and all three known permission-cache flush callers are now covered by the plan. |
| 2 | [InventoryTenantIsolationTest.php:713](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php:713), [InventoryTenantIsolationTest.php:811](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php:811) | Calls without `page` now receive page one and metadata. Current fixtures are below 25, but these paths should join focused verification. |
| 3 | [TreasuryCompanyIsolationTest.php:415](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:415) | Three search requests inherit mandatory pagination; fixtures remain below the cap. |
| 4 | [onboarding.campaign.ts:293](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/campaign/onboarding.campaign.ts:293), [Dashboard.tsx:140](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/dashboard/Dashboard.tsx:140) | Live onboarding uses `limit=100`; Dashboard uses `limit=5`. Both remain within the cap but exercise the edited DocumentController branch. |
| 5 | [CreateCountingPage.tsx:407](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:407) | Counting product entry gains a visible 250 ms search delay. |
| 6 | None additional found | `DocumentForm` and `CreateCreditNotePage` are the production consumers and are named. |
| 7 | [GoodsReceiptListPage.tsx:282](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/GoodsReceiptListPage.tsx:282) | Its namespace invalidation also matches the new shared stock-level query. |
| 8 | None additional found | `DashboardLayout` is the sole provider host; CompanySelector’s separate invalidation is named. |
| 9 | None additional found | All runtime entrypoints and the infrastructure-free types-drift job are now included. |
| 10 | [ReceiptReturnServiceTest.php:292](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:292) | This and other exact log spies can observe the new global warning; CI full-suite coverage is required. |
| 11 | None | New hook has no pre-existing consumer. |
| 12 | [InvoiceDetailPage.tsx:895](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895), [SalesOrderDetailPage.tsx:780](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780), [PurchaseOrderDetailPage.tsx:709](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709) | All active record-payment hosts inherit the key lifecycle and synchronous lock. |
| 13 | None additional in production | Replenishment’s call at line 102 and QuickStockAdjustmentModal are now explicitly handled. Numerous service tests also exercise `initiate()`. |
| 14 | None additional found | [DocumentForm.tsx:277](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/DocumentForm.tsx:277) remains the sole consumer. |

## Onboarding-safety table

| Task | Safe? | Why |
|---|---|---|
| 1 | Conditional yes | Safe only after database-per-tenant topology, migrated tenant databases, queue drain, and worker lifecycle are proven. |
| 2 | No as written | Runtime design is sound, but the exact test fixture does not compile; external capped-client evidence also remains mandatory. |
| 3 | Conditional yes | No protected file, but mandatory pagination requires external client contract evidence. |
| 4 | No as written | Edits live `DocumentController`, and half-specified audit filters silently broaden reads instead of failing validation. |
| 5 | Conditional | Shared across documents, transfers, replenishment, and live counting; the 250 ms delay is observable. |
| 6 | No until WAIT clears | Directly edits the shared document-line editor. The post-PO-merge rebase remains mandatory. |
| 7 | Conditional | Edits the live transfer page; behavior is correctly partial and must be browser-checked. |
| 8 | Conditional | Global authenticated-layout behavior; a second reconnect inside 30 seconds intentionally suppresses recovery. |
| 9 | Conditional | Fail-closed entrypoints are correct but can stop every process role if deployment configuration is wrong. |
| 10 | Conditional | Production behavior is unchanged, but staging/tests gain global warning logging. |
| 11 | Yes | Isolated hook with no existing consumer. |
| 12 | No as written | High-impact payment surfaces still accept the required amount through a forbidden numeric money boundary. |
| 13 | Conditional | Core transfer and replenishment transaction behavior changes; PostgreSQL collision design itself is sound. |
| 14 | Conditional yes | Serialization preserves debounce and unmount/reset cancellation, but it changes the live document form and must rebase after the PO lane. |

## Things verified correct (brief)

- **R4 B1:** Task 2 now computes and asserts the exact `created_at DESC, id DESC` sequence.
- **R4 B2/B3:** Task 4 now uses `error.errors.*`, asserts `VALIDATION_ERROR`, seeds 51 events, traverses page two, and rejects `per_page=101`.
- **R4 B4:** Task 9 installs permanent validation in web, worker, and scheduler entrypoints through a shared helper.
- **R4 B5:** Split-payment arithmetic and fractional tests are corrected, but the numeric `totalAmount` boundary remains blocking.
- **R4 non-blockers:** Task 13 now cites line 102, uses correct pre-seam wording, and includes a nested savepoint case; Task 7 is labeled partial; types-drift pins `array`; Task 14 swallows predecessor-tail rejection; the staging-count claim was removed; external-owner evidence remains binding.
- Task 1 correctly rekeys a pre-resolved `PermissionRegistrar`: `initializeCache()` rereads configuration. Stancl queue initialization emits the tenancy lifecycle and restores central context afterward.
- Task 2’s FormRequest, aliases, portable `ESCAPE '!'` search, stable backend ordering, and page-level document lookup are coherent.
- Task 3’s request signature, six-field metadata, UI keys, Dashboard request, exact ordering proof, and complete E2E page iterator are coherent.
- Task 4’s localized en/fr/ar max-span message, payload opt-in, ordering, default-50 cap, and document-limit clamp are otherwise correct.
- Tasks 5 and 6 use the existing debounce hook and real component/test harnesses; their tests are genuinely red before implementation.
- Task 7’s shared query is tenant/company-reactive, retains the `stock-levels` root, and remains compatible with prefix invalidations.
- Task 8’s epoch-zero-safe sentinel and 30-second cooldown are correct.
- In this Laravel version, `method_exists($store, 'tags')` distinguishes `DatabaseStore` from `RedisStore` and `ArrayStore`.
- Laravel’s lazy-loading callback logs and returns; it does not throw. The relation continues loading.
- Task 13’s PostgreSQL unique violation exits the inner transaction before the outer catch. The top-level reread occurs at transaction level 0; the nested case rereads on a usable level-1 outer transaction after savepoint rollback.
- Task 14 serializes callers without changing the debounce window. Reset/unmount generations suppress queued network work and prevent an in-flight response from repopulating state; a throwing `onError` no longer poisons later jobs.
- No migration is proposed, so the migration self-guard rule is not implicated.