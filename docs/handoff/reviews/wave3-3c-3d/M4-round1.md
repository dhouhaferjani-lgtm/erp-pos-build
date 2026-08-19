# M4 adversarial merge-gate review — round 1

**Diff reviewed:** `48cebf0f2..01331231c` (6 commits, 37 files). Amending authority `TREASURY-RULING-2026-08-19-t20-option-a.md` (Option A `6586`/`7586`, all 17 `MovementReason` classifications, OQ‑12/H‑5 rider as an **M5** pre‑live gate) applied — the map choice itself is ratified and is **not** relitigated below.

**Lens applicability:** inventory‑costing — applies (movement→counter‑account classification, write‑off cost path). treasury — applies (GL counter‑account repoint, chart provisioning, backfill atomicity). fiscal‑pos — not a named lens here; sealed‑byte surface confirmed untouched (only comment text changed in `PosCoreReceiptProjection.php`, `ReceiptReturnService.php`, `ReturnScrapWriteOffService.php`).

---

## 1 — P1 · CONFIRMED · destructive‑loss GL goes dark on deploy: the repoint ships automatically, the repair does not

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4512`, `:4813`; `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:99-106`, `:185-187`

The write‑off counter account moved from `CostOfGoodsSold` to `InventoryShrinkageExpense`. **Nothing in the repository creates that account on any tenant.** Exhaustive producer grep over `apps/api/app` + `apps/api/database` returns only: the new manual command, the enum, the two consumers, the manifest, and the new **draft** v2 templates. Specifically:

- no tenant migration wires `accounting:backfill-inventory-shrinkage-purposes` — unlike the four sibling precedents, including `apps/api/database/migrations/tenant/2026_08_10_090000_backfill_chart_purposes.php`, whose docblock states the rule verbatim: *"The widening ships automatically on push; a console command documented only in a task report does not."*
- `coa.{tn,fr,generic}.default-v2` are created as `TemplateStatus::Draft` and never published, certified, or assigned (`InventoryVarianceCoaTemplateV2Importer.php:123`);
- `ChartOfAccountsService::seederFor()` still provisions new companies from the frozen legacy seeders, which have neither `6586` nor `7586`;
- no deploy‑checklist artifact ships in this diff (contrast `docs/handoff/dpa-seeds-chart-purposes-deploy-checklist.md`).

**Failure scenario (concrete, no preconditions beyond merging).** Push to `origin/dev` → auto‑deploy → `tenants:migrate`. A pharmacy writes off an expired lot worth 240.000 TND. `BatchWriteOffService.php:106` calls `createInventoryWriteOffEntry`, `getAccountByPurpose(InventoryShrinkageExpense)` throws `RuntimeException`, and `BatchWriteOffService.php:122-129` catches it and logs a warning. Batch stock and aggregate stock are decremented; **no journal entry exists**. Same outcome on the POS SCRAP path (`InventoryGlPostingService.php:99-106` returns `null` with a warning). Before this commit both posted `Dr COGS 240.000 / Cr Inventory 240.000`. Net effect: GL Inventory is permanently overstated, destructive losses vanish from the P&L, and the stock ledger and balance sheet diverge — for **every** company, on **every** write‑off, until a human runs a command that no artifact schedules.

This also inverts the milestone's own stated requirement — `M4-account-map-proposal.md`: *"`Damage`, `Expiry`, and `WriteOff` must not become no‑ops merely because they are removed from `affectsCOGS()`."* On every real tenant they are exactly that.

Mitigation present but insufficient: `CheckCogsCoverageCommand` D‑a (`:196-213`) still covers these reasons (`$costedExitReasons` includes Shrinkage), so the loss is *detectable* after the fact by a manually‑run detector. Detection ≠ prevention.

Remediation shape is already proven in‑repo: a tenant migration delegating to the command inside `DB::connection($this->getConnection())->transaction(...)` with a distinct warning‑level gate token, per `2026_08_10_090000_backfill_chart_purposes.php`.

## 2 — P2 · CONFIRMED · no gate forces the purpose into any published or provisioned chart

`apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:78-82`

`InventoryShrinkageExpense` stays `SOFT`, with the amended rationale *"Destructive‑loss posting preflights the purpose and fails soft for frozen legacy charts."* The claim is literally true (all three consumers preflight — verified at `InventoryGlPostingService.php:99`, `:161`, `:53`, and `hasInventoryWriteOffAccounts` at `:4510`), and no hard 500 path exists. But `SOFT`/`REQUIRED` is the *only* remaining gate that could force the account into a certified chart, and the purpose now backs a **live production writer** rather than a dormant T21 one. Failure scenario: an admin certifies and assigns `coa.tn.legacy-v1` (or any v1‑derived chart) as the TN default; `TemplatePublishingService::validateAccountRows` raises no objection; every company provisioned from it is silently incapable of posting a destructive loss, indefinitely. The comment's "frozen legacy charts" framing understates the blast radius: this is the state of **all** charts at HEAD, new and existing.

## 3 — P2 · CONFIRMED · the backfill's documented core contract is largely untested

`apps/api/app/Console/Commands/BackfillInventoryShrinkagePurposesCommand.php:114-144`; `apps/api/tests/Feature/Accounting/BackfillInventoryShrinkagePurposesCommandTest.php`

Four tests cover: purpose‑first + idempotency, legacy‑holder precedence, FR/Generic parents+names, and savepoint containment (the 25P02 antidote is genuine — the CHECK‑constraint injection at `:107` proves `7586` still commits after `6586` fails). Untested:

- the **`promoted`** branch (`:114-134`) — an unpurposed `6586` promoted to carry the purpose. This is half of the "purpose‑first/code‑second" contract named in the class docblock (`:18-19`) and in the brief.
- the **repurpose refusal** branch (`:117-124`).
- the **missing‑parent failure** branch (`:136-144`).
- **`--dry-run`** (`:30`) — the option exists and gates both writes (`:125`, `:145`) with zero coverage; the pinned precedent `BackfillTolerancePurposesCommandTest` tests it.

Failure scenario: a later edit drops `'is_system' => true` from the promote update at `:126-130`, or `--dry-run` starts writing; the suite stays green and the defect reaches an unattended tenant run.

## 4 — P3 · CONFIRMED · handback narrative contradicts the tree

`docs/sessions/codex-dpa-wave3-3c-3d-report.md` (M4 section, added lines) still asserts *"No production, migration, seeder … file changed"* and *"M4 returns to `blocked_owner` before T20/T20b implementation; no adversarial review or fix round is consumed."* At HEAD, 13 production/migration files changed and M4 is `status: review`. The progress YAML and `M4-evidence.md` are correct; the session report was never advanced past the proposal‑only phase and will mislead the next reader of the wave's own record.

Same class: `GeneralLedgerService.php:4516-4519` docblock still reads *"Count‑correction purposes are installed by Wave 3D before that kind is reachable; Wave 3C exits and returns use COGS as the counter‑account"* — the first clause is now false (nothing installs them, per finding 1) and the second no longer covers the shrinkage arm three lines below.

## 5 — P3 · CONFIRMED · misleading diagnostic ordering in the refusal path

`apps/api/app/Console/Commands/BackfillInventoryShrinkagePurposesCommand.php:116-124`

`assertUsable()` runs before the "refusing to repurpose" check. An existing `6586` that carries a foreign `system_purpose` *and* a non‑`expense` type reports *"has wrong type"* — the operator fixes the type, re‑runs, and only then learns the account is already claimed. Two deploy cycles for one diagnosis.

## 6 — P3 · CONFIRMED · gate token is skipped on the schema‑guard path

`apps/api/app/Console/Commands/BackfillInventoryShrinkagePurposesCommand.php:43-47`

The fail‑closed guard returns `FAILURE` **before** `SUMMARY_TOKEN_PREFIX` is emitted at `:93-95`. Since `tenants:run` swallows child exit codes (the stated reason the token exists), a checklist grepping for the token finds nothing and can read "no failures". Inherited verbatim from the pinned precedent `BackfillTolerancePurposesCommand.php:103-109`, so it is faithful copying rather than new drift — noted, not charged against M4.

## 7 — P3 · PLAUSIBLE · idempotency assertion depends on unique `sort_order`

`apps/api/app/Modules/CountryDefaults/Infrastructure/Import/InventoryVarianceCoaTemplateV2Importer.php:209`, `:220`

`assertExisting()` compares `rows($template)` (ordered by `sort_order`) against `expectedRows()` (source iteration order + two appended). If any legacy‑v1 template ever carries duplicate `sort_order` values, PostgreSQL's ordering among ties is unspecified and a re‑run can throw `"row content mismatch"` on an untampered template. Legacy v1 rows are exporter‑generated and appear unique today, so this is latent, not live.

## 8 — P3 · CONFIRMED · non‑TN/FR template‑provisioned charts are counted as backfill failures

`apps/api/app/Console/Commands/BackfillInventoryShrinkagePurposesCommand.php:187`, `:194`, `:201`

`$frenchPlan = TN|FR` mirrors `ChartOfAccountsService::seederFor()` exactly, so legacy‑provisioned charts resolve correctly. But a company whose chart came from an assigned country‑defaults template with a PCG‑shaped hierarchy under, say, `MA` will look for parent `6000`, not find it, and increment the failure counter — turning the deploy gate red for a chart that is merely differently shaped. Fail‑loud is the right default; flagging so the gate's semantics are understood before it fires.

---

## Standing checks — findings and clean results

- **Rule 19 (money/quantity):** clean. No float touches money or quantity anywhere in the diff. `'balance' => '0.000'` is a string literal (`:158`); `InventoryGlPostingService::amount()` (`:216-219`) is unchanged `bcmul`/`bcround` at `scale+6`; every scale resolution passes an explicit currency (`getScale($ctx->currencyCode)` at `:64`, `:109`, `:172`) — no bare no‑arg `getScale()` in the console/queued paths.
- **Constructor injection:** `BackfillInventoryShrinkagePurposesCommand` injects `DatabaseManager` as `private readonly` (`:36`). `app()` appears only in the migration (`2026_08_19_120000_...:12,17`), byte‑identical in shape to the accepted precedent `2026_08_11_100300_import_legacy_coa_templates_as_drafts.php`, and in tests. No `app()` in a service.
- **Tenant scoping:** correct. Backfill scopes every query by `company_id` and stamps `tenant_id` from the company row; the v2 importer is explicitly bound to the central connection (`:266-269`). Confirmed the `(company_id, code)` unique index (`2025_12_30_195200_fix_accounts_unique_constraint.php:22`) makes the per‑company insert loop safe — my collision hypothesis was wrong.
- **Migrations additive/unattended‑safe:** the central migration is additive and ordered after the v1 bootstrap that supplies its required source templates. `down()` refuses to remove an assigned or cloned‑from template (`:48-53`). **But see finding 1** — the *tenant‑side* half of "unattended‑safe" is missing entirely.
- **Named queues / Horizon:** none added. N/A.
- **en+fr i18n:** N/A — no user‑facing strings added. Account names are chart data, correctly localised per country (`:192`, `:199`) consistent with the seeders.
- **Milestone's own invariants:** F‑3 exhaustive routing is present and non‑vacuous (`MovementGlCounterFamily` has no `default` arm; `MovementReasonClassificationTest` pins all 17 cases *and* re‑derives `affectsCOGS`/`affectsShrinkage` from the family). The SEEDS‑owned purposes guard is present (`ChartOfAccountsParityTest.php:72-82`) and the seeders are byte‑unchanged. The 25P02 savepoint remedy is implemented and proven. The T20b antidote is satisfied in the adapted‑but‑faithful form the frozen‑seeder constraint forced: baseline green recorded, mutation of the v2 importer's gain row → RED (3 failures, exact row counts), restore → green.

## Bypasses attempted that FAILED (i.e. the code held)

1. Sought a hard‑throw path when `InventoryShrinkageExpense` is unmapped (a 500 or an aborted POS return transaction) — all four call sites preflight or catch. No hard failure exists; the regression is silent, not fatal.
2. Sought a double‑post (movement buffer + direct write‑off entry) on the batch path — `StockAdjustmentService` enqueues nothing; only `BatchWriteOffService` and `ReturnScrapWriteOffService` post. Clean.
3. Sought a production producer that would reach the new `LogicException` for `DirectionalVariance` (`InventoryGlPostingService.php:136-138`) — no site enqueues `MovementGlKind::Exit`/`Entry` with `CountCorrection`; the five enqueue sites are Delivery/Return/POS only. Unreachable guard, not a regression.
4. Sought a unique‑constraint collision from per‑company `6586` inserts under `unique(tenant_id, code)` — superseded by `(company_id, code)` in 2025‑12‑30. Wrong hypothesis.
5. Sought missing parent codes — `65`/`75` present in the TN and FR seeders, `6000`/`7000` in Generic; `6586`/`7586` unused in all three.
6. Sought an untracked file (`Tests\Support\CountryDefaults\M4Fixtures`, referenced by a new test but absent from the diff) — tracked since `cd73d36fd`; working tree clean.
7. Independently re‑ran `tests/Unit/Inventory/MovementReasonClassificationTest.php` — **19 tests, 77 assertions, OK**. `pint --test` on the five changed production files — **pass**. (PG‑backed suites and PHPStan not re‑run here; accepted on the evidence record.)

---

**Gate disposition.** The Option A map, the frozen‑seeder‑respecting template strategy, the exhaustive counter‑family classification, and the savepoint/token discipline are all correct and well evidenced. Finding 1 alone blocks: this branch, merged and auto‑deployed, converts a working `Dr COGS / Cr Inventory` destructive‑loss posting into a silent no‑op for every company in the fleet, and the repair is a command no artifact runs. Findings 2 and 3 are the surrounding gaps that let it ship unnoticed.

VERDICT: CHANGES-REQUIRED
