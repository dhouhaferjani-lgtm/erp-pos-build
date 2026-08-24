# N-1 VAT resolution — adversarial gate r1 (fiscal-POS lens)

Lane: `fix/campaign-n1-vat-resolution` — worktree `.worktrees/n1-vat-resolution`
Commits reviewed: `14672ed78` (fix, MIGRATION-BEARING) + `9c1fe61bf` (handback). Base = local `dev` `d5443c1c7`.
Reviewer: fiscal-pos-reviewer (adversarial, code-grounded). Nothing merged, nothing modified — every tamper
below was reverted with `git checkout HEAD --` and the tree verified clean (`git status --porcelain` empty).

## VERDICT

**spec ❌ + quality CHANGES-REQUESTED.**

The PRODUCT arm and the POS-seal arm are correct, well-tested and red-proof-verified. The BACKFILL migration
is correct on PostgreSQL and its census is honest — I re-ran it read-only against the campaign tenant and got
the handback's exact numbers. **But the frontend half of brief item 2 introduces a new, proven, fiscal
regression on the document AUTOSAVE path: an autosaved draft line now persists `tax_rate = 0`.** That is
strictly worse than the defect being fixed (19 % instead of 7 % becomes 0 % instead of 7 %), and it is
lane-introduced, not pre-existing. It must be fixed before merge.

---

## What I verified as GOOD (with file:line)

