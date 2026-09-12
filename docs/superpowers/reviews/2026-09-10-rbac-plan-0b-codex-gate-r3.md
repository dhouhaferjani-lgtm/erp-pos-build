# Codex plan gate r3 — RBAC wave 0b plan rev 3 (gpt-5.6-sol, high, read-only, 2026-09-10)

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 3, against the accepted rev-9 specification and settled A-1 amendment. I did not review the wave-1 plan.

`git rev-parse --short HEAD`: **`692cede7a`**

Current lane tips:

- `dev`: `630afa86f`
- `lane/w-lot-a-1a`: `52f5ad796`
- `lane/t2-receipt-spine`: `951a7637e`

## Rev-1 closure table

| Round-1 finding | Rev-3 disposition |
|---|---|
| B0b-1 — catalogue contract incomplete | **CLOSED at rev-3 anchor.** The nineteen admin additions and seven manager/general-manager additions are explicit and correctly split. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:576` |
| B0b-2 — route ceilings inconsistent | **CLOSED at rev-3 anchor.** The settled A-1 figures are now 152/146/298 after 0a and 152/142/294 after 0b-15; the final reduction is 25 write and 20 read routes. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5108`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5294`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5383` |
| B0b-3 — writer census and lock proof incomplete | **NOT CLOSED.** The method census is now AST-bounded and finds the intended 20+3 writer methods, but the row-lock visitor turns 23 unrelated, unresolved lock chains into offenders, making the supplied test red. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3252`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3708` |
| B0b-4 — frontend enforcement incomplete | **NOT CLOSED.** The 22-row query/control census is much improved, but the e2e assertions require hidden navigation and in-page links that Task 6 explicitly leaves untouched. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4051`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4256`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4359` |
| B0b-5 — `AdminRoleMissing` did not fail closed | **CLOSED at rev-3 anchor.** Apply and dry-run both become `FAILED`, `reason=admin_role_missing`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2228`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2298` |
| B0b-6 — operational wrapper was non-atomic | **CLOSED at rev-3 anchor.** The two-invocation wrapper writes `pending`, atomically replaces the status file, exits after the first failure, and writes `ok` only after both invocations succeed. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5720` |
| M0b-1 — lane dependencies not enforced as entry conditions | **CLOSED at rev-3 anchor.** Both lanes are now whole-wave conditions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:43`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:232` |
| M0b-2 — tasks must be re-sequenced to obtain red tests | **REJECTED-correctly.** The no-knowingly-red discipline and per-task run lists are a sufficient sequencing rule; wholesale task reordering is unnecessary. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5899` |
| M0b-3 — red recipes were incomplete or non-compiling | **NOT CLOSED.** Task 5 and Task 13 still contain fixtures that cannot produce their stated red result; Task 6 still specifies behaviours rather than compilable test bodies. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3944`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4268`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4968` |
| M0b-4 — commit/staging instructions were not exact | **PARTIALLY CLOSED / NOT CLOSED.** All implementation subjects now use `Phase 0.2.<task>:`, but Tasks 6 and 8 still delegate the staging list to `git status`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4385`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4391`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4528` |
| M0b-5 — frontend reviewer/e2e gate missing | **CLOSED structurally, but its assertions are not implementable as written.** The reviewer precondition and named e2e file exist; see M3-1 and M3-2. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4022`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4349` |
| Round-1 citation defects for T2, W-LOT, user controllers, and seeder shapes | **CLOSED.** The current pins and post-lane bodies are recorded. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:232`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:271` |
| Cross-plan lock-inheritance proof | **CLOSED.** It is now list-valued, complete over helper entry points, and checked acquire-before-call. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3464`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3611` |

The rev-1→rev-2 change log is therefore not wholly verified by current code: its catalogue, lane, failure-state, wrapper, subject, and harness claims hold, but its census, frontend closure, red-test completeness, and exact-staging claims remain open.

## Rev-2 closure table

| Round-2 finding | Rev-3 disposition |
|---|---|
| B2-1 — W-LOT/T2 split entry condition unsound | **CLOSED at rev-3 anchor.** Both merges are mandatory before Task 1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:43`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:247` |
| B2-2 — row-order checker did not implement the settled rule | **NOT CLOSED.** The intended role chains now have the right ordering, but the supplied visitor reports 23 unresolved non-role locks as violations. It also does not inspect order direction, and a later equality can overwrite a preceding `whereIn` classification. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3286`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3313`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3330`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5834` |
| M2-1 — stale snapshot inside `grantMissing` | **CLOSED at rev-3 anchor.** The snapshot is captured once and passed into `grantMissing`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2238`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2395` |
| M2-2 — Task 4 production recipe incomplete | **CLOSED at rev-3 anchor.** Post-lane bodies and all production call sites are now supplied or constrained to an exact insertion. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2345`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2820` |
| M2-3 — frontend census omitted service-category/component/caller guards | **NOT CLOSED.** Query and component coverage is added, but navigation and `ServiceListPage` control exposure remain deliberately unchanged. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4179`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4256` |
| M2-4 — e2e insufficient for the visible changes | **NOT CLOSED.** Redirect/request-absence assertions are present, but several asserted navigation states are not implemented and POST status expectations are wrong. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4357`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4359` |
| M2-5 — red test bodies absent or non-compiling | **NOT CLOSED.** Task 5’s holder cannot exist before the permission is seeded, Task 13’s company fixture sends an invalid request, and Task 6 still lacks executable bodies. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3971`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4268`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4971` |
| M2-6 — `LOCK_INHERITED_FROM` not complete over entry points | **CLOSED at rev-3 anchor.** Both helpers map to `execute`, and callers are mechanically checked. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3464`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3630` |
| N2-1 — Task 6 typecheck ran before map regeneration | **CLOSED for Task 6.** Regeneration is now before typecheck and the generated map is staged. The separate Task 14 expected-diff statement remains wrong; see M3-3. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4258`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4385` |
| N2-2 — `RequirePermission` fallback behaviour misstated | **CLOSED.** The plan now asserts redirect to `/dashboard` and request absence. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4350` |
| Wrapper exit/status semantics | **CLOSED.** Ordinary first- and second-command failures preserve nonzero exit and a `failed` status; success is written only after both calls. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5744`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5762` |
| Rev-2 citation and lane-pin corrections | **CLOSED.** Current branch tips still match the plan’s pins and diffstat statements. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:232`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:271` |

The rev-2→rev-3 change log is accurate for B2-1, M2-1, M2-2, M2-6, map sequencing, redirect semantics, and the lane citations. Its claims that B2-2, M2-3, M2-4, and M2-5 are closed are contradicted by the supplied code and recipes.

## BLOCKER

### B3-1 — the supplied row-order census cannot be green on the post-lane tree

The intended role-lock chains themselves are correct:

- `LotActionPermissionDelta::resolveCatalogueRoles`: `whereIn`, then `orderBy(name)`, `orderBy(id)`, then `lockForUpdate`. `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:114`
- `LotActionPermissionDelta::provisionGeneralManager`: equality on `name`, correctly treated as the documented single-row exception. `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:142`
- `RoleController::assignRole`: equality on `name`, single-row exception. `lane/w-lot-a-1a:apps/api/app/Http/Controllers/Api/V1/RoleController.php:379`
- `UserController::store`: equality on `name`, single-row exception. `lane/w-lot-a-1a:apps/api/app/Http/Controllers/Api/V1/UserController.php:266`
- `UserController::update`: `whereIn`, ordered by `name`, then `id`. `lane/w-lot-a-1a:apps/api/app/Http/Controllers/Api/V1/UserController.php:388`
- Planned `EnsurePermissionsRunner::resolveCatalogueRoles`: `whereIn`, ordered by `name`, then `id`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2345`

