# Handback — lane N-3 / N-4 (+N-7), Session A, 2026-08-24

Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/fe-contract`, branch
`fix/campaign-fe-contract`. Base: merged local `dev` twice — at start (`e70dcaad4`, then
`d5443c1c7`) and at the end (`9316506d4`, one manifest conflict resolved). Nothing pushed.

## Commits

| SHA | Scope |
|---|---|
| `485b54106` | api — preview + VAT summary payload contracts (3 services, 1 controller, 2 Feature tests, manifest raise) |
| `84ece913f` | web — N-3 discriminated union + N-4 period header + N-7 i18n (71 keys × 3 locales) |
| `03ddf16de` | web — N-4b special-items country code from the period |
| `f435460d7` | merge `dev` (manifest `gated_ceiling` conflict resolved 1149/1152 → 1153) |

## Real payload shapes captured

Two sources, agreeing: (a) the server transformers read directly, (b) a **read-only live capture**
against the campaign tenant `01a03028-9470-70e6-83ca-cdc354f17cf1` (DB
`tenant01a03028-…`, API :8010 healthy) via `artisan tinker`, no writes. Owner credentials were
not in campaign report §F1, so the capture went through tenancy + the service directly rather
than an HTTP login; the two API contract Feature tests below drive the **real HTTP routes**
end-to-end, which is the stronger of the two.

`GET …/opening-batches/{id}/preview` — three STRUCTURALLY DIFFERENT variants:

| variant | header key | rows key | totals keys | extra |
|---|---|---|---|---|
| ACCOUNTING | `entry{entry_date,description,is_historical,source_type}` | `lines[]` | `debit,credit,is_balanced` | — |
| INVENTORY | `batch{cutover_date,description,is_historical,source_type}` | `lines[]` | `total_lines,total_quantity,total_value` | `gl_entry` |
| AR/AP | `batch{cutover_date,description,is_historical,batch_type}` | `documents[]` | `total_documents,total_amount,total_open_amount` | `note` |

There was **no key common to all three**, which is why the FE could not narrow safely. A
top-level `batch_type` discriminator was added to all three (live capture confirms
`"batch_type": "ACCOUNTING"` on the real ParaBio batch).

`GET /vat/reports/{id}/summary` — `{output_vat,input_vat,net_vat,credit_brought_forward,`
`credit_carried_forward,amount_payable,special_items,declaration}`. **No `period` key**, in
either branch. Live capture (`January 2026`, OPEN):
`declaration = {form_reference:"DGI", fields:{…}}` — **no `country_code`**;
`special_items = {stamp_duty_count, stamp_duty_total, retenue_source_total}`.
Breakdown rows: OPEN branch = `VatAggregation::toArray()`
(`direction,tax_rate,base_amount,vat_amount,document_count,is_recoverable,tax_configuration_id`);
the CLOSED/FILED snapshot branch emitted `rate` and omitted `tax_configuration_id`.

## Production changes — file:line

**API**
- `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:437` — top-level `batch_type`.
- `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:375` — same.
- `apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:414` — same (nested `batch.batch_type:419` deliberately kept; removing it would break existing consumers).
- `apps/api/app/Modules/Taxation/Presentation/Controllers/VatReportController.php:80-93` — snapshot branch row keys `rate` → `tax_rate`, `+tax_configuration_id`, key order aligned with the live branch. The **export** path (`:182-200`) always regenerates the summary live, so it was never affected by this drift.
- `apps/api/tests/feature-lane-manifest.json` — `Taxation` 31 → 32, `gated_ceiling` → 1153 (see §Manifest).

**Web — N-3**
- `apps/web/src/features/opening-balances/types/index.ts:89-191` — `PostPreview` replaced by a discriminated union `AccountingPostPreview | InventoryPostPreview | ArApPostPreview` on `batch_type`, plus the three row types. Nothing optional that the API always sends; the dead `obe_offset` (no branch ever emitted it) is gone.
- `apps/web/src/features/opening-balances/components/BatchPreview.tsx:277-315` — the **crash site**. Header built inside each branch: `entry.entry_date` for ACCOUNTING (`:285`), `batch.cutover_date` otherwise (`:290`). Was `preview.batch.cutover_date` at old `:306`.
- `…/BatchPreview.tsx:78,81` — ACCOUNTING footer now `totals.debit` / `totals.credit`; it read `total_debit`/`total_credit`, keys the API has never sent, so the footer rendered two blank cells even without the crash.
- `…/BatchPreview.tsx:14-21,32` — the `batchType` prop is gone (a second source of truth that could disagree with the payload — and did); zero amounts blanked via `bccomp`, never a float compare against the literal `'0.00'` (rule 19).
- `apps/web/src/features/opening-balances/pages/OpeningBalanceWizardPage.tsx:139-170` — new `postSummary` narrowing; the Post step read `totals.total_lines ?? total_documents ?? 0` plus optional `total_value`/`total_debit` off the flattened shape, so an ACCOUNTING batch showed **"Total Rows 0"** and no amount at all. `:468` drops the prop.

**Web — N-4**
- `apps/web/src/features/vat-reporting/types.ts:58-77` — `VatReportSummary` loses the `period` it never had; `declaration` / `special_items` typed to what the API sends.
- `…/types.ts:34-42` — `VatRateBreakdown` gains `direction` + `tax_configuration_id`.
- `…/types.ts:8` — `VatPeriod.country_code`.
- `apps/web/src/features/vat-reporting/hooks/useVatPeriods.ts:25-40` — new `useVatPeriod(id)` (tenant-scoped key, rule 14 bullet).
- `apps/web/src/features/vat-reporting/pages/VatReportPage.tsx:29,55,59-62` — the **crash site**. Header/status/export id now from the period; was `report.period.label` at old `:55`.
- `…/VatReportPage.tsx:105` — `countryCode={period.country_code}`; it was `report.declaration['country_code']`, a key the declaration block has **never** carried, so `countryItemConfigs['']` was undefined and the TN stamp-duty / retenue-à-la-source panel returned `null` for every tenant while `special_items` carried the figures. Same class as the 2026-08-07 MAJOR-2 gate finding, which fixed the item KEYS but left the country code empty.

**Web — N-7 i18n**: 71 keys added to `apps/web/src/locales/{en,fr,ar}/common.json` under
`openingBalances` — the whole `upload`, `validation` and `preview` sub-trees,
`types.{accounting,inventory,arOpenItems,apOpenItems}.uploadHelp`,
`wizard.validate.validateButton`, and the 9 missing error toasts. Identical key set in all three
locales; **no existing translation was overwritten** (the merge is add-only, asserted in the
script). The one new key this lane introduced is `preview.entryDate`. Two apparent misses in the
key sweep are false positives: `openingBalances.types.` (a template literal at
`OpeningBalancesPage.tsx:118`) and `openingBalances.types.aropen_items.title` (a *negative*
assertion in `i18nRawKeyCoverage.test.tsx:95`).

## Tests

| Path | Count |
|---|---|
| `apps/api/tests/Feature/Accounting/OpeningBalancePreviewContractTest.php` | 4 tests, 32 assertions |
| `apps/api/tests/Feature/Taxation/VatReportSummaryContractTest.php` | 3 tests, 16 assertions |
| `apps/web/src/features/opening-balances/components/BatchPreview.test.tsx` | 5 |
| `apps/web/src/features/vat-reporting/pages/__tests__/VatReportPage.test.tsx` | 3 |
| `apps/web/src/lib/i18nRawKeyCoverage.test.tsx` | 5 (+37 catalogued keys × 3 locales) |

Regression runs, all green, by path: `OpeningBalanceBatchTest` (29/102),
`TaxationTenantIsolationTest` (23/47), `VatReportControllerTest` (1/1),
`InventoryOpeningPreviewPrecisionTest` (5/33), and web
`queries.tenantScope` (6), `VatBreakdownTable` (4), `VatSpecialItems` (6),
`vat-reporting/hooks/tenantScope` (2), `computeYtdTotals` (4).
Never ran the full PHPUnit suite; ≤2 parallel PHP processes; `pgrep -fl vitest` clean at the end.

## Red proof (every test failed first)

- **N-3 API** — `['entry','lines','totals']` vs expected `['batch_type','entry','lines','totals']` on all three variants; `ErrorException: Undefined array key "batch_type"` on the discriminator test. 4 tests, 3F+1E.
- **N-4 API** — CLOSED breakdown row keys `[…,'rate','vat_amount']` vs expected `[…,'tax_configuration_id','tax_rate','vat_amount']`. 1F/3. The two "no `period` key" assertions passed on the *unmodified* API — which is the point: the API was right and the FE type was lying.
- **N-3 FE** — `TypeError: Cannot read properties of undefined (reading 'cutover_date')` at `BatchPreview.tsx:306`, the campaign's exact error. 4F/5.
- **N-4 FE** — `TypeError: Cannot read properties of undefined (reading 'label')`, the campaign's exact error. 2F/2.
- **N-4b FE** — `Unable to find an element with the text: Special Items`. 1F/3.
- **N-7 i18n** — re-ran the extended catalogue against the pre-change locale files (restored via `git show HEAD:…`, never `git stash`): 3F/5, one per locale, `expected 'openingBalances.types.accounting.uploadHelp' not to match /[a-z]+(?:\.[a-zA-Z_]+)+/`.

Raw output: `red-n3-api.txt`, `red-n4-api.txt`, `red-n3-fe.txt`, `red-n4-fe.txt`,
`red-n4b-fe.txt`, `red-n7-i18n.txt` in this session's scratchpad.

## Gates

- **PHPStan** (level 8, live DB `127.0.0.1:5433`) on all 6 touched PHP files — `[OK] No errors`. Two `arrayValues.list` findings in the skeleton tests were fixed at source, not baselined.
- **Pint** on the same 6 — `{"result":"pass"}`.
- **`pnpm typecheck`** — clean. The union caught 3 more drifts the compiler had been blind to (wizard Post step, `computeYtdTotals` and `VatBreakdownTable` fixtures).
- **`pnpm lint`** — **EXIT 0**. eslint `6450 → 6448` warnings against the 6449 baseline (net −1: two `?? 0` fallbacks made unnecessary by the exact types; the two warnings this lane did introduce, a `!` in the new hook and an `as never` in the new test, were removed rather than absorbed). tanstack-keys 0 new · design-system 807 acknowledged / 0 new · quantity 0 new.
- **i18n completeness** (`audit:i18n:local`, the pinned-baseline authority) — `OK — 55 namespaces, en=9309 fr=9325 ar=4940, 2762 known gaps held at the baseline`, and **1 baseline entry now translated (burn-down)**. No growth, no scanned-surface shrink.
- **`feature-lane-manifest-check.php`** — **EXIT=0**, re-verified after the final `dev` merge.

## Manifest — deliberate raise

`Taxation` 31 → 32 for `VatReportSummaryContractTest`, `gated_ceiling` → **1153**. The Taxation
lane is parked behind `SELF_HOSTED_RUNNER_READY`, so the ceiling is enforced; the note follows
the existing DELIBERATE-RAISE style and records that the class runs BY PATH on sqlite and is in
no live `--filter` allowlist. `Accounting` needed no raise — it is a live lane, so
`OpeningBalancePreviewContractTest` is free and will actually run on PR→dev. The final `dev`
merge conflicted on exactly this field (mine 1149 vs dev 1152 after N-1/N-2); resolved to 1153.
**No migration in this lane.**

## Not done, and why

1. **No DTO family for the preview payloads (rule 7 deviation — flagged for the gate).** The
   brief said "DTO-driven if a DTO exists, add one if not". None exists: all three
   `getPostPreview()` return `array<string, mixed>`. Building one would mean ~11 new DTO classes
   across three modules (Accounting, Inventory, Document), changing three service return types,
   their controller, and every existing test that array-accesses them — on a P1 go-live hotfix
   lane, against rule 4. The drift guard the brief actually asks for is delivered by the two API
   contract tests, which pin the exact key set of all five payload variants and are strictly
   stronger than the generated `.d.ts` (which today contains 125 `any`s). **Recommend a
   follow-up lane** for the DTO-ification, with the contract tests already in place to keep it
   honest.
2. **`VatSummaryData` not given `#[TypeScript]`** for the same reason: its properties are
   `array` with `array{…}` phpdoc shapes, which the transformer renders as `any` — forbidden by
   rule 3, and worse than the exact handwritten type.
