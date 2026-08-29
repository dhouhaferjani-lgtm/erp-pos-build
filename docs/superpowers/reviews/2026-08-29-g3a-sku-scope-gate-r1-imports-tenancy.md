# Adversarial gate r1 — G-3a company-scoped SKU / variant SKU / partner VAT uniqueness

**Lane:** G-3a, branch `feat/g3a-company-scoped-sku`  
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g3a-sku-scope`  
**Merge base / HEAD:** `a33b01354fd028b1d5e8158b5981baf3ff3b4bde`  
**Review lenses:** imports-reviewer + tenancy-authz-reviewer  
**Posture:** source read-only; only this register was written. The dirty tree remained 16 tracked modifications + 11 untracked files.

## Register

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| G3A-R1 | BLOCKER | `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:762-784`; `apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:53-57,89,145-149` | G-R22 is not implemented. The controller serializes the absolute `$fullPath`, the worker passes that absolute path through, then calls `Storage::disk('local')->exists()` / `delete()` with it. The approved contract is a relative storage key everywhere; the current cleanup is therefore not pinned to the key stored in `import_jobs.file_path` and can miss the ZIP. | Dispatch the relative `$path`; keep the job property as a relative storage key; resolve `Storage::disk('local')->path($key)` only at the filesystem/service boundary; delete by the same relative key. Add a dispatch-payload and immediate-purge regression. `source_purged_at` remains G-6a-owned. |
| G3A-R2 | BLOCKER | `apps/api/database/migrations/tenant/2026_08_30_100200_enforce_company_scoped_partner_vat_numbers.php:49-55`; `apps/api/app/Modules/Partner/Application/Services/PartnerService.php:23-33,64-70`; `apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:81-87`; `apps/api/app/Modules/Partner/Presentation/Requests/UpdatePartnerRequest.php:99-107` | M3 installs lifetime `unique(company_id, vat_number)`, but both partner lookups still use the active-only default scope and both FormRequests still validate `tenant_id + deleted_at IS NULL`. Consequences: a sibling company's VAT is falsely rejected by HTTP validation, while a same-company deleted holder passes validation and later reaches a raw DB violation/import error. The handoff labels these G-4-owned, but every push to `origin/dev` auto-migrates staging, so G-3a cannot be promoted independently with this known schema/consumer mismatch. | Before G-3a is promoted, either land the G-4 partner resolver/FormRequest work atomically in the same verified promotion, or revise ownership and align these consumers now: explicit company scope, lifetime visibility, correct update ignore, and deleted-holder refusal. Add sibling-company and deleted-holder HTTP/import regressions. |
| G3A-R3 | MAJOR | `apps/api/app/Modules/Product/Application/Services/ProductService.php:313-318`; `apps/api/app/Modules/Import/Services/ImportService.php:351-365`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:159-174`; `apps/api/app/Modules/Product/routes.php:53-76` | Product deleted-holder detection is coded only as a `RuntimeException`; sync and queued imports persist its prose in `import_rows.import_error`, with no code. That channel deferral is explicitly owned by later lanes, but the present message says “restore or purge it in the UI” and there is no Product restore or force-delete/purge route or UI path. The prescribed action is not truthful/actionable. | Keep the no-auto-restore policy, but use remediation text that names an actually available administrative path, or ship a real authorized restore/permanent-delete flow before claiming “in the UI.” When G-4 lands, map it to `sku_held_by_deleted_product` instead of leaving raw prose. |
| G3A-R4 | MAJOR | `/private/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/9cdc16cd-491d-4a35-924a-1b1ec9316258/scratchpad/lane-g3a-summary.md:19-51` | The implementer census is not a complete diff of Audit D. It omits 13 explicitly cited Audit-D sites, including the POS device lookups, the POS variant feed, and both web lookup hooks. This repeats the precedent's unsafe “all consumers verified” evidence gap, even though this gate independently checked the omitted sites and found no new accidental cross-company lookup. | Amend the consumer census so every Audit-D hit appears as `touched` or `verified-unchanged`, including N/A/display-only hits, and retain the classifications recorded below. |
| G3A-R5 | MINOR | `apps/api/tests/Feature/Product/UpdateProductTest.php:209-241` | The dirty diff rewrites the unrelated batch-tracking/default-lot assertion while this lane owns SKU/VAT scope. The stronger decimal assertions pass, but this is unreported scope expansion under CLAUDE rule 4. | Revert this unrelated hunk or split it into its owning lane/change with its own rationale. |
| G3A-R6 | NOTE | `apps/api/database/migrations/tenant/2026_08_30_100000_enforce_company_scoped_product_skus.php:27-69,72-159`; `...100100_enforce_company_scoped_variant_skus.php:25-74,77-130`; `...100200_enforce_company_scoped_partner_vat_numbers.php:26-68,71-159` | Migration mechanics pass review. M1/M3 census all rows (including soft-deleted holders), M3 excludes only NULL VAT; M2 mirrors the existing live-row partial predicate. All refuse before any drop, discover old uniques by columns, add only when absent, and use logged forward-only `down()`. If an old index is absent, the discovery loop drops nothing and the new index is created; if the canonical new index is present, re-run is a no-op after the census. | No source change required for these mechanics. A dedicated “old index absent” regression would improve the auto-deploy proof but the source path is non-throwing and the clean/idempotent paths already execute on SQLite and PG. |
| G3A-R7 | NOTE | `apps/api/app/Modules/Catalog/Domain/VariantIndexNames.php:7-13`; `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php:123-141,384-393`; `apps/api/tests/Feature/Catalog/VariantIndexScopeTest.php:62-137` | RUL-2 and constraint-name behavior are preserved: variant SKU is company-scoped; variant barcode remains tenant-wide; products.barcode receives no unique constraint; create and restore SKU/barcode collisions map to field-specific 422s through constants. The PG migration test proves the barcode/default/check neighbours are byte-for-byte unchanged. | None. |
| G3A-R8 | NOTE | `apps/api/tests/Feature/Import/ProductSkuCompanyScopeImportTest.php:84-136` | The 1d reproduction is substantive: it asserts two persisted product rows with the same SKU in sibling companies. The second-location case asserts product count stays 1, stock-level count becomes 2, and the annex quantity is persisted as `4.0000`. | None. |

## Migration and contract stress results

