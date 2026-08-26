# Gate r1 — imports-reviewer — W4-1 opening-lot expiry

- Lane: `fix/campaign-w4-1-opening-lot-expiry`, base `9d0d08ae5` → head `f60268868`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4-1-opening-lot-expiry` (READ-ONLY review)
- Lens: unified two-file import (Products), opening stock ingress, opening-balance wizard, result workbook, gating, precision, i18n.

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

The FEFO/null-expiry core is sound and the wizard (INVENTORY opening-balance) ingress works end-to-end.
The **Products import** half of the deliverable is not reachable through the product UI, and the
column contract has two unhandled shapes (Excel date cells, past dates) that the brief called out by name.

---

## Findings (ordered by severity)

### [CRITICAL] `apps/web/src/features/import/pages/ImportWizardPage.tsx:154-172` — `expiry_date` is missing from `TARGET_COLUMNS.products`, so the wizard silently drops the column from every Products import

`TARGET_COLUMNS` (declared @126, products entry @154-172) contains no `expiry_date`. Chain:

1. `ColumnMapper` builds `targetNames` from `targetColumns` (`apps/web/src/features/import/components/ColumnMapper.tsx:24-26`) and only auto-maps a suggestion when `targetNames.has(target)` (@29-38); the per-source `<select>` options are rendered from `targetColumns` (@148, @169). `expiry_date` is therefore neither auto-mapped nor selectable — it lands in `skippedColumns` (@53-55).
2. `handleMappingComplete` always posts the mapping (`ImportWizardPage.tsx:465-483`), and `importApi.createJob` attaches `column_mapping` whenever it is non-empty (`apps/web/src/features/import/api/importApi.ts:41-44`).
3. Server-side, `ImportService::applyColumnMapping` (`apps/api/app/Modules/Import/Services/ImportService.php:664-690`) **keeps only mapped targets** — `$mapped[$target] = …` inside `foreach ($mapping as $source => $target)`. Any source column with no mapping entry is dropped from the row before `addRowsBatch` (`ImportController.php:165-171`).

Consequence: an operator downloads the official Products template — which now *does* carry the header, because
`MigrationWizardService::generateTemplate()` merges `getOptionalColumns()` (`MigrationWizardService.php:205-217`) —
fills in `expiry_date`, uploads it through the wizard, and the value never reaches validation, never reaches
`ProductOpeningStockPhase`, never reaches the lot, and never appears in the result workbook (workbook headers are
derived from `$row->data`, `ResultWorkbookService.php:88-107`). No error, no warning, no "unknown column" report —
`$mappedHeaders = array_values($columnMapping)` (`ImportController.php:166-168`) so the skipped source is invisible
to `validateHeaders` too. Every lot silently opens undated.

Why the lane's tests are green anyway: `ProductsImportPipelineTest` posts the file directly to `/api/v1/imports`
with **no** `column_mapping` (`tests/Feature/Import/ProductsImportPipelineTest.php:361-372`, `runImport()`), which is
the `$mapping === null` early-return branch of `applyColumnMapping`. The only path exercised is the one no operator uses.

Fix: add `{ name: 'expiry_date', required: false, description: 'YYYY-MM-DD lot expiry for the opening stock' }` to
`TARGET_COLUMNS.products`, and add a wizard-path test that posts `column_mapping` and asserts the lot carries the date.

### [IMPORTANT] `apps/api/app/Modules/Import/Services/SpreadsheetParserService.php:168-177` — an XLSX cell formatted as a Date yields an Excel serial, and `date_format:Y-m-d` then rejects the WHOLE product row

`parseExcel()` reads `$cell->getValue()` (the raw value, not `getFormattedValue()`), so a date-typed cell arrives as
the serial (`46387`), stringified @172. `'expiry_date' => ['nullable','date_format:Y-m-d']`
(`ImportType.php:179`) fails it, `validateJob` marks `is_valid = false` for the entire row
(`ImportService.php:151-169`), and the product itself is not imported — not just its expiry. Excel auto-formats a
typed `2027-09-30` as a date, so this is the default outcome for any operator who edits the template in Excel and
saves as `.xlsx`. The brief named Excel serials explicitly; nothing in the diff handles them and no test covers an
XLSX upload. Either normalize serial/`Y-m-d H:i:s` shapes before validation, or accept a documented format list.

### [IMPORTANT] no past-date handling anywhere — a back-dated `expiry_date` silently creates opening stock that FEFO and the transfer guard treat as non-existent

`ImportType.php:179` and `OpeningBalanceBatchController.php:422` accept any well-formed `Y-m-d`, including
`2024-01-01`. `InventoryOpeningService::validateRow()` (`InventoryOpeningService.php:190-212`) does **not** check the
date against today, and `OpeningBalanceLine::normalizeExpiryDate()` (`OpeningBalanceLine.php:57-79`) only checks
shape. The resulting lot is `EXPIRED` (`Batch::expiryStatus()` via `daysUntilExpiry() < 0`,
`Batch.php:82-108`), so `canBeSold()` is false → `StockTransferService::computeFefoSplit()` skips it
(`StockTransferService.php:864-867`) and raises *"Insufficient sellable batch stock at the source"* (@924-926), and
`FEFOInventoryService::suggestBatchesForSale()` excludes it (@102-111). The operator sees quantity on the stock level
and a refusal at every issue — the same failure shape W4-1 exists to remove — with **no row-level warning code**.
The brief asked for "row-level warning codes for unparseable/past dates"; neither exists.

### [IMPORTANT] `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:416-422` — comment asserts a guard that does not exist

> `// InventoryOpeningService re-validates per row (and refuses a past date); this is ingress shape only.`