But `recordRoleLock()` emits an unordered multi-row role-lock record whenever it cannot reduce the receiver to a static query root. The assertion then treats every such record as an offender. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3252`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3286`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3708`

Executing the extracted support class mechanically yielded **23 false offenders** on both `dev` and W-LOT:

1. `TemplateAssignmentService::assign` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/TemplateAssignmentService.php:104`
2. `TemplatePublishingService::cloneToDraft` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:117`
3. `TemplatePublishingService::publish` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:42`
4. `InventoryVarianceCoaTemplateV2Importer::removeUntouchedDrafts` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/InventoryVarianceCoaTemplateV2Importer.php:48`
5. `InventoryVarianceCoaTemplateV2Importer::rows` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/InventoryVarianceCoaTemplateV2Importer.php:220`
6. `LegacyCoaBootstrapImporter::assertExisting` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/LegacyCoaBootstrapImporter.php:123`
7. `LegacyCoaBootstrapImporter::assertRemovable` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Application/Services/LegacyCoaBootstrapImporter.php:221`
8. `TemplateRowController::replaceDraftRows` — `lane/w-lot-a-1a:apps/api/app/Modules/CountryDefaults/Presentation/Controllers/TemplateRowController.php:73`
9. `SalesOrderToInvoiceConverter::lockCompleteDeliveryNoteSet` — `lane/w-lot-a-1a:apps/api/app/Modules/Delivery/Application/Services/SalesOrderToInvoiceConverter.php:734`
10. `TerminalRegistrySnapshotService::emitInitialSnapshot` — `lane/w-lot-a-1a:apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:187`
11. `StockThresholdService::update` — `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Application/Services/StockThresholdService.php:44`
12. `PosCoreReceiptProjection::decrementStock` — `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Application/Services/PosCoreReceiptProjection.php:2306`
13. `PosCoreReceiptProjection::restockStock` — `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Application/Services/PosCoreReceiptProjection.php:2971`
14. `HeldOrderService::recallOrder` — `lane/w-lot-a-1a:apps/api/app/Modules/Pos/Application/Services/HeldOrderService.php:203`
15. `ReceiptCreationService::decrementStock` — `lane/w-lot-a-1a:apps/api/app/Modules/Pos/Application/Services/ReceiptCreationService.php:950`
16. `VirtualAdminFiscalEventService::appendAccountStatusChanged` — `lane/w-lot-a-1a:apps/api/app/Modules/Pos/Application/Services/VirtualAdminFiscalEventService.php:63`
17. `VirtualAdminFiscalEventService::appendDepositReceipt` — `lane/w-lot-a-1a:apps/api/app/Modules/Pos/Application/Services/VirtualAdminFiscalEventService.php:224`
18. `PurchaseQuoteRequestService::reopenGroup` — `lane/w-lot-a-1a:apps/api/app/Modules/Purchasing/Application/Services/PurchaseQuoteRequestService.php:141`
19. `ProductEnrichmentCorrelationService::findOwnedProduct` — `lane/w-lot-a-1a:apps/api/app/Modules/SupplierPortal/Application/Services/ProductEnrichmentCorrelationService.php:101`
20. `InstrumentLifecycleService::bounce` — `lane/w-lot-a-1a:apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:364`
21. `StatementImportService::withoutExistingFingerprints` — `lane/w-lot-a-1a:apps/api/app/Modules/Treasury/Application/Services/StatementImportService.php:359`
22. `PaymentRefundService::resolveInstrumentForReversal` — `lane/w-lot-a-1a:apps/api/app/Modules/Treasury/Application/Services/PaymentRefundService.php:1647`
23. `VoucherVoidService::lockVoucher` — `lane/w-lot-a-1a:apps/api/app/Modules/Treasury/Application/Services/VoucherVoidService.php:199`

