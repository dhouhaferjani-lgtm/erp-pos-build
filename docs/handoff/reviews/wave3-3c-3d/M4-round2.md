## M4 adversarial merge-gate review — round 2

**Diff reviewed:** `48cebf0f2..HEAD` (11 commits, 44 files); remediation slice `01331231c..HEAD` (`81441329a`, `d82670202`, `a422982be`). Amending authority `TREASURY-RULING-2026-08-19-t20-option-a.md` applied — Option A (`6586`/`7586`) and the 17 `MovementReason` classifications are ratified and not relitigated. `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md` (F-1/F-3) read and weighed in finding 1.

**Lens applicability.** inventory-costing — applies (movement→counter-family routing, destructive-loss cost path). treasury — applies (chart provisioning, GL counter-account repoint, backfill atomicity). fiscal-pos — not a named lens; sealed-byte surface re-confirmed untouched (comment-only edits in `PosCoreReceiptProjection.php`, `ReceiptReturnService.php`, `ReturnScrapWriteOffService.php`).

**Round-1 register disposition (verified against code, not the report):** #1 partially closed — see finding 1 below. #2 CLOSED (`ProvisioningRequiredPurposesV1.php:44`, partition now 28/1/4/10). #3 CLOSED (promote, repurpose-refusal, missing-parent, `--dry-run`, schema-guard token all covered, `BackfillInventoryShrinkagePurposesCommandTest.php:64-146`). #4 CLOSED (`codex-dpa-wave3-3c-3d-report.md:426-444`; `GeneralLedgerService.php:4516-4520` docblock corrected). #5 CLOSED (`BackfillInventoryShrinkagePurposesCommand.php:117-128` — refusal now precedes `assertUsable`). #6 CLOSED (`:45-48`). #7 CLOSED (`InventoryVarianceCoaTemplateV2Importer.php:220` — `orderBy('sort_order')->orderBy('code')`). #8 was informational; now documented in the deploy checklist.

---

### 1 — P1 · CONFIRMED · the silent destructive-loss GL hole survives for every company created after the deploy

`app/Modules/Tenant/Application/Services/TenantInitializationService.php:220`; `app/Modules/Company/Presentation/Controllers/CompanyController.php:160`; `app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:41-57`; `apps/api/.env.production.example:24`; `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:164-186`

The remediation repairs **companies that exist when `tenants:migrate` runs**. It does nothing for companies created afterwards, and the production provisioning path is exactly the one that cannot supply the purpose:

- `.env.production.example:24` and `.env.example:22` ship `COUNTRY_DEFAULTS_PROVISIONING_ENABLED=false`, and owner-checklist G4 keeps it false until a separate Release 2. So `ChartOfAccountsService::seedForCompany()` takes the `else` arm (`:52-57`) → the three fingerprint-frozen legacy seeders. `grep -rn "InventoryShrinkageExpense\|InventoryGainIncome" apps/api/database` returns **zero** hits.
- Both live creation paths — new-tenant onboarding (`TenantInitializationService:218-221`) and second-company creation (`CompanyController:160`) — seed the chart and then run expense-category and tax provisioning. Neither invokes `accounting:backfill-inventory-shrinkage-purposes` nor installs the purpose any other way.
- Nothing detects it at creation: `validateCompanyAccounts()` iterates `SystemAccountPurpose::requiredPurposes()` (`SystemAccountPurpose.php:166-186`), which does **not** list `InventoryShrinkageExpense`. The new `REQUIRED` classification (`ProvisioningRequiredPurposesV1.php:44`) is consumed only by `TemplatePublishingService::validateAccountRows` (`:304-308`) — a gate on a path that is off in production.