`InventoryOpeningService::validateRow()` (`InventoryOpeningService.php:190-212`) contains no past-date refusal —
grep for `isPast|after_or_equal|startOfDay` in that file returns nothing. A maintainer reading the ingress rule will
believe the deeper layer covers it. Either implement the refusal/warning or delete the claim.

### [IMPORTANT] a supplied `expiry_date` is silently discarded whenever the DEFAULT lot already exists — and the row is still reported `ok`

`BatchStockService::findOrCreateBatch()` matches on `(company, product, batch_number='DEFAULT', variant)` and returns
the existing lot **unchanged** (`BatchStockService.php:296-306`); `expiry_date` is not part of the key and is never
written on the found branch. `ensureDefaultBatch()` (@100-108) passes it only to that call. Reachable inside a *single*
import file, not just on re-run: the opening enter-once guard is per **product + location**
(`OpeningBalancePostingService.php:88-101`), so two rows for the same SKU at two locations both post, and the second
row's expiry is dropped. `ProductOpeningStockPhase` records `['opening_stock' => 'ok']`
(`ProductOpeningStockPhase.php:112-117`) — the workbook reports success for a row whose date was thrown away. Same
silent drop when `ensureDefaultBatchForUntrackedRemainder` finds remainder ≤ 0 and mints nothing (@239-271).
Minimum fix: fill a NULL expiry on the found lot, or emit an `expiry_ignored_existing_lot` warning so the workbook is honest.
(This is report concern 3 stated precisely — the operator consequence is *"the date you typed is gone and nothing told you"*,
and the only remediation path is Batches → edit lot, which `CreateBatchRequest`/`UpdateBatchRequest` still force to be non-null.)

### [IMPORTANT] a supplied `expiry_date` on a non-batch-tracked product is silently ignored

`OpeningBalancePostingService.php:168-189` consumes `$line->expiryDate` **only** inside
`if ($product !== null && $product->requires_batch_tracking)`. For IziPOS/generic-retail products the operator's date
is accepted by validation, echoed in the preview (`InventoryOpeningService.php:386-391`), and then dropped with no
warning. A `expiry_ignored_not_batch_tracked` warning code (Products import) / preview note (wizard) would keep the
feedback channel truthful.

### [MINOR] no header alias for the new column — FR/TN sheets will not auto-map

`MigrationWizardService::getColumnAliases()` (@165-195) has entries for `quantity`, `purchase_price`,
`balance_date`, … but none for `expiry_date`, and `findBestMatch()` falls back to `[$target]` with a `str_contains`
test (@136-158). `date de péremption`, `péremption`, `DLC`, `DLUO`, `expiration` therefore return `null` — the exact
headers a parapharmacy sheet carries. (Moot until the CRITICAL above is fixed, since the target is unmappable at all today.)

### [MINOR] template offers no example value for the strict format