The test therefore cannot be green after the lane merge, contradicting the claimed B2-2 closure at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5834`.

The repair must distinguish provably non-`roles` chains from unresolved possible-role chains while retaining fail-closed treatment for genuinely unresolvable role candidates. The scanner must also enforce ascending order explicitly and make `whereIn` classification monotonic; the present loop permits a later equality predicate to reverse it. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3313`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3330`

## MAJOR

### M3-1 — e2e-visible navigation and control hiding is asserted but not implemented

The e2e contract says:

- manager lacks Channels, Categories, Growth, and company-creation navigation;
- a services-capable user without `service-categories.view` lacks both the service-category navigation entry and the in-page link. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4359`

But Task 6 explicitly says not to touch the two relevant links:

- `ServiceListPage.tsx:117`
- `Sidebar.tsx:148`  
  `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4256`

The post-W-LOT code confirms the exposure:

- Sidebar’s Service categories item still uses the broad `services` permission. `lane/w-lot-a-1a:apps/web/src/components/organisms/Sidebar/Sidebar.tsx:146`
- The Service list’s Service categories link is unconditional. `lane/w-lot-a-1a:apps/web/src/pages/settings/services/ServiceListPage.tsx:116`
- Channel navigation remains under the broader ecommerce/inventory grouping, with no channel-specific child permission. `lane/w-lot-a-1a:apps/web/src/components/organisms/Sidebar/Sidebar.tsx:270`
- Task 6’s staging list does not include `Sidebar.tsx`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4385`

The plan must either implement and stage the navigation/control guards or remove the assertions and explicitly limit the UX contract. Given the accepted requirement that every visible caller be guarded, implementation is the appropriate correction.

### M3-2 — the e2e response assertions cannot pass

The plan expects the service-category GET, POST, and PATCH operations all to return 200. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4358`