**Failure scenario.** Deploy → `tenants:migrate` repairs today's companies → the first tenant is onboarded (this wave sits directly before the first-tenant launch). Its chart has `601/603` COGS but no `6586`. A pharmacy writes off an expired lot worth 240.000 TND: `BatchWriteOffService.php:106` calls `createInventoryWriteOffEntry`, `GeneralLedgerService.php:4814` `getAccountByPurpose(InventoryShrinkageExpense)` throws `RuntimeException`, and `BatchWriteOffService.php:122-129` catches it and logs a warning. Batch and aggregate stock decrement; **no journal entry exists**. Same outcome on the POS SCRAP path (`InventoryGlPostingService.php:99-106`). Before this branch the identical operation posted `Dr COGS 240.000 / Cr Inventory 240.000`, because `CostOfGoodsSold` is in `requiredPurposes()` and in all three seeders. Net: GL inventory permanently overstated, destructive losses absent from the P&L, indefinitely, for every newly onboarded tenant.

This is the exact failure class the enum's own H-5 register comment records as a shipped incident (`SystemAccountPurpose.php:178-183`: *"both were absent from this list, so `validateCompanyAccounts()` passed the French chart that could post NEITHER — France booked zero COGS silently"*). SEEDS closed that one by adding the purposes to all three seeders; M4 cannot (F-1 freeze) and substituted nothing for the new-company case.

It is also **pinned as intended behaviour** rather than flagged: `tests/Feature/Inventory/InventoryGlPostingSeamTest.php:628-651` (`test_frozen_legacy_chart_without_shrinkage_purpose_guards_damage_as_a_warning_no_op`) asserts `[null]`, zero journal entries, one warning. And `docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md:33-35` states the exposure — *"A company provisioned later through the legacy-seeder fallback can still lack the purposes… until this same idempotent command is run"* — with **no** step, ordering rule, or gate attached to it.

**Weighing the authorization.** F-1 permits the frozen fallback to *"deliberately omit the purposes behind a guarded no-op"*, and the executor cites it. That reading is defensible for the **dormant** gain purpose (no producer until T21). It is not defensible for shrinkage after F-3's reroute made `Damage`/`Expiry`/`WriteOff` live shrinkage writers in the same commit — the combination converts a working posting into a permanent silent no-op on the only production provisioning path, which is what round 1's finding 1 already established and the executor already accepted for existing tenants. The milestone's own stated requirement (`M4-account-map-proposal.md`: *"`Damage`, `Expiry`, and `WriteOff` must not become no-ops merely because they are removed from `affectsCOGS()`"*) is violated for every future company.

Closing shapes that do **not** touch the frozen seeders: invoke the already-tested, idempotent backfill (or its purpose-first definitions) from the two creation paths after `seedForCompany`; or add `InventoryShrinkageExpense` to `requiredPurposes()` so `validateCompanyAccounts()` at least reports it; or an explicit owner ruling accepting new-company exposure plus a mandatory post-creation checklist step. Any of the three is small; none exists at HEAD.

### 2 — P2 · CONFIRMED · legacy-v1 chart templates can no longer be certified, silently breaking owner-checklist step G2

`app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:44`; `app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:304-308`; `tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php:163-183`; `docs/handoff/OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:89-92`

Moving `InventoryShrinkageExpense` to `REQUIRED` makes `validateAccountRows` throw `DomainException("Missing REQUIRED purpose inventory_shrinkage_expense")` for any template lacking it. The three `coa.*.legacy-v1` bootstrap templates lack it — asserted directly at `ChartOfAccountsParityTest.php:83-86`. The branch's own test proves the consequence: `BootstrapKeyAssertionImportTest` stopped calling `TemplatePublishingService::publish()` on `coa.tn.legacy-v1` and now forges the published state with a raw `admin_templates` UPDATE, commented *"cannot be newly certified now that the live shrinkage purpose is REQUIRED."*

