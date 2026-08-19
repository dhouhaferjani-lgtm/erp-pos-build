# M4 adversarial merge-gate review — round 4

**Diff reviewed:** `48cebf0f2..HEAD` (16 commits, 57 files). New since round 3: `266d37bc1`, `ad9909716`, `faa023e3d`.
**Amending authority applied:** `TREASURY-RULING-2026-08-19-t20-option-a.md` — Option A (`6586`/`7586`) and the 17 `MovementReason` classifications are ratified; not relitigated. `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1/F-3/F-5/F-8) read and applied.
**Working tree:** clean; no file modified, staged, or committed by this review.

**Lens applicability.** *inventory-costing* — applies (movement→counter-family routing, destructive-loss cost path, batch write-off readiness gate). *treasury* — applies (chart provisioning atomicity, GL counter-account repoint, backfill savepoint containment). *fiscal-pos* — not a named lens; the sealed-byte surface in this diff is comment-only (`PosCoreReceiptProjection.php:2077`, `ReceiptReturnService.php`, `ReturnScrapWriteOffService.php`).

---

## Round-3 register disposition — verified against code and by running the gates myself

- **#1 (P2, deptrac record) — CLOSED, and I reproduced the base measurement independently.** I extracted `48cebf0f2` and ran the exact CI command there: `TOTAL 99 174`, same seven categories, same `ModuleDomain on ModuleApplication` BLOCKER, `RESULT: FAIL`. HEAD reports the identical 174/FAIL. The two runs analysed different trees (`13231 allowed / 12961 uncovered` at base vs `13251 / 12969` at tip), so the base number is a real measurement and not a copy of the tip. `M4-evidence.md:182-186,196-209` now states the gate is **FAIL** at both points, records the 99 / 111 / 174 three-way discrepancy, and escalates it via `docs/superpowers/tickets/2026-08-19-wave3d-inherited-deptrac-ratchet-blocker.md`. Attribution holds: M4 adds zero edges.
- **#2 (chart-health blindness) — CLOSED.** `SystemAccountPurpose.php:185`; covered by `ChartOfAccountsPurposeParityTest::test_validation_catches_a_chart_missing_inventory_shrinkage`.
- **#3 (template path) — CLOSED, and it is the source of finding 1 below.** `ChartOfAccountsService.php:48-55`.
- **#4 (base-red parity test) — CLOSED.** `M4-evidence.md:225-227` retracts the earlier green-directory wording explicitly.
- **#5 (preview under-reports by 2) — CLOSED.** `LegacyExistingChartRepairPreviewer.php:40-45` + `SeedChartsCommandTest::test_legacy_preview_counts_every_account_the_normal_legacy_write_creates`.
- **#6 (untested atomicity + wrong message) — CLOSED.** `ProvisioningFlagMatrixTest::test_template_provisioning_rolls_back_the_chart_when_variance_installation_fails`; `InventoryVarianceAccountProvisioner.php:69`.
- **#7 (red-first coverage) — CLOSED.** `M4-evidence.md:213-217` records a 5-failure + 1-error red run and a revert-replay over six guards.
- **#8 (raw type strings, checklist wording) — CLOSED.** `InventoryVarianceAccountProvisioner.php:106,113` now use `AccountType::*->value`; `dpa-inventory-shrinkage-deploy-checklist.md:34-38` no longer implies a live raw-seeder writer.
- **Round-3's one unverified item — now verified.** Round 3 could not run `InventoryGlPostingSeamTest` / `PosReturnScrapWriteOffTest`. I ran both on real PostgreSQL: **38 tests, 154 assertions, OK.**

---

## Register

### 1 — P2 · CONFIRMED · the new template-path provisioning can hard-abort company creation on a country↔template plan mismatch

`ChartOfAccountsService.php:48-55`; `InventoryVarianceAccountProvisioner.php:66-74` and `:98-118`; `TemplatePublishingService.php:304-307`; `ProvisioningRequiredPurposesV1.php:88` (`InventoryGainIncome` = `SOFT`)

Round 3's finding 3 was a *missing guarantee* on a dark path. The remedy replaced it with an *added hard failure* on the same path, and the failure lands on company creation rather than on a chart report.

`seedForCompany`'s template branch now calls `provisionCompany(..., $company->country_code)`. `definitions()` derives the parent code **from the company's country code only** — `65`/`75` for `TN|FR`, `6000`/`7000` otherwise — while the assigned template is chosen by *assignment*, which is not bound to a chart plan. When the assigned template does not already hold a purpose, the provisioner resolves that parent in the freshly seeded chart and throws `RuntimeException` when it is absent (`:67-74`), inside the same `DB::transaction` as the template seed — so the chart rolls back and the caller (`TenantInitializationService::initializeForNewRegistration`, `CompanyController::seedForCompany`) aborts.

The mismatch is real, not hypothetical:
- `InventoryGainIncome` is `SOFT`, so `validateAccountRows` (`:304-307`) enforces only `REQUIRED` purposes — a template may legally be published **without** `7586`.
- I checked the chart shapes rather than assuming: `tn`/`fr` goldens contain `65` and `75` and **no** `6000`/`7000`; `generic` contains `6000`/`7000` and **no** `65`/`75`.

**Failure scenario.** G2 is completed and provisioning is enabled. An operator certifies a French-plan template (cloned from `coa.fr.default-v2`, or authored via the external editor) that omits the optional `7586` row, and assigns it to a French-plan North-African country — the multi-country set this product targets. A tenant registers there: `definitions('MA')` asks for parent `7000`, the French-plan chart has none, the provisioner throws, the transaction rolls back, **registration fails outright**. Before this commit the same tenant got a working chart missing one purpose, repairable by the idempotent backfill.

The checklist does carry a fail-loud rationale — *"A non-TN/FR custom PCG-shaped template is intentionally fail-loud if it does not contain the Generic `6000`/`7000` parents"* (`dpa-inventory-shrinkage-deploy-checklist.md:27-29`) — but that sentence sits under **"Repair a failed tenant"**, i.e. the backfill command, where fail-loud costs one log line and a promotion gate. It is not an accepted rationale for aborting company creation, and nothing records the extension.

No test covers the shape: both new template-path cases (`ProvisioningFlagMatrixTest:64,86`) use the **generic** fixture with a `'ZZ'` company, where the plan always matches.

**Discharge (either is acceptable):** resolve the parent from the chart actually present rather than from `country_code` on the template branch (or downgrade a missing *gain* parent to warn-and-skip on the creation path only, leaving the backfill fail-loud); **or** add a covering test for a plan-mismatched assigned template and record explicit owner acceptance that this must abort company creation.

### 2 — P3 · CONFIRMED · template-provisioned charts now contain two accounts the certified template does not

`ChartOfAccountsService.php:48-55`

Under template provisioning the company chart is no longer the template's row set: the provisioner appends `6586`/`7586` afterwards. `country-defaults:verify` (`VerifyCountryDefaultsCommand::handle`) checks assignments, publication metadata, scope, and template content hashes — never the provisioned chart — so nothing detects or records the divergence. It **is** documented (`dpa-inventory-shrinkage-deploy-checklist.md:34-38`), which is why this is P3 and not higher, but the country-defaults lane's "the certified template is the chart" contract is now approximate and the deviation is owned by a different program.

### 3 — P3 · CONFIRMED · `preview()` gained a throwing contract its docblock still denies

`LegacyExistingChartRepairPreviewer.php:16-22`, `:40-45`

The class is documented as a *"Read-only preview"*; it now invokes the provisioner, which throws on a purpose collision or a missing parent. I checked the blast radius: the only production consumer is `SeedChartsCommand`, which catches `Throwable` per company (`:132-137`) and degrades to a reported failure — so the `--dry-run` path holds today. The finding is the undeclared contract change, not a live break.

### 4 — P3 · CONFIRMED · a swallowed per-tenant backfill leaves destructive-loss postings silently entry-less

`2026_08_19_130000_backfill_inventory_shrinkage_purposes.php:63-70`; `InventoryGlPostingService.php:99-105`; `GeneralLedgerService.php:4510-4514`

The migration catches `Throwable` and returns, so the migration row is marked run even when the chart was not repaired. That tenant then boots on code where `hasInventoryWriteOffAccounts` requires `InventoryShrinkageExpense`; every batch write-off from then on creates a stock movement with **no** journal entry, where before M4 it always posted to COGS. Detection is a `Log::warning`, the D-e detector, the now-widened chart-health report (round-3 #2's fix), and the promotion-blocking `status=ok exit=0` grep. That is a defensible, gated compatibility contract — noted because every layer of it is a *pull*, and the default product behaviour on the failure path is silence.

### 5 — P3 · CONFIRMED · a test method M4 edited still names a partition size it no longer asserts

`tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php:43`, `:89-90`

`test_manifest_is_the_complete_exact_41_case_partition` asserts `assertCount(43, …)` twice. The stale `41` predates M4, but this diff edits that method's body (moving `inventory_shrinkage_expense` from `SOFT` to `REQUIRED`) and left the name. Cosmetic; a future reader reconciling the STOP-B 41→43 note will trip on it.

---

## Standing checks

- **Rule 19 (money/quantity).** Clean. The only value the new code writes is the literal string `'balance' => '0.000'` (`InventoryVarianceAccountProvisioner.php:88`); the provisioner does no arithmetic. `InventoryGlPostingService::postForCountCorrection` resolves scale with an explicit currency (`getScale($ctx->currencyCode)`); no bare no-arg `getScale()` on a console/queued/projection path in the diff. No float touches money or quantity.
- **Constructor injection.** `InventoryVarianceAccountProvisioner` (`:21`), `ChartOfAccountsService` (`:30-34`), `LegacyExistingChartRepairPreviewer` (`:24-27`), and the backfill command (`:34-39`) all use `private readonly`. `app()` appears only in the two migrations (matching the accepted `2026_08_11_100300` precedent) and in tests.
- **Tenant scoping.** Every provisioner query is `company_id`-scoped; `tenant_id` is stamped from the caller's company row (`:79`); the backfill selects `id, tenant_id, country_code` per company and never crosses them; the v2 importer is bound to the central connection.
- **Migrations additive / unattended-safe.** `2026_08_19_130000` is fail-closed on missing tables, wraps `Artisan::call` in a connection-bound transaction (savepoint under PG — the 25P02 remedy), catches `Throwable`, emits a distinct tenant-attributed token at **warning** level, and has an explicit irreversible `down()`. I verified the connection identity by hand: the migration leaves `$connection` unset so `DB::connection($this->getConnection())` and the provisioner's `$this->database->table(...)` both resolve to the default connection. `2026_08_19_120000` (central) sorts after `2026_08_11_100300`, which is the *migration* that creates its `coa.*.legacy-v1` sources — so a fresh database cannot hit the missing-source throw.
- **Named queues / Horizon.** None added. N/A.
- **en + fr.** No new user-facing strings. Account names are chart data, correctly per-country and byte-matched between the provisioner (`:105`,`:112`) and the v2 templates (`ChartOfAccountsParityTest:144-146`).
- **Frozen seeders / `.github/workflows/**`.** `git diff --name-only 48cebf0f2..HEAD` matches neither. F-1 and F-8 respected. **F-5** ticket present, no code. **S-16** recorded as a parent gate, not claimed.
- **Milestone's own invariants.** F-3 routing is exhaustive and non-vacuous — 17 of 17 cases enumerated in `glCounterFamily()` with **no** `default` arm, and `MovementReasonClassificationTest` re-derives `affectsCOGS`/`affectsShrinkage` from the family so the predicates cannot drift apart. `Damage`/`Expiry`/`WriteOff` are off COGS at every consumer. `CheckCogsCoverageCommand`'s D-a/D-b/D-e partitions are set-equivalent to the pre-change ones (I enumerated both sides). Backfill run-twice idempotency, the savepoint containment test, the warning-level token, the SEEDS-owned purpose guard (`ChartOfAccountsParityTest:72-82`), the T20b baseline/mutation/restore in the form F-1 forces, and the recorded treasury ruling are all present.

## Verification I ran myself

| Command | Result |
|---|---|
| `deptrac-ratchet.php` at **`48cebf0f2`** (extracted tree) | `TOTAL 99 174` · BLOCKER · **FAIL** |
| `deptrac-ratchet.php` at **HEAD** | `TOTAL 99 174` · BLOCKER · **FAIL** (identical categories) |
| `tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest` [PG] | 14 tests, 27 assertions, **OK** |
| `tests/Feature/CountryDefaults` (whole dir) [PG] | 167 tests, 1120 assertions — 1 error, `MissingAppKeyException`; re-ran `CompanyCreationRollbackTest` with `APP_KEY` set → 5 tests, 33 assertions, **OK** |
| `tests/Feature/Inventory/InventoryGlPostingSeamTest` + `tests/Feature/POS/PosReturnScrapWriteOffTest` [PG] | 38 tests, 154 assertions, **OK** *(round 3 could not run these)* |
| `tests/Unit/Inventory` + `tests/Unit/Accounting` + `tests/Unit/CountryDefaults` [PG] | 233 tests, 1369 assertions, **0 failures**; 4 errors, all environment (`GoodsReceiptDataTest` ×2 fixture, `WeightedAverageCostServiceTest`, `AccountEntityTest` Spatie container) |
| `tests/Feature/Accounting` (whole dir) [PG] | 743 tests, 3229 assertions; 8 errors + 1 failure, **none on M4's surface** — `DeliveryRequiredBeforeInvoiceException`, a `pos_shifts_closed_logic` CHECK violation, and a db-per-tenant context assertion that needs real per-tenant databases |
| `pint --test` on the six changed production files | **pass** |
| `phpstan --level=8` on the changed production files | **[OK] No errors** |

## Bypasses attempted that FAILED (the code held) — and one of my own methods that failed

1. **My base-vs-tip test comparison was invalid and I discarded it.** I extracted `48cebf0f2` to a scratch tree with a symlinked `vendor` and got 8 extra failures at "base". They are an artifact: Composer's `autoload_psr4.php` computes `$baseDir` from `__DIR__`, which resolves through the symlink, so the base *tests* ran against **HEAD** *code*. Recorded and thrown away. The deptrac base run is unaffected — deptrac parses `./app` by path, and the differing allowed/uncovered counts prove it read the base tree.
2. Sought a production `Company` creation path bypassing the provisioner — the three creation sites converge on `seedForCompany`; `SeedChartsCommand` delegates; the only raw-seeder consumers roll back or are read-only.
3. Sought a live crash from the new `LogicException` on `MovementGlCounterFamily::DirectionalVariance` in `postMovement` — all five enqueue sites pair a fixed reason with a fixed kind, and none pairs `CountCorrection` with `Exit`/`Entry`. Unreachable guard, not a regression.
4. Sought a hard gate broken by widening `requiredPurposes()` — the sole production consumer is the report-only `AccountPurposeController:62`, and `SystemAccountPurposeTest:15` uses `assertContains`, not an exact list.
5. Sought a company-chart↔template count invariant broken by the new template-path writes — `TemplateChartOfAccountsSeederSemanticsTest:94` drives the seeder directly and is unaffected.
6. Sought a 25P02 re-entry through a connection mismatch between the migration wrapper and the command's per-definition transaction — both resolve to the default connection; containment is real.
7. Sought a fresh-database ordering hazard on the central v2 import — its `coa.*.legacy-v1` sources are created by an earlier **migration**, not a seeder.

---

**Gate disposition.** Everything round 3 asked for is discharged, and the one item it could not verify (the base deptrac number, the seam tests) I verified myself. The substance of M4 — exhaustive counter-family routing, the destructive-loss repoint, the savepoint-contained backfill, the v2 templates, the parity and mutation proofs, the treasury ruling on record — is sound and green under tests I ran rather than read about. What blocks is the *remedy* applied this round: closing a dark-path P3 by extending a fail-loud installer onto the company-creation path introduced an untested, undocumented abort whose trigger (a template whose chart plan differs from the company's country, missing the optional gain purpose) is an ordinary operator action once G2 flips. Fix it or record explicit owner acceptance of it, then this milestone is mergeable.

VERDICT: CHANGES-REQUIRED