| Area | Result |
|---|---|
| M1 products | Guards at `:29-48`; all-row census at `:74-81`; refusal at `:104-112`; old unique discovered by `['tenant_id','sku']` at `:115-133`; PG constraint/index split at `:136-159`; canonical company unique at `:53-56`; forward-only `down()` at `:62-70`. |
| M2 variants | PG-only and SQLite no-op at `:27-38`; live-row census (`deleted_at IS NULL`) at `:79-87`; only the `tenant_id,sku` unique is dropped at `:121-130`; new predicate is exactly `WHERE deleted_at IS NULL` at `:57-61`. Barcode columns do not match the drop tuple and the PG test compares its full definition before/after. |
| M3 partners | All soft-delete states are counted; only NULL VAT is excluded at `:73-81`; old unique discovery/drop and forward-only behavior match M1. |
| Old index never existed | No crash by source path: each drop helper iterates discovered indexes and does nothing if none match, then creates the company unique when absent. On a normal fresh tenant, historical migrations still run in order and generally create the old index first; the missing-old path is a drift/fresh-schema safeguard. |
| Barcode contract | `products.barcode` remains a plain non-unique index; no changed migration adds a constraint. `product_variants_tenant_barcode_unique` remains `(tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL`. |
| Product UI lifetime rule | Create and Update rules now use tenant + company without `whereNull(deleted_at)` (`CreateProductRequest.php:177-184`, `UpdateProductRequest.php:162-169`); Update retains `ignore($productId)`. |
| Product lookup lifetime rule | `ProductService::findIdBySku()` and the import identity ladder use `withTrashed()` plus explicit tenant/company (`ProductService.php:36-47,291-321`). The current import outcome is a raw `import_error` message, not a coded row error; the code channel is deferred. |
| Image-import tenancy | The lookup itself is fixed: explicit tenant + serialized company at `ProductImageImportService.php:232-245`, `ProcessProductImageImport.php:53-57,89`, and dispatch at `ImportController.php:784`. The test clears `CompanyContext` and selects the correct sibling-company product (`ProductImageImportServiceMediaTest.php:148-182`). G3A-R1 remains for relative-key handling. |
| Variant exception mapping | Create/update path maps tenant-barcode and company-SKU constants at `ProductVariantService.php:128-140`; restore path maps both at `:387-392`. `VariantBarcodeRaceTest.php` references the company-SKU constant rather than the obsolete tenant-SKU literal. |
| Variant label | Intentionally tenant-wide and unchanged: soft-deleted variant barcodes stay reserved (`VariantLabelService.php:45-57`); any tenant product barcode or SKU, including a sibling company's SKU, blocks a label (`:75-80`). |

## Direct `rg` classification

Command:

```text
rg -n "where\(\s*['\"]sku['\"]|where\(\s*['\"]vat_number['\"]" apps/api/app --type php
```

Result: **12 hits across 8 files**.

- **10 compliant for their contract:** two explicitly company-scoped diagnostic SKU lookups; MatchSuggestion's company-scoped base query; three ProductService company + `withTrashed()` SKU lookups; ProductImage's explicit tenant+company lookup; InventoryOpening's `forCompany()` active lookup; and both company-parameterized variant repositories.
- **2 lifetime-incomplete:** `PartnerService.php:31,68` are company-scoped but active-only, producing G3A-R2.
- No remaining accidental tenant-only direct `where('sku', ...)` / `where('vat_number', ...)` hit was found. `VariantLabelService`'s tenant-wide product-code check uses a nested `orWhere('sku', ...)`, is outside this exact regex, and is intentionally tenant-wide.

## Census diff against Audit D

The implementer table does cover the high-risk backend sites: ProductService (both paths), ProductImageImportService/job/controller, InventoryOpeningService, MatchSuggestionService, ProductController, LineEntryController, both variant repositories, SalesReportService, Create/UpdateProductRequest, PartnerService/requests, TestTaxRecoverability, the historical variant index literals, VariantLabelService, and the zero Scout/Meilisearch result.

Audit-D hits absent from the implementer table are below. This gate independently read each one; none adds another accidental cross-company data exposure, but omission from the required census is G3A-R4.

| Audit-D hit absent from handoff | Gate classification |
|---|---|
| `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:182-192` | Format-only Partner/Product row rules; no uniqueness lookup. Verified unchanged / N/A. |
| `apps/api/app/Modules/Import/Services/MigrationWizardService.php:169-179,315-345` | Header aliases and sample/template rows; no lookup. Verified unchanged / N/A. |
| `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:227` | Reads `sku ?? barcode` from an already-resolved product. Verified unchanged / display/reference only. |
| `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:173` | Reads product reference from an already-resolved row. Verified unchanged / display/reference only. |
| `apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php:275` | Reads product reference from an already-resolved row. Verified unchanged / display/reference only. |
| `apps/pos/src/lib/db/repositories/productRepository.ts:98-104,128-134` | Local device lookup over the current company's synced catalogue; plural lookup handles same-code multi-match. Verified unchanged / effectively company-scoped. |
| `apps/pos/src/lib/scan/resolveScannedCode.ts:94-125` | In-memory lookup over the company-scoped snapshot and company-keyed scan cache. Verified unchanged / effectively company-scoped. |
| `apps/pos/src/api/productApi.ts:30-39` | Calls `/products`, whose base query is company-scoped before filters. Verified unchanged. |
| `apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php:35-60` | Passes both required tenant and company IDs to the variant feed reader. Verified unchanged. |
| `apps/web/src/features/pos/hooks/useBarcodeLookup.ts:56-100` | Local company catalogue first, then company-scoped `/products`. Verified unchanged. |
| `apps/web/src/components/molecules/line-items/useProductLineLookup.ts:77-99` | Calls `/line-entry/resolve-code`; backend lookup is tenant + company scoped. Verified unchanged. |
| `apps/web/src/features/import/pages/ImportWizardPage.tsx:154-157` | Column metadata/help text only. Verified unchanged / N/A. |
| `apps/api/app/Modules/Product/Domain/Product.php:84-90,186-195,345-357` | Confirms no global company scope; company isolation remains call-site discipline. Verified unchanged. |

Audit D's earlier suggestion to re-scope variant barcode is superseded by binding RUL-2 and design §3.1a. The implementation correctly preserves tenant-wide variant-barcode scan safety.

## Consumer claims checked from the handoff

| Consumer | Gate result |
|---|---|
| `InventoryOpeningService.php:144-147` | `forCompany($companyId)` precedes SKU; active-only is appropriate for posting opening stock. |
| `MatchSuggestionService.php:54-65,102-123,251-277` | Partner and product candidate sets both start with tenant + company; SKU predicate is applied to the scoped Product base query. |
| `ProductController.php:83-129,258-269` | Company context supplies the base `where(company_id)` before search/barcode-or-SKU filters. |
| `LineEntryController.php:159-172` | Explicit tenant + company + active predicate. |
| `SalesReportService.php:122-164` | Identity/grouping is `product_id`; SKU is a display column. Company/location filters come from receipt facts. |
| `EloquentProductVariantRepository.php:18-30` and `EloquentProductVariantLookup.php:26-43` | Both SKU/barcode APIs require and apply company ID. |
| Partner requests/service | The handoff accurately says “unchanged/G-4-owned,” but that unchanged behavior is incompatible with independently promoting M3; see G3A-R2. |

