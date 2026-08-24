# W2-3 imports gate r1 — products import `category_name` create-on-miss

Lane `fix/campaign-w23-import-category` · worktree `.worktrees/w23-import-category` · HEAD `2352b3c2b`
Diff reviewed: `git diff dev...2352b3c2b` (8 files, +512/-8).
Reviewer lens: imports (unified two-file import, opening balances, result workbook, `imports.manage`).
Method: every claim below was read in the file AND, where it is a behavioural claim, EXECUTED — on sqlite
and on a throwaway PostgreSQL 16 DB `autoerp_test_w23g` (127.0.0.1:5433), in a temporary detached
`git worktree` (removed; DB dropped). The lane worktree was never modified (`git status --short` empty at
HEAD `2352b3c2b` after the review).

## VERDICT

**spec ❌ · quality CHANGES-REQUESTED.**

The ruling itself (create-on-miss, company-scoped, never vertical-scoped, per-row report) is **correct and
fully evidenced** — I re-verified every leg of it independently (§A). The two new tests are real, red-proven
and mutation-proven. But the lane ships a **new sqlite-masks-PostgreSQL defect of its own**: the
soft-delete / race recovery block (`CategoryResolutionService.php:55-75`) is **dead on PostgreSQL** and
converts a plausible operator flow into a hard row rejection carrying a raw `SQLSTATE[25P02]` as its
operator-visible reason. It is not covered by the LEDGER C-10 carve-out (that covers two *inherited*
defects, which I confirmed are the only other PG reds). A second, related regression: an over-long
`category_name` — a cell that was harmlessly ignored before this lane — now **fails the whole product row on
PG**, because `category_name` has no validation rule at all.

## A. The ruling — independently re-verified (all four asks)

| Claim | Verdict | Evidence I read/ran |
|---|---|---|
| Categories are company-scoped, never vertical-scoped | **CONFIRMED** | `database/migrations/tenant/2025_12_26_194624_create_categories_table.php:13-20,35,38` — `company_id` uuid, `slug`, `softDeletes()`, `unique(['company_id','slug'])`; **no** vertical column. `grep -n "categor" apps/api/config/verticals.php` → **zero matches**, so `config/verticals.php` declares no category scoping. |
| The N-13 Parapharmacy "Product Category" is a different field | **CONFIRMED** | `Product/Application/DTOs/ParapharmacyProductMetadataData.php:42` → `public ParapharmacyCategory $category` — an **enum** on the metadata DTO, not a `categories` row. Untouched by this lane. |
| Brands set the create-on-miss precedent | **CONFIRMED** | `Product/Application/Services/BrandResolutionService.php:85-91` → `Brand::create([...])` on a slug miss. (Note the asymmetry the lane did *not* inherit: brands are TENANT-scoped, categories COMPANY-scoped — the lane got the scope right.) |
| No other route exists to create categories before a products import | **CONFIRMED** | `Import/Domain/Enums/ImportType.php:125` lists `category_name` as a **products** column; there is no `Categories` case in the enum. Warn-only would leave a day-one tenant with no remedy. |
| Cross-module boundary (rule 6) | **PASS** | `Import/Services/ImportService.php:562-570` reaches Product only through `App\Shared\Contracts\ProductServiceInterface` (`ProductServiceInterface.php:38-52`, returning `App\Shared\DTOs\CategoryResolutionDTO`). No `Product\Domain\Category` import anywhere in the Import module. `php tools/deptrac-ratchet.php` → `RESULT: PASS — no boundary regression against baseline` (TOTAL 182 = 182). |

**On the create-on-miss decision itself: I concur.** Given no categories importer exists, warn-only is the
N-2 trap (a warning with no remedy). Creating + reporting per row is the right call.

## B. Findings

### 1. [CRITICAL] `apps/api/app/Modules/Product/Application/Services/CategoryResolutionService.php:46-75` — the `QueryException` recovery is DEAD on PostgreSQL; a soft-deleted category turns every matching product row into a hard failure with a raw SQLSTATE