3. **`packages/shared/types/generated.d.ts` +1 line** — running `CACHE_STORE=array php artisan
   typescript:transform` (required by rule 7) revealed the committed file was one enum stale on
   `dev` (`MembershipRevocationReason`, from another lane). Regenerating is the correct state;
   it is the only content in that diff and is unrelated to this lane.
4. **No live HTTP capture with an owner session.** Campaign report §F1 records the signup flow
   but not credentials. Substituted a read-only in-process capture plus HTTP-route Feature tests
   (see §Real payload shapes).

## Residuals seen, NOT touched

- `BatchPreview.tsx` line/document tables still cap at 20 rows with a "+N more" hint; the wizard
  offers no way to see the rest. Pre-existing.
- The ACCOUNTING preview has no `gl_entry`, so the balancing OBE offset the posting service
  actually creates is invisible in the preview. The removed dead `obe_offset` type field suggests
  it was once intended. Pre-existing product gap, not a contract bug.
- `N-8` (General Ledger prints `$` and 4 decimals for a TND tenant,
  `features/finance/components/LedgerTable.tsx:12-15`) is untouched — different lane.
- `scripts/lint-ratchet.mjs` cannot run in this worktree: it also ratchets `@autoerp/pos`, whose
  `node_modules` are not installed here (`ERR_MODULE_NOT_FOUND: globals`). The web half was
  ratcheted by hand against `scripts/lint-warning-baseline.json` (6449) — see §Gates.