## Commands and fresh outputs

No full suite was run.

| Command/check | Fresh result |
|---|---|
| Five new classes, each via `./vendor/bin/phpunit <path>` on SQLite | **15 selected: 7 passed, 8 expected PG-only skipped, 52 assertions.** Per file: M1 2/11; M2 3 selected, 2 skipped/5 assertions; M3 2/11; VariantIndex 6 skipped; Product import 2/25. |
| Required PG anchored filter over all five new classes | **14 passed, 1 expected SQLite-only skip, 84 assertions, 36.00s.** An initial transcription with one collapsed backslash selected zero tests; the exact `/\\(…​)::/` form was rerun and produced these counts. |
| Existing touched tests by path: CreateProduct, UpdateProduct, ProductUpsertKeyPrecedence, LabelBarcodeCollision, VariantBarcodeRace, ProductImageImportServiceMedia, ScheduledJobTenantIsolation | **54 selected: 52 passed, 2 expected PG-only skipped, 158 assertions.** |
| `./vendor/bin/phpstan analyse` on the 25 dirty PHP paths | **0 errors.** |
| `./vendor/bin/pint --test` on the same 25 PHP paths | **pass.** |
| `php tools/feature-lane-manifest-check.php` | **pass:** 1,461 Feature classes / 74 groups; every filter anchored and every entry uniquely matches among 1,861 total test classes; parked ceiling 1,202. |
| CI YAML parse + class occurrence check | **pass:** Ruby/Psych parsed `.github/workflows/ci.yml`; each of the three new migration class names appears once in the filter. |
| Manifest arithmetic | **correct:** gated 1197→1202 (+5); Migrations 7→10 (+3); Catalog 33→34 (+1); Import 18→19 (+1); Product 58→58; exactly five new test classes. Recompute after any concurrent G-7 merge. |
| `php vendor/bin/deptrac analyse` | Parsed **3,154 files**; repository baseline **183 violations**, **0 errors**, exit 1 as expected for the baseline. JSON recheck: 57 violating files, **0 touched files**. |
| `git diff --check` | **pass**. |
| Final worktree inventory | **27 dirty entries:** 16 tracked modifications + 11 untracked files; unchanged from initial inventory. |

## Staging safety

F-BUG-1's second-company attempts failed row-by-row on the old tenant-wide product SKU unique. `ImportService.php:351-365` and `ProcessImportJob.php:159-174` wrap each row in a transaction and record the exception after rollback, so those failed attempts left no duplicate product rows. Code-less default locations affect stock placement, not any of the three migration census tuples.

Therefore, on an intact staging tenant:

- M1 cannot find a `(company_id, sku)` collision that the stronger existing `(tenant_id, sku)` unique allowed.
- M2 cannot find a live `(company_id, sku)` collision that the existing live `(tenant_id, sku)` partial unique allowed.
- M3 cannot find a non-null `(company_id, vat_number)` collision that the stronger existing `(tenant_id, vat_number)` unique allowed.

That is not a fleet-wide guarantee. Any of the three migrations **can deliberately abort one staging tenant** if its old constraint/index drifted away, data was manually damaged, or tenant/company IDs are internally inconsistent. The refusal happens before any drop and logs the collision groups, which is the safe failure mode. This gate did not query the staging fleet; the implementer handoff reports a local PG census of zero groups for all three tables. A pre-promotion per-tenant census remains operationally necessary because `origin/dev` auto-migrates every staging tenant.

## VERDICT

**CHANGES**

Required before independent G-3a promotion:

1. Implement and test the G-R22 relative ProductImages storage-key / immediate-purge path.
2. Eliminate the M3/partner consumer window: atomically promote the G-4 lifetime/company fixes with G-3a, or align PartnerService and both VAT FormRequests before M3 auto-migrates staging.
3. Replace the false “restore or purge it in the UI” guidance with an actually available remediation path; preserve the later coded-error mapping requirement.
4. Complete the handoff census with every Audit-D hit listed above.
5. Revert or split the unrelated `UpdateProductTest` batch-tracking hunk.

The three schema migrations, Product/variant company scoping, barcode invariants, Product FormRequests, company-pinned ProductImages lookup, variant 422 mappings, 1d import reproduction, CI filter, manifest arithmetic, targeted tests, PHPStan, Pint, and touched-class deptrac check otherwise pass this gate.

---

# Gate r2 — re-check after fix round 1