The controller returns **201** from `store`; only update returns 200. `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:120`, `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:160`

The e2e must assert 201 for POST and 200 for GET/PATCH. Its “both roles” console/5xx statement also follows three actor arms—admin, manager, and the custom services-capable role—so the custom-role capture must be stated explicitly. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4355`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4366`

### M3-3 — multiple red-test recipes still cannot produce their stated red

Task 5:

- `CreditNoteCancelPermissionTest` calls `postedCreditNoteAndActor()` without supplying its body. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3944`
- The proposed helper must look up and assert the new permission exists before creating the holder. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3971`
- Before the seeder edit, that lookup fails. The claimed red therefore cannot be “holder reaches route and receives 403.” `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3973`

Task 6 names five test files and describes ten cases, but provides no executable bodies. That is not enough to verify that the red tests compile and fail specifically from request-count mismatches. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4268`

Task 13:

- The supplied trait calls undefined `companyForCurrentTenant()` and `userForCompany()` helpers. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4968`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4998`
- Its direct company-creation request uses camelCase `countryCode` and omits required `currency`, `locale`, and `timezone`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4971`
- The actual request requires `country_code`, `currency`, `locale`, and `timezone`, so the recipe returns 422 before the asserted creation. `lane/w-lot-a-1a:apps/api/app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:22`

These bodies must be completed and mechanically run at the relevant pre-implementation commits.

### M3-4 — Task 14’s generated-map expected diff is arithmetically wrong

Task 14 says the final generated-map diff versus `dev` contains exactly nineteen new keys. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5417`

That comparison also contains lane-owned additions:

- W-LOT adds `batches.recall.request` and `treasury.manage_all_locations`. `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91`
- T2 adds `inventory.transfers.close` and `inventory.transfers.reconcile`. `lane/t2-receipt-spine:apps/web/src/hooks/permissionsMap.generated.ts:119`

Those four keys are not members of `WAVE_0B_ADDITIONS`, whose nineteen-key list begins at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:576`. The final diff versus pre-wave `dev` therefore contains **23** new keys, not nineteen. The assertion should distinguish “nineteen wave-declared additions” from the complete post-lane diff.

### M3-5 — task-exact staging remains unresolved

The global discipline requires explicit staging, but:

- Task 6 says to add every backend test file Step 7 touched, deriving the list from `git status`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4391`
- Task 8 uses the same placeholder for modified Inventory tests. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4528`

Those are discovery instructions, not deterministic task manifests. The exact files must be named after the corresponding red recipes are completed.

## MINOR

1. The AddCompanyModal audit anchor is stale. The component begins at line 41, but the submit button is currently at 326–330, not 382–389 as stated. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4097`, `lane/w-lot-a-1a:apps/web/src/components/AddCompanyModal.tsx:326`

2. Task 6 says the pre-change catalogue test first fails on `channels.view`; after Task 5 adds `credit-notes.cancel`, the first remaining key in declared order is `services.view`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:580`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4345`

3. The manual second-company probe expects HTTP 200, while company creation returns 201. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5443`, `lane/w-lot-a-1a:apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:208`

