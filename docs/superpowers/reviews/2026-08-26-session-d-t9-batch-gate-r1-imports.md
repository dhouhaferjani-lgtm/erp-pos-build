# Session D — Task 9 onboarding-hour batch — adversarial gate r1 (imports / opening / provisioning lens)

**Reviewer:** imports-reviewer (adversarial, code-grounded, read-only)
**Branch:** `fix/session-d-onboarding-hour-batch` · base `c94d23043` → head `6a7adfa81` (6 commits)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t9-onboarding-hour-batch`
**Items in scope:** (a) W4R-1 lock idempotency · (b) W2-5 category→tax + `default_tax_configuration_id` · (c) N-9 UomSeeder + backfill migration · (d) N-14 lineless draft / R-7 inversion · (f) FE minors.
**Out of scope (other reviewer):** (e) C-13(i) `pos_pin`, route/permission gating beyond the auto-save route.
**Ruling honoured:** N-14 residual R-2 (defer allocation to confirm) is a follow-on lane, not judged here.

## VERDICT

**spec ❌ (item b) · quality CHANGES-REQUESTED**

Items (a), (c), (d) and (f) verify clean and are merge-ready on their own. Item (b) introduces a
NEW, executable N-1 (product priced at one VAT rate, sold at another) on the re-import path: the
coherence guard the change is built around is enforced only when the product has no configuration,
so a re-import that changes the rate leaves the previously inherited configuration standing beside
a rate it disagrees with. Proven with a run probe, not argued.

---

## What I actually ran

| Command | Result |
|---|---|
| `phpunit tests/Feature/Import/ProductsImportPipelineTest.php --filter test_imported_products_inherit_the_category_rate…` | OK (1 test, 8 assertions) |
| `phpunit tests/Feature/Accounting/OpeningBalanceBatchLifecycleHardeningTest.php` | OK 15 tests / 44 assertions / 3 PG-only skips |
| `phpunit tests/Feature/Document/AutoSaveRouteHardeningTest.php` | OK 35 tests / 148 assertions |
| `phpunit tests/Feature/Uom/SeedBaseUnitsForUnitLessTenantsMigrationTest.php tests/Feature/Tenant/TenantReferenceDataSeedingTest.php` | OK 7 tests / 46 assertions |
| `pint --test` (6 touched PHP files) | pass |
| `phpstan analyse` (6 touched PHP files, level 8) | No errors |
| **Adversarial probe** (scratchpad, `ProductService::upsert` twice with a changed rate) | **FAILS — mismatch reproduced (see F-1)** |

Note: the PHPUnit runs used the SQLite runner (3 `requiresPostgres` skips). Nothing in this batch
depends on a PostgreSQL-only aggregate, so no SQLite-masking risk was identified.

---

## BLOCKING

### [CRITICAL] F-1 — the tax-coherence guard is one-sided: a re-import seals a stale configuration next to a changed rate

`apps/api/app/Modules/Product/Application/Services/ProductService.php:122-133`

```php
if ($existing === null || $existing->default_tax_configuration_id === null) {
    $inherited = $this->resolveDefaultTaxConfigurationId(...);
```

The guard whose docblock (`:154-166`) states the invariant — *"Storing a configuration next to a
rate it disagrees with is exactly defect N-1"* — never runs when the product ALREADY carries a
configuration. But `tax_rate` is rewritten unconditionally on that same path
(`:72` builds it into `$attributes`, `:135-137` `fill()` + `save()`), and on the import path
`$attributes['tax_rate']` is never null because `ImportService::importProduct()` pre-sets it
(`apps/api/app/Modules/Import/Services/ImportService.php:534-537`). So the rate moves and the
configuration does not.

Executed probe (`ProductService::upsert` twice, TN company, category `Medicaments` with
`default_tax_rate 7.00` + `default_tax_configuration_id` = TVA 7 %, company default = TVA 19 %):

```
AFTER 1st: rate=7.00  cfg=<TVA 7 id>
AFTER 2nd: rate=19.00 cfg=<TVA 7 id>     <-- unchanged
MISMATCH? product.tax_rate=19.00 vs config.percentage_rate=7.00
```

Why it matters, precisely: `apps/api/app/Modules/Document/Application/Services/DocumentLineTaxResolver.php:75-76`
prefers `product.default_tax_configuration_id` over the product's rate, while the POS seals
`products.tax_rate` into the receipt hash chain. The same product then taxes at **7 % on a document
line and 19 % at the till** — the exact shape of N-1.

It is a REGRESSION, not an inherited condition: before this commit the import path never wrote
`default_tax_configuration_id` (the report says so at §b root cause 2), so the column stayed NULL,
the document resolver fell through to `tax_rate`, and the two layers agreed. This change is what
puts a configuration there to go stale.

Reachable without any UI step: the operator corrects the category's `default_tax_rate` between two
runs of the same products file — the ordinary onboarding loop — or adds a `tax_rate` column to the
second file.

**Fix:** evaluate coherence on EVERY write, not only on the first. Either (i) when the existing
configuration's percentage disagrees with the rate being written, null it — the docblock's own
"honest answer"; or (ii) derive the rate FROM the retained configuration, the way
`ProductController::applyTaxRateFromConfiguration()` (`Product/Presentation/Controllers/ProductController.php:1139-1163`)
already does for the API path. Silence is the one option that is not available.

---

## IMPORTANT

### [IMPORTANT] F-2 — the rate ladder ignores a category that states its tax as a CONFIGURATION

`apps/api/app/Modules/Taxation/Domain/Services/TaxResolutionService.php:80-94` reads only
`categories.default_tax_rate`. `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:132-136,:175`
lets a category be stored with `default_tax_configuration_id` set and `default_tax_rate` NULL —
the two columns have no coherence sync (unlike products, which got one for N-1).

For such a "TVA 7 %" category the import now resolves: rate = COMPANY 19 % (category skipped), then
the candidate ladder in `ProductService.php:189-211` tries the category configuration (7 %, rejected
on mismatch) and falls through to the COMPANY configuration (19 %, accepted). The product lands at
19 % **with a confident 19 % selector**, in a category the operator marked 7 %. Before this diff the
selector was blank — a question rather than a wrong answer.

**Fix:** in the default ladder, treat `category.default_tax_configuration_id` as a rate source
(via `resolveRateFromTaxConfiguration`) ranked above the company default; or refuse to inherit the
company configuration when the row's category names a different one.

### [IMPORTANT] F-3 — the load-bearing half of item (b) has no test

`apps/api/tests/Feature/Import/ProductsImportPipelineTest.php:334-406` covers only the two cases
where everything agrees (category 7/7, company 19/19). Nothing pins:

1. a category rate that agrees with NO available configuration → the id must stay **NULL** (the
   stated coherence guard — currently unexercised in any direction);
2. **re-import idempotency** for the configuration — the report claims "a re-import must not clobber
   an operator's explicit choice" and no test asserts it;
3. the F-1 rate-change re-import.

A guard with no failing-direction test is a comment. (2) and (3) are the two an import lane must
always carry.

---

## MINOR

### [MINOR] F-4 — `tax_source` now conflates category and company defaults
`apps/api/app/Modules/Import/Services/ImportService.php:534-537` still records
`'tax_source' => 'default'` when the rate came from the CATEGORY. Grep shows the key is written and
never read (single hit repo-wide), so there is no result-workbook fidelity defect today — but it is
the only breadcrumb the row keeps about where its rate came from, and it is now wrong for the case
the commit exists to fix.

### [MINOR] F-5 — a lineless auto-save still stamps a "saved at" time
`apps/web/src/hooks/useDraftAutoSave.ts:171-177` — on the new `draft_id: null` answer the hook still
runs `setLastSavedAt(new Date(body.saved_at))`, so the editor can show a save timestamp for a call
that authored nothing. `onSuccess` is correctly suppressed (`:179-181`); this is honesty of the
indicator only.

### [MINOR] F-6 — `is_numeric` admits values bcmath rejects
`apps/api/app/Modules/Product/Application/Services/ProductService.php:174` gates on `is_numeric`,
which passes exponent forms (`1e2`); `bccomp` at `:208` throws `ValueError` on those. Not reachable
through the current products FormRequests, but this method sits on the queued import path where the
value arrives from a spreadsheet cell.

---

## Verified clean (adversarial notes, so the next gate does not redo them)

**(a) W4R-1 — the early return is safe and does not mask a genuine double-lock.**
`apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:438-440` returns
BEFORE `calculateBatchHash()` (`:449`) and before the previous-locked-batch lookup (`:452-456`), so a
re-lock cannot recompute `hash` or re-point `previous_hash`. The concurrency guard survives intact:
the early return reads the IN-MEMORY status, so a caller holding a stale `VALIDATED` model against a
row that is already `LOCKED` still falls through to the conditional claim (`:463-479`) and throws
"Refusing to re-seal the hash chain". The endpoint re-reads the batch before calling
(`Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:257`), so no stale 200. The
old throw was never the double-POST guard — that is `lockBatchForPosting()` (`:133-151`, requires
DRAFT) plus the partial unique index — so removing it opens no re-post path. Byte-identity of the
sealed row is pinned by `OpeningBalanceBatchLifecycleHardeningTest.php:501-523`, DRAFT still 422s
(`:525-547`), and the AR/AP real seal is pinned (`:549-576`). Ran green.

**(c) N-9 — both guards correct; no double-seed, no `unit_categories.code` collision.**
`TenantInitializationService.php:261-266` demands BOTH tables empty; `UomSeeder` writes with bare
`create()` (`database/seeders/UomSeeder.php:16,24,78,…`), so all-or-nothing is the right shape. The
demo seeders already carry the equivalent guard (`CoffeeShopSeeder.php:131`,
`ParapharmacySeeder.php:346` — `unit_categories.count() === 0`), so a demo tenant does not collide.
The migration's `companies` guard is correct on the ordering that matters:
`initializeForNewRegistration()` runs AFTER the company row exists (`TenantInitializationService.php:59-83`)
and the tenant DB is migrated before that, so a new tenant is skipped by the migration and served by
provisioning; an existing tenant has a company and is backfilled. There is no company-bearing path
that skips initialization — `TenantProvisioningService.php:197` is the only creator, and
`CreateTenantCommand.php:141-166` refuses under db-per-tenant. `UomSeeder`/`UnitSeeder` never touch
`$this->command`, so invoking them outside `artisan db:seed` is safe (confirmed by the green
provisioning test). Migration `down()` no-op is justified (`products.unit_id`). 4 migration tests +
tenant seeding green.
Residual risk, accepted: a tenant left half-provisioned (DB migrated, no company, initialization
never completed) is skipped by both paths forever — but such a tenant is unusable for other reasons.

**(d) N-14 — no NULL-`document_number` draft is ever created, so the consumer question is moot.**
`DraftPersistenceService::saveDraft()` returns null only on the CREATE branch (`:115-121`);
`createNewDraft()` still allocates the number unconditionally (`:240-244`) and is now only reached
with a line, so every persisted draft still carries a number — list pages, resume and delete are
untouched. `DraftController::autoSave()` answers 200 `{draft_id: null, line_count: 0}` (`:181-193`)
and is the only caller. Client-side, `/documents/auto-save` has exactly one consumer
(`apps/web/src/hooks/useDraftAutoSave.ts:163`) — no POS, no mobile, no OpenAPI schema to drift. The
route gate is real and pre-existing: `apps/api/app/Modules/Document/Presentation/routes.php:75-77`
(`can:documents.update`) plus the per-type `*.create` check in `AutoSaveDraftRequest::authorize()`.
The **R-7 inversion is legitimate**: the lineless half now asserts the new behaviour and the
with-a-line half stays characterised in the same test
(`tests/Feature/Document/AutoSaveRouteHardeningTest.php:1229-1256`), which is what the residuals
ticket asks of a characterisation a later lane fixes. 35/35 green.
**Ledger note (not a finding):** this does not close the campaign symptom. The FE debounce already
refused lineless payloads (`useDraftAutoSave.ts:233-235`), so the observed `PO-2026-0001…0009`
orphans were one-line-then-abandoned drafts, which still hold their numbers. That is R-2, ruled a
follow-on lane; recording it so the register does not read N-14 as closed.

**(f) FE minors.** `getErrorMessage` is imported and now matches its two siblings
(`apps/web/src/features/inventory-counting/api/queries.ts:9,133-135,203,258`). The `is_proforma`
docblock corrections (`apps/web/src/types/document.ts:98-106`,
`apps/web/src/types/creditNote.ts:87-94`) match the consumers and name the unfixed
`components/CreditNoteDetail.tsx` residual honestly. `DocumentTotals.tsx:107-119` is comment-only.

**Precision (rule 19).** No float touches money/quantity anywhere in the diff. `ProductService`
compares percentages with `bccomp(..., 2)` on strings with a `precision-ok` annotation; no
`(float)`, no `number_format`, no `parseFloat`. Percent is correctly kept off the currency scale.
No no-arg `getScale()` was introduced on a queued path.

---

## What to fix before merge

Make the tax-configuration coherence check run on EVERY product write (F-1) — clear or re-derive a
configuration that disagrees with the rate being written — and add the three missing import tests
(F-3: mismatch → NULL, re-import must not clobber, re-import rate change). F-2 can follow in the
same lane or be ticketed. Items (a), (c), (d), (f) are approved as-is.