**Lane:** G-3a, branch `feat/g3a-company-scoped-sku`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g3a-sku-scope` (HEAD `a33b01354`, still DIRTY/uncommitted)
**Review lenses:** imports-reviewer + tenancy-authz
**Posture:** source read-only; only this register was appended. Worktree inventory at r2: **31 dirty entries — 19 tracked modifications + 12 untracked files** (r1 saw 27 = 16 + 11; the delta is fix-round test files, incl. `tests/Feature/Partner/PartnerVatLifetimeScopeTest.php`).

## r1 register disposition

| ID | r1 severity | r2 status | evidence |
|---|---|---|---|
| G3A-R1 relative ProductImages key | BLOCKER | **CLOSED** | `ImportController.php:756-780` stores `$path`, passes it to `createJob(filePath: $path)` and dispatches `ProcessProductImageImport::dispatch($job->id, $path, $tenantId, $companyId)` — the absolute `$fullPath` local is gone from this method (the surviving `Storage::disk('local')->path($path)` at `:146` belongs to the ordinary CSV/XLSX upload path, not ProductImages). `ProcessProductImageImport.php:89` pins `$storageKey = $job->file_path` as authoritative, resolves the absolute path only at the service boundary (`:107`) and purges by the same relative key (`:167-169`). `import_jobs.file_path` == the deleted key: `createJob()` (`ImportService.php:61-72`) writes exactly the `$path` that was dispatched, and `PartiesImportTypeTest::test_product_images_dispatches_and_purges_the_import_jobs_relative_storage_key` asserts `$job->file_path === $queuedJob->zipPath`, `! str_starts_with($zipPath,'/')`, `assertExists($job->file_path)` before `handle()` and `assertMissing($job->file_path)` after. |
| G3A-R2 partner VAT lifetime consistency | BLOCKER | **CLOSED** | `PartnerService.php:30-36` (resolution) and `:72-80` (upsert VAT holder) both use `Partner::withTrashed()` + explicit `tenant_id`/`company_id`; `refuseSoftDeletedVatHolder()` at `:135-145` throws the coded `vat_held_by_deleted_partner:` token. `CreatePartnerRequest.php:87-89` / `UpdatePartnerRequest.php:105-108` now read `->where('tenant_id',$tenantId)->where('company_id',$company->id)` with `whereNull('deleted_at')` REMOVED (lifetime), `ignore($partnerId)` retained on update. No `tenant_id` predicate was removed anywhere in this diff — every removal is `whereNull('deleted_at')`. |
| G3A-R3 truthful message | MAJOR | **CLOSED** | Both refusals lead with the token and promise only purge-or-choose: `ProductService.php:316-319`, `PartnerService.php:138-141`, and the four FormRequest `messages()` overrides. `PartnerVatLifetimeScopeTest:146` and `ProductUpsertKeyPrecedenceTest:145` both `assertStringNotContainsString('restore', …)`. Confirmed by grep that no `restore`/`forceDelete` route exists for Product or Partner, so the retained "purge the deleted record" half is still not an in-product path — but "choose a different SKU/VAT" is, and the text no longer claims "in the UI". Accepted; see r2-4. |
| G3A-R4 census completeness | MAJOR | **CLOSED** | Diffed the handoff census (`lane-g3a-summary.md:19-64`) line-by-line against Audit D (`docs/sessions/session-G-imports-hardening-2026-08-29/audit/D-sku-scope-census.md`). All 13 previously-omitted Audit-D hits are now present with the same classifications this gate reached independently at r1: `ImportType.php:182-192`, `MigrationWizardService.php:169-179,315-345`, `ReceiptCreationService.php:227`, `CreateSupplierInvoiceService.php:173`, `StandaloneReceiptService.php:275`, `pos/productRepository.ts:98-134`, `pos/resolveScannedCode.ts:94-125`, `pos/productApi.ts:30-39`, `PosVariantController.php:35-60`, `web/useBarcodeLookup.ts:56-100`, `web/useProductLineLookup.ts:77-99`, `web/ImportWizardPage.tsx:154-157`, `Product.php:84-90,186-195,345-357`, plus the zero-hit Scout/Meilisearch census (Audit D §2c). **No Audit-D hit is missing.** |
| G3A-R5 unrelated UpdateProductTest hunk | MINOR | **CLOSED** | `apps/api/tests/Feature/Product/UpdateProductTest.php` no longer appears in `git status --porcelain`; the file is clean at HEAD. |
| G3A-R6 / G3A-R7 / G3A-R8 | NOTE | **UNCHANGED** | The three migrations are byte-identical to r1: mtimes `13:28:10` / `13:29:38` / `13:36:37` versus fix-round source edits at `15:09:21`–`15:13:51`. Still census-then-refuse, forward-only, self-guarding (driver guard, table guard, per-column guard, `Schema::hasIndex` before add, `down()` = logged no-op). |

## New findings at r2

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| G3A-R2-1 | MAJOR | `apps/api/app/Modules/Product/Application/Services/ProductService.php:306-323` (with `:61-65,146-152`) | The new deleted-holder guard is keyed on the **matched row**, not on the **SKU actually being written**, and the barcode branch is therefore wrong in both directions. `upsert()` computes `$createSku = $fileSku ?? ($barcode ?? $nameSku)` (`:65`). The `fileSku` and `nameSku` branches happen to check that same value, but the `barcode` branch matches on `barcode` and then refuses using the trashed row's **SKU**. (a) **Over-refusal with a false token:** a trashed product with `sku='ABC', barcode='XYZ'` makes an incoming sku-less row with `barcode='XYZ'` fail with `sku_held_by_deleted_product: SKU ABC …` — but `products.barcode` has **no unique index at any scope** (Audit D §3), so nothing in the schema forbids that row; before this lane it created a product, now it is a row error naming a SKU the operator never supplied. (b) **Under-refusal:** if `barcode='XYZ'` matches nothing but a trashed product holds `sku='XYZ'`, no guard fires, `Product::create(['sku'=>'XYZ'])` hits the new lifetime `unique(company_id, sku)` and the operator gets a raw 23505 in `import_rows.import_error` instead of the coded token this lane exists to provide. No test covers either case. | Key the guard on the value being written: after computing `$createSku`, refuse iff `Product::onlyTrashed()->where(tenant,company)->where('sku',$createSku)->exists()`, and drop the per-branch `refuseSoftDeletedHolder()` on the barcode match (a trashed barcode twin is not a constraint violation). Add two import regressions: trashed `sku=ABC/barcode=XYZ` + incoming `barcode=XYZ` (must succeed), and trashed `sku=XYZ` + incoming `barcode=XYZ` (must yield the coded token, not 23505). |
| G3A-R2-2 | MAJOR | `.github/workflows/ci.yml:1081-1087`; `apps/api/tests/feature-lane-manifest.json` Catalog/Import entries | Four of the six new classes were added to the live PG `--filter` allowlist (`ProductSkuCompanyScopeMigrationTest`, `VariantSkuCompanyScopeMigrationTest`, `PartnerVatCompanyScopeMigrationTest`, `PartnerVatLifetimeScopeTest`), but **`ProductSkuCompanyScopeImportTest` and `VariantIndexScopeTest` appear ZERO times in `ci.yml`** and their Catalog / Import feature lanes are PARKED behind `vars.SELF_HOSTED_RUNNER_READY`. Those two are the lane's central proofs — the §1d sibling-company import reproduction and the RUL-2 variant sku/barcode scope + 422 mapping — and they execute in no live CI job. This is the exact reasoning the repo already recorded for `SpreadsheetParserDateCellTest` ("the feature lane is PARKED, so that filter is the only live gate that can run them") and for `PartnerVatLifetimeScopeTest` in this very fix round. The manifest notes are honest (they do not claim allowlist membership), so this is a coverage gap, not a false claim. | Add `ProductSkuCompanyScopeImportTest` and `VariantIndexScopeTest` to the same anchored PG allowlist and say so in their manifest notes, or record an explicit owner-visible reason why these two alone stay CI-dark. |
| G3A-R2-3 | MINOR | `apps/api/tests/Feature/Import/PartiesImportTypeTest.php:156-227` | The two ProductImages regressions (relative-key dispatch/purge, legacy-payload refusal) live in `PartiesImportTypeTest`, a class whose subject is the **parties** import type. They are correct and substantive tests — the legacy case is a genuine hand-built `unserialize()` of the pre-deployment 3-property payload (`:228-256`) — but the placement misattributes ProductImages coverage to the parties pins and makes the Import lane's manifest note inaccurate about what its 19 classes cover. | Move both into a ProductImages-named class (and re-do the Import lane arithmetic), or amend the Import manifest note to record that `PartiesImportTypeTest` now also owns the ProductImages dispatch/purge contract. |
| G3A-R2-4 | MINOR | `apps/api/app/Modules/Product/Application/Services/ProductService.php:317`; `apps/api/app/Modules/Partner/Application/Services/PartnerService.php:139` | "purge the deleted record" still names an action with no route: grep for `forceDelete` / `restore` across `app/Modules/Product` and `app/Modules/Partner` returns only `CategoryResolutionService.php:93,128` (an unrelated category merge). The message is truthful because its second half ("choose a different SKU/VAT") is actionable, which is why r1's G3A-R3 is closed — but half of every one of these refusals still points nowhere. | Track the purge/restore admin path as a named follow-up lane (G-4 or later) so this text stops being half-dead, and map the raw `RuntimeException` to a coded row-error channel at the same time. |
| G3A-R2-5 | MINOR | `apps/api/app/Modules/Partner/Application/Services/PartnerService.php:83-93,111-130` | Only the **deleted** VAT holder gets a coded refusal. `updateOrCreate` writes `'vat_number' => $vatNumber` unconditionally (`:121`), so a row that matches by **code** while a **live** sibling partner in the same company owns that VAT still ends in a raw 23505 under the new lifetime `unique(company_id, vat_number)`. Pre-existing shape, not introduced here, but now asymmetric with the case the lane did code. | Deferred-channel note; when the coded row-error channel lands, cover the live-holder collision with the same token family. |
| G3A-R2-6 | MINOR | `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:317-327`; `UpdateProductRequest.php:296-306`; `CreatePartnerRequest.php:153-163`; `UpdatePartnerRequest.php:187-197` | The same 11-line `onlyTrashed()->exists()` block is copy-pasted into four `messages()` overrides. `messages()` runs on **every** create/update request, so each one now issues an extra `requireCompany()` + `onlyTrashed()` query whether or not the unique rule fails. | Extract to one shared trait/helper (`deletedHolderMessage()`), and consider deferring the query behind the failure by using a custom `Rule` object instead of `messages()`. |
| G3A-R2-7 | NOTE | `apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:59,89` | `$this->zipPath` is now dead inside `handle()` — the persisted `$job->file_path` is authoritative. The property is still required (payload back-compat + the dispatch assertion), which is the right call, but nothing in the file says so. Separately, `import_jobs` carries **no `company_id` column** (grep: none on `ImportJob`), so the worker cannot cross-check that the payload company matches the job's originating company; the payload is server-authored, so this is defence-in-depth only. | Add a one-line comment on `zipPath` recording that it is retained for payload compatibility and asserted at dispatch, not read in `handle()`. `import_jobs.company_id` is a G-6a/history-lane concern. |

## Judgements requested by the dispatch

**Precedence — deleted-VAT-holder check BEFORE code matching (`PartnerService.php:72-93`): CORRECT.** `upsertWithTypeMerge` builds `$vatHolder` (and refuses on trashed) unconditionally before the `match(true)` that prefers `code`. A row with a matching **code** whose VAT is held by a **deleted other** partner must refuse, not update by code, because `Partner::updateOrCreate` writes `'vat_number' => $vatNumber` into the code-matched row (`:121`) and M3 installs a **lifetime** `unique(company_id, vat_number)` that counts soft-deleted rows (`…100200….php:73-81` excludes only NULL VAT). Updating by code would therefore hit a raw 23505 immediately after. Refusing early converts that raw driver error into the coded `vat_held_by_deleted_partner:` token, which is strictly better. The code-matched row is always live (default scope), so it can never be the deleted holder itself — no self-collision false positive. `PartnerVatLifetimeScopeTest:118-147` pins exactly this shape (row carries `code=IMPORT-CODE` + the held VAT, and errors).

**Legacy absolute-payload behaviour: acceptable.** A pre-deployment payload deserialises with `companyId === null` (typed property with a `null` default, so `unserialize` of the 3-property object leaves it initialised), throws `company_context_missing:` **before** the `status => Importing` write, lands the job on `Failed` with `started_at` still null, and purges the ZIP via the persisted relative `file_path`. `PartiesImportTypeTest::test_legacy_product_images_job_fails_before_importing_and_purges_persisted_relative_key` asserts every one of those five facts. The operator cost is one re-upload, and the message says exactly that. **Per the dispatch brief there are no in-flight queued ProductImages jobs on staging at deploy time; this gate has no staging access and did not independently verify that** — if a ProductImages ZIP were queued across the deploy it would fail-and-purge rather than process.

**Tenancy / cross-tenant leak: clean.** Every new or modified query carries `tenant_id` AND `company_id`: `ProductService.php:41-44,298-310`, `PartnerService.php:30-36,72-80,84-92`, `ProductImageImportService.php:238-241`, and the four FormRequest unique rules. **No `tenant_id` predicate is removed anywhere in this diff** — the only removals are `whereNull('deleted_at')`, which is the intended lifetime change. Under database-per-tenant those `tenant_id` predicates remain belt-and-braces; the meaningful new isolation is the `company_id` predicate added to `ProductImageImportService` (r1's confirmed accidental cross-company image attach, Audit D class **(c)**), now threaded from the request through the job payload rather than from `CompanyContext` — correct for a queue worker under rule 20, and pinned by `ProductImageImportServiceMediaTest:148-182`, which calls `app(CompanyContext::class)->clear()` before asserting the sibling-company product is chosen.

## Commands and fresh outputs (r2)

No full suite was run.

| Command | Fresh result |
|---|---|
| `./vendor/bin/phpunit` on the 6 new classes by path (SQLite) | **20 tests: 12 passed, 8 expected PG-only skipped, 76 assertions, 15.2s.** |
| `./vendor/bin/phpunit` on the 8 touched existing classes by path (SQLite) — LabelBarcodeCollision, VariantBarcodeRace, PartiesImportType, ScheduledJobTenantIsolation, ProductImageImportServiceMedia, CreateProduct, ProductUpsertKeyPrecedence, UpdateProduct | **60 tests: 58 passed, 2 expected PG-only skipped, 198 assertions, 46.9s.** |
| `php artisan test -c phpunit-pgsql.xml --filter='/\\(ProductSkuCompanyScopeMigrationTest\|VariantSkuCompanyScopeMigrationTest\|PartnerVatCompanyScopeMigrationTest\|VariantIndexScopeTest\|ProductSkuCompanyScopeImportTest\|PartnerVatLifetimeScopeTest)::/'` (last name adjusted from the brief's `PartnerVatCompanyScopeTest` to the class that exists) | **19 passed, 1 expected SQLite-only skip, 108 assertions, 39.05s.** Migration census lines observed live: M1 `0` then `1` group, M2 `0` then `1`, M3 `0` then `1` — refusal path genuinely exercised on PG. |
| `./vendor/bin/phpstan analyse` on all 29 dirty PHP paths | **`[OK] No errors.`** |
| `./vendor/bin/pint --test` | **`{"result":"pass"}`.** |
| `php tools/feature-lane-manifest-check.php` | **OK** — 1,462 Feature classes in 74 groups, every group has a disposition, every declared lane present in `ci.yml`, every `--filter` entry anchored and uniquely matched across 1,862 test classes. Standing warnings only (70 parked groups / 1,203 classes; 1 coverage-debt group). |
| Manifest arithmetic | **correct:** gated `1197 → 1203` (+6) = Migrations `7→10` (+3), Catalog `33→34` (+1), Import `18→19` (+1), Partner `21→22` (+1). Six new classes, six ceiling seats. Recompute the union if G-7 lands first (handoff records dev G-7 at Import 23 / gated 1,202). |
| CI YAML parse + occurrence count | **`YAML OK`** (Ruby/Psych on `.github/workflows/ci.yml`). Occurrences of the six new class names in `ci.yml`: `PartnerVatLifetimeScopeTest` **2** (manifest note + PG allowlist), the three migration tests **1** each, `VariantIndexScopeTest` **0**, `ProductSkuCompanyScopeImportTest` **0** → G3A-R2-2. |
| `php vendor/bin/deptrac analyse` | **Violations 183 (unchanged baseline), skipped 0, warnings 0, errors 0.** No touched class appears as a new edge. |
| Migration immutability since r1 | **confirmed:** migration mtimes `Aug 29 13:28:10 / 13:29:38 / 13:36:37`; fix-round source edits `15:09:21` (PartnerService) and `15:13:51` (ProcessProductImageImport). |

## Staging safety (r2, three migrations under the F-BUG-1 data shape)

The r1 analysis stands unchanged because the three migrations are byte-identical. Under F-BUG-1 (second-company import attempts failing row-by-row on the old tenant-wide product SKU unique), each row was wrapped in its own transaction and rolled back (`ImportService.php:351-365`, `ProcessImportJob.php:159-174`), so those failures left no duplicate `products` rows, and the code-less default-location defect affects stock placement, not any census tuple. On an intact tenant, none of M1/M2/M3 can find a `(company_id, …)` collision that the strictly stronger `(tenant_id, …)` unique already permitted.

What r2 **adds**: r1's BLOCKER G3A-R2 was precisely that M3 would auto-migrate staging while `PartnerService` and both VAT FormRequests still validated `tenant_id + deleted_at IS NULL`. **That window is now closed in the same tree** — schema and consumers move together, so G-3a is promotable independently without waiting for G-4.

Unchanged residual risk, and still not fleet-verified by this gate: any of the three migrations will **deliberately abort one tenant** if its old constraint/index drifted, data was manually damaged, or tenant/company IDs are internally inconsistent. Refusal happens before any drop and logs every collision group, which is the safe failure mode, but `origin/dev` auto-migrates every staging tenant — **a per-tenant census across the fleet remains a mandatory pre-promotion ops step.** The implementer reports a local PG census of zero groups on all three tables; this gate reproduced zero-group and one-group runs locally on both drivers but queried no staging tenant.

## VERDICT (r2)

**CHANGES** — all five r1 items are genuinely closed with real regressions behind them; two new items block promotion.

1. **G3A-R2-1 (MAJOR)** — re-key the deleted-holder guard on `$createSku` instead of the matched row; the barcode branch currently refuses rows no constraint forbids (naming a SKU the operator never supplied) and misses the trashed row that actually collides with the SKU being written. Add both import regressions.
2. **G3A-R2-2 (MAJOR)** — `ProductSkuCompanyScopeImportTest` and `VariantIndexScopeTest` run in no live CI lane; add them to the anchored PG allowlist alongside their four siblings, or record why they alone stay dark.

Minors R2-3..R2-7 may ride along or be filed. Everything else — the relative-key ProductImages contract, partner VAT lifetime/company alignment, truthful coded tokens, the completed Audit-D census, the reverted `UpdateProductTest` hunk, the three unchanged census-first migrations, manifest arithmetic, PHPStan, Pint, deptrac baseline, and both driver legs — passes this gate.

---

# Gate r3 — final re-check after fix round 2

**Lane:** G-3a, branch `feat/g3a-company-scoped-sku`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g3a-sku-scope` (HEAD `a33b01354`, still DIRTY/uncommitted)
**Review lens:** imports-reviewer
**Posture:** source read-only; only this register was appended. The one file this gate authored is a throwaway red-proof test written OUTSIDE the worktree (`scratchpad/GateR3RedProofTest.php`, run by absolute path) — the worktree was never modified. Inventory: **31 dirty entries — 19 tracked modifications + 12 untracked files**, byte-identical set to r2. `tests/Feature/Product/UpdateProductTest.php` is still absent from `git status` (r1 G3A-R5 stays closed).

