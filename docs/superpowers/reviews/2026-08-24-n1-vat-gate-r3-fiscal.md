# N-1 VAT resolution — adversarial gate r3 (fiscal-POS lens)

Lane: `fix/campaign-n1-vat-resolution` — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n1-vat-resolution`, HEAD `0b2a74de2`.
Reviewed range: `git diff 51250b4cd...HEAD` (lane content = `3f5dea872` fix round + `0b2a74de2` handback; every other file in that
range arrived with the `924e79b01` dev merge — verified: `git diff --stat 924e79b01 HEAD` is 9 files, all lane files).
Prior rounds: `…-r1-fiscal.md` (CHANGES), `…-r2-fiscal.md` (CHANGES, 2 CRITICAL), `…-r1-treasury.md` (CHANGES, 3 CRITICAL).
Method: read, then executed. Throwaway PG `autoerp_test_n1g3` on `127.0.0.1:5433` — **created and dropped**. One test process
at a time, always by path; the full suite was never run. Three tampers applied and byte-exactly restored. Nothing merged,
nothing left modified: final `git status --porcelain` **empty**, HEAD still `0b2a74de21d35a9df647b8afe994d5143539220f`,
tamper-marker count 0, my probe class deleted.

## VERDICT

**spec ✅ + quality ACCEPT-with-conditions.**

**treasury r1 CRITICAL 1/2/3 closed by withdrawal: YES.** Evidence, by execution, not by reading (§1 below): on a mixed
PostgreSQL fixture carrying a draft credit note, a draft supplier invoice with `recoverable_tax_amount = 19.000`, a
CONFIRMED sales order with a live `payment_allocations` row (so the trigger populated `balance_due = 69.000`), a SEALED
invoice and a POSTED invoice — both with `document_tax_details` snapshots — the md5 of the whole-row text of
`documents`, `document_lines`, `document_tax_details` and `payment_allocations` is **byte-identical before and after**
`up()`. Type-blindness (CRITICAL 1), stale `balance_due` (CRITICAL 2) and stale `document_tax_details` (CRITICAL 3) all
require a write that no longer exists. Fiscal r2 CRITICAL 1 (draft credit notes rewritten) and CRITICAL 2 (deptrac red)
are closed by the same withdrawal and by the relocation (`RESULT: PASS`, Domain-on-Application back to 54).

The conditions are all on the thing that REPLACED the write — the worklist the deploy log now emits. It is emitted for
document types whose correct remedy is not the one it prints (finding 1), and the remedy it prints does not maintain
`document_tax_details` on a CONFIRMED document, which is the very column whose staleness sank the r1 repair (finding 2).
Both are one-string / one-checklist-line fixes and neither mutates a row, which is why this is not CHANGES.

---

## What I verified GREEN, by execution

### 1. The migration writes `products` and nothing else — proven byte-for-byte on PostgreSQL

Static: `grep -nEi '\b(UPDATE|INSERT|DELETE|TRUNCATE|ALTER|DROP)\b'` over
`apps/api/database/migrations/tenant/2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1.php`
returns two executable statements, both `UPDATE products` (`:240` PG, `:253` SQLite); every other hit is prose. The
census statement is a bare `SELECT` (`:191-210`, executed at `:402-404`). No `document_lines`, `documents`,
`document_tax_details` or `balance_due` appears in any executable string.

Dynamic (throwaway PG, whole-row md5 per table, `md5(string_agg(t::text,'|' ORDER BY t.id::text))`):

```
gate line:  ... status=ok repaired=1 flagged_doc_lines=4 flagged_docs=4.
documents            md5 identical    document_lines       md5 identical
document_tax_details md5 identical    payment_allocations  md5 identical
products             md5 CHANGED  (19.00 -> 7.00, the single intended write)

balance_due(confirmed SO with allocation) = 69.000   (unmoved)
supplier line recoverable_tax_amount      = 19.000   (unmoved)
credit-note line tax_rate                 = 19.00    (unmoved)
sealed invoice document_tax_details.rate  = 19.00    (unmoved)
posted invoice documents.total            = 119.000  (unmoved)

second run: ... status=ok repaired=0 flagged_doc_lines=4 flagged_docs=4.
            all five table hashes identical to the first run  -> idempotent, products included