Every import row executes inside a transaction — `Import/Services/ImportService.php:348`
(`DB::transaction(function () use ($job, $row)…`) for the sync path and
`Import/Application/Jobs/ProcessImportJob.php:161` for the queued path. On PostgreSQL, **any** statement that
errors inside a transaction aborts it: every subsequent statement returns `SQLSTATE[25P02] current
transaction is aborted, commands ignored until end of transaction block` until rollback. So when
`Category::create()` at `:47` raises a unique violation, the recovery `SELECT` at `:59-62` **cannot run**.

The trigger is not exotic. `categories` uses `SoftDeletes` (`Product/Domain/Category.php:52`) and the unique
index is **not partial** (`…create_categories_table.php:38`), so a trashed row keeps its slug — and the
delete endpoint soft-deletes (`Product/Presentation/Controllers/CategoryController.php:269` `$category->delete()`,
allowed precisely when the category is empty, i.e. exactly the day-one situation).

Executed, same code, same test, two engines:

```
[PROBE-A] driver=pgsql          # trashed "Hygiene" holds the slug, row imports category_name=Hygiene
  import_error='SQLSTATE[25P02]: In failed sql transaction: 7 ERROR: current transaction is aborted,
     commands ignored until end of transaction block (… SQL: select * from "categories"
     where "company_id" = … and "slug" = hygiene limit 1)'
  _results.category=NULL   warnings=null   product_category_id=NULL      <-- row REJECTED, product not created

[PROBE-A] driver=sqlite
  import_error=NULL
  _results.category='restored'
  warnings=[{"code":"category_restored","detail":"category \"Hygiene\" … was restored from this row"}]
  product_category_id=1                                                   <-- green, masks the defect
```

Consequences on the production engine: (a) `CategoryResolutionOutcome::Restored` is **unreachable** — the
enum case, the `category_restored` warning and the handback's whole "race + soft-delete safe" claim are
untested fiction on PG; (b) the affected rows land in the **Rejected** sheet of the result workbook with a
raw `SQLSTATE[25P02] … select * from "categories"` string as the operator's only explanation
(`ResultWorkbookService.php:126-133` prints `import_error` verbatim); (c) the concurrent-worker race the
block was written for is equally unrecoverable.

I proved the mechanism in isolation, in the exact production shape (outer row transaction, failing insert
inside), on PG:

```
[PROBE-G/bare]      RECOVERY-SELECT-FAILED: SQLSTATE[25P02]: In failed sql transaction …   <- lane's shape
[PROBE-H/savepoint] RECOVERY-SELECT-OK id=1                                                 <- fix's shape
```

**Fix (both legs; I ran this exact shape on PG and PROBE-A went green — `_results.category='restored'`,
product linked, `import_error=NULL`):**

```php
// 1. handle the soft-deleted holder DETERMINISTICALLY, before any insert can fail
$trashed = Category::onlyTrashed()
    ->where('company_id', $companyId)->where('slug', $slug)->first();
if ($trashed !== null) {
    $trashed->restore();
    return new CategoryResolutionDTO((int) $trashed->id, CategoryResolutionOutcome::Restored);
}

// 2. the remaining (true race) case: nested transaction => Laravel emits a SAVEPOINT, so a unique
//    violation rolls back to the savepoint instead of poisoning the caller's row transaction
$created = DB::transaction(fn (): Category => Category::create([...]));
```
(keep the existing `catch (QueryException)` re-read as the race arm; it now works, because the savepoint
leaves the transaction usable.)

**Test debt this exposes:** the lane's two tests never reach line `:55`. Add, and run on **PG**:
(i) trashed-category-holds-the-slug → `restored`; (ii) at minimum a service-level test that a unique
violation inside a row transaction is recovered (note: a genuine cross-connection race cannot be simulated
under `RefreshDatabase` — the company row is uncommitted; I tried, PROBE-F died on the FK).

### 2. [IMPORTANT] `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:161-177` — `category_name` has NO validation rule; a >255-char cell now rejects the whole product row on PG (it was harmlessly ignored before this lane)

