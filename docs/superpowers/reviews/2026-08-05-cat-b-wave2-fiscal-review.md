# Adversarial fiscal review — cat-(b) wave 2 verifier/gate conversions

**Scope:** `dbc5476aa` (preflight-gate), `97f123c1c` (fiscal:verify-chains + pos:verify-chains),
`6c07d2730` (fiscal:verify-event-chain + fiscal:enqueue-resolved-event-projections),
`43e14a041` (fiscal backfills). Context: `6037c8493`, `7686be04e`, `d21811a7a`, `61189c8b8`.
**Reviewer:** fiscal-pos-reviewer (read-only). **Date:** 2026-08-05.
**Verdict: CHANGES-REQUESTED** — 3 blockers, 6 required, 4 minor.

These commands produce E-7/§Y launch evidence. Two of the three blockers make a FAILING run
print the exact string the launch checklist ticks as PASS.

---

## Verdict on each attack line

| # | Question | Answer |
|---|---|---|
| 1 | Verification logic invariance | **Chain math: unchanged.** `verifyReceiptChain` / `findReceiptChainBreak` / `verifyZReportChain` / `walkChain` / `resolveExpectedPreviousHash` / `verifyChainForCompanyAndType` are byte-identical (diffs touch only `handle()`→`executeCommand()`, `resolveTerminals`, + a `stringOption` helper). **But the two claimed exceptions are NOT the only behaviour deltas** — see B2, R1, M1, M2. |
| 2 | Preflight fail-closed rebuild | Missing table ✅ (code + test). Zero tenants ✅ (code + test). Probe-skipped-not-inherited ✅ in code, ❌ **untested**. `whereNotNull→orWhere` grouping ✅ correct and genuinely covered. New fail-OPEN hole introduced: R2. |
| 3 | Per-tenant verdict attribution | Gate ✅. Two verifiers ❌ — a probe-skipped tenant is silent and keeps the run green (B3). Aggregate does reflect any single failing tenant. |
| 4 | Connection pinning fix (6c07d2730) | **Incomplete** — B1. |
| 5 | `--tenant` required / docs | Yes, 5 documented procedures broken or stale — R5. |
| 6 | INVALID(2)→1 remap | **Complete** in both fiscal-event commands; no operator-error path returns 2. Caveat M3. |
| 7 | Multi-tenant test fixtures | `fiscal:verify-chains` = REAL posted chains + real post-seal tamper (good). `pos:verify-chains` = synthetic rows; the "A passes" leg is an EMPTY chain (M4). A genuinely broken chain in tenant B **does** turn the aggregate red in both. |

---

## BLOCKERS

### B1 — [Critical] Connection-pinning fix is incomplete: `pos:verify-chains` still verifies through a central-pinned connection
`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:57` (`private readonly ConnectionInterface $db`),
used at `:213` (`verifyTerminalChainFiscalArm`) and `:450` (`verifyHash`).

