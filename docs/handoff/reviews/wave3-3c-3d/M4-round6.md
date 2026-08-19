## M4 adversarial merge-gate review — round 6

**Diff reviewed:** `48cebf0f2..HEAD` (`f56fd9e65`, 23 commits, 59 files). New since round 5's cut (`192330a8c`): **`336559830`** (tests), **`7179bb643`** (implementation), **`f56fd9e65`** (records).
**Amending authority applied:** `TREASURY-RULING-2026-08-19-t20-option-a.md` — Option A (`6586`/`7586`) and the 17 `MovementReason` classifications are ratified; not relitigated. `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1, F-2, F-3, F-5, F-8, S-16, amended exit condition) read and applied.
**Working tree:** clean before and after. I modified, staged and committed nothing.

**Lens applicability.** *inventory-costing* — applies (counter-family partition, destructive-loss cost path, D-a/D-b/D-e populations, reversal symmetry). *treasury* — applies (chart provisioning atomicity, counter-account repoint, savepoint containment, publication gate). *fiscal-pos* — not a named lens for M4; its surface in this diff is comment-only (`PosCoreReceiptProjection.php:2077`, `ReceiptReturnService.php:419,1416`, `ReturnScrapWriteOffService.php:40,193`) plus one test fixture.

---

## Round-5 register disposition — verified against code

- **#1 (P2, third-plan template hard-abort) — DISCHARGED, by one of round 5's own named options.** `provisionTemplateCompany` (`InventoryVarianceAccountProvisioner.php:39-63`) now warns and skips the **optional gain** when `resolveTemplateParent` exhausts its candidates, keeping REQUIRED shrinkage fail-closed. Covered red-first by `ProvisioningFlagMatrixTest::test_template_variance_overlay_warns_and_skips_optional_gain_without_a_compatible_parent` (`:162-215`), which builds a legally published `9000`-rooted third-plan template, asserts shrinkage present / gain absent, and pins the exact warning payload including `tenant_id`. I re-ran it: **2 tests, 5 assertions, OK** (driver caveat below).
- **#2 (checklist asserted guarantees the code did not provide) — CLOSED.** `dpa-inventory-shrinkage-deploy-checklist.md:36-46` now states the qualified contract (same-type `65`/`6000` for REQUIRED shrinkage, same-type `75`/`7000` for the SOFT gain, warning token instead of abort) and separates `country-defaults:verify` from per-company chart health.
- **#3 (parent matched by code only) — CLOSED.** `resolveTemplateParent:195-198` now carries `->where('type', $definition['type'])`. I verified this is not a regression on the supported plans: `65`=expense (`TunisiaChartOfAccountsSeeder.php:278`, `FranceChartOfAccountsSeeder.php:336`), `75`=revenue (`:346`, `:422`), `6000`=expense / `7000`=revenue (`GenericChartOfAccountsSeeder.php:165,205`).
- **#4 (creation path vs repair command disagree) — DOCUMENTED, not code-closed.** `checklist:29-31` now scopes the strict command to the pre-G2 legacy path and forbids injecting a country-derived root into another plan. Acceptable as a record; see finding 3.
- **#5 (stale `Consumption` docblock) — CLOSED.** `MovementReason.php:157-158`.

---

## Register

### 1 — P3 · CONFIRMED · REQUIRED shrinkage still hard-aborts tenant registration for a pre-policy template with no expense-typed `65`/`6000`

`InventoryVarianceAccountProvisioner.php:43` (the skip is gated on `InventoryGainIncome` only), `:101-109` (the throw), `ChartOfAccountsService.php:48-55` (inside the creation transaction)

The round-5 fix narrows the abort to the shrinkage half. For any **newly** certified template that half is unreachable — `TemplatePublishingService.php:304-308` now refuses publication without `InventoryShrinkageExpense`, and a template that carries the purpose short-circuits at `resolveTemplateParent:179-187` without needing a parent at all. The residual is templates published **before** this gate tightened: a pre-policy chart that omits the purpose and roots its expenses somewhere other than `65`/`6000` throws `RuntimeException: Company … is missing parent account 6000` inside the seed transaction, and registration in that country fails with no operator-facing pointer at the template.

Round 5's discharge menu explicitly offered *"warn-and-skip a missing **gain** parent on the creation path only, leaving the backfill fail-loud"* — which is exactly what shipped, so this is a **recorded residual, not a re-raise**. Reachable only in the pre-G2 window against an operator-authored third-plan chart. No test covers the shape; `checklist:36-46` describes the shrinkage half as "must already exist or be installable" without naming the abort consequence.

### 2 — P3 · CONFIRMED · the missing-parent diagnostic names a code that may be present, because only `resolveTemplateParent` type-checks

`InventoryVarianceAccountProvisioner.php:194-201` (type predicate) vs `:101-109` (message)

`resolveTemplateParent` skips a candidate whose `type` does not match; `applyDefinition` then reports the *country-derived* `parent_code` as "missing". **Failure scenario:** a template chart contains `65` typed `revenue` (a mis-typed group node) and no shrinkage purpose. The overlay throws `Company X is missing parent account 65; cannot provision inventory variance account 6586`. The operator looks, finds `65` present, and the checklist's prescribed remedy — *"add the missing approved parent"* (`checklist:26`) — is impossible: `accounts` is unique on `(company_id, code)` (`2025_12_30_195200_fix_accounts_unique_constraint.php:22`). Free to close by naming the type in the message, or by reporting the exhausted candidate set.

### 3 — P3 · CONFIRMED · the legacy/backfill installer still resolves the parent by code only, with no type predicate

`InventoryVarianceAccountProvisioner.php:101` (no `type` filter) vs `:194-198` (template path filters on `type`)

Two contracts for the same account on the same table. Not reachable against the three frozen seeders (types verified above), so this is a divergence note rather than a live defect; it becomes reachable for any future chart the command is pointed at. Round 5's #4 is documented at `checklist:29-31` but the code divergence itself is unchanged.

### 4 — P3 · CONFIRMED · the destructive-loss quiet-decline path is now reachable, and one docblock overstates the opposite

`GeneralLedgerService.php:4512-4513` (`hasInventoryWriteOffAccounts` now requires `InventoryShrinkageExpense`), `InventoryGlPostingService.php:99-106` (warn + `null`), `ReturnScrapWriteOffService.php:42-47` ("Every failure path THROWS")

Before M4 the gate resolved `CostOfGoodsSold`, a REQUIRED purpose present in every seeded chart, so the `return null` arm was effectively dead. It is now live for any tenant between code deploy and `tenants:migrate` completing, or any tenant whose migration logged `status=FAILED` (the migration does **not** rethrow — `2026_08_19_130000…php:63-70` — so it is marked run and never retried automatically). **Failure scenario:** a POS scrap or batch write-off in that window commits its `−qty` movement with `unit_cost`/`total_cost` persisted and **no journal entry**; `ReturnScrapWriteOffService`'s own "it never declines quietly" contract is satisfied for the movement pair but not for the GL leg.

Mitigations I verified rather than assumed, which is why this is P3 and not P2: the degrade is a **deliberate, tested** contract (`InventoryGlPostingSeamTest::test_frozen_legacy_chart_without_shrinkage_purpose_guards_damage_as_a_warning_no_op:628-652`); `SystemAccountPurpose::requiredPurposes()` now contains the purpose (`:185`) so per-company chart health flags it, with a red-first guard (`ChartOfAccountsPurposeParityTest:226-238`); the D-a detector still covers write-off movements — `Damage`/`Expiry`/`WriteOff` remain in `$costedExitReasons` (`CheckCogsCoverageCommand.php:165-175`) and neither the `stock_adjustment` nor `inventory_counting` exclusion applies to them — so a costed movement without an entry surfaces after the 2h grace; and `checklist:8-15` makes a missing/`FAILED` token block promotion.

### 5 — P3 · CONFIRMED · seven BatchExpiry fixtures now label the shrinkage account "Cost of Goods Sold" at code `601`

`BatchWriteOffCostPersistenceTest.php:159-163`, `BatchWriteOffDoubleDecrementTest.php:156-160`, `BatchWriteOffScalingTest.php:160-164,186-190`, `GroupedWriteOffRouteTest.php:159-163`, `GroupedWriteOffServiceTest.php:134-138`, `ReverseWriteOffRouteTest.php:150-154`, `ReverseWriteOffServiceTest.php:159-163`

These suites flipped `system_purpose` on the existing `601 / "Cost of Goods Sold"` row instead of adding the approved `6586 / "Inventory Shrinkage Expense"` account, and now seed **no** COGS-purpose account at all. Not vacuous — a revert of the routing would make `getAccountByPurpose(…, CostOfGoodsSold)` (`GeneralLedgerService.php:4814`) throw rather than pass — but the fixtures now assert the correct behaviour under a name that says the opposite, and they cannot distinguish "posted to shrinkage" from "posted to COGS" the way `PosReturnScrapWriteOffTest.php:601-620` and `PosCoreReceiptProjectionRefundDispositionStockTest.php:684-703` do, both of which correctly seed **both** accounts and assert the shrinkage one.

### 6 — P3 · CONFIRMED · `blockers: []` in the machine-readable progress state while two promotion blockers are open

`docs/handoff/progress/wave3-3c-3d.progress.yaml:137`

The wave clears its blocker list, but `M4-evidence.md` records deptrac at **174 violations, `RESULT: FAIL`** at both the pinned base and the tip (checked-in baseline 99, dispatch-stated 111) with a Domain-tier BLOCKER, and S-16's per-tenant duplicate-count query remains an unexecuted parent gate (`checklist:3-4`). Both have tickets (`docs/superpowers/tickets/2026-08-19-wave3d-inherited-deptrac-ratchet-blocker.md`, and the S-16 note), and both are correctly scoped as parent-owned — but a parent resuming from the YAML alone reads "no blockers". Record-keeping only; M4 introduces zero new deptrac edges by the evidence's base-vs-tip measurement.

### 7 — P3 · CONFIRMED · one pre-existing test lost its real publish path to the tightened gate

`BootstrapKeyAssertionImportTest.php:167-183`

`test_migration_down_refuses_historical_published_bootstrap_history` can no longer publish `coa.tn.legacy-v1` through `TemplatePublishingService` (shrinkage is now REQUIRED), so it writes `status`/`certified_by`/`published_at` directly. The substitution is honest and commented, and the publish path is exercised against Option A v2 fixtures elsewhere (`ChartOfAccountsParityTest:90-122`, `CertifiedFixtureDeltaTest:45-54`), but the rollback-refusal guard no longer proves the state it refuses is one the product can actually produce.

---

## Standing checks

- **Rule 19.** Clean. The only literal the provisioner writes is `'balance' => '0.000'` (`:123`) into `accounts.balance decimal(19,2)` — a zero, no precision path. No arithmetic in the new code. Every scale resolution on a console/projection/queue path passes explicit currency: `InventoryGlPostingService.php:64,109,172,214` all use `getScale($ctx->currencyCode)`; no bare no-arg `getScale()` anywhere in the delta; no float touches money or quantity.
- **Constructor injection.** `private readonly` throughout — `InventoryVarianceAccountProvisioner:22-25` (now `DatabaseManager` + `LoggerInterface`), `ChartOfAccountsService:30-35`, `LegacyExistingChartRepairPreviewer:24-28`, `BackfillInventoryShrinkagePurposesCommand:34-39`. `app()` appears only in the two migrations (the accepted `2026_08_11_100300` precedent, asserted by `BootstrapKeyAssertionImportTest:151-166`) and in tests.
- **Tenant scoping.** Every new query is `company_id`-scoped, including both probes in `resolveTemplateParent` (`:178-198`); `tenant_id` is stamped from the caller's company (`:114`); the backfill iterates `companies` on the tenant-bound connection.
- **Migrations additive / unattended-safe.** `2026_08_19_130000` is `Schema`-fail-closed (`:31-33`), wraps `Artisan::call` in `DB::connection($this->getConnection())->transaction()` — a savepoint under PG, the 25P02 remedy — catches `Throwable`, and emits the tenant-attributed token at **warning** (`:52`) / **error** (`:64`), both surviving production `LOG_LEVEL=warning`. `down()` is an explicit irreversible no-op. The connection binding is consistent: the anonymous migration declares no `$connection`, so `getConnection()` is `null` and resolves to the same default connection the command's per-definition savepoints use (`BackfillInventoryShrinkagePurposesCommand:68`). Central migration `2026_08_19_120000` is ordered after the `2026_08_11_100300` bootstrap that creates its `*.legacy-v1` sources.
- **Named queues / Horizon.** None added. N/A.
- **en + fr.** No new user-facing strings. The new `LogicException` (`InventoryGlPostingService.php:137`) and the provisioner's `RuntimeException`s are operator/developer diagnostics on non-UI paths; the overlay warning is a log token. The overlay's account names follow the **resolved plan** rather than the company country (`:203-207`), byte-consistent with the v2 templates asserted in `ChartOfAccountsParityTest::charts()`.
- **Frozen seeders / `.github/workflows/**`.** `git diff --name-only 48cebf0f2..HEAD` intersects neither. F-1 and F-8 respected. F-5 is a ticket with no code. S-16 is recorded, not claimed.
- **Milestone's own invariants — all present and non-vacuous.** F-3 routing is exhaustive: 17 of 17 cases in `glCounterFamily()` with **no** `default` arm (`MovementReason.php:74-93`), `Damage`/`Expiry`/`WriteOff` off COGS at every consumer (`GeneralLedgerService.php:4530-4534`, `InventoryGlPostingService.php:185-187`, `:4814`), and `MovementReasonClassificationTest` re-derives both predicates from the family with an exhaustiveness guard. I independently re-enumerated the detector partitions: `Cogs ∪ Shrinkage` = the old `affectsCOGS()` set exactly (7 reasons), and `requiresGLEntry() && ∉{Cogs,Shrinkage}` = its old complement exactly (5 reasons, `CountCorrection` held by `DirectionalVariance`) — `CheckCogsCoverageCommand.php:165-187` is set-equivalent to the pre-change command, so D-a/D-b/D-e populations did not drift. Backfill run-twice idempotency, purpose-first/code-second precedence, `Schema` fail-closed guards, the `SUMMARY_TOKEN_PREFIX` printed last, the SEEDS-owned purpose guard (`ChartOfAccountsParityTest:72-82` pins COGS/GeneralExpense/CustomerAdvance byte-identical across the v1→v2 boundary), the T20b green-baseline + mutation + restore, and the recorded treasury ruling are all on record.

## Verification I ran myself

| Command | Result |
|---|---|
| `phpunit tests/Unit/Inventory/MovementReasonClassificationTest.php` | **OK (19 tests, 77 assertions)** |
| `phpunit --filter 'test_template_variance_overlay' …/ProvisioningFlagMatrixTest.php` | **OK (2 tests, 5 assertions)** — matches the evidence's round-5 line exactly |
| `phpunit …/ChartOfAccountsParityTest.php …/BackfillInventoryShrinkagePurposesCommandTest.php …/BackfillInventoryShrinkagePurposesMigrationTest.php` | **OK (18 tests, 80 assertions, 2 skipped)** |
| `pint --test` on all 43 changed PHP paths | `{"result":"pass"}` |
| `git status --porcelain` after the review | empty |

**Driver caveat, stated rather than glossed.** This worktree has no `.env`, so phpunit ran on the default `sqlite :memory:` (`phpunit.xml`). Under the house rule PG is the asserting driver, so treat my greens as **corroborative only**. The two skips I saw are precisely the `[PG]`-only savepoint-containment cases — `test_savepoint_contains_one_bad_definition_and_emits_warning_level_gate_token_last` and `test_a_backfill_failure_is_contained_and_emits_a_failed_deploy_gate` — i.e. the 25P02 invariant is proven **only** by the executor's recorded PG runs, not by anything I re-ran. I did not re-run deptrac; round 4's base-vs-tip measurement (`174 / 174`, `RESULT: FAIL` at both) stands unchallenged and M4 adds no edges.

## Bypasses I tried that FAILED (the code held)

1. **Reversal asymmetry across the cutover** — the sharpest available P1: a `Damage`/`WriteOff` posted to COGS *before* the reroute and reversed *after* it, leaving the COGS debit uncleared and shrinkage credited. Both reversal writers mirror the **original entry's `account_id`** rather than re-resolving by purpose: `reverseInventoryMovementEntry` (`GeneralLedgerService.php:4706-4715`) and `reverseInventoryWriteOffEntry` (`:4965-4977`). No asymmetry is constructible.
2. **A `DirectionalVariance` reason reaching `postMovement`** and hitting the new `LogicException` (`InventoryGlPostingService.php:136-138`) as a live 500. Every `MovementGlKind` is hard-coded per call site — `DeliveryNoteService.php:300`, `ReturnNoteService.php:735`, `PosCoreReceiptProjection.php:2042,2441`, `ReceiptReturnService.php:1364`, `ReturnScrapWriteOffService.php:170` — and none pairs `Exit`/`Entry` with `CountCorrection`. The throw is unreachable defensive code.
3. **A template-seeding path that bypasses the overlay.** `TemplateChartOfAccountsSeeder` has exactly one production caller, `ChartOfAccountsService.php:49`, now inside the same `DB::transaction` as the overlay. No second entry point exists.
4. **A production writer calling a frozen seeder directly** (which would produce a chart with no variance accounts, matching the checklist's own claim). The only references outside the seeder files are `ChartOfAccountsService` (which now installs), the rollback-owned `LegacyExistingChartRepairPreviewer` (which now installs, with `SeedChartsCommandTest:308-321` pinning preview/real count parity), and the export-only `LegacyCoaGoldenExporter`.
5. **A detector-population drift from the `affectsCOGS()` → `glCounterFamily()` rewrite** that would blind D-a/D-b or spam D-e. Enumerated both sides by hand against the old frozen expectations; set-equivalent.
6. **A cross-family mis-parenting through the new type predicate** — shrinkage under a revenue root or gain under an expense root. The candidate lists are family-partitioned (`:189-192`) and the probe now requires `type` equality, so the round-5 grafting vector is closed in both directions.
7. **A same-tenant second-company collision on code `6586`.** `accounts` was re-uniqued to `(company_id, code)` in `2025_12_30_195200`, and `(company_id, system_purpose)` is unique from `2025_12_06_002513:53-59`. Multi-company tenants provision cleanly.
8. **A publication rule forcing a `65`/`6000`/`75`/`7000` root into every template**, which would make finding 1 unreachable. `TemplatePublishingService::validateAccountRows:295-333` enforces in-template parent resolution, REQUIRED purposes, timbre scope and the protected-code registry — never a specific family root. The bypass I wanted does not exist, which is what keeps finding 1 CONFIRMED rather than dismissed.

---

**Gate disposition.** The round-5 remediation discharges its register on the terms round 5 itself set: the third-plan overlay now warns and skips the SOFT gain instead of aborting registration, the parent probe is type-checked, the checklist states the qualified contract, and the stale docblock is corrected — all red-first, with the covering test pinning the full warning payload rather than just its absence-of-abort. Everything the milestone's own gate names is present and non-vacuous: an exhaustive 17-case counter-family partition with no `default` arm, `Damage`/`Expiry`/`WriteOff` off COGS at every consumer with no detector-population drift, the destructive-loss repoint gated at both call sites with its degrade explicitly tested, a savepoint-contained backfill emitting a warning-level tenant-attributed token, v2 templates with parity plus the recorded mutation/restore proof, and the treasury ruling on file. Six P3s remain — one recorded residual, two diagnostic/contract divergences, one reachability note on an already-tested degrade, one fixture-naming defect, and two record-keeping items. None blocks a merge, and none is worth a sixth fix round against the brief's `fix rounds ≤ 5` budget; findings 1, 2 and 5 are the ones worth carrying into M5's whole-branch gate.

VERDICT: ACCEPT