The Products rule set validates `name`, `sku`, `brand` (`:173` `max:255`), `unit`, `location_code`… and has
**no entry for `category_name`**. That was harmless while resolution was lookup-only. Now the raw cell is
INSERTed into `categories.name` / `categories.slug`, both `varchar(255)` (`…create_categories_table.php:19-20`).

```
[PROBE-C] pgsql : import_error='SQLSTATE[25P02] …'  cat_count=0     <-- row REJECTED (22001 then abort)
[PROBE-C] sqlite: import_error=NULL                 cat_count=1     <-- sqlite ignores varchar length: masked
```

This is a straight regression in row survivability introduced by the lane, and it is invisible to the
existing suite for the same reason as finding 1.

**Fix:** add to `ImportType::getValidationRules()` Products (and CompositeItems when that lane lands):
`'category_name' => ['nullable', 'string', 'max:255'],` — the row is then rejected at *validation* with a
readable reason instead of blowing up mid-import. Also clamp the slug in
`CategoryResolutionService::slugFor()` (`:103-108`) — `Str::slug()` can *lengthen* a string
(`عطور` → `aator`, 4 chars → 5), so a 255-char name can still overflow `slug`.

### 3. [IMPORTANT] `CategoryResolutionService.php:78-94` — slug-collision merges are SILENT, contradicting the lane's own "never silent" standard

`findLive()` falls back to matching on the slug, which is what prevents the unique violation — correct. But
`Str::slug` collapses far more than case and accents, and a slug match on a **different name** is reported as
`Matched`, which is deliberately silent (`CategoryResolutionOutcome::isStateChange()`,
`Shared/Enums/CategoryResolutionOutcome.php:27-30`).

Measured (`Str::slug` on this vendor tree):

```
Crème / Creme        -> creme            Thé / The       -> the
Soins & Beauté       -> soins-beaute     Soins Beauté    -> soins-beaute
Hygiène/Beauté       -> hygienebeaute    Hygiene Beaute  -> hygiene-beaute   (these do NOT merge)
```

Executed on PG (PROBE-B), rows `Crème` then `Creme`:

```
row1 = {"r":"created","w":[{"code":"category_created",…}]}      categories = {"creme":"Crème"}
row2 = {"r":"matched","w":null}                                  p1_cat = p2_cat = 3
```

So a file containing two genuinely distinct categories (`Soins & Beauté` and `Soins Beauté`) silently ends up
as **one** category, named after whichever row came first, with **nothing** in the workbook. The lane's own
§2 argument ("a typo'd `Hygène` becomes a real category, so every create is reported") applies with equal
force to a merge — a merge is also a master-data decision made from a spreadsheet cell.

**Ruling I'd take:** merging is right (it is what makes the unique index survivable); the *silence* is wrong.
**Fix:** have `findLive()` distinguish an exact-name hit from a slug hit and return a fourth outcome
(`MatchedBySlug`, `isStateChange() === true`) so the row emits e.g.
`category_matched_by_slug: "Creme" was linked to the existing category "Crème"`. Cheap, and it makes the
order-dependence visible instead of invisible.

### 4. [IMPORTANT] `CategoryResolutionService.php:68-72` — restoring a soft-deleted category silently re-arms its pricing/tax/discount policy; the warning text does not say so

`categories` is not an inert label table: it carries `default_tax_rate`, `default_tax_configuration_id`,
`target_margin_override`, `minimum_margin_override`, `max_discount_percent`, `restock_policy`
(`Product/Domain/Category.php:32-37`; migrations `2025_12_30_104000_add_tax_fields_to_categories.php`,
`2026_06_27_100000_add_margin_hierarchy_columns.php`, `2026_07_08_130000_add_discount_policy_columns.php`).
Un-deleting one re-applies all of it to the imported products.

Answering the question the brief posed: **restore + warn is the right call** (refusing would leave a day-one
tenant stuck, which is exactly why create-on-miss was chosen) — but the warning detail at
`ImportService.php:543-547` ("category X did not exist and was restored from this row") is materially
incomplete. **Fix:** say that a previously *deleted* category was reactivated **with its existing tax /
margin / discount policy**. This must be settled together with finding 1, since the path is dead today.