## r2 register disposition

| ID | r2 severity | r3 status | evidence |
|---|---|---|---|
| G3A-R2-1 deleted-holder guard keyed on the matched row | MAJOR | **CLOSED** | `ProductService.php:71-88` — the guard now runs **after** `$createSku = $fileSku ?? ($barcode ?? $nameSku)` (`:69`) and is keyed on that value: `if ($existing === null) { $this->refuseIfSkuHeldByDeletedProduct($tenantId, $companyId, (string) $createSku); }` (`:86-88`). The helper (`:350-361`) is `Product::onlyTrashed()->where('tenant_id',…)->where('company_id',…)->where('sku',$sku)->exists()` — company-scoped and trashed-only, as required. `findExistingProduct()` (`:326-343`) now opens with `Product::query()` (default SoftDeletes scope ⇒ **live rows only**), so the barcode branch can no longer match a trashed row and can no longer refuse; the per-branch `refuseSoftDeletedHolder()` calls are gone from the ladder. `findIdBySku()` (`:36-54`) keeps `withTrashed()` + matched-row refusal, and the code states why in-line at `:46-49` ("the lookup key IS the SKU here … unlike the upsert key ladder"). |
| G3A-R2-2 two classes absent from the live PG filter | MAJOR | **CLOSED** | `.github/workflows/ci.yml:1081-1098` — the anchored allowlist now ends `…\|SpreadsheetParserDateCellTest\|ProductSkuCompanyScopeMigrationTest\|VariantSkuCompanyScopeMigrationTest\|PartnerVatCompanyScopeMigrationTest\|PartnerVatLifetimeScopeTest\|ProductSkuCompanyScopeImportTest\|VariantIndexScopeTest)::/`. Occurrence counts in `ci.yml`: `ProductSkuCompanyScopeImportTest` **2**, `VariantIndexScopeTest` **2** (why-comment + filter), three migration tests **1**, `PartnerVatLifetimeScopeTest` **2**. Both manifest notes now record allowlist membership explicitly (Catalog 33→34 and Import 18→19 entries). |
| G3A-R2-3 / R2-4 / R2-5 / R2-6 / R2-7 | MINOR / NOTE | **UNCHANGED, correctly deferred** | Both ProductImages regressions still live in `PartiesImportTypeTest.php:157,194` (R2-3). `grep -rn "forceDelete\|->restore("` over `app/Modules/Product` + `app/Modules/Partner` still returns only `CategoryResolutionService.php:93,128` — "purge the deleted record" still names no route (R2-4). No change to the live-VAT-holder asymmetry (R2-5), the four duplicated `messages()` blocks (R2-6), or the `zipPath` comment (R2-7). |