- **Single derivation site.** `TaxResolutionService::resolveRateFromTaxConfiguration()`
  (`apps/api/app/Modules/Taxation/Domain/Services/TaxResolutionService.php:123-149`) is the only lookup;
  `ProductController::applyTaxRateFromConfiguration()`
  (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1136-1160`) is the only
  caller, wired into `store()` at `:420` and `update()` at `:765`. Constructor-injected (`$this->taxResolution`),
  no `app()`.
- **Scope filters mirror the document resolver exactly.** `TaxResolutionService.php:139-141`
  (`country_code` + `applies_to = LINE_ITEMS` + `isPercentage()`) is byte-equivalent to
  `apps/api/app/Modules/Document/Application/Services/DocumentLineTaxResolver.php:82-92`.
- **Foreign-country id is genuinely refused 422 upstream**, as claimed:
  `CreateProductRequest.php:206-209` and `UpdateProductRequest.php:192-195` both attach
  `new TaxConfigurationCountryCoherent($company->country_code)`
  (`apps/api/app/Modules/Catalog/Presentation/Rules/TaxConfigurationCountryCoherent.php:41-47`).
- **No float on money/percent.** `CurrencyScale::bcformatStrict($configuration->percentage_rate ?? '0', 2)`
  (`TaxResolutionService.php:148`). Both columns are `decimal(5,2)`
  (`2025_12_30_100000_create_tax_configurations_table.php:23`,
  `2025_11_30_052910_create_products_table.php:25`), so the scale-2 format is exact, not truncating.
  The migration compares/copies inside the DB — no PHP numeric touches a rate.
- **POS seal path untouched in shape.** `ReceiptCreationService.php:224-225` still reads
  `(string) ($product->tax_rate ?? '0.00')`; the lane changes no POS file (`git diff dev...HEAD --stat`
  lists none). The new POS test asserts at the aggregate/line-rate level and never compares
  `line_subtotal` against `unit_price × qty` — rule-19 clean.
- **Backfill migration is self-guarding, idempotent, savepoint-contained and PG-correct.**
  `2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1.php:151-159` (table-absent gate),
  `:165-201` (per-driver statement inside `$connection->transaction()`), `:203-208` (WARNING-level gate line),
  `:224-232` (declared no-op `down()`). The SQLite `SET` subquery is safe because the `EXISTS` predicate
  pins the same PK row.
- **PG leg re-run by me, green.** Throwaway DB `autoerp_test_n1gate` on `127.0.0.1:5433`, created and
  dropped: `tests/Feature/Product/Migrations/BackfillProductsTaxRateN1MigrationTest.php` → **OK (7 tests,
  24 assertions)**. Run BY PATH, one process, full suite never invoked.
- **Red-proof #1 (controller).** Commenting out the single assignment
  `$validated['tax_rate'] = $rate;` (`ProductController.php:1156`) →
  `ProductTaxRateDerivationTest` **4 failed / 4 passed** (`13.00`→`19.00`, `7.00`→`19.00`), and
  `ReceiptProductTaxConfigurationRateTest` **1 failed** (`pos_receipt_lines.tax_rate` came back `['19.00']`).
  Both discriminate. Reverted.
- **Red-proof #2 (migration, on PostgreSQL).** Replacing the PG predicate
  `AND products.tax_rate IS DISTINCT FROM tc.percentage_rate` with `AND false` → **4 failed / 3 passed**;
  the three "must-not-touch" cases stayed green and the gate line degraded to `repaired=0`. Confirms the
  guarding cases are guarding, not vacuous. Reverted.
- **Census honesty — independently re-run (read-only) against `tenant01a03028-9470-70e6-83ca-cdc354f17cf1`
  on 5433.** Drift rows = **2** (`LAIT-INF400` 19.00→13.00, `SERU-PHY20` 19.00→7.00). Case-(b) refusals =
  **0**. `pos_receipt_lines` for affected products = **0** — so nothing wrong is sealed in that tenant's
  hash chain today, exactly as the handback states. `document_lines` for affected products = **7**, also as
  stated (see finding 3 for what the handback got wrong about them).
- **Manifest raises are correct and named.** `apps/api/tests/feature-lane-manifest.json`: POS 150→151
  (`ReceiptProductTaxConfigurationRateTest`), Product 55→57 (`ProductTaxRateDerivationTest` +
  `Migrations/BackfillProductsTaxRateN1MigrationTest`), `gated_ceiling` 1148→1151 (= +3, arithmetic checks).
  `php apps/api/tools/feature-lane-manifest-check.php` → **EXIT=0**, zero ✗.
- **Brief's "missing" document test really does already exist on base** —
  `apps/api/tests/Feature/Document/DocumentLineTaxConfigurationResolutionTest.php:94`. The handback's
  disclosure is accurate.

---

## Findings

### 1. [CRITICAL] Autosaved draft lines now persist `tax_rate = 0` — VAT silently collapses to zero
`apps/web/src/features/documents/DocumentForm.tsx:188-196` (`applyLineTax` early-returns after setting
`tax_configuration_id`, so `tax_rate` is omitted) + `:339-350` (autosave `draftData` now routes through
`buildLinePayload` and the old `tax_rate: line.tax_rate || 0` line was DELETED).

The autosave consumer cannot read `tax_configuration_id` at all:
- `apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php:194-217` declares
  `lines.*.tax_rate` but **has no `lines.*.tax_configuration_id` rule**, so `validated()` drops the key
  entirely before it reaches the service.
- `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:400` (`addLine`) and `:750`
  (`addLinesBatch`) write `'tax_rate' => $lineData['tax_rate'] ?? 0`. The draft path never calls
  `DocumentLineTaxResolver` — grep of that file returns only those two `tax_rate` occurrences.
- `DraftPersistenceService.php:329` then recalculates the document totals from those lines.

**Proven empirically, not inferred.** I added a throwaway probe test (since deleted; tree verified clean)
that POSTs `/api/v1/documents/auto-save` with exactly the payload `buildLinePayload()` now emits for a line
whose product carries a configuration:

```
post-lane payload  (tax_configuration_id, no tax_rate)  -> document_lines.tax_rate = 0
pre-lane  payload  (tax_rate '7.00')                    -> document_lines.tax_rate = 7
```

Every product-picked line carries an id — `DocumentLineEditor.tsx:380` sets
`tax_configuration_id: product.default_tax_configuration_id ?? null` on product selection — so on a
correctly-configured TN tenant this is *every* line, not an edge case.

*Why it matters:* the draft is a real document row. Re-opening it hydrates `tax_rate: l.tax_rate ?? '0'`
(`DocumentForm.tsx:467`) and does **not** hydrate `tax_configuration_id`, so the operator sees 0 % and can
save/convert it that way; conversion copies the rate verbatim
(`Conversion/Concerns/CopiesDocumentData.php:141`). N-1 was "19 % instead of 7 %"; this ships
"0 % instead of 7 %" on the autosave lane.

**Fix (preferred).** Make the draft path resolve tax the same way create/update does:
(a) add `'lines.*.tax_configuration_id' => ['nullable','uuid', Rule::exists('tax_configurations','id')->where('country_code',$company->country_code)->where('applies_to','LINE_ITEMS'), new TaxConfigurationCountryCoherent($company->country_code)]`
to `AutoSaveDraftRequest::rules()` (copy `CreateDocumentRequest.php:144-151` verbatim); and
(b) run `$data['lines']` through the injected `DocumentLineTaxResolver` in
`DraftPersistenceService::saveDraft()` before `addLinesBatch`/`addLine`, so `:400`/`:750` receive a resolved
`tax_rate` instead of `?? 0`.
**Fix (minimal, if (b) is judged out of lane).** Restore the rate on the autosave payload only —
`DocumentForm.tsx:347` → `{ id: line.id, ...buildLinePayload(line), tax_rate: line.tax_rate ?? '0' }` — and
say in the comment that the draft endpoint does not resolve configurations.
**Either way, add a feature test on `POST /api/v1/documents/auto-save`** asserting the persisted
`document_lines.tax_rate` for a config-bearing line. The lane's vitest file tests `buildLinePayload` in
isolation, which is precisely why this slipped.

### 2. [IMPORTANT] The identical defect survives on composite items — and that arm IS hash-chained
`apps/api/app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:93-94` still does
`if (($validated['tax_rate'] ?? null) === null) { $validated['tax_rate'] = $this->taxResolutionService->getDefaultTaxForNewProduct(...) }`
— the pre-N1 pattern, never reading the `default_tax_configuration_id` that
`StoreCompositeItemRequest.php:59` accepts and persists. `ReceiptCreationService.php:215` reads
`(string) ($compositeItem->tax_rate ?? '0.00')` for composite lines and seals it, exactly like a product line.
The backfill migration also does not cover `composite_items`.
*Why it matters:* N-1 is now half-fixed. A tenant selling menus/bundles keeps sealing the company default.
The handback lists two residuals and this is not one of them.
**Fix:** out of the brief's literal scope, so at minimum register it as a named follow-up lane (controller
derivation via the same `resolveRateFromTaxConfiguration()` + a sibling backfill for `composite_items`)
before promotion, and say so in the handback's residuals section.

### 3. [IMPORTANT] The campaign tenant's 7 stale document lines are quotes/orders, not "issued documents" — they will seal 19 % at conversion
The handback says those rows need "a fiscal correction (credit note), not an UPDATE". My read-only census
shows what they actually are:

```
quote       | confirmed | QT-2026-0004 | 2 lines @ 19.00
quote       | draft     | QT-2026-0002 | 1 line  @ 19.00
quote       | draft     | QT-2026-0003 | 2 lines @ 19.00
sales_order | draft     | SO-2026-0001 | 2 lines @ 19.00
```

None is posted; none is fiscally sealed. But `CopiesDocumentData.php:141` copies `tax_rate` verbatim on
conversion, so converting any of them after the backfill produces an INVOICE at 19 % for a 7 %/13 % product
— a *new* wrong-VAT fiscal document created after the fix shipped.
**Fix:** replace the handback's "credit note" framing with the truth (unposted, repairable) and add a named
deploy-checklist step: on every backfilled tenant, list unposted `document_lines` whose rate disagrees with
the product's configuration and re-price them (remove/re-add the line, or repair in place) BEFORE any
conversion. The campaign tenant's four documents are named above.

### 4. [IMPORTANT] The migration's failure path is one-shot, untested, and has no checklist grep
`…_backfill_products_tax_rate_from_tax_configuration_n1.php:210-222` catches `Throwable` and logs
`status=FAILED` instead of throwing. That is the right call for an unattended `tenants:migrate` — but the
migrator still records the migration as RUN, so a failed tenant is **never retried** on any later deploy and
the only signal is one log line. Neither the `status=skipped` branch (`:151-159`) nor the `status=FAILED`
branch is covered by `BackfillProductsTaxRateN1MigrationTest` (7 tests, all on the happy predicate).
**Fix:** add the explicit promotion-checklist line
`grep 'PRODUCT TAX-RATE N1 BACKFILL MIGRATION:' <log> | grep -c 'status=FAILED'` must be `0`, and add a test
that drops/renames one of the two tables (or forces the catch) and asserts the gate token still emits with
the right `status=`.

### 5. [MINOR] The new 422 refusal is a real API contract change with no test
`ProductController.php:1148-1154` now throws `ValidationException` when the chosen configuration cannot
state a line-item percentage. Previously such a payload saved fine. It is unreachable from the UI
(`apps/web/src/components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx:55` filters
`applies_to !== 'LINE_ITEMS'`, and `TaxConfigurationField` just wraps that select), but it IS reachable from
the API, imports and integrations — a product previously attached to a `DOCUMENT_TOTAL` / stamp-duty
configuration can no longer be saved at all.
**Fix:** add one feature test to `ProductTaxRateDerivationTest` posting a `DOCUMENT_TOTAL` configuration and
asserting `422` + the `default_tax_configuration_id` error key, so the refusal is a pinned contract rather
than an accident.

### 6. [MINOR] Detaching a configuration leaves an orphaned derived rate
`ProductController.php:1142-1144` returns unchanged when the key is present but null, so a product detached
from TVA 7 % keeps `tax_rate = 7.00` with no configuration to justify it
(`ProductTaxRateDerivationTest::test_update_clearing_the_configuration_without_a_rate_leaves_tax_rate_unchanged`
pins this deliberately). The reasoning in the docblock is sound — inventing the company default would be
N-1 pointed the other way — but it is an owner-visible semantic, not a neutral default.
**Fix:** name it in the handback's residuals so it reaches the owner sheet; no code change required.

### 7. [MINOR] The same denormalisation drift is untouched one level up, on categories
`apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:174-175` writes
`default_tax_rate` and `default_tax_configuration_id` independently, with no derivation. A product created
with NO configuration under such a category inherits `categories.default_tax_rate` via
`TaxResolutionService::getDefaultTaxForNewProduct()` (`:82-91`) — which can be as stale as
`products.tax_rate` was. Not in scope; register as a follow-up.

### 8. [MINOR] Resolver branch order still lets a client rate outrank a configuration
`DocumentLineTaxResolver.php:42-44`: an explicit `lines.*.tax_rate` short-circuits before
`tax_configuration_id` is consulted. The handback flags this. It matters slightly MORE after this lane,
because the web client is now the only caller that stopped sending both — mobile/integration clients still
can, and the server takes their number. Needs the owner ruling the handback asks for.

---

## What must change before merge

**Finding 1 only is blocking.** Fix the autosave arm (preferred: teach `AutoSaveDraftRequest` +
`DraftPersistenceService` to accept and resolve `tax_configuration_id`; minimal: keep sending `tax_rate` on
the autosave payload), and add a `POST /documents/auto-save` feature test that asserts the persisted
`document_lines.tax_rate`. Findings 2, 3 and 4 must be **registered** (residuals section + deploy checklist)
before promotion even if their code lands in other lanes; findings 5-8 are follow-ups.

Re-gate scope for r2: the autosave fix + its new test, plus the amended handback residuals/checklist. The
product arm, the POS arm, the migration and the manifest raises are ACCEPTED as-is and need no re-review.