### 5. [MINOR] `CategoryResolutionService.php:96-102` — the docblock's Arabic/CJK claim is false

It states `Str::slug()` "returns `''` for names with no latin-transliterable characters (pure Arabic, CJK,
punctuation)". Measured: Arabic **transliterates** — `عطور` → `aator`, `أدوية` → `adoy`, `أدويه` → `adoyh`;
only CJK (`化妆品` → ``, `护肤` → ``) and pure punctuation (`---` → ``) return `''`. PROBE-D on PG confirms
two Arabic categories are created with transliterated slugs and no error. So (a) the hash fallback almost
never fires for Arabic, and (b) Arabic transliteration is itself a collision source, feeding finding 3.
**Fix:** correct the comment to name CJK/punctuation only.

### 6. [MINOR] `apps/api/app/Shared/Contracts/ProductServiceInterface.php:50` vs `CategoryResolutionService.php:36-39` — the contract says "must be non-blank after trimming" and nothing enforces it

Both current callers guard (`ImportService.php:564-568`, `ProductService.php:86`), so this is latent — but a
blank name reaches `slugFor('')` → `'category-'.substr(hash('sha256',''),0,16)` and **creates a category with
an empty name**. **Fix:** `throw new InvalidArgumentException` on a blank `$trimmed` in `resolve()`.

### 7. [MINOR] The handback's FE residual (§6.4) is misfiled — and the real answer to "does the wizard crash?" is **no**

`apps/web/src/features/import/types.ts:99-103` is `DependencyCheck.warnings: string[]`, and that is **correct**:
it matches `Import/Services/MigrationWizardService.php:41,103` which returns `array<string>`. It has nothing
to do with row warnings. The row-level `{code,detail}` objects returned at
`Import/Presentation/Controllers/ImportController.php:345` land on a FE type that has **no `warnings` field at
all** (`types.ts:79-86 ImportRow`, `:167-172 ImportPreviewRow`), and `grep -rn "warnings" apps/web/src` shows
nothing in the import feature renders them. So: **no crash, no render** — an undeclared extra JSON key is
inert in TS. The genuine (minor) gap is that `category_created` is invisible in the wizard UI; the operator
only sees it in the downloaded workbook, plus the `warning_rows` count (`ImportController.php:672,681-691`).
Restate §6.4 accordingly so the ledger does not chase a non-bug.

### 8. [MINOR] `ImportService.php:526` + `ProductService.php:86-89` — the same name is resolved twice per product row

Correct (the service is idempotent, and I verified the second call reports `matched`), but it is 2 extra
queries per row — 20k on a 10k-row file. Optional: stash the resolved id on `$data['_category_id']`
(underscore keys are already filtered out of the workbook, `ResultWorkbookService.php:96-107`) and have
`upsert()` prefer it.

### 9. [RESIDUAL — for the LEDGER, confirmed] `apps/api/app/Modules/Catalog/Application/Services/CompositeItemImportService.php:52-61`

Confirmed identical: lookup-only, silent drop on miss. **And it is also a rule-6 violation** — it imports
`App\Modules\Product\Domain\Category` directly at `:12` (Catalog → Product model import). One-line fix that
closes both:

```php
// constructor: private readonly ProductServiceInterface $productService
$resolvedCategoryId = $attributes['category_id'] =
    $this->productService->resolveCategoryByName($companyId, trim((string) $data['category_name']))->categoryId;
```

Caveat for whoever takes it: `ImportService::importCompositeItem` has **no per-row warning channel** (it is
called as `$this->importCompositeItem($job->tenant_id, $row->data, $companyId)` from `importRow`, with no
`ImportRow`), so the "report it" half of the ruling needs its own plumbing there — do not silently auto-create.

### 10. [INFO — not a lane defect] The Import feature lane is PARKED, so these tests do not run in CI on a PR to dev

