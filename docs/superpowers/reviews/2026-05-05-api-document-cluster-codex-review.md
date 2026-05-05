REQUEST-CHANGES

Commit reviewed: `dd56691b` + `5174e756` + `8bd2b13a` (combined cluster review), plus round-1 remediation `1f0aaf10`.

## Opus claim verification

Confirmed Opus's primary round-1 finding is fixed by `1f0aaf10`.

- `DraftPersistenceService::addLinesBatch` now scopes both batch lookups with `tenant_id` and `company_id` before `whereIn('id', ...)`.
- `vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php --filter test_auto_save_batch_lines_does_not_leak` is GREEN: `OK (1 test, 2 assertions)`.
- Temporarily reverting just those batch product/service queries to the old unscoped `Product::whereIn` / `Service::whereIn` shape makes the leak repro fail exactly on the intended assertion: `Found 2 affected line(s)`, expected `0`.
- `test_auto_save_batch_lines_query_includes_tenant_and_company_predicates` is GREEN: `OK (1 test, 4 assertions)`, and the test captures the `products` `whereIn` query and checks both `"tenant_id"` and `"company_id"`.
- `api.document.043` / `.044` are present in `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml`.

Confirmed the three batch regression files are green on restored HEAD:

- `CreditNoteTenantIsolationTest.php`: `OK (11 tests, 26 assertions)`.
- `DocumentConversionTenantIsolationTest.php`: `OK (15 tests, 31 assertions)`.
- `RefundResidualTenantIsolationTest.php`: `OK (17 tests, 42 assertions)`; this is 15 original + 2 addLinesBatch remediation tests.

Confirmed Opus's per-batch test honesty result using reverse-applied production diffs rather than literal stash, because this sandbox cannot write `.git/FETCH_HEAD` or `.git/index.lock`.

- Batch 1 production diff reversed: `CreditNoteTenantIsolationTest.php` failed `11/11`, matching Opus.
- Batch 2 production diff reversed: `DocumentConversionTenantIsolationTest.php` had 13 red outcomes and 2 passers. With the tightened PDF test this appears as `12 failures + 1 error`; this is the same 13/15 red shape Opus reported.
- Batch 3 production diff reversed: current 17-test file failed 15 tests while the two new addLinesBatch remediation tests still passed. This matches Opus's original 15/15 Batch 3 red set before the two remediation tests existed.

Confirmed commit attribution caveat:

- `git show --stat --oneline 5174e756` shows only Document files: two converters, three Document controllers, and `DocumentConversionTenantIsolationTest.php`; zero Inventory files.
- `git show --stat --oneline 1eada1cb` shows the actual Inventory cluster fix.

Confirmed Opus's informational/NICE-TO-HAVE findings remain appropriately classified:

- Finding B remains validator-tier protected for `CreditNoteService::createStandaloneCreditNote`.
- Finding C remains a company-only asymmetry in `AgedReceivablesService`, not a proven active tenant leak.
- Finding G remains the three deferred `exists:document_lines,id` validators.
- Finding H is just the `AgedReceivablesService` class-name collision.

Could not literally reproduce `git pull --ff-only` or `git stash push -- <production-files>` because filesystem permissions block git metadata writes:

- `git pull --ff-only` failed with `error: cannot open '.git/FETCH_HEAD': Operation not permitted`.
- `git restore` failed with `Unable to create ... .git/index.lock: Operation not permitted`.
- I used `git diff <parent> <commit> -- <production-files> | git apply -R` and then `git apply` to perform equivalent temporary production rollbacks without touching the git index.

## New findings (round 1, second-layer)

### Finding 1 — REQUEST-CHANGES: shared document create/update validators are tenant-only while controllers snapshot unscoped product/service records

`CreateDocumentRequest` and `UpdateDocumentRequest` still validate important foreign IDs with `Rule::exists(...)->where('tenant_id', $tenantId)` but no `company_id` predicate:

- `CreateDocumentRequest.php:43` partner, `:65` source document, `:70-72` location, `:79` product, `:85` service, `:90-92` line location.
- `UpdateDocumentRequest.php:43` partner, `:66` product, `:72` service.

The shared CRUD controllers then create a document under the current company context, but batch-fetch line product/service snapshots without tenant or company predicates:

- `QuoteController.php:221,223,329,331`
- `SalesOrderController.php:221,223,329,331`
- `InvoiceController.php:236,238,346,348`
- `PurchaseOrderController.php:230,232,340,342`
- `DeliveryNoteController.php:226,228`
- `ReturnNoteController.php:225,354` for products

Example shape from `QuoteController.php:217-248`: after `Document::create([... 'tenant_id' => $tenantId, 'company_id' => $companyId ...])`, it does `Product::whereIn('id', $productIds)->get()` and snapshots `$lineProduct->name` into `designation_default_snapshot`.

This is not a cross-tenant leak because tenant-B IDs fail the tenant-only validator. It is still an active same-tenant cross-company isolation defect: a user operating in Company A can submit a Product/Service/Partner/Location/SourceDocument UUID from Company B in the same tenant. The validator accepts it, and the unscoped product/service batch read can persist Company B names into Company A document lines. The current sweep invariant has consistently required both tenant and company predicates, so this should not be left as informational.

Required remediation: change the shared request validators to `ScopedExists::tenantAndCompany(...)` or equivalent tenant+company `Rule::exists` predicates using the active `CompanyContext`, and scope the controller batch reads by the document/current tenant and company before snapshotting names.