**Failure scenario.** Country-Defaults Phase A step **G2** (still unchecked and owed on staging) instructs a super_admin to *"review and publish the TN, FR, and Generic chart templates through the HTTP surface"* — CLI/synthetic certification explicitly forbidden. After this merge that click returns a 4xx `DomainException` on the v1 templates, blocking G3/G4. The operator is not told anywhere to certify `coa.{tn,fr,generic}.default-v2` instead: `dpa-inventory-shrinkage-deploy-checklist.md` never mentions the v2 templates or G2, and the owner checklist is unamended. The v2 drafts also require a `cloneToDraft` before publish (`ChartOfAccountsParityTest.php:92-102`) — an undocumented extra step.

The change itself is correct (it is round-1 finding 2's fix); what is missing is the cross-program operator instruction and a parent-visible note that a merged program's published activation sequence changed.

### 3 — P3 · CONFIRMED · a parent-marked certification fixture and its pinned content hash were mutated without a recorded parent ruling

`tests/Support/CountryDefaults/M4Fixtures.php:46-66`; `tests/Feature/CountryDefaults/TemplatePublishGateTest.php:50`; `tests/Feature/CountryDefaults/CertifiedFixtureDeltaTest.php:48,53`

The M4 golden fixture now appends `6586`/`7586` to every chart, the publish-gate `content_hash` pin moved from `c3436e61…` to `cffde042…`, and `CertifiedFixtureDeltaTest`'s assertion message was rewritten from *"M0 reconciliation pins an empty certification content delta"* to an Option-A framing. Orchestrator ruling item 1 describes the frozen fixture as a **parent-side** reconciliation artifact. The edits are consequences of finding 2's `REQUIRED` change and are individually reasonable, but a wave milestone re-baselining a parent-pinned hash should be surfaced to the parent explicitly; `M4-evidence.md` records the classification change, not the fixture/hash re-pin.

### 4 — P3 · CONFIRMED · no cutover note for the COGS→shrinkage discontinuity in existing reporting

`app/Modules/Inventory/Domain/Enums/MovementReason.php:71-91`; `docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md`

Historical `Damage`/`Expiry`/`WriteOff` entries stay in `601`/`603`; from the deploy forward they land in `6586`. No backfill or restatement is proposed (correct — a retro reclassification of posted, hash-sealed entries would be worse), but neither the checklist nor `M4-evidence.md` warns that any period-over-period COGS comparison, expense-account report, or expert-comptable export spanning the cutover will show a step change in both accounts. One line in the deploy checklist discharges this.

---

## Standing checks

- **Rule 19 (money/quantity):** clean. No float reaches money or quantity anywhere in the diff. `'balance' => '0.000'` is a string literal (`BackfillInventoryShrinkagePurposesCommand.php:161`, `BackfillInventoryShrinkagePurposesMigrationTest.php:112`); `InventoryGlPostingService::amount()` is unchanged `bcmul`/`bcround`; every scale resolution passes an explicit currency (`getScale($ctx->currencyCode)`), no bare no-arg `getScale()` on a console/queued path.
- **Red-first evidence:** recorded and specific — pre-implementation focused run *34 tests, 9 errors, 26 PG-only skips*; remediation round *12 tests, 49 assertions, 2 failures + 2 errors*, with the four causes named; revert-replay of `d82670202` against the retained guard commit `81441329a` → *4 tests, 3 failures + 1 error*. The guard-tests-first split (`81441329a` before `d82670202`) is the right shape and makes the replay non-vacuous.
- **Constructor injection:** `BackfillInventoryShrinkagePurposesCommand` takes `private readonly DatabaseManager` (`:36`). `app()` appears only in the two migrations (matching the accepted precedent `2026_08_11_100300_import_legacy_coa_templates_as_drafts.php`) and in tests. No `app()` in a service.
- **Tenant scoping:** correct. Every backfill query is scoped by `company_id`, `tenant_id` is stamped from the company row, and the v2 importer is bound to the central connection. The `(company_id, code)` unique index makes the per-company insert loop safe.
- **Migrations additive/unattended-safe:** the tenant migration is fail-closed on missing tables, delegates inside `DB::connection($this->getConnection())->transaction(...)` (savepoint under PG, so no 25P02 poisoning), catches `Throwable`, and emits a distinct warning-level, tenant-attributed token — matching the pinned precedent. `down()` is an explicit irreversible no-op with a covering round-trip test. The central v2 migration is additive and ordered after the v1 bootstrap that supplies its sources; `removeUntouchedDrafts()` refuses assigned or cloned-from templates.
- **Named queues / Horizon:** none added. N/A.
- **en+fr:** N/A — no user-facing strings. Account names are chart data, correctly localised per country (`fr` for TN/FR, `en` for Generic), consistent with the seeders.
- **Milestone's own invariants:** F-3 exhaustive routing present and non-vacuous — 17 enum cases, all 17 enumerated in `glCounterFamily()` with **no** `default` arm (verified by count), and `MovementReasonClassificationTest` re-derives `affectsCOGS`/`affectsShrinkage` from the family. `affectsCOGS()` has zero remaining production call sites outside the family (only docblocks), so the reclassification cannot regress a hidden consumer. The SEEDS-owned purposes guard (`ChartOfAccountsParityTest.php:70-81`) is present and the three seeders are byte-unchanged. T20b's antidote is satisfied in the adapted form the frozen-seeder constraint forces: green baseline recorded, gain-row mutation → RED with exact row deltas, restore → green.

## Bypasses attempted that FAILED (the code held)

1. Sought a way for the tenant migration to abort a whole `tenants:migrate` run — the outer `catch (Throwable)` plus per-definition savepoints contain it; `BackfillInventoryShrinkagePurposesMigrationTest:58-77` proves `7586` still commits after a CHECK constraint rejects `6586`, and the FAILED token fires exactly once.
2. Sought a non-exhaustive arm in `glCounterFamily()` — 17 of 17 cases enumerated, no `default`; adding an enum case is a compile-time break by construction.
3. Sought a surviving consumer of `affectsCOGS()` that relied on `Damage`/`Expiry`/`WriteOff` being true (valuation, WAC, reports) — `grep` over `app` + `database` returns only `GeneralLedgerService:4530` (which now uses `affectsShrinkage()`) and three docblocks. `WeightedAverageCostService:364` is a comment. Clean.
4. Sought a reachable producer for the new `LogicException` on `DirectionalVariance` (`InventoryGlPostingService.php:133-135`) — no site enqueues `CountCorrection` through the ordinary movement seam; dormant guard, not a regression.
5. Sought a `--dry-run` write leak — `:129` and `:149` both gate, and the new test asserts `system_purpose` still null, `is_system` still false, and `7586` absent.
6. Sought a mis-cited line in the throwing-call-site registry — re-read `GeneralLedgerService.php:4591`, `:4592`, `:4814`, `:4815`; all four citations land exactly on their `getAccountByPurpose` calls.
7. Independently re-ran two suites rather than trusting the evidence file: `tests/Unit/Inventory/MovementReasonClassificationTest.php` → **19 tests, 77 assertions, OK**; `tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php` → **11 tests, 429 assertions, OK**. (PG-backed feature suites and PHPStan not re-run here; accepted on the evidence record.)

---

**Gate disposition.** The remediation is real and well-built: the unattended tenant migration, the savepoint containment proof, the SOFT→REQUIRED move, and the five new command branches close round-1 findings 2–8 outright. Finding 1 is the surviving half of round-1's P1 — narrowed from "the whole fleet" to "every company created after the deploy", on the only provisioning path production actually runs, undetected by `validateCompanyAccounts`, and pinned by a test as intended behaviour. Merging as-is means the first tenant onboarded after this deploy silently loses its destructive-loss GL entries. Finding 2 is the operator-facing consequence that would surface the same day, at owner-checklist G2.

VERDICT: CHANGES-REQUIRED