## "update leg never changes sku in the service" — JUDGED TRUE

Verified, not taken on trust. Every `sku` occurrence in `ProductService.php` is `:44` (findIdBySku predicate), `:68` (`$fileSku` read), **`:173` `'sku' => $createSku` — inside `Product::create()` only**, `:335`/`:342` (ladder predicates), `:355` (guard predicate). The `$attributes` array built at `:90-98` and mutated at `:100-160` never gains a `sku` key, and the update branch is `$existing->fill($attributes); $existing->save();` (`:161-166`). So no service path can move a live product onto a held SKU, and skipping the guard on the update branch is sound. The API update path is independently fenced: `UpdateProductRequest.php:163-170` is `Rule::unique('products','sku')->where('tenant_id',…)->where('company_id',$company->id)->ignore($productId)` with **no** `whereNull('deleted_at')` — lifetime, company-scoped, ignore retained; `CreateProductRequest.php:178-184` matches without the ignore.

## Red-first meaningfulness of the three new tests

| Test | Assessment |
|---|---|
| `test_a_trashed_barcode_twin_holding_a_different_sku_does_not_block_the_row` (`ProductUpsertKeyPrecedenceTest:139-163`) | **Genuinely red under the r2 code.** Under r2 the ladder ran `withTrashed()`, matched the trashed twin by barcode and refused naming `TRASHED-ABC`. The test asserts a *new* product is created (`assertNotSame($twin->id, …)`), that its SKU is `XYZ-BARCODE`, and that the twin stays trashed. |
| `test_a_barcode_derived_sku_held_by_a_trashed_product_is_refused_with_the_coded_token` (`:171-201`) | **Genuinely red, and the `assertNotInstanceOf(QueryException::class, …)` is load-bearing.** `Illuminate\Database\QueryException` extends `PDOException` extends `RuntimeException`, so the `catch (RuntimeException)` would happily swallow a raw 23505 — the `assertNotInstanceOf` is the only thing separating "coded token" from "driver error". This gate proved the underlying failure mode empirically rather than by argument: a throwaway test run by absolute path from the scratchpad (trashed holder `sku=XYZ-BARCODE`, then `Product::create(['sku'=>'XYZ-BARCODE'])`) **passed `expectException(QueryException::class)` on SQLite**, i.e. M1's lifetime `unique(company_id, sku)` really does fire on the SQLite leg (migration log during every run: `[G-3A M1] products unique scope: (company_id, sku).`). Without the `$createSku` guard this test is red for exactly the reason claimed. |
| `test_a_name_derived_sku_held_by_a_trashed_product_is_refused_with_the_coded_token` (`:207-232`) | **Meaningful but NOT red-first** — under the r2 matched-row guard the name branch matched the trashed row whose SKU *is* `$createSku`, so it would have passed there too. It is a correct non-regression pin for the live-only ladder (which removed the old refusal route for this case), and the fix note is honest: it claims red-first for "(a) and (b)" only. No finding. |