`6c07d2730` correctly identified the hazard ("a connection captured at construction stays pinned
to central") and fixed it in `VerifyEventChainCommand` / `EnqueueResolvedEventProjectionsCommand`
via `DatabaseManager` + a call-time `db()`. `97f123c1c` converted `pos:verify-chains` to per-tenant
iteration but its constructor-injected `ReceiptHashService` (`VerifyPosChainCommand.php:64-70`)
holds a `ConnectionInterface` resolved when the command is built — i.e. at console bootstrap,
before `forEachTenant` calls `tenancy()->initialize()`.

Verified mechanism: `db.connection` is a **bind** returning `$app['db']->connection()`
(`vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php:71-73`), and
Stancl's `connectToTenant()` only purges the `tenant` connection and changes the *default
connection name* (`vendor/stancl/tenancy/src/Database/DatabaseManager.php:41-45`). An
already-handed-out central `Connection` object stays central.

Impact under db-per-tenant: the **authoritative** arm of the POS chain verifier queries
`fiscal_events` on CENTRAL → 42P01 → thrown inside the closure → swallowed by
`TenantScopedCommand.php:267-274` into a FAILURE with **only a Log::error**. Combined with B2 the
run prints `All chains verified successfully.` and exits 1. The sibling
`ZReportHashService.php:257-258` resolves at call time and is unaffected.

*Fix:* inject `DatabaseManager` and resolve per call, mirroring `VerifyEventChainCommand.php:114-118`.

### B2 — [Critical] Both verifiers print the documented PASS banner on a non-zero exit
`apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:190-198`;
`apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php:162-175`.

`$hasFailure` / `$invalidChains` only capture verdicts the closure *computed*. A tenant whose
closure THREW is turned into FAILURE by `forEachTenant` without touching either counter, while
`$terminalsVerified` / `$companiesVerified` were already incremented — so control reaches the
success branch, prints `All chains verified successfully.` / `Status: ALL CHAINS VALID ✓`, then
returns `$exit = 1`.

The launch checklist ticks on those exact strings:
`docs/qa/2026-05-12-first-tenant-smoke.md:117` (D.1 "output ends Status: ALL CHAINS VALID ✓") and
`:118` (D.2 "output ends All chains verified successfully."). With B1 this is the *expected*
outcome of a real db-per-tenant run.

*Fix:* gate the success banner on `$exit === SUCCESS`; print an explicit per-tenant error line for
a thrown tenant (forEachTenant's continue-on-throw is log-only by design, so the caller must
surface it).

### B3 — [Critical] A probe-skipped tenant produces a green fleet run in both verifiers
`apps/api/app/Console/TenantScopedCommand.php:242-252` — an unprovisioned tenant DB is skipped
with `Log::warning`, no console output, aggregate untouched.

`PreflightFiscalGateCommand.php:125,143-150` handles this correctly (`skipped > 0` ⇒
"unable to verify" + non-zero). **Neither verifier reads `skippedTenantIds()`**, so
`fiscal:verify-chains` / `pos:verify-chains` can report `ALL CHAINS VALID` and exit 0 while N
tenants were never opened. `failIfTenantFilterUnvisited` only saves the explicit-`--tenant` case.

Compounding: a tenant with no companies (`VerifyFiscalChainsCommand.php:127-130`) or no active
terminals (`VerifyPosChainCommand.php:112-115`) emits **no line at all**, so the E-7 pack cannot
demonstrate coverage — only tenants that happened to have data appear.

*Fix:* mirror the gate — report skipped tenants and fail closed; emit one line per visited tenant
including "nothing to verify".

---

## REQUIRED

### R1 — [Important] Invalid `--type` now prints `ALL CHAINS VALID ✓` and exits 1
`VerifyFiscalChainsCommand.php:113-118` — `DocumentType::from($documentType)` is inside the
per-tenant closure; a `ValueError` is caught by `TenantScopedCommand.php:267`. `$companiesVerified`
was incremented at `:110` before the throw, so the summary block runs. Pre-conversion the
`ValueError` was uncaught and loud. Validate `--type` up front (as `pos:verify-chains` does for its
own `--type` at `VerifyPosChainCommand.php:73-78`).

### R2 — [Important] The destructive-rebuild gate re-introduced a fail-open path via the tenant predicate
`PreflightFiscalGateCommand.php:191-208` + `:220-223`. Under db-per-tenant the bound database is
*by definition* that tenant's, but the counts now only see rows whose `tenant_id` equals the
directory id (and z-reports/prints only via terminals owned by that tenant). A row with a
mismatched/legacy `tenant_id`, or an orphaned `pos_z_reports` / `pos_receipt_prints` row, is
invisible ⇒ "SERVER SURFACE: clear" ⇒ green light for schema destruction. The pre-conversion count
had **no** predicate and could not miss rows. For a fail-closed gate the safe reading is
"count everything in the bound database".
*Fix:* keep the predicate only in single-schema compat mode, or add an explicit
`foreign_tenant_id_rows` finding.

### R3 — [Important] Unrelated tenants leak into a single-tenant verdict; O(N) tenant connections
`TenantScopedCommand.php:206-287` probes AND `tenancy()->initialize()`s **every** directory tenant;
`forEachTenantFiltered` applies the filter inside the closure (`:310-316`). So a probe throw on an
unrelated tenant (`:219-240`) degrades the aggregate: `fiscal:verify-event-chain --tenant=A` can
print `chain verified — …` and exit 1 with the reason only in the log. Also opens N tenant
databases to verify one.
*Fix:* when a single tenant is named, resolve/bind it directly and consider only its slot.

### R4 — [Important] The wave's central claim is untested — the suite never runs db-per-tenant
`apps/api/phpunit.xml:46` forces `TENANCY_DB_PER_TENANT=false`. Every new test therefore exercises
only the single-schema `tenant_id`-predicate path. `tenancy()->initialize()`, the `databaseExists`
probe, the skip branch, the probe-throw branch and the B1 pinning behaviour are **all**
unexercised. Of the three preflight fail-closed behaviours: missing table ✅
(`PreflightFiscalGateCommandTest.php:69`), zero tenants ✅ (`:58`), probe-skipped ❌.
*Fix:* one test that flips `config(['tenancy_resolver.db_per_tenant' => true])` with a directory
row whose database does not exist, asserting the skip is reported and the gate fails.

### R5 — [Important] Documented E-7/§Y procedures broken or stale by this wave (list only, do not edit)
- `docs/runbooks/fiscal-verify-all-chains.md:51-59` — wrapper pseudocode calls
  `Artisan::call('fiscal:verify-chains' | 'pos:verify-chains' | 'fiscal:verify-event-chain')`
  INSIDE `forEachTenant`. All three now drive their own `forEachTenant`; the inner
  `tenancy()->end()` (`TenantScopedCommand.php:276-278`) tears down the OUTER binding and the loop
  becomes O(N²).
- `docs/superpowers/specs/2026-06-24-parapharmacy-launch-e2e-campaign-design.md:71` —
  `php artisan fiscal:enqueue-resolved-event-projections --actor-id=<user-uuid>` now exits 1
  ("Missing --tenant option").
- `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v5.md:645` (and `…-v4.md:633`,
  v7 equivalent) — §15.2 documents `{--fiscal-event-id=} {--tenant=}` with `--tenant` optional.
- `docs/qa/2026-05-12-first-tenant-smoke.md:117-118` and
  `docs/qa/2026-08-01-money-test-plan.md:1652-1653` — pass criteria are output strings; per B2
  those strings can appear on a failing run. Must read "AND exit code 0".
- No change needed for D.3 (`docs/qa/2026-05-12-first-tenant-smoke.md:119`), Y-1
  (`docs/qa/2026-08-01-money-test-plan.md:1651`) or
  `docs/qa/2026-05-12-migration-audit-and-rollback.md:39-41` — they already pass `--tenant`.
  No in-app `Artisan::call` of either fiscal-event command exists, so the required-`--tenant`
  change breaks no runtime path.

### R6 — [Important] `--fix` is still a declared, dangerous, dead flag
`VerifyFiscalChainsCommand.php:52` declares `{--fix : Attempt to fix broken chains (dangerous)}`;
nothing reads it. `docs/qa/2026-05-12-first-tenant-smoke.md:123` and
`docs/qa/2026-08-01-money-test-plan.md:1653` both warn operators never to pass it. The commit
rewrote this class docblock and left the flag. A server-side "fix" of a device-authored chain
would be a fiscal-integrity defect if ever implemented — remove the flag.

---

## MINOR

- **M1** `VerifyPosChainCommand.php:214-218` — the historic `Terminal 'X' not found.` message is
  gone and `Verifying %d terminal(s)...` became `TENANT … verifying …`. The commit claims verbatim
  output preservation only for preflight-gate; existing D.2 transcripts/greps break.
- **M2** `forEachTenantFiltered` returns `INVALID` (2) for an unknown `--tenant`
  (`TenantScopedCommand.php:433-438`). The two fiscal-event commands remap it; the two
  document/POS verifiers do not, so they can now exit 2 — a code their contract never used.
- **M3** Remap completeness (Q6): `VerifyEventChainCommand.php:180-187` and
  `EnqueueResolvedEventProjectionsCommand.php:171-176` both remap; exit 2 remains reserved for the
  documented transient paths. Caveat: a Throwable escaping outside those commands' inner
  try/catch — e.g. the relocated permission gate's `User::query()->find()`
  (`VerifyEventChainCommand.php:225-231`) — is now remapped by `forEachTenant` to **1**, where a
  transient DB fault previously aborted loudly.
- **M4** Test quality: the POS multi-tenant fixture's clean leg is an EMPTY chain
  (`VerifyPosChainCommandTest.php:30-46`, `:170-176`); no new test proves a non-empty VALID chain
  still passes under per-tenant iteration. The `fiscal:verify-chains` fixture is materially better
  (real `postingService->post()` chains + a real post-seal tamper with the PG immutability trigger
  disabled). Both prove a broken tenant B turns the aggregate red.

---

## Confirmed-good (no action)

- Chain-walk / rehash / link-check code paths are byte-identical in all three verifiers.
- Grouping truth table for the chain-state probe: before `L ∨ S` (fleet-wide, no tenant
  predicate); after `T ∧ (L ∨ S)`. Per-row blocking semantics unchanged; grouping is required
  because AND binds tighter than OR, and it is genuinely covered by
  `PreflightFiscalGateCommandTest.php:236-250` (ungrouped, the clean tenant would inherit the dirty
  terminal's `current_sequence > 0`). Note it is not a *pre-existing latent* bug — pre-conversion
  there was no tenant predicate at all; the grouping prevents a bug this commit would have
  introduced.
- Refund/void model untouched; no new fiscal Event class, rename or parallel refund type.
- No money/quantity math added; no float, no bare `getScale()`, no new `onQueue`.
- The gate's zero-tenant and missing-table fail-closed contracts are correct and tested.
- `43e14a041` / `7686be04e` / `6037c8493`: `forEachExplicitlySelectedTenant` correctly refuses an
  implicit fleet run for one-shot backfills; hashing/creation logic untouched.

---

# Re-gate — cat-(b) wave-2 FIX ROUND

**Scope:** `2de2a8157` (B1 connection), `c101fc7d2` (directory narrowing + R2/R3), `8097fa809`
(banner truth + coverage + R1/R6/M2/M3), `6abe9d8fe` (justification corrections),
`c3fbab2ae` (tenancy minors), `186b19fe6` (runbook).
**Reviewer:** fiscal-pos-reviewer (read-only; working tree left clean). **Date:** 2026-08-05.

**VERDICT: spec ✅ — quality APPROVE-WITH-FIXES.** All 3 fiscal blockers and all 6 required items
are genuinely closed and empirically demonstrated. The 4 residual findings are docs/ops and one
untested rewrite; none blocks the fiscal contract.

## Evidence actually gathered (not claims)

- **B1 closed at both call sites.** `ReceiptHashService.php:58` injects `DatabaseManager`;
  `:79-82` resolves at call time; `:236` (`verifyTerminalChainFiscalArm`) and `:473`
  (`verifyHash`) both go through `db()`. No `ConnectionInterface` remains in the constructor.
- **The regression fixture genuinely inverts.** I temporarily re-introduced the constructor pin
  (capture `$databaseManager->connection()` in `__construct`, serve it from `db()`) and re-ran
  `tests/Feature/POS/VerifyPosChainCommandDbPerTenantTest.php`: leg 1 (tenant-clean chain, corrupt
  `fiscal_events` row planted in CENTRAL) failed `Expected status code 0 but received 1`; leg 2
  (corruption inside the bound tenant) failed `Expected status code 1 but received 0`. Both legs
  invert on the pin and pass without it (`OK (3 tests, 7 assertions)`). File restored, tree clean.
- **The db-per-tenant legs really run in that mode.** `TenancyServiceProvider.php:48-60` gates
  `BootstrapTenancy` at FIRE time, not boot time, so the per-test
  `config(['tenancy_resolver.db_per_tenant' => true])` does activate the real connection swap —
  and the inversion above is end-to-end proof that reads landed in a different database. 15 legs
  now run in that mode (9 helper + 3 gate + 3 POS), plus 1 in the genesis-document test.
- **Suites run green:** `VerifyPosChainCommandDbPerTenantTest` (3), `TenantScopedCommandForEachTenantTest`
  + `PreflightFiscalGateCommandTest` (39), `VerifyFiscalChainGenesisDocumentTest` +
  `VerifyPosChainCommandTest` + `VerifyEventChainCommandTest` + `AuditDiscountsCommandTest` (55).
  PHPStan over the 8 changed app files: **no errors**.
- **C2 — every emission site checked.** `grep "ALL CHAINS VALID|verified successfully"` over `app/`
  returns exactly 3 live sites. `VerifyFiscalChainsCommand.php:247` and
  `VerifyPosChainCommand.php:257` are both behind `unaccounted === [] && $exit === SUCCESS`.
  Early returns audited: invalid `--type` returns before the summary
  (`VerifyFiscalChainsCommand.php:102-110`); zero tenants lands on "No companies found." /
  "No active terminals found."; an unknown `--tenant` (base INVALID=2) falls through to the
  `$exit !== SUCCESS` branch and is remapped to 1 (`:239-245`, `VerifyPosChainCommand.php:249-255`).
- **C3 — coverage classes complete.** `TenantScopedCommand::reportTenantCoverage()` (`:506-539`)
  emits a line for every *visited* id (verdict, or ERRORED when the closure threw and left no
  verdict) and every *skipped* id, plus an explicit "(no tenant matched this run)". Both verifiers
  set a verdict on every non-throwing path including NO-DATA (`VerifyFiscalChainsCommand.php:140`,
  `VerifyPosChainCommand.php:124`). The only tenant that can be absent is a directory row created
  *after* the `Tenant::all()` snapshot (`TenantScopedCommand.php:366-377`) — see M-a.
- **R1/R3 — narrowing is in the directory query.** `directoryTenants()` (`:366-377`) returns
  `whereKey($filter)`; `forEachTenantNarrowed` probes/initializes only what that returns.
  `TenantScopedCommandForEachTenantTest` asserts visited==[target] and skipped==[] with an
  unprovisioned bystander present, and that a global probe fault is confined to the named tenant.
  Unknown `--tenant` still yields INVALID at the helper (`:574-579`) and both fiscal-event commands
  remap it to 1 (`VerifyEventChainCommand.php:182-189`). The gate's `$skipped` is scoped by
  construction (`PreflightFiscalGateCommand.php:125-131`) and pinned by
  `test_a_scoped_gate_run_ignores_an_unrelated_unprovisioned_tenant`.
- **R2 (fiscal) — the orphan finding is a GATE FAILURE, not a note.**
  `unownedRowCount()` (`:243-261`) is merged into `$findings` (`:217-219`), so it flows through
  `array_sum($findings) === 0` (`:103`) ⇒ "NON-EMPTY" ⇒ per-tenant `self::FAILURE` (`:121`) ⇒
  non-zero aggregate, before any rebuild is authorised. db-per-tenant only, deliberately.
  Covered by `test_gate_fails_on_a_row_the_tenant_predicate_would_hide`.
- **R4 justifications (`6abe9d8fe`) — the four rewritten claims are now TRUE.** Guards verified:
  `BackfillBanksCommand.php:84-90`, `BackfillTolerancePurposesCommand.php:103-109`,
  `ConfigureCashRoundingCommand.php:106-117`, `SeedChartsCommand.php:77-83`. Both corrected command
  names are real: `treasury:backfill-banks` (`BackfillBanksCommand.php:70`), `accounting:seed-charts`
  (`SeedChartsCommand.php:63`). See M-b for the line numbers they cite.
- **R6** `--fix` is gone from the signature; `test_the_dead_fix_flag_is_gone` asserts it against the
  console kernel definition. Remaining `--fix` mentions are in the Phase-E sheets, ticketed in
  `docs/superpowers/tickets/2026-08-05-wave2-phase-e-doc-updates-owed.md`.
- **Fiscal-contract invariants re-checked:** no device-authored fact is re-authored server-side; no
  Event class renamed/restructured; refund/void model untouched; no money/quantity math added; no
  float; no bare `getScale()`; no new `onQueue`. Chain-walk bodies unchanged in this round.
- **Bonus, verified not a defect:** `FiscalEventProjectionRegistry` is a process-lifetime singleton
  (`FiscalServiceProvider.php:37-43`) that materialises `PosCoreReceiptProjection`, which
  constructor-injects `ReceiptHashService` — so the pre-fix pin also sat in the live projection
  graph. It was inert only because the projection calls just `hashVATBreakdown` /
  `hashPaymentMethods` (`PosCoreReceiptProjection.php:2130,2163`), neither of which touches the
  connection. `2de2a8157` removes that latent hazard as well.

## Findings (none blocking)

### [Important] R-1 — the documented D.1/D.2 launch recipe is a FLEET run that now fails on any unprovisioned directory row
`docs/runbooks/fiscal-verify-all-chains.md:37-49` heads a block "Manual run (single tenant)" whose
document/POS commands pass only `--company`, and
`docs/superpowers/tickets/2026-08-05-wave2-phase-e-doc-updates-owed.md:64-73` calls `--tenant`
"Optional; the existing `--company`-only form is still correct". Post-fix that is no longer the
whole truth: without `--tenant` the run iterates the entire directory, and ONE archived /
failed-provision tenant row makes it print `Status: INCOMPLETE` and exit 1
(`VerifyFiscalChainsCommand.php:221-237`) for reasons unrelated to the tenant under test. That is
correct fail-closed behaviour for a fleet run and exactly what B3 asked for — but D.1/D.2 are P0
launch gates, so the recipe should be the scoped form. Same applies to the nightly schedule added at
`docs/runbooks/fiscal-verify-all-chains.md:95-105`: a chronically unprovisioned directory row will
page on-call every night, which is how a fail-closed gate gets muted.
*Fix:* make `--tenant=<TENANT_UUID>` the documented form for D.1/D.2 (not "optional"), and add one
line to the runbook on what to do with a permanently unreachable directory row.

### [Minor] M-a — the coverage block's own docblock over-claims
`TenantScopedCommand.php:498-500` says a reviewer "must be able to see that EVERY directory tenant
was accounted for", but the block reports the tenants this run *touched*, taken from the
`Tenant::all()` snapshot at `:369`. A row inserted after the snapshot is silently outside the
evidence. The command-level docblocks word it correctly ("every directory tenant it touched").
*Fix:* align the base docblock to "every tenant this run touched", or count the directory once and
report the delta.

### [Minor] M-b — the corrected `@cross-tenant-by-design` annotations cite line numbers their own edit invalidated
`6abe9d8fe` lengthened each docblock by 3–4 lines, shifting the guards it points at:
`BackfillBanksCommand.php:64` cites `(:81-87)`, actual guard `84-90`;
`BackfillTolerancePurposesCommand.php:75` cites `(:99-105)`, actual `103-109`;
`ConfigureCashRoundingCommand.php:68` cites `(:102-113)`, actual `106-117`;
`SeedChartsCommand.php:57` cites `(:74-80)`, actual `77-83`. All four also quote the predicate
without its negations (`Schema::hasTable('companies') || Schema::hasTable('banks')` — the code is
`! … || ! …`). The commit's own thesis is that the annotation is evidence, so a stale pointer is the
same defect class in miniature. Feeds the AST-check ticket
(`docs/superpowers/tickets/2026-08-05-cross-tenant-annotation-ast-check.md`).

### [Minor] M-c — `parapharmacy:migrate-data`'s rewritten transaction and `--limit` cap are untested and unanalysed
`c3fbab2ae` replaced `DB::transaction(closure)` with manual
`beginTransaction`/`rollBack`/`commit` (`MigrateParapharmacyDataCommand.php:167-213`) and moved the
`--limit` cap into the `chunk()` callback (`:119-145`). The only test that names this command is the
explicit-scope data provider (`tests/Feature/Console/OneShotBackfillTenantScopeTest.php:64`), and the
file is excluded from PHPStan (`phpstan.neon:19`, tenancy M2 — ticketed). So the round asserts new
behaviour in the one file neither the suite nor static analysis can see. Non-fiscal, one-shot, bounded
risk — but a `--limit=1 --dry-run` smoke would close it.

### [Minor] M-d — SQLite fidelity limits of the new db-per-tenant harness (test-quality, not a code defect)
`tests/Traits/ProvisionsTenantDatabases.php:66-89` clones DDL from central `sqlite_master`
best-effort and swallows a `Throwable` per object, and sets `PRAGMA foreign_keys = OFF` on the tenant
binding without restoring it. Consequences to keep in mind before leaning on this harness:
(a) an object that fails to clone is invisible until a test happens to need it;
(b) PG-only artifacts (uuid type checking, the fiscal immutability triggers) do not exist, so
`test_a_malformed_tenant_filter_is_reported_not_raised` would pass even with the
`Str::isUuid()` guard at `TenantScopedCommand.php:372-374` deleted — the guard is correct and
necessary on PG, but that test does not guard it.
Also: nothing structurally prevents the NEXT constructor-pinned collaborator. `grep "readonly
ConnectionInterface" app/` still returns 8 captures (`FiscalEventProjectionDispatcher.php:67`,
`OutboxIngestor.php:121`, `ParseFailureResolutionService.php:96`, `Nf525DataProvider.php:82`,
`VirtualAdminFiscalEventService.php:30`, `TerminalRegistrySnapshotService.php:101`, +2). None is
reachable from the 12 converted commands (the tenancy review's item-3 sweep stands), but the class
of defect is only closed by convention today. Worth an architecture test or a ticket.

### [Note, pre-existing] `tests/Architecture/ConsoleCommandTenantContextTest.php` is RED on this branch
`test_every_concrete_artisan_command_is_tenant_classified` fails with 8 unclassified commands
(`ScanPercentScaleDrift`, `ExportFrontendPermissionsMap`, `ConfigureMethodRepositoryRoutingCommand`,
`BackfillPayableInstrumentAccountsCommand`, `BackfillTaxDetailsCommand`, `RunEnrichmentCommand`,
`BackfillLocationAttributionCommand`, `BackfillMembershipsCommand`). None is touched by any of the six
fix commits and several post-date the guard (`9b60733b7`), so this is the in-flight cat-(b) backlog,
NOT a regression from this round — recorded so it is not mistaken for one, and so nobody treats this
guard as a green merge signal today.

**Fix before merge:** nothing blocking — make `--tenant` the documented D.1/D.2 form (R-1) and
correct the four annotation line refs (M-b); M-a/M-c/M-d can ride follow-ups.
