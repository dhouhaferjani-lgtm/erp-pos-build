## M4 adversarial merge-gate review — round 5

**Diff reviewed:** `48cebf0f2..HEAD` (19 commits, 58 files). New since round 4: `266d37bc1`… no — new since round 4's cut (`faa023e3d`) are **`ad9909716`**(already in r4), **`bbe846284`**, **`e28562448`**, **`192330a8c`**.
**Amending authority applied:** `TREASURY-RULING-2026-08-19-t20-option-a.md` — Option A (`6586`/`7586`) + the 17 `MovementReason` classifications are ratified and not relitigated. `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1/F-3/F-5/F-8, amended exit condition) read and applied.
**Working tree:** clean (`git status --porcelain` empty). I modified, staged and committed nothing.

**Lens applicability.** *inventory-costing* — applies (counter-family routing, destructive-loss cost path, D-a/D-b/D-e populations). *treasury* — applies (chart provisioning atomicity, counter-account repoint, backfill savepoint containment). *fiscal-pos* — not a named lens for M4; the sealed-byte surface in this diff is comment-only (`PosCoreReceiptProjection.php:2077`, `ReceiptReturnService.php:419,1416`, `ReturnScrapWriteOffService.php:40,193`).

---

## Round-4 register disposition — verified against code

- **#1 (P2, template-path hard-abort on plan mismatch) — PARTIALLY DISCHARGED.** `provisionTemplateCompany` + `resolveTemplateParent` (`InventoryVarianceAccountProvisioner.php:35-45,158-184`) now prefer the country-derived parent and fall back to the *other* plan's family parent found in the seeded chart; `ChartOfAccountsService.php:50` routes the template branch to it. The round-4 scenario (French-plan template → `MA` company, gain omitted) is fixed and covered red-first by `ProvisioningFlagMatrixTest::test_template_variance_overlay_uses_the_assigned_chart_plan_for_a_non_native_country` (`:117-155`). **The candidate set is hardcoded to four codes, so the abort survives for any third plan — see finding 1.**
- **#3 (preview's undeclared throwing contract) — CLOSED.** `LegacyExistingChartRepairPreviewer.php:29-33` now declares `@throws \RuntimeException`.
- **#5 (stale `41`-case method name) — CLOSED.** Renamed to `test_manifest_is_the_complete_exact_43_case_partition` (`ProvisioningRequiredPurposesV1ConformanceTest.php:43`).
- **#2 and #4 (P3 notes)** were not remediated and were not asked to be; they remain open by record. #2 is now partly *aggravated* by new checklist wording — see finding 2.

---

## Register

### 1 — P2 · CONFIRMED · the creation-path abort is narrowed to two hardcoded plans, not closed; a third-plan template still fails tenant registration outright

`InventoryVarianceAccountProvisioner.php:158-184` (candidate list), `:83-91` (the throw), `ChartOfAccountsService.php:47-55` (inside the creation transaction); `TemplatePublishingService.php:255-333`; `ProvisioningRequiredPurposesV1.php:83`; `ProtectedAccountCodeRegistry.php:44-71`

`resolveTemplateParent` builds its candidate list as `[country-derived parent, '65', '6000']` for shrinkage and `[…, '75', '7000']` for gain (`:160-163`). If **none** of those codes exists in the freshly seeded chart it returns the definition unchanged (`:183`), `applyDefinition` fails its parent lookup and throws `RuntimeException` (`:84-91`) **inside the same `DB::transaction` as the template seed**, so the chart rolls back and `TenantInitializationService::initializeForNewRegistration` / `CompanyController::seedForCompany` abort.

I checked whether such a template is *legally publishable* rather than assuming it: `validateAccountRows` (`:255-333`) enforces nonblank codes, in-template parent resolution, REQUIRED purposes, timbre scope and the protected-code registry — **it never requires `65`, `6000`, `75` or `7000`**. `ProtectedAccountCodeRegistry::forCountry` lists only leaf instrument/demo codes (`5112`, `413`, `403`, `627`, `44566`, `416`, `6130`…). And `InventoryGainIncome` is **SOFT** (`ProvisioningRequiredPurposesV1.php:83`), so a compliant template may omit `7586`.

**Failure scenario.** G2 is complete and provisioning is enabled. An operator authors a template in the external editor for GB/IT/MA — or a slimmed French chart that simply has no `75` root (`Autres produits de gestion courante` is routinely omitted when unused). It publishes cleanly: `InventoryShrinkageExpense` is REQUIRED and present, gain is SOFT and omitted, protected codes present. It is assigned to that country. **Every tenant registration in that country now throws `RuntimeException: Company … is missing parent account 7000` and rolls back** — registration fails, with no operator-facing pointer at the template. Nothing tests this shape; both template-path cases in `ProvisioningFlagMatrixTest` (`:64`, `:86`, `:114`) use plans that contain a family parent, and the new `:117` case is the French↔Generic pair.

Round 4's discharge menu was *(a)* resolve the parent from the chart actually present, **or** *(b)* test the shape and record explicit owner acceptance of the abort. What shipped is *(a)* restricted to two plans, with **no** acceptance record for the residual: I grepped `dpa-inventory-shrinkage-deploy-checklist.md` and `M4-evidence.md` — the only "fail-loud" acceptance on record (`checklist:27-29`, `evidence:110`) is scoped to the **backfill/repair** path, where fail-loud costs a log line, not to company creation.

**Discharge (any one):** fall back to a same-`type` root/parent already in the chart, or to `parent_id = NULL` (legal — `GenericChartOfAccountsSeeder.php:165` seeds `6000` with `parent_code => null`); **or** warn-and-skip a missing *gain* parent on the creation path only, leaving the backfill fail-loud; **or** add a covering test for a no-family-parent template plus a recorded owner acceptance that this must abort registration.

### 2 — P3 · CONFIRMED · the deploy checklist now asserts two guarantees the code does not provide

`docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md:35-38`; `SystemAccountPurpose.php:164-187`

(a) *"it selects `65`/`75` or `6000`/`7000` from the chart actually seeded, so a legal country-to-plan mismatch does not abort company creation"* — true only for a mismatch **between those two plans**; finding 1 is the counterexample, and the sentence carries no qualifier.
(b) *"the per-company chart-health detector validates the resulting overlay"* — `ChartOfAccountsService::validateCompanyAccounts` iterates `SystemAccountPurpose::requiredPurposes()`, which contains `InventoryShrinkageExpense` (`:185`) but **not** `InventoryGainIncome`. Half the overlay is invisible to chart health. An operator reading this checklist will believe a missing `7586` is detected; it is not.

### 3 — P3 · CONFIRMED · `resolveTemplateParent` matches the parent by code only, never by type

`InventoryVarianceAccountProvisioner.php:165-180`

The candidate probe is `where('code', $parentCode)->exists()`. `assertUsable` (`:138-152`) type-checks the *variance* account but nothing type-checks its parent, and publication validation binds a code to a type only for protected-registry codes — `65`/`75`/`6000`/`7000` are not in that registry. An operator template that uses `75` (or `7000`) for a non-revenue node gets `7586` (revenue) grafted under it, and parent-tree-driven chart exports/rollups mis-classify the gain account. Low likelihood on golden-derived charts; free to close by adding a `type` predicate to the same query.

### 4 — P3 · CONFIRMED · the creation-path overlay and the repair command now disagree about the same chart

`InventoryVarianceAccountProvisioner.php:23-28` + `definitions():115-135` (strict, country-derived) vs `:35-45` (cross-plan); `docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md:25-29`

`provisionCompany` — used by the legacy path, `LegacyExistingChartRepairPreviewer:44-48` and `BackfillInventoryShrinkagePurposesCommand` — still derives the parent from `country_code` alone. So a company that the **creation** path can legally complete cross-plan (gain under `75` for an `MA` company) is, if the purpose is later lost, **refused** by the repair command with "missing parent `7000`", and the checklist's prescribed remedy — *"add the missing approved parent"* (`:26`) — would inject a Generic `7000` root into a French-plan chart. Not reachable at this deploy (every existing tenant is legacy-seeded, so the migration's charts always match), reachable after G2.

### 5 — P3 · CONFIRMED · a docblock this diff edited still describes a mechanism the diff removed

`MovementReason.php:156-159` vs `:66-93`

The `Consumption` (D7b) bullet says *"`requiresGLEntry()` and `affectsCOGS()` are both false via the `default` arms"*. `affectsCOGS()` now delegates to `glCounterFamily()`, which has **no** `default` arm — `Consumption` is enumerated explicitly into `Neither` (`:92`). The conclusion still holds; the stated mechanism no longer does. Cosmetic, and the same class as round-4 #5 which was fixed.

---

## Standing checks

- **Rule 19.** Clean in the delta. `resolveTemplateParent` does no arithmetic; the only value the provisioner writes is the literal `'balance' => '0.000'` (`:105`). Every scale resolution on a console/projection path passes explicit currency — `InventoryGlPostingService.php:64,109,171` all use `getScale($ctx->currencyCode)`; no bare no-arg `getScale()` in the diff. No float touches money or quantity.
- **Constructor injection.** `private readonly` throughout (`InventoryVarianceAccountProvisioner:21`, `ChartOfAccountsService:30-34`, `LegacyExistingChartRepairPreviewer:24-27`). `app()` appears only in the two migrations (the accepted `2026_08_11_100300` precedent) and in tests.
- **Tenant scoping.** Every new query is `company_id`-scoped, including both probes in `resolveTemplateParent` (`:166-169`); `tenant_id` is stamped from the caller's company (`:96`).
- **Migrations additive / unattended-safe.** `database/migrations/tenant/2026_08_19_130000` is `Schema`-fail-closed (`:30-32`), wraps `Artisan::call` in `DB::connection($this->getConnection())->transaction()` (savepoint under PG — the 25P02 remedy, `:40-44`), catches `Throwable`, and emits the tenant-attributed token at **warning** (`:51`) / **error** on the exception arm (`:63`), so both survive production `LOG_LEVEL=warning`. `down()` is an explicit irreversible no-op.
- **Named queues / Horizon.** None added. N/A.
- **en + fr.** No new user-facing strings in the delta. The overlay's account names now follow the resolved **plan** rather than the company country (`:174-178`), which is the correct pairing for a cross-plan chart and is byte-consistent with the v2 templates asserted in `ChartOfAccountsParityTest::charts()`.
- **Frozen seeders / `.github/workflows/**`.** `git diff --name-only 48cebf0f2..HEAD` intersects neither. F-1 and F-8 respected; F-5 is a ticket with no code; S-16 is recorded as a parent gate, not claimed.
- **Milestone's own invariants.** F-3 routing is exhaustive and non-vacuous — 17 of 17 cases in `glCounterFamily()` with **no** `default` arm (`MovementReason.php:66-93`), `Damage`/`Expiry`/`WriteOff` off COGS at every consumer, and `MovementReasonClassificationTest` re-derives both predicates from the family. I re-enumerated the detector partitions myself: `Cogs ∪ Shrinkage` is exactly the old `affectsCOGS()` set, and `requiresGLEntry() && ∉{Cogs,Shrinkage}` is exactly its old complement (`CountCorrection` stays in the non-COGS arm via `DirectionalVariance`) — `CheckCogsCoverageCommand.php:165-187` is set-equivalent to the pre-change command. Backfill run-twice idempotency, savepoint containment, the SEEDS-owned purpose guard, the T20b baseline/mutation/restore, and the recorded treasury ruling are all present.

## Verification I ran myself

| Command | Result |
|---|---|
| `phpunit --filter test_template_variance_overlay_uses_the_assigned_chart_plan_for_a_non_native_country` | **OK (1 test, 1 assertion)** — see driver caveat below |
| `pint --test` on the three changed production files + the changed test | `{"result":"pass"}` |
| `phpstan analyse --level=8` on the three changed production files | `[OK] No errors` |
| `git status --porcelain` after the review | empty |

**Driver caveat, stated rather than glossed:** this session is read-only, so I did not create a scratch PostgreSQL database; the focused run above executed on the phpunit default (`sqlite :memory:`, `phpunit.xml:44-45`). Under the house rule PG is the asserting driver, so treat my green as corroborative only — round 4's `[PG]` runs (`ProvisioningFlagMatrixTest` 14/27 OK, `InventoryGlPostingSeamTest`+`PosReturnScrapWriteOffTest` 38/154 OK) remain the authoritative evidence, and the executor's own round-4 record (26 tests/457 assertions, 221 tests/1838 assertions) is not re-proved by me. I did not re-run deptrac; round 4's base-vs-tip measurement (`TOTAL 99 174` FAIL at both points, escalated in `docs/superpowers/tickets/2026-08-19-wave3d-inherited-deptrac-ratchet-blocker.md`) stands unchallenged and M4 adds no edges.

## Bypasses I tried that FAILED (the code held)

1. Sought a way to make the new overlay **silently skip** instead of throwing (a dark chart hole, which would be worse than finding 1): `resolveTemplateParent` returns the definition unchanged on exhaustion (`:183`) and `applyDefinition` then throws (`:84-91`). It cannot fail silently.
2. Sought a cross-family mis-parenting through the new fallback (shrinkage under a revenue root, gain under an expense root): the candidate lists are family-partitioned (`:160-162`) and the country-preferred code is always same-family. Held. (The *type* hole that remains is finding 3, which is a different vector.)
3. Sought a live 500 on an unbackfilled chart from the destructive-loss repoint: `InventoryGlPostingService.php:161-169` gates on `hasInventoryMovementAccounts($companyId, $reason)`, which now selects the shrinkage purpose for `Damage`/`Expiry`/`WriteOff` (`GeneralLedgerService.php:4530-4534`), and `postForBatchWriteOff:99-105` gates on `hasInventoryWriteOffAccounts`. Both degrade to a warning and `null`, not an exception.
4. Sought a definition-mutation leak on the already-satisfied path (`resolveTemplateParent` rewrites `name`/`parent_code` even when the purpose exists): `applyDefinition` returns `'satisfied'` at `:54-59` before either field is read. No effect.
5. Sought a legacy-path regression from leaving `provisionCompany` strict: all three frozen seeders create the required family parents — `TunisiaChartOfAccountsSeeder.php:278`, `FranceChartOfAccountsSeeder.php:336` (`65`, and `75` per `:324`-family), `GenericChartOfAccountsSeeder.php:165` (`6000`/`7000`). The strict lookup cannot fail there.
6. Sought a detector-population drift from the `affectsCOGS()` → `glCounterFamily()` rewrite: enumerated both sides by hand; set-equivalent, including `CountCorrection`.
7. Sought a publication-gate that would make finding 1 unreachable (i.e. some rule forcing a `65`/`6000`/`75`/`7000` root into every template): `validateAccountRows:255-333` and `ProtectedAccountCodeRegistry:44-71` have no such rule. The bypass I *wanted* to find does not exist — which is what makes finding 1 CONFIRMED rather than PLAUSIBLE.

---

**Gate disposition.** The substance of M4 is sound and unchanged from round 4's assessment: exhaustive counter-family routing with no `default` arm, the destructive-loss repoint correctly gated at both call sites, the savepoint-contained backfill with a warning-level tenant-attributed token, the v2 templates with parity and mutation proofs, and the treasury ruling on record. The round-4 remediation is a real improvement, red-first, and it fixes the exact scenario round 4 described. But it fixes that scenario by enumerating two chart plans, and the register's finding was about the class, not the instance — a template that uses neither family still rolls back tenant registration, no test covers it, and the checklist now states the opposite guarantee without a qualifier. That is one small code change (a same-type or null-parent fallback) or one recorded owner acceptance plus a test away from merge.

VERDICT: CHANGES-REQUIRED