4. “Signature changes for wave 1” says “One, deliberate,” but no production signature changes. The only change is a private test-fixture constant shape from `array<string,string>` to `array<string,list<string>>`. The section should say “None in production; one test-fixture contract change.” `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5901`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5912`

## Citation audit

### Entry conditions and overlaps

Both required ancestry checks currently fail:

- `git merge-base --is-ancestor lane/w-lot-a-1a dev` → exit 1
- `git merge-base --is-ancestor lane/t2-receipt-spine dev` → exit 1

Therefore wave 0b cannot start. This matches the whole-wave entry condition. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:43`

The recorded lane sizes match current tips:

- W-LOT: 83 files, +4714/−663.
- T2: 100 files, +13144/−546.  
  `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:232`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:271`

The seven T2-owned overlapping paths also match the current tip. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:247`

### Writer census

Fresh AST enumeration found exactly the expected **20 methods on `dev`** and **23 after W-LOT**. There are no missing or extra writer methods relative to the plan’s fixture.

The three W-LOT additions are:

- `LotActionPermissionDelta::execute`
- `LotActionPermissionDelta::provisionGeneralManager`
- `LotActionPermissionDelta::synchronizeSeededRole`  
  `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:33`, `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:139`, `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:161`

`methodSource()` is AST-bounded rather than brace-text bounded. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3108`

`LOCK_INHERITED_FROM` is proven rather than waived:

- both helpers are private;
- `callersOf()` finds only `execute`;
- `execute` acquires both role locks before either helper call.  
  `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:45`, `lane/w-lot-a-1a:apps/api/app/Domain/Identity/Services/LotActionPermissionDelta.php:78`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3464`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3611`

### Frontend line audit

The plan’s dev→post-W-LOT census is broadly accurate for the query and mutation call sites. Current post-lane anchors include:

| Area | Post-W-LOT anchor |
|---|---|
| Company onboarding route | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:554` |
| Categories route | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1194` |
| Service-category route | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1603` |
| Service-edit route | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1627` |
| Channel routes | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:2255`, `:2265`, `:2275`, `:2285`, `:2295`, `:2309` |
| Growth routes | `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:3211`, `:3219` |
| Channel list query | `lane/w-lot-a-1a:apps/web/src/pages/channels/ChannelListPage.tsx:21` |
| Channel wizard mutation | `lane/w-lot-a-1a:apps/web/src/pages/channels/ChannelCreateWizard.tsx:13` |
| Aggregate channel orders | `lane/w-lot-a-1a:apps/web/src/pages/channels/hooks/useAggregateChannelOrders.ts:19` |
| Categories query and controls | `lane/w-lot-a-1a:apps/web/src/pages/categories/CategoriesPage.tsx:27`, `:140`, `:172` |
| Category form caller | `lane/w-lot-a-1a:apps/web/src/pages/categories/CategoryForm.tsx:26` |
| `useCategoryTree` gate | `lane/w-lot-a-1a:apps/web/src/pages/categories/hooks/useCategories.ts:108` |
| Category tree controls | `lane/w-lot-a-1a:apps/web/src/pages/categories/components/CategoryTreeView.tsx:84`, `:93`, `:102` |
| Progression queries | `lane/w-lot-a-1a:apps/web/src/pages/progression/CompanyProfilePage.tsx:86`, `:101`; `lane/w-lot-a-1a:apps/web/src/pages/progression/components/ModuleReadiness.tsx:22`; `lane/w-lot-a-1a:apps/web/src/pages/progression/components/Recommendations.tsx:21` |
| Progression mutations | `lane/w-lot-a-1a:apps/web/src/pages/progression/components/ModuleCard.tsx:27`; `lane/w-lot-a-1a:apps/web/src/pages/progression/components/RecommendationCard.tsx:21` |
| Company add affordance | `lane/w-lot-a-1a:apps/web/src/components/CompanySelector.tsx:113` |
| Add-company mutation | `lane/w-lot-a-1a:apps/web/src/components/AddCompanyModal.tsx:64` |
| Service-category queries/mutations/controls | `lane/w-lot-a-1a:apps/web/src/pages/settings/services/ServiceCategoryListPage.tsx:55`, `:64`, `:77`, `:90`, `:186`, `:235`, `:262` |
| Service query and category link | `lane/w-lot-a-1a:apps/web/src/pages/settings/services/ServiceListPage.tsx:46`, `:116` |
| Service-form category query | `lane/w-lot-a-1a:apps/web/src/pages/settings/services/ServiceForm.tsx:69` |

