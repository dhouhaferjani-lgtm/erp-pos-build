# Gate r1 — lane N-3 / N-4 / N-7 (`fix/campaign-fe-contract`) — frontend-conventions + API-contract lens

**Reviewed tip:** `6e10fcbe6` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/fe-contract`)
**Diff:** `git diff dev...HEAD` (lane base dev ≈ `51c5b98d7`; **local dev has since moved to `efb4d6475`** — see F-3)
**Reviewer:** adversarial gate, everything below re-run by me. Nothing accepted on report.
**Worktree left PRISTINE** (`git status --porcelain` empty at exit; every tamper reverted from a byte-copy backup, never `git stash`).

## VERDICT: **ACCEPT-with-conditions**

The two crash sites are genuinely fixed, the FE types now mirror the real payloads (live-verified against the campaign
tenant), the API contract test for the preview genuinely pins drift (proved by tamper), and no baseline/detector was
touched or evaded. Three conditions must land before merge (F-1, F-2, F-3); four tickets follow.

---

## What I verified by execution (not by report)

| Check | Result |
|---|---|
| `pnpm --filter @autoerp/web typecheck` | clean |
| `pnpm --filter @autoerp/web lint` | **EXIT 0**, `✖ 6448 problems (0 errors, 6448 warnings)` vs baseline `scripts/lint-warning-baseline.json` **6449** — ratchet claim TRUE |
| `audit:keys` (tanstack) | `Gate C: 0` — `0 acknowledged, 0 new, 0 stale` |
| `audit:design-system` | `807 acknowledged, 0 new, 0 stale`; **`apps/web/tools/audit-design-system-baseline.json` UNCHANGED in the diff** |
| `audit:quantity` | `0 total (0 baselined, 0 new)` |
| `audit:i18n:local` | `OK — 55 namespaces, en=9309 fr=9325 ar=4940, 2762 known gaps held`; **1 baseline entry burned down, 0 growth, 0 scanned-surface shrink** |
| `test:eslint-rules` (RuleTester) | all 6 rules pass |
| vitest `BatchPreview.test.tsx` | 5/5 |
| vitest `VatReportPage.test.tsx` | 3/3 |
| vitest `i18nRawKeyCoverage.test.tsx` | 5/5 |
| vitest `queries.tenantScope` + `VatBreakdownTable` + `computeYtdTotals` | 14/14 |
| PHPUnit `OpeningBalancePreviewContractTest` (sqlite) | 4 tests / 32 assertions OK |
| PHPUnit `OpeningBalancePreviewContractTest` **on PostgreSQL** (`-c phpunit-pgsql.xml`, scratch DB) | **4/32 OK** — this matters: `.github/workflows/ci.yml:1215` runs `./vendor/bin/phpunit tests/Feature/Accounting` in the `treasury-spine-pgsql` job on PR→dev, so this class runs on PG in CI and the handback only claimed sqlite |
| PHPUnit `VatReportSummaryContractTest` | 3/16 OK |
| PHPUnit `OpeningBalanceBatchTest` | 29/102 OK |
| PHPUnit `VatReportControllerTest` | **5 tests / 33 assertions** OK |
| PHPUnit `InventoryOpeningPreviewPrecisionTest` | **1 test / 1 assertion** OK |
| `php tools/feature-lane-manifest-check.php` (lane tree) | EXIT 0, gated 1153 == ceiling 1153 |
| Escape-hatch grep on the whole diff (`: any`, `as any`, `as unknown`, `eslint-disable`, `@ts-`, `phpstan-ignore`) | **zero added** |

**Red proof, replicated by me (not taken on report):**
- API, additive drift: injected `'tamper_extra' => 1` into `AccountingOpeningService::getPostPreview()` and renamed
  `note` → `notice` in `ArApOpeningService` → `OpeningBalancePreviewContractTest` went **2F/4** with exact key-set diffs.
  The preview contract test therefore pins the key set in **both** directions (addition and rename). Reverted.
- FE: `BatchPreview.tsx` `date: preview.entry.entry_date` → `.description`, and `VatReportPage.tsx`
  `countryCode={period.country_code}` → `{''}` → **2 failed / 8**, in the two intended assertions. Reverted.

**Live, read-only capture on the campaign tenant** (`tenant01a03028-9470-70e6-83ca-cdc354f17cf1`, API :8010 healthy,
`artisan tinker`, no writes) on the real ACCOUNTING batch `01a03036-5df5-7255-b96e-fd91fb20e9c7`:
`top=[batch_type, entry, lines, totals]`, `batch_type="ACCOUNTING"`,
`entry=[entry_date, description, is_historical, source_type]`, `totals={debit:"0", credit:"0", is_balanced:true}`.
The FE union matches the real payload exactly. That tenant also has **12 VAT periods, ALL `OPEN`, country `TN`** — which
is what makes F-1 material.

---

## Contract correctness (N-3)

- Union in `apps/web/src/features/opening-balances/types/index.ts:137-191` matches the three transformers key-for-key:
  `AccountingOpeningService.php:432-450`, `InventoryOpeningService.php:374-393`, `ArApOpeningService.php:410-428`.
- `batch_type` is **additive**: `OpeningBalanceBatchController.php:526-528` is the only backend consumer of
  `getPostPreview()`; the only HTTP consumer is `openingBalancesApi.ts:104` (single unwrap via `apiGet`, rule 14 OK).
  No POS/mobile consumer (grepped). The nested `batch.batch_type` was deliberately kept — correct call.
- The union is exhaustive against `OpeningBatchType` (4 cases) and the controller `match` is total, so the
  `else` header branch (`BatchPreview.tsx:288-292`) can never dereference a missing `batch`.
- ACCOUNTING footer fix (`BatchPreview.tsx:78,81`, was `total_debit`/`total_credit` — keys the API never sent) and Post
  step fix (`OpeningBalanceWizardPage.tsx:133-163,523-531`, was `total_lines ?? total_documents ?? 0`) are both correct.
- `batchType` prop removal (second source of truth) is the right structural fix; `batchType` is still used at
  `OpeningBalanceWizardPage.tsx:99,117,205,286,371`, no dead code.

## Contract correctness (N-4)

- `useVatPeriod` (`hooks/useVatPeriods.ts:25-34`) uses `tenantScopedKey(['vat-period', periodId])` (rule 14 bullet),
  `enabled` gates on tenant+company, no double-unwrap (`api.ts:26-28` returns `apiGet` directly).
- `GET /vat/periods/{id}` really carries `country_code`, `label`, `status` (`VatPeriodResource.php:23,27,30`;
  `VatPeriodStatus` enum values are `OPEN|CLOSED|FILED`, matching `types.ts:1`). Route exists and is permissioned
  identically to the summary route (`Taxation/routes.php:109,118`, both `can:reports.financial`).
- `period.country_code` is the **right** field: the declaration is filed under the period's country
  (`countryItemConfigs` in `VatSpecialItems.tsx:23`), not the company's current country. Semantics correct.
- The `rate` → `tax_rate` + `tax_configuration_id` change to the snapshot branch
  (`VatReportController.php:80-93`) breaks **no other consumer**: I grepped every reader — the export path
  (`VatReportController.php:182-216`) regenerates live from `VatSummaryData` and reads `tax_rate`; the only FE reader is
  `VatBreakdownTable.tsx` (already on `tax_rate`); POS `rate` hits are unrelated fiscal payloads.
  `VatReportControllerTest` (5/33) and `TaxationTenantIsolationTest` (23/47) stay green.
- Ad-hoc endpoint (`VatSummaryResource.php:25-33`) emits the same 8 keys, so the shared `VatReportSummary` type is safe
  there too (that FE export currently has zero consumers — pre-existing).

## i18n (N-7) — verified independently

I extracted **all 125 static `t('…')` keys** from `features/opening-balances` (excluding tests) and resolved each against
`en/fr/ar` `common.json`: **ALL RESOLVE**. Both dynamic key families resolve for all 4 batch types
(`openingBalances.types.${typeKey}.{title,description,uploadHelp}` at `OpeningBalancesPage.tsx:118,121` and
`OpeningBalanceWizardPage.tsx:366`), as do `t(step.labelKey)`, `t(config.labelKey)` and the new
`t(postSummary.amountLabelKey)`. Flattened `openingBalances` subtree: **en 235 / fr 235 / ar 235, zero set difference**
in either direction. `+87/-6` lines in each of the three locale files. `I18N_BASELINE` not raised (burn-down of 1).

---

## Findings

### F-1 — MAJOR — the VAT contract test does **not** pin the branch every real tenant is served by
`apps/api/tests/Feature/Taxation/VatReportSummaryContractTest.php:166-186`

`BREAKDOWN_KEYS` is only asserted against the **CLOSED/FILED snapshot** row (`:175-179`). The OPEN/live row shape is
`VatAggregation::toArray()` (`apps/api/app/Modules/Taxation/Domain/DTOs/VatAggregation.php:26-36`), and the test's only
statement about it is `assertSame([], $openData['output_vat']['breakdowns'])` (`:185`) — an assertion that the array is
**empty**. The comment at `:183-184` ("its contract is the reference") is not backed by code.

**Proved by tamper:** I renamed `'tax_rate'` → `'rate'` in `VatAggregation::toArray()` — the exact drift class N-4 was —
and ran the **whole** `tests/Feature/Taxation/` directory: `Tests: 244, Failures: 1`, and that one failure is the
unrelated inherited red in F-8. `VatReportSummaryContractTest` stayed **GREEN 3/3**. Reverted.

This is material, not theoretical: the campaign tenant has 12 VAT periods and **all of them are OPEN**, so the live
branch is the only branch a first tenant ever renders. It also undercuts the handback's own justification for the rule-7
deviation ("the contract tests pin the exact key set of all five payload variants") — today they pin four.

**Fix (≈6 lines, same file):** add
`$this->assertSame(self::BREAKDOWN_KEYS, $this->sortedKeys((new VatAggregation('OUTPUT','19.00','1.000','0.190',1,false,null))->toArray()), 'OPEN/live breakdown row keys drifted.');`
(or seed a taxed document so the OPEN branch actually yields a row), and correct the `:183-184` comment.

### F-2 — MAJOR — `VatReportPage` renders a silent blank page when the period query fails
`apps/web/src/features/vat-reporting/pages/VatReportPage.tsx:26-29,44-54,128`

`isLoading`/`error`/`refetch` come from `useVatReport` only; `useVatPeriod`'s loading and error states are discarded
(`:29`). The render gate is `report && period ? … : null` (`:54,128`). Consequences:
- period fetch still pending while the report resolved → blank flash instead of the loading text;
- period fetch **fails** (`queryClient.ts:7` sets `retry: 1`, so two attempts and done) → the page is permanently a bare
  back-link: **no error, no `QueryError`, no retry affordance**. The lane replaced a crash-to-ErrorBoundary with a
  silent blank in the failure path, which is worse to diagnose in the field.

**Fix:** destructure `isLoading: isPeriodLoading, error: periodError, refetch: refetchPeriod` from `useVatPeriod`; use
`isLoading || isPeriodLoading` at `:44` and `error ?? periodError` at `:48` (retry both). Add a fourth case to
`VatReportPage.test.tsx` asserting the `QueryError` title renders when `mockGetVatPeriod.mockRejectedValue(...)`.

### F-3 — MAJOR (merge-time, not a code defect) — `gated_ceiling` must resolve to **1154**, not 1153
`apps/api/tests/feature-lane-manifest.json:9`

Reconciliation for the parent, measured on both trees:

| | local dev `efb4d6475` | lane `6e10fcbe6` |
|---|---|---|
| `gated_ceiling` | 1153 | 1153 |
| `groups.Document.classes` | **78** (N-2 `MissingStockLevelConfirmRefusalTest`) | 77 |
| `groups.Taxation.classes` | 31 | **32** (`VatReportSummaryContractTest`) |
| `feature-lane-manifest-check.php` | EXIT 0 (1153 gated) | EXIT 0 (1153 gated) |

Both sides are individually green at 1153 because each consumed the *same* unit of headroom with a *different* class.
`Document` and `Taxation` are both F-2-parked (gated) lanes, so the post-merge union is **1154**. Merging the lane as it
stands into current dev makes the manifest check FAIL. Session B's Q-7 record already anticipates "manifest union 1154".

**Fix at merge:** resolve `gated_ceiling` → `1154`, keep `Document: 78` **and** `Taxation: 32`, and concatenate both
DELIBERATE-RAISE notes. `Accounting` needs no raise — it is a live lane
(`.github/workflows/ci.yml:1214-1215`, whole-directory selector), which I confirmed also means the new Accounting class
**will actually run on PG in CI**; I pre-verified it green there.

### F-4 — MINOR — rule-7 deviation: acceptable for this P1, but only once F-1 lands, and it needs a ticket
`apps/api/.../AccountingOpeningService.php:397`, `InventoryOpeningService.php:305`, `ArApOpeningService.php:369`
(all `: array`), `apps/web/src/features/opening-balances/types/index.ts:103-191`, `vat-reporting/types.ts:34-74`

The judgement asked for: **the deviation is acceptable.** Building the ~11-class DTO family across three modules
(Accounting / Inventory / Document) plus their controller and every array-accessing test, on a go-live blocker, is
exactly the scope creep rule 4 forbids, and `VatSummaryData`'s `array{…}` phpdoc shapes would transform to `any`
(forbidden by rule 3) — a handwritten exact type is strictly better than a generated `any`. I verified the substitute
guard is real for the preview (tamper → red, both directions). It is **not yet** real for the VAT OPEN branch (F-1).
Condition: land F-1, then open a follow-up lane ticket for DTO-ification with the contract tests already in place.

### F-5 — MINOR — money rendered as raw decimal strings on touched lines
`BatchPreview.tsx:78,81` (touched), `OpeningBalanceWizardPage.tsx:530` (touched); untouched siblings at
`BatchPreview.tsx:109,113,172,174,203,207,258,260`

The live capture returns `totals.debit = "0"` (unscaled — the reducer seeds `'0'`), so a TND tenant sees `0` / `10000.000`
with no currency, no grouping and no millimes, while every sibling screen formats through `useCurrency()`. This is the
same class as campaign finding N-8. Fix on the touched lines only (rule 18 touch-scope): `const { format } = useCurrency()`
and render `format(accounting.totals.debit, { symbol: false })` / `format(postSummary.amount, { symbol: false })`.

### F-6 — MINOR — server-authored English strings shown to a French/Arabic tenant (rule 11)
`BatchPreview.tsx:305` (`header.description`) ← `AccountingOpeningService.php:443` / `InventoryOpeningService.php:377` /
`ArApOpeningService.php:418`; and `BatchPreview.tsx:215` (`arAp.note`) ← `ArApOpeningService.php:426`

Live-confirmed on the fr-locale campaign tenant: the description reads `"GL Opening Balance - Ouverture ParaBio …"`.
The AR/AP `note` is a full English paragraph. Pre-existing, but this lane is the i18n lane for this exact wizard and its
new test pins the English note (`BatchPreview.test.tsx`, "renders … and server note"). Ticket: emit a code/enum from the
API and translate FE-side (or drop the note in favour of a `t()` string). Not a merge blocker.

### F-7 — MINOR (recorded, not a regression) — new `{{count}}` keys have no plural forms
`en/common.json` `openingBalances.messages.rowsImported`, `messages.validationHasErrors`, `upload.rowsFound`,
`upload.uploadButton`, `upload.moreRows`, `preview.moreRows`, `validation.totalRows` (and their fr/ar twins)

English renders "1 rows imported"; Arabic gets one form for six plural categories. **Not lane-introduced:** I checked —
there are **zero** `_one`/`_other` suffixed keys anywhere in `en|fr|ar/common.json` against 23 pre-existing `{{count}}`
usages, so the lane matches the established (imperfect) convention. Repo-wide ticket, not a lane condition.

### F-8 — MINOR (inherited red, for the ledger)
`apps/api/tests/Feature/Taxation/FranceTaxConfigurationSeederTest.php:30` fails on sqlite (`'20.0000'` vs `'20.00'`).
Reproduced on the **clean, untampered** lane tree (`git diff --stat` empty). Not lane-introduced — the lane touches
nothing in that path. Belongs on the inherited-red ledger, not on this lane.

### F-9 — MINOR — handback evidence transcription errors (no code impact)
`docs/superpowers/reviews/2026-08-24-fe-contract-handback.md` §Tests reports `VatReportControllerTest (1/1)` and
`InventoryOpeningPreviewPrecisionTest (5/33)`; the actual numbers are **5 tests / 33 assertions** and **1 test / 1
assertion** respectively (the two labels are swapped). Both are green either way. Same section overstates the contract
tests' coverage — see F-1.

### F-10 — MINOR — the raw-key test only *renders* the landing page, not the wizard
`apps/web/src/lib/i18nRawKeyCoverage.test.tsx:80-113,145-160` — the 37 catalogued keys are checked by `i18n.t()` lookup,
and the only render assertion is `OpeningBalancesPage`. The N-7 surface (wizard steps 2→5) is covered by catalogue
lookups only, and the catalogue is hand-maintained, so a *future* raw key in the wizard is not detected. I closed this
gap for r1 by exhaustive extraction (125/125 keys resolve in en/fr/ar), but the guard does not carry forward.
Optional hardening: render `OpeningBalanceWizardPage` at the `preview` step in that test.

### F-11 — MINOR — preview step also blanks silently on query error
`OpeningBalanceWizardPage.tsx:462-468` — `isLoadingPreview ? spinner : previewData ? <BatchPreview/> : null`. Same class
as F-2 and pre-existing (the lane only dropped the prop). Fold into the F-2 fix if cheap; otherwise ticket.

---

## Mechanism audit (detector-evasion sweep) — clean

- No alias/indirection table re-exporting `tokens.*`; `BatchPreview.tsx` and `VatReportPage.tsx` use
  `semanticColorTokens` directly; **no interpolated variant prefix or opacity modifier onto a token** anywhere in the
  diff (`no-dead-tailwind-token-interpolation` RuleTester also green).
- No suppression comment containing a detector keyword; **zero** `eslint-disable` / `@ts-` / `phpstan-ignore` added.
- No baseline file in the diff: `apps/web/tools/*` and `scripts/*` untouched (`git diff --stat` over those paths is
  empty). The eslint ratchet moved **down** (6448 < 6449) without a baseline rewrite.
- `packages/shared/types/generated.d.ts` +1 line (`MembershipRevocationReason`) is **honest**: the enum exists at
  `apps/api/app/Modules/Company/Domain/Enums/MembershipRevocationReason.php` and dev's committed `.d.ts` was missing it
  (`grep -c` → 0 on dev). Regenerating is rule-7-correct; unrelated to this lane but not evasion.
- Test-fixture edits (`VatBreakdownTable.test.tsx`, `computeYtdTotals.test.ts`, `queries.tenantScope.test.tsx`) only
  **add** now-required fields; no assertion was weakened or deleted.

## Owner-ruled UI principles — no violations introduced

No new competing colours/badges/accents; no new high-contrast decorative band (OQ-5); no disabled control or
coming-soon placeholder shipped (OQ-11); no hardcoded brand string ("AutoERP"/"Syneriva" absent from the diff, OQ-1);
nothing blends refunds into sales; no count screen pre-shows expectations (A-9); no new FE permission gate or route —
both endpoints already sit behind `can:reports.financial` and the wizard route is unchanged; no orphaned/mislinked
route added; no copy that overstates a system guarantee.

---

## Conditions before merge

1. **F-3** — resolve `gated_ceiling` to **1154** (keep `Document: 78` + `Taxation: 32`, merge both notes) and re-run
   `php apps/api/tools/feature-lane-manifest-check.php` on the merged tree.
2. **F-1** — pin the OPEN/live breakdown row key set in `VatReportSummaryContractTest`; re-prove red by the same
   `tax_rate` → `rate` tamper on `VatAggregation::toArray()`.
3. **F-2** — surface `useVatPeriod`'s loading and error states in `VatReportPage`, with a test for the rejected case.

## Tickets (not merge blockers)

4. **F-4** — DTO-ification follow-up lane for the three `getPostPreview()` payloads + `VatSummaryData`.
5. **F-5** — currency-format the opening-balance preview/post amounts (same lane as campaign N-8).
6. **F-6** — stop shipping server-authored English descriptions/notes into the wizard (rule 11).
7. **F-7** — repo-wide i18n plural forms for `{{count}}` keys; **F-8** — inherited red
   `FranceTaxConfigurationSeederTest`; **F-9** — correct the handback's §Tests numbers; **F-10/F-11** — optional
   hardening.

*No merge, no push, no modification of the lane was performed by this review.*