### Finding 2 — MINOR test-pin gap: `DraftPersistenceService` source-level regex is still file-wide

The Opus Finding D remediation tightened regexes to require both predicate literals, but `test_draft_persistence_service_uses_scoped_lookups` still only proves that some `Product::query()` and some `Service::query()` in the file contain both predicates.

I temporarily removed `->where('company_id', $companyId)` from the single-line `addLine` product lookup at `DraftPersistenceService.php:208-210` and ran:

`vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php --filter test_draft_persistence_service_uses_scoped_lookups`

It still passed: `OK (1 test, 9 assertions)`, because the batch product query later in the same file still matched the file-wide regex. The same pattern could hide a regression in one of multiple lookups of the same model class.

The tightened checks do work for single-occurrence files: removing `company_id` from `DocumentPostingService` made `test_document_posting_service_uses_scoped_product_lookup` fail. The PDF method-body anchor also works: changing `generatePath()` back to `Document::findOrFail($id)` made `test_pdf_generate_path_uses_scoped_lookup` fail.

Recommended remediation: make `DraftPersistenceService` source tests method-body or callsite anchored for `api.document.011`, `.012`, `.013`, `.043`, and `.044` separately, or replace them with SQL-log/integration tests where feasible.

## Audit exhaustiveness

Commands run and relevant outputs:

- `git pull --ff-only` -> blocked: `Operation not permitted` on `.git/FETCH_HEAD`.
- `git status --short --branch` -> branch `feat/tenant-isolation-sweep-execution...origin/feat/tenant-isolation-sweep-execution`; only pre-existing untracked docs/tooling files.
- `sed -n '1,260p' docs/superpowers/reviews/2026-05-05-api-document-cluster-opus-review.md` -> read end-to-end.
- `git show --stat --oneline 5174e756` -> 6 Document files, zero Inventory files.
- `git show --stat --oneline 1eada1cb` -> Inventory files and `InventoryTenantIsolationTest.php`.
- `vendor/bin/phpunit tests/Feature/Document/CreditNoteTenantIsolationTest.php` -> `OK (11 tests, 26 assertions)`.
- `vendor/bin/phpunit tests/Feature/Document/DocumentConversionTenantIsolationTest.php` -> `OK (15 tests, 31 assertions)`.
- `vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php` -> `OK (17 tests, 42 assertions)`.
- `vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php --filter test_auto_save_batch_lines_does_not_leak` -> `OK (1 test, 2 assertions)`.
- Reverse-applied Batch 1 production diff, then `CreditNoteTenantIsolationTest.php` -> 11 failures / 11 tests.
- Reverse-applied Batch 2 production diff, then `DocumentConversionTenantIsolationTest.php` -> 12 failures + 1 error / 15 tests, with the expected two passers still green.
- Reverse-applied Batch 3 production diff, then `RefundResidualTenantIsolationTest.php` -> 15 failures / 17 tests; the two addLinesBatch remediation tests were outside Batch 3 and stayed green.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` from `apps/api` -> `verified 963 event(s) across 268 callsite(s); 0 problem(s).`
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` -> empty output.
- Hostile grep over `apps/api/app/Modules/Document` including `find`, `first`, `firstOrFail`, `findOrFail`, `where`, and `whereIn` found the already-remediated cluster sites plus the shared CRUD create/update product/service batch reads listed in Finding 1.
- Bare validator audit: `rg -n "Rule::exists\\('(partners|documents|products|services|locations)'|exists:(partners|documents|products|services|locations)|ScopedExists::tenantAndCompany" apps/api/app/Modules/Document/Presentation` found the remediated `ScopedExists::tenantAndCompany` callsites and the tenant-only shared request rules listed in Finding 1.
- Cross-module service import grep found only the Workshop adapter using `DocumentPostingService::post($document)` on a freshly-created structurally scoped document, plus comments / unrelated Treasury/POS refund services. I found no external caller bypassing the remediated Document route/service reads.

Test setup honesty:

- All three test files create distinct Tenant A and Tenant B records, then Company B is created with `tenant_id => tenantB->id`, and cross documents/partners/products are created with Company B and Tenant B. The cross-tenant assertions are not relying on coincident IDs.
- `CreditNoteTenantIsolationTest.php` creates `tenantB`, `companyB`, `customerB`, `productB`, and `invoiceB` separately from A fixtures.
- `DocumentConversionTenantIsolationTest.php` creates `tenantB`, `companyB`, `customerB`, and quote/order/invoice/delivery-note B separately from A fixtures.
- `RefundResidualTenantIsolationTest.php` creates `tenantB`, `companyB`, `customerB`, `productB`, `invoiceB`, and `creditNoteB` separately from A fixtures.

## Confidence

High confidence that Opus Finding A is closed and the original 42 inventoried callsites plus `.043/.044` remediation behave as intended under the current tests. The per-batch red/green checks, SQL-log invariant, verify-history, and POS diff all line up.

I am requesting changes because the second-layer sweep found a real sibling surface in the shared document create/update path: tenant-only validators combined with unscoped batch product/service reads and persisted line snapshots. It is same-tenant cross-company rather than cross-tenant, but the cluster invariant being enforced everywhere else is tenant + company, and this path writes foreign company references/snapshots into active documents.