The unresolved defects are the Sidebar and `ServiceListPage` visible links described in M3-1, not the query census itself.

### Wrapper, routes, and downstream signatures

The wrapper’s two-command sequencing and status-file semantics are sound. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5720`

Every listed PHPUnit invocation carries the PostgreSQL harness; no SQLite-only invocation was found.

`Http` manifest group 2→3 is correctly scheduled. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4706`, `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5404`

There are no wave-1-facing production signature changes. The three re-pin notes are correct:

1. the marker helper gains a second parameter but its class is subsequently deleted;
2. the census gains `collect()`, `methodSource()`, and `callersOf()`;
3. the wrapper is subsequently removed with its stopgap.  
   `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5943`

## Rejected false positives

- **No writer-census cardinality defect:** fresh enumeration matches 20 on `dev` and 23 after W-LOT. The blocker is the lock-chain classifier, not missing writer methods.
- **No missing inherited-lock proof:** both helper entry points are mapped to `execute`, and acquire-before-call is checked.
- **No violation from `provisionGeneralManager`:** its equality lookup is the documented single-row exception.
- **No admin/grant-set defect:** admin receives all nineteen additions; manager and general manager receive exactly the seven settled keys.
- **No wrapper happy-path or ordinary failure-path defect:** both invocations and the status transition are correctly sequenced.
- **No need to resequence the entire wave for M0b-2:** execution discipline is an acceptable solution. The invalid individual red recipes still need repair.
- **No Task 6 typecheck-order defect:** map regeneration now precedes typecheck.
- **No route-ceiling defect:** the settled A-1 ceilings are correctly represented.
- **No production API-signature change for wave 1:** only a private test-fixture shape changes.
- **No `TemplateDeltaApplier` lock defect:** it acquires no lock and its two entry points are documented as sync and reapply.

## Preserve

Preserve these rev-3 improvements while fixing the gate findings:

- both lanes as whole-wave entry conditions;
- the nineteen/seven admin and manager grant split;
- snapshot passage into `grantMissing`;
- AST-bounded method extraction;
- list-valued `LOCK_INHERITED_FROM` with complete caller proof;
- the single-row `provisionGeneralManager` exception;
- the 22-row frontend query/control census;
- `/service-categories` canonicalization and PUT→PATCH change;
- redirect-to-`/dashboard` plus request-absence assertions;
- Task 6 map regeneration before typecheck;
- `AdminRoleMissing` → `FAILED`, including dry-run;
- atomic two-invocation operational wrapper;
- PostgreSQL harness on every PHPUnit command;
- `Phase 0.2.<task>:` subjects;
- settled A-1 route ceilings and Task 15 placement;
- the three downstream re-pin notes.

## Owner decisions required

None. The required corrections follow from already-settled rulings:

1. repair the lock-chain visitor without weakening fail-closed treatment;
2. add the missing Sidebar and in-page permission guards;
3. correct the e2e status assertions and console/5xx coverage wording;
4. supply runnable Task 5, Task 6, and Task 13 red bodies;
5. correct the final generated-map arithmetic;
6. replace dynamic staging placeholders with exact file lists.

## Dispatch assessment

Wave 0b cannot start today because neither required lane is an ancestor of `dev`. Even after both lanes merge, REV 3 is not dispatchable: its mandatory writer-census test is mechanically red, its e2e contract asserts UI behaviour that the plan explicitly does not implement, and several required red tests cannot compile or fail for their stated reason.

These are blocker/major execution defects, not editorial minors.

VERDICT: CHANGES-REQUIRED