`apps/api/tests/feature-lane-manifest.json` → group `Import` → lane `feature-lane-data-console/Import`,
`"runs_on_pr_dev": false`, `execution_gate: vars.SELF_HOSTED_RUNNER_READY`. Local execution is the only gate
today (LEDGER S-17). Relevant because findings 1 and 2 are PG-only: nothing automated will catch them.

## C. Gates and claims I re-ran myself

| Check | Result |
|---|---|
| Red-proof test 1 (`git apply -R` of the production hunk only) | **RED** — `category_name must create the missing category, not drop it / Failed asserting that null is not null` @ `ProductsImportPipelineTest.php:279` |
| Red-proof test 2 | **RED** — `Failed asserting that null is identical to 1` @ `:354` |
| **Mutation** proof of the warning leg (production restored, `addRowWarning` guard forced false) | **RED** — `Failed asserting that null is identical to 'category_created'` @ `:291` → the warning assertion is load-bearing, not decorative |
| Workbook leg (task ask 3: prove `category_created` reaches the workbook `warnings` column) | **PROVEN GREEN on a real import run** — the test downloads `/api/v1/imports/{id}/result-workbook`, loads the XLSX and finds `category_created` in the `Imported` sheet. It can only come from `ResultWorkbookService.php:61,74,112-124`: `_`-prefixed keys are excluded from data headers (`:96-107`) and `_results['category']` is `created`, not `category_created`. |
| `ProductsImportPipelineTest.php` on **PostgreSQL** | `OK (4 tests, 62 assertions)` |
| `tests/Feature/Import/` on **PostgreSQL** | `Tests: 124, Errors: 1, Failures: 1` — **exactly** the two inherited LEDGER C-10 defects: `ResultWorkbookTest.php:84` (`invalid input syntax for type uuid: "product-1"`) and `PartiesImportBalancesTest.php:130` (array-order). Not counted, per brief. |
| feature-lane manifest | `EXIT=0` — `1399 Feature classes in 74 groups`. **No raise claimed and none needed**: group `Import` ceiling `"classes": 17`, and the lane added tests to an existing class, not a new one. Verified in `tests/feature-lane-manifest.json`. |
| deptrac ratchet | `RESULT: PASS` (TOTAL 182 = 182) |
| PHPStan level 8, all 6 changed production files | `[OK] No errors` |
| Pint `--test`, all 7 changed files | `{"result":"pass"}` |
| Rule 13 (constructor injection) | PASS — `ProductService.php:23-28` `private readonly CategoryResolutionService`; no `app()` anywhere in the diff |
| Rule 9 (enums, no magic strings) | PASS — `CategoryResolutionOutcome` backed enum |
| Rule 19 (money/quantity) | **N/A, correctly** — the diff touches no money or quantity field; no float, no `bcformat`, no scale resolution added |
| Migration risk | **NONE** — no DDL in the diff |
| `imports.manage` gating | Untouched; no new route added by this lane |
| Lane worktree left unmodified | Yes — `git status --short` empty at `2352b3c2b`; temp worktree removed, `autoerp_test_w23g` dropped |

## D. What must change before merge

1. **Finding 1 (blocking):** make the soft-delete/race path work on PostgreSQL — `onlyTrashed()` pre-check
   before the insert, and wrap the insert in a nested `DB::transaction()` so a unique violation rolls back to
   a SAVEPOINT instead of aborting the row transaction. Add a **PG-executed** test for the trashed-holder case.
2. **Finding 2 (blocking):** add `'category_name' => ['nullable','string','max:255']` to the Products
   validation rules, and clamp the slug to 255.
3. **Finding 3 (blocking on the lane's own "never silent" standard):** report a slug-match-on-a-different-name
   (`category_matched_by_slug`) instead of staying silent.
4. Findings 4-6 (small): richer `category_restored` detail; fix the Arabic/CJK docblock; enforce the
   non-blank contract.
5. Correct handback §6.4 (finding 7) and log finding 9 (CompositeItemImportService — silent drop **plus** a
   rule-6 model import) as its own lane.