Both refusal tests also assert `assertSame(0, Product::query()->where('company_id',…)->count())` — no partial row survives the refusal.

## Commands and fresh outputs (r3)

No full suite was run.

| Command | Fresh result |
|---|---|
| `./vendor/bin/phpunit` by path: ProductUpsertKeyPrecedence, ProductSkuCompanyScopeImport, VariantIndexScope, CreateProduct, UpdateProduct + the three Migration tests (SQLite) | **59 tests: 51 passed, 8 expected PG-only skipped, 195 assertions, 36.3s.** Matches the fix note exactly. Census lines observed live: M1 `0` then `1` group, M3 `0` then `1`, M2 `SKIPPED: SQLite has no variant partial indexes`. |
| `php artisan test -c phpunit-pgsql.xml` by path: 3 migrations + VariantIndexScope + ProductSkuCompanyScopeImport + ProductUpsertKeyPrecedence | **24 passed, 1 expected SQLite-only skip, 122 assertions, 41.8s.** All three new upsert tests green on PG. |
| Independent red-proof (scratchpad file, absolute path, worktree untouched) | **PASS** — `expectException(QueryException::class)` satisfied: inserting a SKU held by a trashed same-company row raises SQLSTATE 23000 on SQLite. Confirms the guard prevents a real raw driver error, not a hypothetical one. |
| `./vendor/bin/phpstan analyse` on all 29 dirty PHP paths (via `xargs`) | **`[OK] No errors.`** |
| `./vendor/bin/pint --test` on the same 29 paths | **`{"result":"pass"}`.** |
| `php tools/feature-lane-manifest-check.php` | **OK** — 1,462 Feature classes in 74 groups; every group has a disposition; every declared lane present in `ci.yml`; every `--filter` entry anchored and uniquely matched across 1,862 test classes. Standing warnings only (70 parked groups / 1,203 classes; 1 coverage-debt group). |
| `ci.yml` YAML parse | **YAML OK** (`python3 -c "yaml.safe_load(...)"`, 26 jobs). (System Ruby 2.6's Psych lacks `unsafe_load_file`/`aliases:`, so Python was used.) |
| `ci.yml` filter regex parseability | **parses and discriminates.** Extracted the 5,250-char pattern with `preg_match` and ran it: matches `Tests\Feature\Import\ProductSkuCompanyScopeImportTest::test_x` (**1**) and `Tests\Feature\Catalog\VariantIndexScopeTest::test_y` (**1**), does **not** match `Tests\Feature\Product\CreateProductTest::test_z` (**0**); `preg_last_error_msg()` = `No error`. |
| Manifest JSON parse + arithmetic | **parses**; `gated_ceiling` `1197 → 1203` (+6) = Migrations `7→10`, Catalog `33→34`, Import `18→19`, Partner `21→22`; Product stays `58`. Six new classes, six seats. Union with a G-7 that lands first must still be recomputed. |
| Inherited-red check — MAIN checkout `/Users/houssamr/Projects/syneriva/apps/erp/apps/api`, branch `dev`, read-only | **CONFIRMED INHERITED.** The class is `tests/Unit/Product/ProductServiceUpsertTest.php` (Unit, not Feature — the fix note's path is imprecise). On `dev`: `Tests\Unit\Product\ProductServiceUpsertTest::test_upsert_ignores_nonexistent_category_name` → `Failed asserting that 1 is null.` at `:157`. In the lane worktree: **byte-identical failure, same line.** Not this lane's. |
| `git status --short` | **31 entries, all lane files** — 19 tracked modifications (ci.yml, manifest, 10 app PHP files, 7 test files) + 12 untracked (3 index-name value objects, 3 migrations, 6 test classes). No stray file, no unrelated hunk. |

## New findings at r3

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| G3A-R3-1 | MINOR (recommended before merge — one line) | `.github/workflows/ci.yml:1081-1098`; `apps/api/tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php:139-232`; `apps/api/tests/Feature/Product/CreateProductTest.php:156,209` | **The proofs of the R2-1 fix itself run in no live CI job.** `ProductUpsertKeyPrecedenceTest` appears **0 times** in `ci.yml`; its group is `feature-lane-catalog/Product`, and `feature-lane-catalog` is listed in `ALLOW_SKIPPED_JOBS` (`ci.yml:2713`) ⇒ parked. `backend-test` does **not** rescue it: that job runs `php artisan test --testsuite=Unit` plus `tests/PHPStan` and two architecture ratchets — **no Feature suite** (`ci.yml:420,423,468`). So the three fix-round-2 regressions, and the two `CreateProductTest` deleted-holder cases from fix round 1, execute nowhere. This is the *identical* reasoning the lane accepted for G3A-R2-2. Distinction that keeps it MINOR rather than MAJOR: `ProductUpsertKeyPrecedenceTest` pre-existed this lane and was already dark, so the lane did not regress the coverage baseline — it added its central proof to an already-dark class. | Append `ProductUpsertKeyPrecedenceTest` (and, if cheap, `CreateProductTest`) to the same anchored PG allowlist and note it in the Product manifest entry. Purely additive, no ceiling change (Product stays 58). |
| G3A-R3-2 | NOTE | `apps/api/app/Modules/Product/Application/Services/ProductService.php:36-54`; `apps/api/app/Shared/Contracts/ProductServiceInterface.php:21` | `findIdBySku()` has **zero production callers** — repo-wide `grep -rn findIdBySku apps/api --include='*.php'` (vendor excluded) returns only the interface, the implementation, and `ProductUpsertKeyPrecedenceTest:269-270`. Its new throwing behaviour is therefore currently unreachable from any import or opening-stock path (`ImportService.php:551` goes through `upsert()`). The added refusal is correct and future-proof, not wrong — but no shipped flow exercises it, so it cannot be cited as import-path coverage. | None required. If a later lane wires opening-stock SKU linkage back through `findIdBySku`, re-verify that a `RuntimeException` there is caught per-row (`ImportService.php` per-row transaction) and not batch-fatal. |
| G3A-R3-3 | NOTE | `apps/api/app/Modules/Product/Application/Services/ProductService.php:326-343` | Behaviour change worth recording for the merge note: because the ladder is now **live-only**, a re-import whose `sku` matches a **soft-deleted** product no longer silently resurrects/mutates that row (r2 behaviour) — it now refuses with `sku_held_by_deleted_product`. That is the intended and safer outcome (a trashed row is not an updatable target), and it is pinned by `test_upsert_refuses_a_sku_held_by_a_soft_deleted_product` (`:125-137`), but it is an operator-visible change for tenants that soft-delete and re-import. | None. Mention in the merge/deploy note so support recognises the token. |

## Import-lens spot checks (unchanged by this fix round, re-confirmed)

- **Idempotency / no double-post:** the fix touches only product identity resolution. The per-row transaction + rollback shape (`ImportService.php:351-365`, `ProcessImportJob.php:159-174`) is untouched, so a refusal is a *row* error, never a batch abort, and leaves no partial product (asserted by both refusal tests' `count() === 0`).
- **No money/quantity float introduced:** `git diff` on `ProductService.php` adds no numeric handling; the only arithmetic in the file remains the `bccomp($configuredRate, $taxRate, 2)` tax-configuration coherence check (`:294`), which is a percent field and correctly not currency-scaled.
- **Silently dropped rows:** none — every new refusal path throws a coded token that the row-error channel records; nothing returns early with a success id.

## VERDICT (r3)

**PASS**

Both r2 MAJORs are genuinely closed, with the fix verified in code rather than from the fix note: the deleted-holder guard is re-keyed onto `$createSku` with a company-scoped `onlyTrashed()` existence check on the create branch only, the ladder is live-only so barcode-matched rows no longer refuse, `findIdBySku`'s matched-row check is justified in-line, the "update leg never writes `sku`" claim is verified true at the source level and independently fenced by `UpdateProductRequest`'s lifetime unique, and both previously-dark classes are now in the anchored PG allowlist with matching manifest notes. Two of the three new tests are provably red-first (the third is an honest non-regression pin), and the `assertNotInstanceOf(QueryException)` assertion was validated against a real 23505 reproduced by this gate. SQLite 51/8-skip/195, PG 24/1-skip/122, PHPStan 0 errors, Pint pass, manifest checker OK, YAML valid, filter regex parses and discriminates, `git status` shows only lane files, and the one inherited-red (`ProductServiceUpsertTest::test_upsert_ignores_nonexistent_category_name`, a **Unit** test) fails identically on `dev`.

Carry into merge: **G3A-R3-1** (one-line `ci.yml` allowlist addition for `ProductUpsertKeyPrecedenceTest`, so the R2-1 proof is not CI-dark) and the still-open r2 minors R2-3..R2-7. Unchanged operational precondition, not waived by this gate: **`origin/dev` auto-migrates every staging tenant — run the per-tenant `(company_id, sku)` / live `(company_id, sku)` / non-null `(company_id, vat_number)` census across the fleet before promotion.**