```

The lane's own class is **22 passed / 89 assertions on PostgreSQL** and green on sqlite.

**The guard tests are no longer vacuous — tamper-proved (treasury r1 finding 4 is discharged).** I re-introduced the
withdrawn r1 write (an `UPDATE document_lines … WHERE document_id IN (unposted, unsealed, live)`) inside
`censusUnpostedDocumentLines()` and re-ran the class: **8 of 22 failed**, the 12-column fingerprint diff naming the
rewritten rate and the moved header. Restored byte-exactly. Treasury r1 finding 4 is therefore **not moot** — it was
actually fixed, and the fix bites.

### 2. The campaign-tenant census is honest — re-run read-only by me

`tenant01a03028-9470-70e6-83ca-cdc354f17cf1` on 5433, running the migration's own `CENSUS_SQL` verbatim (SELECT only):

```
QT-2026-0002 quote       draft      19.00 -> 7.00   (1 line)
QT-2026-0003 quote       draft      19.00 -> 7.00 / 19.00 -> 13.00
QT-2026-0004 quote       confirmed  19.00 -> 7.00 / 19.00 -> 13.00
SO-2026-0001 sales_order draft      19.00 -> 7.00 / 19.00 -> 13.00
(7 rows, 4 documents)                products with drift: LAIT-INF400 19->13, SERU-PHY20 19->7 (2 rows)
```

Exactly the claim: 7 lines / 4 documents / 2 products, all sales-side, none sealed. Nothing was written to that tenant.
`flagged_doc_lines`/`flagged_docs` count rows and distinct documents from the same statement, so the gate counters and
the worklist come from one query — no second predicate to drift.

### 3. Deptrac PASS and the Domain tier is back to dev's bytes

`php apps/api/tools/deptrac-ratchet.php` → `ModuleDomain on ModuleApplication 54 → 54 held (domain)`, `TOTAL 182 → 182`,
`RESULT: PASS`, **exit 0**. `git diff dev HEAD -- apps/api/app` no longer lists `DraftPersistenceService.php` at all —
the Domain service is byte-identical to dev, including its constructor, and the two hand-wired unit tests are back to
dev's copies. The resolution now sits in Presentation (`DraftController.php:89 resolveLineTaxRates()`, called at `:161`),
which is the precedent written at `PurchaseQuoteRequestToPurchaseOrderConverter.php:86-94`.

**Coverage did not shrink with the relocation** — repo-wide, `saveDraft()` has exactly ONE caller
(`DraftController.php:167`), so moving the resolution up a tier loses no path. Manual create/update still resolve through
the same object: `QuoteController` `:206`/`:382`, `SalesOrderController`, `InvoiceController`, `PurchaseOrderController`
(2 call sites each), plus `DraftPurchaseOrderService:29` and `PurchaseQuoteRequestAwardService:23`.

### 4. Precedence: the configuration outranks a client-echoed rate, on BOTH write paths — tamper-proved

`DocumentLineTaxResolver.php:64-73` — the `tax_configuration_id` branch now precedes the `tax_rate` branch; an id that
resolves to nothing still falls through, so no caller loses a path. `AutoSaveDraftLineTaxResolutionTest` **8/8 green**.

| Tamper | Result |
|---|---|
| rename the `lines.*.tax_configuration_id` rule key at `AutoSaveDraftRequest.php:232` (drops it from `validated()`) | **4 failed / 4 passed** — headline `7.00→19.00`, product-with-no-config `7.00→19.00`, precedence `7.00→19.00`, and the country-scope case `Expected 422, received 200` |
| swap the two resolver branches back (echoed rate wins) | **2 failed** — `test_the_line_configuration_outranks_a_client_echoed_rate` (autosave) AND `test_the_manual_create_path_applies_the_same_precedence` (`POST /api/v1/invoices`), both `7.00→19.00` |

Both restored from byte-exact scratch copies; `git status` empty afterwards. The r2 finding-5 fixture fix is real: the
product now sits on a *different* band with the stale `19.00` (`:370-378`), so only the configuration branches can
answer `7.00`.

No behavioural risk from the swap on the web client: `applyLineTax` (`DocumentForm.tsx:188-197`) sends the id **or** the
rate, never both, and the document editor's tax cell is a `TaxConfigurationSelect` (`DocumentLineEditor.tsx:870-877`),
not a free rate field — so there is no operator-typed rate for a configuration to silently outrank.

### 5. r1/r2 closed items are still closed

`ProductTaxRateDerivationTest` + `ReceiptProductTaxConfigurationRateTest` → **10 passed / 23 assertions** (product
store/update derivation and the POS seal at 7 %). Country-scope 422 is green and demonstrably discriminating (tamper
above). Rule 19 holds across the whole lane diff: `git diff dev HEAD` adds no `(float)`, `parseFloat`, `Number(` or
`number_format` on a rate — the two grep hits are comments forbidding them. The migration's `formatRate()` (`:470-483`)
normalises through `bcadd($raw,'0',2)`. PHPStan level 8 on the three changed `app/` files **[OK] No errors**; Pint
`{"result":"pass"}` on all six changed PHP files.

### 6. Manifest (item 6 of the brief) — the number the parent must set

Computed against CURRENT dev, not the r2 numbers. `git log -1 dev` = **`c34314d6c`** (a review-record commit;
`gated_ceiling` 1153). merge-base(HEAD, dev) = `29035b48c`, whose manifest is **identical to dev's** — dev has not moved
the manifest since the lane merged it, so **dev delta = 0, lane delta = +4**.

```
group          merge-base   dev     lane    MERGED
Document            78       78      79       79    (+1 AutoSaveDraftLineTaxResolutionTest)
POS                150      150     151      151    (+1 ReceiptProductTaxConfigurationRateTest)
Product             55       55      57       57    (+2 ProductTaxRateDerivationTest + the migration test)
gated_ceiling     1153     1153    1157     1157
```

**The parent must set `gated_ceiling = 1157` at merge — which is the value the lane already carries, so with dev at
`c34314d6c` the merge needs NO manifest edit.** `php apps/api/tools/feature-lane-manifest-check.php` in the worktree:
`EXIT=0`, and its own count agrees (`70 group(s) / 1157 class(es) parked`). **If any other lane lands on dev first**
(e.g. the N-3/N-4/N-7 lane whose gate record proposes 1154), the merged value is `<dev ceiling at merge> + 4`, with the
same three group raises — re-run the checker and take its number.

---

## Findings

### 1. [IMPORTANT] The worklist prints the SAME remedy for a supplier invoice and a credit note as for a quote — and calls the sale-side product rate "correct" for them
`…_n1.php:445-453` emits, unconditionally:
`… document=%s type=%s status=%s lines=%d rates=%s NOT REPAIRED BY THIS MIGRATION - re-pick the product on each line in the editor.`
and `CENSUS_SQL` (`:191-210`) is deliberately not type-filtered (`:181-186`).

Executed on the mixed PG fixture, verbatim from the log:

```
CENSUS: document=INV-6905 type=supplier_invoice status=draft lines=1 rates=19.00->7.00 NOT REPAIRED … re-pick the product …
CENSUS: document=INV-8918 type=credit_note      status=draft lines=1 rates=19.00->7.00 NOT REPAIRED … re-pick the product …
```

Naming those rows is right; the *instruction* is the r1 defect handed to a human. `19.00->7.00` on a supplier invoice
asserts that the supplier's stated VAT is "stale" and our sale-side product band is "correct" — it is not
(`CreateSupplierInvoiceService.php:127-128,180` writes what the vendor's paper says), and re-picking the product on that
line is precisely the rewrite the withdrawal exists to prevent, plus it will not re-derive
`document_lines.recoverable_tax_amount`. On a credit note it breaks agreement with the SEALED invoice the avoir mirrors.
The docblock's defence — "it is emitted with `type=` so whoever reads the log can route it" — is not what the sentence
after `type=` says.

**Fix:** branch the instruction on `d.type`. Sales-side originating types (`quote`, `sales_order`, and `invoice` while
`status='draft'`) keep `action=repair … re-pick the product on each line in the editor`; everything else gets
`action=review-only — purchase/reversal document: the rate is the supplier's or the original invoice's, do NOT re-derive
it from the product master`. One `match`/ternary on the type string. Add one assertion to
`test_a_non_sales_document_is_never_written_to` (it already builds every excluded type) that the worklist line for those
types does NOT contain `re-pick the product`.

### 2. [IMPORTANT] The prescribed remedy does not maintain `document_tax_details` on a CONFIRMED document — treasury CRITICAL 3 survives the withdrawal, relocated onto the operator
`…_n1.php:349-354` and the handback (§"Treasury CRITICAL 2 (balance_due) and CRITICAL 3 (tax snapshot) — answered by the
withdrawal", handback `:442-448`) both claim the operator's re-pick "goes through the write path, which maintains every
derived column this migration could not". For the tax snapshot that is false for `status='confirmed'`.

Every writer of `document_tax_details` in `app/` is `TaxCalculationService.php:512,516`
(`snapshotTaxDetails()`) plus `ExpenseService.php:532,576` — exhaustive grep. Every caller of `snapshotTaxDetails()` is a
draft→confirmed **transition** or the backfill command: `InvoiceController.php:629` (inside the confirm action,
`:613-629`), `QuoteController.php:551`, `CreditNoteController.php:310`, `SalesOrderService.php:107,188`,
`DeliveryNoteService.php:144`, `PurchaseOrderService.php:95`, `ReturnNoteService.php:619`,
`BackfillTaxDetailsCommand.php:280`. **No `update()` path re-snapshots.** And a confirmed document IS editable
(`DocumentStatus::isEditable()` `:19-25` — `Draft, Confirmed => true`; `Document::isEditable()` `:577-580`), so the
re-pick succeeds, moves the header, and leaves the snapshot at the old rate. The declaration reads that snapshot with no
status predicate (`EloquentVatDataRepository.php:31-66`, `d.type IN ('invoice','credit_note','expense')`), so a
CONFIRMED INVOICE repaired off this worklist declares the old rate to the DGI — treasury CRITICAL 3, arriving by hand.
It does not bite the campaign tenant (its only confirmed row is `QT-2026-0004`, a quote, which the declaration does not
read) but the worklist ships fleet-wide.

**Fix (cheap):** make the worklist say so, and put it in the promotion checklist — for any line with
`status=confirmed`, append `— confirmed: re-confirm the document or run BackfillTaxDetailsCommand afterwards, the
document_tax_details snapshot is NOT refreshed by an edit`. `BackfillTaxDetailsCommand` is already named in the handback;
name it in the log line, which is the artefact the operator actually reads. Pin it with one case
(confirmed sales-side document → worklist line carries the caveat).

### 3. [MINOR] The census cannot tell a deliberate per-line band override from N-1 drift, and its remedy would destroy one
`document_lines` has **no** `tax_configuration_id` column (verified: `\d document_lines` — `tax_rate`, `tax_amount`,
`tax_recoverable`, `recoverable_tax_amount`, `non_recoverable_tax_amount`, `non_recoverable_tax`, eco-tax…), while the
editor lets an operator set a per-line band (`DocumentLineEditor.tsx:870-877`) that is stored only as a number. The
census flags every line whose rate ≠ the PRODUCT's configuration (`:208`), so a deliberately overridden line (an exempt
line at `0.00`, a product legitimately sold on another band) appears on the worklist, and "re-pick the product" would
silently replace the override with the product's band.
**Fix:** narrow the predicate to the N-1 signature — the stale value is the COMPANY default
(`AND dl.tax_rate IS NOT DISTINCT FROM (SELECT c.default_tax_rate FROM companies c WHERE c.id = d.company_id)`) — or, if
you prefer to keep it wide, add `verify the line was not deliberately overridden` to the log line. The narrowing keeps
all 7 campaign rows (all are `19.00`, the company default).

### 4. [MINOR] The worklist groups by `document_number`, which is not a key
`…_n1.php:416-422` keys the aggregate on `document_number ?? 'unknown'` and `flagged_docs` is `count($documents)`
(`:458`). The unique constraint is `documents_tenant_id_type_document_number_unique (tenant_id, type, document_number)`
— so the same number on two different TYPES is legal and collapses into one worklist entry under whichever type was read
first, and the column is NULLABLE (`chk_fiscal_mandatory_core` only requires a number once sealed), so any
number-less document collapses every one of its peers into a single `document=unknown` line. `flagged_docs` then
under-reports the very worklist a checklist greps. No draft is number-less on the campaign tenant today
(`SELECT type,status,document_number IS NULL,count(*) …` → zero nulls) and `DraftPersistenceService.php:220` assigns one,
so this is latent, not live.
**Fix:** select `d.id` and key the aggregate on it, printing `document_number` (and `d.company_id`) in the line.

### 5. [MINOR] The census loads every drifted line into PHP memory inside an unattended migration
`:401-404` runs `$connection->select(self::CENSUS_SQL)` with no `LIMIT`, and `count($rows)` (`:458`) needs them all; the
`CENSUS_REPORT_CAP` (`:171`) caps only the *logging*. A tenant with a large drift population (a bad import, a re-rated
configuration) materialises every row at once during `tenants:migrate`.
**Fix:** take the counters from a `SELECT COUNT(*), COUNT(DISTINCT d.id)` and enumerate with
`LIMIT CENSUS_REPORT_CAP + 1`. The counters stay exact and the memory is bounded.

### 6. [MINOR] `resolveLineTaxRates()` is called OUTSIDE the try that makes auto-save "never 500", contradicting its own docblock
`DraftController.php:161` runs the resolution before the `try` at `:166`, so a `QueryException` from the `Company`
lookup (`:78`), the `Product` lookup (`:98-103`) or the per-line `TaxConfiguration::find()`
(`DocumentLineTaxResolver.php:105-108`) escapes to a 500 on an endpoint the editor calls every 3 s — while the docblock
at `:81-83` states "DEFENSIVE, BECAUSE AUTO-SAVE MUST NOT 500" and the catch at `:186-201` exists precisely to answer
200 `silent_failure`. Before the relocation the resolution ran inside `saveDraft()`, i.e. inside that try.
(`requireCompanyId()` at `:155` can already throw, so the 500 window is not new — but the lane widened it by two
queries plus one per line.) Also worth a glance: `CompanyContext::getCompany()` (`:86-93`) is `Company::find()` with no
`tenant_id` predicate, where the withdrawn service version scoped it — moot under database-per-tenant, not under the
single-connection compat suite.
**Fix:** move `$data = $this->resolveLineTaxRates($data);` to the first line inside the `try`.

### 7. [MINOR — informational, pre-existing, residual] No per-rate output-VAT sub-accounts (treasury r1 finding 8)
Recorded as a residual, unchanged by this lane: a 7 % sale and a 19 % sale still credit the same aggregate
`4457 TVA collectée` (`GeneralLedgerService.php:142,183-192`; `TunisiaChartOfAccountsSeeder.php:207-208`), because
`tax_configurations` carries no GL-account column (`TaxConfiguration.php:36-54`). Rate granularity lives only in
`document_tax_details` — which is what makes finding 2 the load-bearing one. `git diff dev HEAD -- apps/api/app` touches
no Accounting file, so nothing here regressed. Owner ticket if `44571/44572/44573` is ever wanted; not this lane.

### 8. [MINOR] Nothing static-analyses the migration
Unchanged from r2 finding 7 and still worth one line in the deploy note: `phpstan.neon` scans `app/` only, so this file
gets no level-8 pass and none of the `ForbidHardcodedBcmathScale` / `ForbidFloatCastOnDecimalProperty` money guards. I
read it for float use instead: there is none (`bcadd` at `:482`, all comparison inside the DB).

---

## What must change before merge

Findings 1 and 2 are the conditions: make the worklist line type-aware (never tell anyone to re-pick a product on a
supplier invoice or a credit note) and carry the `document_tax_details`-is-not-refreshed caveat for `status=confirmed`,
in the log line and in the promotion checklist. Both are string-level changes with an assertion each in a test class
that already builds the fixtures. Findings 3-6 are follow-ups; 7-8 are recorded residuals. At merge, set
`gated_ceiling = 1157` (already correct against dev `c34314d6c`; recompute as `dev + 4` if dev moves first) and keep
Document 79 / POS 151 / Product 57.

**Re-gate scope if the conditions are addressed:** the two worklist strings and their assertions only. The withdrawal
itself, the deptrac relocation, the precedence swap, the guard-test rebuild, the census predicate, idempotency,
savepoint isolation, the product arm and the POS arm are **ACCEPTED as verified in this round and need no re-review**.
