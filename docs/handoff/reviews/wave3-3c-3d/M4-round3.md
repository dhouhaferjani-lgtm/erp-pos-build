# M4 adversarial merge-gate review — round 3

**Diff reviewed:** `48cebf0f2..HEAD` (13 commits, 51 files); remediation slice `a422982be..HEAD` (`c1a067287`, `5d23ab014`). Amending authority `TREASURY-RULING-2026-08-19-t20-option-a.md` applied — Option A (`6586`/`7586`) and the 17 `MovementReason` classifications are ratified and not relitigated. `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1/F-3/F-5/F-8) read and applied.

**Lens applicability.** inventory-costing — applies (movement→counter-family routing, destructive-loss cost path, reversal mirroring). treasury — applies (chart provisioning, GL counter-account repoint, backfill atomicity). fiscal-pos — not a named lens; sealed-byte surface re-confirmed comment-only (`PosCoreReceiptProjection.php:2077`, `ReceiptReturnService.php:419`/`:1416`, `ReturnScrapWriteOffService.php:40`/`:193`).

**Round-2 register disposition, verified against code and by running tests — not from the report:**

- **#1 (P1, new-company exposure) — CLOSED.** `ChartOfAccountsService.php:55-63` now runs `InventoryVarianceAccountProvisioner::provisionCompany()` inside the same `DB::transaction` as the frozen seeder. I enumerated **every** production `Company::create` site — `TenantProvisioningService.php:137`, `AuthController.php:403`, `CompanyController.php:81` — and all three converge on `TenantInitializationService::initializeForNewRegistration` (`:189`, `:482`) → `seedForCompany` (`:220`) or on `CompanyController.php:160`, both legacy-path covered. `SeedChartsCommand.php:134` is the third writer and also delegates. The only remaining raw-seeder consumers are `LegacyExistingChartRepairPreviewer` (rolls its transaction back unconditionally, `:29`/`:37`) and `LegacyCoaGoldenExporter` — neither writes. Definitions match `seederFor()` exactly (`TN|FR`→`65`/`75`, else `6000`/`7000`; parents verified present in all three seeders, `6586`/`7586` verified absent from all three). Ran `ProvisioningFlagMatrixTest` → **12/12 OK**, `ChartOfAccountsPurposeParityTest` → **11/11 OK**, `ChartOfAccountsServiceTest` → **18/18 OK**.
- **#2 (G2 blocked) — CLOSED.** `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:90-94` now names `coa.{tn,fr,generic}.default-v2`, mandates the `cloneToDraft` step, and forbids certifying `*.legacy-v1`.
- **#3 (parent fixture/hash re-pin) — CLOSED.** `docs/superpowers/tickets/2026-08-19-m4-country-defaults-v2-certification-repin.md` records both hashes and demands explicit parent acknowledgement at merge review.
- **#4 (cutover discontinuity) — CLOSED.** `dpa-inventory-shrinkage-deploy-checklist.md:38-40`.

---

## 1 — P2 · CONFIRMED · the architecture gate is RED at HEAD, and the evidence line reads as if it passed

`docs/handoff/reviews/wave3-3c-3d/M4-evidence.md:183`; `apps/api/deptrac.baseline.json`; `.github/workflows/ci.yml:173-178`

`M4-evidence.md` records: *"Deptrac remains exactly **174 violations**, matching the pinned 3D base and the pre-round result."* I ran the gate the CI job runs:

```
TOTAL                                                99      174
BLOCKER — new Domain-tier leakage: ModuleDomain on ModuleApplication  (35 → 52, +17)
RATCHET REGRESSION — total violations rose from 99 to 174.
RESULT: FAIL — architecture boundary regression.
```

Three facts the record does not carry: the baseline file is **99**, not the brief's stated **111** (house rules, `CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md`) and not 174; the tool's verdict is **FAIL** with a **BLOCKER** category; and `.github/workflows/ci.yml:178` runs this exact command as a hard-failing job (`if: github.base_ref == 'main' || push→main`).

**Attribution — I checked it rather than assuming.** I dumped the JSON report and intersected the violating files with `git diff --name-only 48cebf0f2..HEAD`. Only two changed files carry any violation at all, and both are pre-existing:

- `ChartOfAccountsService.php:32` — `ModuleApplication on ModuleInfrastructure`, the `TemplateChartOfAccountsSeeder` constructor parameter that predates this diff. The parameter M4 *added* (`InventoryVarianceAccountProvisioner`, Application→Application) produces no violation.
- `GeneralLedgerService.php:55`, `:57`, `:58` — `PartnerBalanceService` / `GeneralLedgerHashService` / `FiscalPeriodResolverService`, in the import/constructor region. M4's edits to that file are at `:4512`, `:4530`, `:4814`, `:4842` and are constant/comment changes only.

So **M4 introduces zero deptrac violations** and the FAIL is inherited from the 3D base / `dev`. That is exactly why this is P2 and not P1: the required change is to the *record and the escalation*, not to the code. **Failure scenario as written:** the parent reads line 183, treats the architecture gate as satisfied, promotes, and the `main`-bound CI run fails on a BLOCKER nobody attributed. Discharge by re-running the ratchet at `48cebf0f2`, recording its `TOTAL` and `RESULT` verbatim, correcting line 183 to state the gate is **RED at base**, and raising the 99/111/174 discrepancy to the parent as a promotion blocker owned above this wave.

## 2 — P3 · CONFIRMED · the in-product detector stays blind to the purpose this milestone made live

`app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:164-186`; `app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:71-87`; `app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:44`

`InventoryShrinkageExpense` is now `REQUIRED` in the template-certification registry and backs three live writers, but it is **not** in `requiredPurposes()`, so `validateCompanyAccounts()` reports `valid: true` for a chart that cannot post a destructive loss. The two "required" registries now disagree for this one purpose with no conformance test binding them.

The docblock immediately above that list records the identical incident class (`:178-183` — *"both were absent from this list, so `validateCompanyAccounts()` passed the French chart that could post NEITHER"*). **Failure scenario:** a tenant whose backfill hit the refusal branch (`InventoryVarianceAccountProvisioner.php:45-52`, an existing `6586` already carrying a foreign purpose) is left unable to post shrinkage; the admin chart-health endpoint (`AccountPurposeController.php:62`) reports the chart healthy. Rated P3 rather than P2 only because `dpa-inventory-shrinkage-deploy-checklist.md:8-15` makes the per-tenant `status=ok exit=0` token a **promotion-blocking** grep, so the state is gated — by an operator step, not by the product. `validateCompanyAccounts` is consumed by a report-only endpoint, so adding the entry is one line with no behavioural risk.

## 3 — P3 · PLAUSIBLE · the template-provisioning path has no runtime purpose guarantee

`ChartOfAccountsService.php:43-51`; `TemplatePublishingService.php:304-308`; `VerifyCountryDefaultsCommand.php:63-66`

With `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=true`, `seedForCompany` returns before the provisioner and the chart is whatever the assigned template holds. Newly published templates are covered (`validateAccountRows` now throws on the missing REQUIRED purpose — that is round-1 finding 2's fix, and `ChartOfAccountsParityTest.php:90-122` proves a published v2 provisions a posting-ready chart). Not covered: a template **published before this merge** and still assigned. `country-defaults:verify` inspects assignments and account rows but never checks REQUIRED purposes, and `ProvisioningFlagMatrixTest`'s three template-path cases assert only the equity account name. Currently unreachable in production — the flag is `false` in both `.env*.example`, owner-checklist G2 is unchecked, and v1 can no longer be certified — hence P3, not P2.

## 4 — P3 · CONFIRMED · `ChartOfAccountsPurposeParityTest` was red at the 3D base and rounds 1–2 declared that suite directory green

`tests/Feature/Accounting/ChartOfAccountsPurposeParityTest.php:127-167`

The test asserts `seeded === every SystemAccountPurpose::cases() minus documented exemptions`. At `48cebf0f2`: both enum cases exist (`git show 48cebf0f2:…/SystemAccountPurpose.php:36-37`), `seedForCompany` was seeder-only, the three frozen seeders contain no `6586`/`7586`, and the test file is byte-identical to HEAD (`git diff --stat` on it is empty) with no exemption for either purpose. It was therefore **RED for all three countries at the base**, i.e. throughout rounds 1 and 2, in `tests/Feature/Accounting` — a suite directory the diff has touched since `ea1280d21`, which the house rule requires in the declared regression set. Round 2 used it as the red-first reproduction, which is the right shape; the gap is that it went unrun for two rounds and delayed the P1 by one. **Parent-visible consequence:** `dev` currently carries this red test; M4 is what closes it. I ran it at HEAD: **11 tests, 46 assertions, OK.**

## 5 — P3 · CONFIRMED · `seed-charts --dry-run` legacy preview now under-reports by two accounts per company

`app/Console/Commands/SeedChartsCommand.php:106-133`; `LegacyExistingChartRepairPreviewer.php:26-40`

On the `$legacyOnlyPreview` branch (dry-run + provisioning enabled) the count comes from the previewer, which runs the frozen seeder alone and knows nothing about the variance installer; the real run goes through `seedForCompany` and creates two more accounts per company. The output is already labelled `[LEGACY-ONLY PREVIEW]` / `[NOT ASSIGNED-TEMPLATE PARITY]`, which blunts it, but the numbers now diverge from the write path by a fixed, unexplained 2.

## 6 — P3 · CONFIRMED · the provisioner's atomicity claim is asserted in prose and untested; one error message is wrong in the new context

`InventoryVarianceAccountProvisioner.php:65-73`; `M4-evidence.md:159-160`

`M4-evidence.md` states *"onboarding and second-company creation cannot commit a chart without the approved accounts."* No test drives a provisioner failure through `seedForCompany` and asserts the chart rolled back. Relatedly, the missing-parent message reads *"…inventory variance account 6586 **was skipped**"* — accurate for the backfill command's per-definition savepoint, false in the creation path where the whole chart is rolled back and the company creation aborts.

## 7 — P3 · CONFIRMED · new behavioural assertions not demonstrated red

`tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php:50`, `:88`, `:322-336`; `M4-evidence.md:167-169`

The recorded RED run is *"29 tests, 191 assertions, **1 failure**"* — the parity guard alone. Had the two new `assertInventoryVariancePurposes` call sites been in that run there would have been three failures, so they were added green. Mechanical rather than substantive (they cannot pass without the provisioner), but the red-first record for this fix round covers one assertion, not three.

## 8 — P3 · CONFIRMED · type strings bypass the enum, and the checklist implies a live population that does not exist

`InventoryVarianceAccountProvisioner.php:105`, `:112`; `dpa-inventory-shrinkage-deploy-checklist.md:35-37`

`'type' => 'expense'` / `'revenue'` are raw strings where `AccountType::Expense->value` exists (Rule 9); it mirrors the frozen seeders' own style, so it is consistent drift rather than new invention. Separately, *"Direct/raw use of a frozen seeder outside that service remains the owner-approved compatibility case"* implies a production writer that does this — the exhaustive grep finds none (previewer rolls back, exporter is read-only, `2026_03_23_300000` is a historical migration ordered before the new backfill). The sentence describes tests and manual use only.

---

## Standing checks

- **Rule 19 (money/quantity):** clean. No float reaches money or quantity in the diff. The only value written by the new code is the string literal `'balance' => '0.000'` (`InventoryVarianceAccountProvisioner.php:87`). `InventoryGlPostingService::amount()` is unchanged `bcmul`/`bcround`; every scale resolution passes an explicit currency (`getScale($ctx->currencyCode)`); no bare no-arg `getScale()` on a console/queued path. The provisioner touches no arithmetic at all.
- **Constructor injection:** `InventoryVarianceAccountProvisioner` takes `private readonly DatabaseManager` (`:20`); `ChartOfAccountsService` takes it as a third `private readonly` dependency (`:33`); the command injects both (`:34-39`). `app()` appears only in the two migrations (matching the accepted `2026_08_11_100300` precedent) and in tests. No `app()` in a service.
- **Tenant scoping:** correct. Every provisioner query is scoped by `company_id`, `tenant_id` is stamped from the company row (`:79`), the backfill selects `id, tenant_id, country_code` per company and never crosses them, and the v2 importer is bound to the central connection.
- **Migrations additive/unattended-safe:** `2026_08_19_130000` is fail-closed on missing tables, delegates inside `DB::connection($this->getConnection())->transaction(...)` (savepoint under PG — the migration's connection resolves to the same default the command writes on, so containment is real), catches `Throwable`, and emits a distinct tenant-attributed token at warning/error level. `down()` is an explicit irreversible no-op. The central v2 migration is additive and ordered after the v1 bootstrap that supplies its sources.
- **Named queues / Horizon:** none added. N/A.
- **en+fr:** N/A — no new user-facing strings. Account names are chart data, correctly localised per country and byte-matched between the provisioner (`:104`, `:111`) and the v2 templates (`ChartOfAccountsParityTest.php:144-146`). `UseBatchWriteOffException`'s English message is a reword of a pre-existing English string, not new drift.
- **`.github/workflows/**` and the three frozen seeders:** untouched — `git diff --name-only 48cebf0f2..HEAD` yields zero matches for either. F-8 and F-1 respected.
- **F-5 ticket:** present (`docs/superpowers/tickets/2026-08-19-inventory-movement-entry-idempotency-company-scope.md`), no code in this wave. **S-16:** recorded as a parent gate, not claimed (`dpa-inventory-shrinkage-deploy-checklist.md:3-4`).
- **Milestone's own invariants:** F-3 exhaustive routing present and non-vacuous — 17 of 17 cases enumerated in `glCounterFamily()` with **no** `default` arm, and `MovementReasonClassificationTest` re-derives `affectsCOGS`/`affectsShrinkage` from the family so neither predicate can drift independently. `Damage`/`Expiry`/`WriteOff` are off COGS and land on shrinkage at every consumer. The SEEDS-owned purposes guard is present (`ChartOfAccountsParityTest.php:72-82`) and non-vacuous. The 25P02 savepoint remedy is implemented with a PG containment test. The T20b antidote is satisfied in the adapted form F-1 forces: green baseline, gain-row mutation → RED with exact row deltas, restore → green.
- **Citation registry:** I re-derived all seven re-pinned line numbers with `awk` rather than trusting the diff — `GeneralLedgerService.php:4340`, `:4428`, `:4591`, `:4592`, `:4814`, `:4815`, `:5000` and `ChartOfAccountsService.php:96` all land exactly on their `getAccountByPurpose`/`findByPurposeOrFail` calls. Partition `28+1+4+10 = 43` matches `SystemAccountPurpose::cases()`.

## Verification I ran myself

| Command | Result |
|---|---|
| `tests/Unit/Inventory/MovementReasonClassificationTest.php` | 19 tests, 77 assertions, **OK** |
| `tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php` | 11 tests, 429 assertions, **OK** |
| `tests/Feature/Accounting/ChartOfAccountsPurposeParityTest.php` | 11 tests, 46 assertions, **OK** |
| `tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php` | 12 tests, 21 assertions, **OK** |
| `tests/Feature/Accounting/ChartOfAccountsServiceTest.php` | 18 tests, 147 assertions, **OK** |
| `SeedChartsCommandTest` + both new backfill tests | 25 tests, 137 assertions, **OK**, 2 PG-only skips |
| `pint --test` on the four changed production files | **pass** |
| `phpstan --level=8` on the provisioner + chart service | **No errors** |
| `deptrac-ratchet.php` | **FAIL** — see finding 1 |
| `InventoryGlPostingSeamTest` + `PosReturnScrapWriteOffTest` | **could not run locally** — 11 errors from `no such table: tenants` (needs the db-per-tenant PG env, not the sqlite phpunit default) + 27 loud `[PG]` skips. Accepted on the evidence record's PG run; **not** independently confirmed. |

## Bypasses attempted that FAILED (the code held)

1. Sought a production `Company` creation path that skips `seedForCompany` — enumerated all three (`TenantProvisioningService:137`, `AuthController:403`, `CompanyController:81`); all converge on `initializeForNewRegistration`/`seedForCompany`. Round-2's P1 has no surviving production population.
2. Sought a production writer using a frozen seeder directly, which would still yield a purpose-less chart — only `LegacyExistingChartRepairPreviewer` (unconditional `rollBack()` in `finally`) and `LegacyCoaGoldenExporter`. Both read-only.
3. Sought a stale-account reversal defect: a pre-cutover write-off posted to COGS being reversed after the repoint into shrinkage, leaving both accounts permanently skewed. Both reversal paths mirror `$line->account_id` from the original entry (`GeneralLedgerService.php:4966-4976`, `:4700+`) and never re-resolve by purpose. Clean — this was my strongest hypothesis and it is wrong.
4. Sought a code collision: `6586`/`7586` already present in a frozen seeder under `UNIQUE(company_id, code)` — grepped all three; `6580`, `6585`, `6588`, `6590`, `7580`, `7585`, `7592` exist, `6586`/`7586` do not. Parents `65`/`75`/`6000`/`7000` all present, so the fail-loud missing-parent branch is unreachable on a seeder-provisioned chart.
5. Sought a country-mapping divergence between `getSeederForCountry()` (`:167-171`) and `definitions()` (`:99`) that would send a Generic-seeded company looking for parent `65` — both are `strtoupper` + `TN|FR`, identical.
6. Sought another test constructing `ChartOfAccountsService` positionally and now broken by the third constructor argument — one anonymous subclass at `SeedChartsCommandTest.php:313`, updated in the same commit; no other `new ChartOfAccountsService(` anywhere.
7. Sought a surviving consumer of `affectsCOGS()` relying on `Damage`/`Expiry`/`WriteOff` being true — only `MovementReason` itself and docblocks; `CheckCogsCoverageCommand` was migrated to the family in the same diff, and its D-a/D-b/D-e partitions are set-equivalent to the pre-change ones.
8. Sought a way to attribute any deptrac violation to M4's own edits — intersected the JSON report with the changed-file list; the only hits are `ChartOfAccountsService.php:32` and `GeneralLedgerService.php:55/57/58`, all in import/constructor regions this diff does not touch. M4 adds zero; the FAIL is inherited (which is what turned finding 1 from a P1 into a P2).

---

**Gate disposition.** The round-2 P1 is genuinely and verifiably closed: a single shared purpose-first installer now runs in the same transaction as the frozen seeder on every production provisioning path, the frozen seeder bytes and `.github/workflows/**` are untouched, all four surviving round-2 findings are discharged with artifacts, and every named M4 invariant — F-3 exhaustive routing, the SEEDS-owned purpose guard, the 25P02 savepoint, the warning-level token, backfill idempotency, the adapted T20b mutation proof, the recorded treasury ruling — is present, non-vacuous, and green under tests I ran rather than read about. Nothing in the code blocks. What blocks is the record: the branch's architecture gate returns `RESULT: FAIL` with a BLOCKER category against a baseline the brief cites as 111 and the file states as 99, and `M4-evidence.md:183` reports that state as a held number. Correct the line, attribute the 174 to `48cebf0f2` by running the ratchet there, and escalate it as the parent-owned promotion blocker it is; findings 2–8 are notes for the same pass or for the parent's ledger.

VERDICT: CHANGES-REQUIRED