`MigrationWizardService::generateExampleRows()` Products examples (@299-330) omit `expiry_date`, so the generated
template prints the header with two blank cells. The rule is the strictest in the Products set
(`date_format:Y-m-d`, vs `['nullable','date']` for Parties' `balance_date`, `ImportType.php:151`) and the operator
gets no in-file hint of it. The INVENTORY wizard template does this right (`FileUpload.tsx:92-97`, sample `2027-03-31`).

### [MINOR] backfill fingerprint is no longer unique now that the import can supply `+365`

`2026_08_26_100100_null_invented_default_lot_expiries.php:131-153` matches
`DEFAULT` lot + `products.default_shelf_life_days IS NULL` + `expiry_date = manufacturing_date + 365 days`, with no
upper bound on `created_at`. An operator-supplied 12-month expiry on an opening posted the same day
(`manufacturing_date = asOfDate`) now produces exactly that shape. Harmless on a normal one-shot `tenants:migrate`
(the migration runs before the column exists in anyone's sheet), but it means this predicate must never be lifted
into a re-runnable repair command, and a restored/replayed DB could lose real dates. Worth a one-line guard comment or a
`created_at < :migration_time` clause.

### [MINOR] `docs/modules/imports.md:196-200` — opening-stock column table not updated

The supported-path table still lists only `quantity` / `purchase_price` / `location_code`. The new optional
`expiry_date`, its `Y-m-d`-only rule, and the "blank = undated, FEFO last" semantics are undocumented.

### [MINOR] `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:86-101` — `compareByFefo` has no tie-break

The server orders `(expiry_date IS NULL) ASC, expiry_date ASC, id ASC`
(`StockTransferService.php:858-861`) and the guard demands an exact match (@934-946). `compareByFefo` returns `0`
for equal expiries and for two undated lots, so the draft order follows API list order rather than `id`. Pre-existing
for dated ties; the undated tie is new. Low reach (one DEFAULT lot per product+variant), but cheap to close by
comparing `id` on tie.

---

## Verified good (no action)

- Precision contract intact: no new float/`parseFloat` on money or quantity in the diff; `ProductOpeningStockPhase`
  keeps `CurrencyScale::bcformatStrict(...)` at scale 4 / `$scale` (@98-100) and resolves scale from the company
  currency (@34) — no bare `getScale()` inside the queued phase.
- `NumericFieldNormalizer::normalize()` is rule-driven (`isNumericField`, @49-52) and `expiry_date` carries no
  `numeric` rule, so the date string is never touched by currency normalization; the `7.140` passthrough (@85) is unchanged.
- Parties/AR-AP sign-quadrant logic, `imports.manage` gating and the middleware chain are untouched by this diff.
- Optional-column contract respected on the wizard side: `INVENTORY_REQUIRED_COLUMNS`
  (`apps/web/src/features/opening-balances/types/index.ts:298-309`) excludes `expiry_date`, so four-column legacy
  sheets still upload (`FileUpload.tsx:62-75`).
- Wizard ingress survives persistence: `$validated['rows']` includes `rows.*.expiry_date` because the rule is declared
  (`OpeningBalanceBatchController.php:422`), and `OpeningBalanceBatchService::addImportRows` passes unknown/non-numeric
  fields through untouched (`OpeningBalanceBatchService.php:203-219`, docblock @230).
- Result workbook echoes `expiry_date` automatically wherever the value reaches `$row->data`
  (`ResultWorkbookService.php:88-107`); rejected rows carry the field name in `reasons` (@126-134) — pinned by
  `ProductsImportPipelineTest.php:322-343`.
- Carbon handling is correct: `hasFormat()` before `createFromFormat()` and a null re-check
  (`OpeningBalanceLine.php:57-79`, `InventoryOpeningService.php:199-211`) — `2027-02-31` is refused, not rolled over.
- i18n: `openingBalances.preview.expiryDate|noExpiry|noExpiryHint` present in `en`, `fr` **and** `ar` common.json;
  `batches`/`stock-transfers` keys in `en`+`fr` only, and `apps/web/src/locales/ar/` genuinely has no
  `batches.json`/`stock-transfers.json` (verified by directory listing) so those fall back to English like the other
  aliased namespaces. Leaving `i18n-completeness-baseline.json` re-pin to consolidation is **acceptable** — the
  authority script reports 0 new gaps and regenerating it mid-flight would conflict with the parallel lanes.
- Generated types: none of `BatchSuggestionDTO` / `ConsumedBatchDTO` / `OpeningBalanceLine` carries `#[TypeScript]`;
  the edited `apps/web/src/features/*/types.ts` are hand-authored feature types, not `packages/shared/types` output.
  No hand-edit of generated types in the diff.
- Schema change is safe: `product_batches.expiry_date` was a bare `$table->date('expiry_date')` with no default or
  comment and separately-declared indexes (`2026_01_05_150000_create_product_batches_table.php:29,46-49`), so
  `->nullable()->change()` drops no attribute.

## What to fix before merge

Add `expiry_date` to `TARGET_COLUMNS.products` (+ a wizard-path test that posts a `column_mapping`) — today the
Products half of this lane is dead through the only UI that exists — then decide and implement the past-date and
Excel-serial policies as row-level warnings rather than whole-row refusals or silent acceptance.

## r2 scoped re-review (fix range f60268868..fba317686) — recorded by the orchestrator from the reviewer's return

**VERDICT: spec ❌ + quality CHANGES-REQUESTED.** r1 items: CRITICAL TARGET_COLUMNS ADDRESSED (`ImportWizardPage.tsx:172`, FE test 3/3 red-without-entry); Excel serials ADDRESSED (`SpreadsheetParserService.php:174-262`, money path protected); past-date policy ADDRESSED for import, NOT for wizard; false comment ADDRESSED; DEFAULT-lot discard ADDRESSED (set-once `BatchStockService.php:110-130,271-289`); non-batch-tracked ADDRESSED; minors addressed except header alias (partial).
New: [IMPORTANT] CI allowlist change claimed in `fba317686`/report/manifest notes was never made (0 hits in `.github/workflows/ci.yml`); [IMPORTANT] `expiry_is_past` (`InventoryOpeningService.php:402`) consumed by nothing in apps/web; [IMPORTANT] batch-tracked product with pre-existing lots gets reason `expiry_ignored_not_batch_tracked` (`OpeningBalancePostingService.php:364-365`) — false wording; [MINOR] accented FR aliases don't auto-map (`MigrationWizardService.php:197`) vs doc claim; [MINOR] provenance-window test fixture created before cutoff (second-boundary flaky); [MINOR] no blank XLSX date-cell case. Verified clean: warning codes reach the result workbook (`ImportService.php:471-484`, `ResultWorkbookService.php:112-134`); provenance window correct in production (`…100100:232-236`).
