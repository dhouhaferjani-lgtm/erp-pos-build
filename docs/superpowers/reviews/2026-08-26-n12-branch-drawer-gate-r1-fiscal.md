# N-12 branch drawer — adversarial gate r1 (fiscal / POS / projection lens)

- Branch: `fix/campaign-n12-branch-cash-repository` · merge base `8f50d7d54` · head `a6fa9d65d`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n12-branch-drawer`
- Package reviewed: `.superpowers/sdd/PLAN/review-8f50d7d54..a6fa9d65d.diff` (16 files, +1818/-18) plus the files themselves at HEAD. `dev..HEAD` deliberately not used.
- Reviewer scope: fiscal chain, device-authored facts, projection replay-safety/idempotency, queue-context (rule 20), precision (rule 19), event immutability (rule 8).

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

---

## What is clean (verified, not assumed)

| Claim | Evidence |
|---|---|
| No fiscal payload, canonical-bytes, hash-chain or Event-class change (rule 8) | No `apps/api/app/Modules/Fiscal/**` production file in the package file list; the only `previous_hash` / `signature_version` lines in the diff are test-fixture columns (`apps/api/tests/Feature/Treasury/BranchCashRepositoryRoutingTest.php:700-732`). `TreasuryReceiptBridge` reads the sealed payload only; the location comes from `pos_terminals`, never from the payload (`apps/api/app/Modules/Treasury/Application/Projections/Concerns/ResolvesTerminalLocation.php:17-21`). |
| No new named queue (rule 20) | Zero `onQueue(` in the diff; horizon coverage unaffected. |
| No CompanyContext dependency introduced on the projection path (rule 20) | Every new query takes explicit `tenantId`/`companyId`/`locationId` args: `TenderRepositoryResolver.php:92-97, 209-251`; `LocationCashRegisterProvisioner.php:49-70`; `PostShiftCashVarianceAdjustment.php:989-1001`. `PaymentRepository` carries no context-reading global scope. |
| No new money/quantity math, so no rule-19 surface | The lane adds only `where`/`orderBy` predicates and a `forceCreate` of a repository at port-managed balance 0 (`LocationCashRegisterProvisioner.php:111-121`). No scale resolution added. |
| Receipt-bridge repository choice is replay-deterministic | An already-written leg re-reads the repository off the existing `Payment` and never re-resolves: `TreasuryReceiptBridge.php:1300-1313`. A replay after a location change / later backfill therefore reuses the original drawer. |
| A missing branch drawer fails LOUDLY and replayably at the projection | `TreasuryReceiptBridge.php:1324-1331` throws naming the location; `ApplyFiscalEventProjectionJob` (`$tries = 5`, backoff `[10,30,60,300,900]`) retries then flips the per-projector row to `dead_lettered`. The POS-core receipt projection is a separate row, so the fiscal record is unaffected. Pinned by `BranchCashRepositoryRoutingTest.php:246-263` (no Payment, no movement, Main balance untouched). |
| Refund/void cash leg direction is correct and branch-scoped | `BranchCashRepositoryRoutingTest.php:194-223` — branch drawer 200.000 → 157.200 after a 42.800 REFUND, Main untouched. Refund travels the same `SALE_RECEIPT` + `invoice_type_code=REFUND` path (no parallel refund event invented). |
| Projection test clears CompanyContext before `apply()` (rule 20) | `BranchCashRepositoryRoutingTest.php:160` (`app(CompanyContext::class)->clear()` at the end of `setUp()`), `PosBridgeLocationAttributionTest.php:133`. |
| No per-line TTC-vs-HT assertion (rule 19) | The fixture sets `unit_price == line_subtotal` at 0% VAT and asserts only aggregate balances. |

---

## Findings

### [CRITICAL] `apps/api/database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php:125-147` — the backfill silently breaks every already-claimed branch terminal, and the lane's only gate cannot catch it

The migration attributes every `location_id IS NULL` drawer to the company's default location **unconditionally**. It never asks whether the company has *other* POS-enabled locations, and it never provisions a drawer for them. For exactly the tenant shape this lane exists to fix (the campaign's `POS01 Main` + `POS02 Boutique Ariana`), the result on `tenants:migrate` is:

- Ariana loses its tier-2 candidate (`TenderRepositoryResolver.php:223-232` now excludes the unattributed drawers because Main owns one — `locationOwnsDrawer` @242-251),
- the Ariana terminal is **already claimed**, so the new gate never runs again: `TerminalController::claim()` @403-409 is a one-time check, guarded by `hardware_identifier !== null` @417,
- there is **no second server gate**: `SESSION_OPEN` is device-authored and `ShiftController::open()` @47-65 is retired for device-authoritative terminals; receipt sync performs no repository check,
- so the branch keeps selling and **every** cash receipt's Treasury leg throws at `TreasuryReceiptBridge.php:1324`, burns 5 retries, and dead-letters. The cashier sees nothing; the money never reaches a drawer or the GL.

Push-to-`origin/dev` auto-runs `tenants:migrate` on staging, so this ships without a human step. The implementer report acknowledges the behaviour change (Concern 2) and proposes a *deploy note* — a deploy note is not a guard for a migration that auto-runs fleet-wide.

**Fix before merge (pick one):** (a) in the same per-company loop, provision a drawer for every POS-enabled location that has none (the provisioner already exists and is idempotent) before attributing Main's; or (b) skip attribution for any company where another POS-enabled location would be left with no GL-linked drawer, and log the census under `N-12-REPOSITORY-LOCATION-BACKFILL` for an operator; or (c) split the migration out behind an explicit console command with a pre-flight census, and keep `tenants:migrate` inert. In all cases add a re-validation on a path the device hits every boot (terminal refresh / heartbeat), because a claim-time predicate that the lane's own migration invalidates is not a gate.

### [IMPORTANT] `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:780` + `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php:189-236` — the shift-variance leg is NOT replay-deterministic

`terminalLocationId()` is re-read on **every** attempt, so a redelivery after a location change, a drawer provisioning, or the backfill above can resolve a **different** repository than the first attempt. On that replay:

- `RepositoryAdjustment::firstOrCreate(['id' => $adjustmentId], …)` (`RepositoryAdjustmentService.php:189`) returns the **original** document, still carrying `payment_repository_id` = the first-attempt drawer, and the journal entry is reused (`:214`);
- but the movement is recorded against the **newly resolved** repository (`:234` `repositoryId: $repository->id`);
- `TreasuryMovementService.php:660-697` finds the existing movement by idempotency key and throws `IdempotencyConflictException` on `repository … != …` (@676).

No double-book (good), but a retry that should have been a clean replay becomes a permanent dead-letter, and the window is opened by this very lane. Contrast the receipt bridge, which already does this right (`TreasuryReceiptBridge.php:1300-1313`: existing leg wins over re-resolution).

**Fix:** on a replay, take the repository from the existing `repository_adjustments.payment_repository_id` rather than from a fresh resolve — same "existing history wins over mutable routing" rule the bridge states at `TreasuryReceiptBridge.php:1297-1299`.

### [IMPORTANT] the brief's shift-variance deliverable is entirely untested (rules 2 and 5)

`grep -n 'location' ` over `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php`, `ShiftCashVarianceQueueRetryTest.php`, `ShiftCashVarianceOfflineDevicePayloadTest.php`, `ShiftCashVarianceTriggerPathsTest.php` and `tests/Feature/POS/CashCountDispatchGuardTest.php` returns **nothing**, and `BranchCashRepositoryRoutingTest` has no variance case (its 15 tests are all bridge/claim/provisioning — `:167-533`). The listener change at `:780` and the `location_id` in the `no_repository_resolved` refusal payload (`:814`) are shipped unpinned. The existing variance suite passes only because its fixtures use unattributed repositories (tier 2). Needs at least: a branch shift's variance lands in the branch drawer; a drawer-less branch refuses with `no_repository_resolved` + `location_id`; and the replay case above.

### [IMPORTANT] `apps/api/app/Modules/Treasury/Application/Projections/Concerns/ResolvesTerminalLocation.php:32` + `PostShiftCashVarianceAdjustment.php:1001` + `TenderRepositoryResolver.php:219-221` — a null location silently routes a branch's money to Main

Both call sites return `null` on a terminal-lookup failure, and `scoped()` treats `null` as "the historical company-wide set, verbatim". Post-backfill on a multi-branch tenant, that set is ordered `cash_register` first then by UUID — i.e. **Main's till**. So the one degradation path the lane leaves open produces exactly the outcome the lane exists to prevent, silently. `pos_terminals.location_id` is `NOT NULL` (`2026_01_08_190429_create_pos_terminals_table.php:29-31`), so the trigger is narrow (missing row / tenant-company mismatch / `QueryException`), which is why this is Important rather than Critical — but it should be an explicit owner ruling ("degrade to company-wide" vs "refuse"), not a docblock. The implementer raises the same point (Concern 5).

### [IMPORTANT] `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:984-992` — the new 422 has no client handling and no translation (rule 11)

Nothing in `apps/pos` or `apps/web` references `LOCATION_HAS_NO_CASH_REGISTER`. The device rethrows (`apps/pos/src/stores/terminalStore.ts:755-758`) and `TerminalSetupPage.tsx:168-170` renders `getErrorMessage(err)`, which returns the raw server English string (`apps/pos/src/lib/api.ts:33-36`). `apps/web/src/pages/POS/Terminals.tsx:120` maps `LOCATION_POS_DISABLED` to a `t()` key; the new sibling code gets nothing. Retry behaviour itself is fine — the claim is a single user-initiated call with no retry loop, so there is no "retry to exhaustion" hazard on this code.

### [MINOR] `LocationCashRegisterProvisioner.php:129-137` vs `TenderRepositoryResolver.php:242-251` — the claim predicate and the resolver disagree on what a drawer is

`ownCashRegisterQuery()` requires `type = cash_register`; `locationOwnsDrawer()` accepts `cash_register` **or** `safe`. A location owning only a GL-linked safe is refused at claim even though the resolver would have served it from that safe. The divergence is in the safe direction (refuse, never mis-book), but the two predicates are documented as answering the same question (`LocationCashRegisterProvisionerInterface.php:36-46`) and should share one definition.

### [MINOR] `TerminalController.php:403-409` sits before the `TERMINAL_ALREADY_CLAIMED` check at `:417`

A second device attempting a terminal at a drawer-less location gets `422 LOCATION_HAS_NO_CASH_REGISTER` instead of the nearer, more actionable `409 TERMINAL_ALREADY_CLAIMED`. The controller's own stated ordering principle ("the nearer, more actionable cause keeps reporting first", `:386-388`) argues for moving it after.

### [MINOR] `is_active` is ignored by both the arming predicate and the fallback

`ownCashRegisterQuery()` (`:129-137`) and `locationOwnsDrawer()` (`:242-251`) do not filter `is_active`, and the resolver's fallback branch deliberately does not either (`TenderRepositoryResolver.php:138-145`). A deactivated branch till therefore still arms tier 1, excludes the legacy fallback, and remains the only candidate — POS cash keeps booking into a repository the operator believes is switched off. The `is_active`-on-mapped-only asymmetry is pre-existing; the newly load-bearing part (arming tier 1 off an inactive row) is not.

### [MINOR] `BranchCashRepositoryRoutingTest.php:711, 737-746` — the fixture hand-rolls the canonical encoder

`canonicalEncode()`/`sortRecursive()` reimplement canonicalization rather than using the production canonicalizer, and the payload's `terminal_id` (`:672`) is a fixed literal unrelated to `$this->branchTerminalId`. Harmless for these assertions (the bridge reads the `terminal_id` **column**, and no hash is verified), but it is a second implementation of a fiscal-sealing concern living in a test.

---

## One line to fix before merge

Make the backfill safe for live multi-branch tenants (provision or refuse-to-attribute when another POS-enabled location would be left drawer-less) and make the shift-variance leg reuse the existing adjustment's repository on replay — then pin both with tests.

---

# r2 scoped re-review

- Fix range `a6fa9d65d..e8a7a2dca` (4 commits, 17 files, +1399/-218). Package `.superpowers/sdd/PLAN/review-a6fa9d65d..e8a7a2dca.diff`; every claim below re-read at HEAD. `task-1-report.md` does not exist at the stated path (`.superpowers/sdd/PLAN/`), so the fix report was not consulted — findings are code-grounded only.
- Read-only. No files in the worktree touched.

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

One new Critical, introduced by the r1-1 fix itself.

## r1 findings — disposition

| r1 finding | Status | Evidence |
|---|---|---|
| 1 [CRITICAL] backfill breaks already-claimed branch terminals | **ADDRESSED** | Migration step 2 provisions a drawer for every POS-capable location that owns none (`…backfill_payment_repository_location_n12.php:191-217`, `posCapableLocations()` @278-303 covers `pos_enabled` OR "a terminal exists here"). Attribution is now conditional on non-ambiguity (@173-189). End-to-end on the campaign shape: `BranchCashRepositoryRoutingTest.php:280-320` binds `hardware_identifier` on the branch terminal, runs `$migration->up()` (@693-699), then applies the real bridge and asserts the payment lands in the branch drawer with Main at `0.000`. The fixture is chart-seeded (`setUp` @148), so provisioning genuinely runs. Real test. **But see r2-A.** |
| 2 [IMPORTANT] variance leg not replay-deterministic | **ADDRESSED** | `PostShiftCashVarianceAdjustment.php:788-791` returns the repository already carried by `repository_adjustments.payment_repository_id` before any re-resolution; lookup @1051-1080 keyed on the same deterministic `documentIdFor($shiftId)`. Pinned by `ShiftCashVarianceBranchDrawerTest.php:180-212`, which moves the original drawer to a third location, adds a replacement at the branch, redelivers, and asserts 1 adjustment / 1 movement / replacement untouched — i.e. a clean replay, no `IdempotencyConflictException`. |
| 3 [IMPORTANT] shift-variance deliverable untested | **ADDRESSED** | New `ShiftCashVarianceBranchDrawerTest.php` — branch drawer @136-149, drawer-less refusal naming `location_id` @151-168, replay @180-212, unreadable-terminal default @225-234. Runs the listener as a worker does with `app(CompanyContext::class)->clear()` @261 (rule 20). |
| 4 [IMPORTANT] null location silently routes to Main | **ADDRESSED** | `ResolvesTerminalLocation::resolveRepositoryLocationId()` @55-84 and `PostShiftCashVarianceAdjustment::terminalOrDefaultLocationId()` @1010-1027 both fall to the company DEFAULT location (same `is_default → is_active → pos_enabled → created_at` order as the seeder and the migration @258-269); the bridge now passes it at `TreasuryReceiptBridge.php:1318-1322`. Matches the ruling ("null terminal location → default location's drawer, never the company-wide set, fail loudly if missing"). Pinned @225-234. |
| 5 [IMPORTANT] 422 has no client handling / no translation | **ADDRESSED** | `apps/pos/src/pages/TerminalSetupPage.tsx:177-180` maps `err.code === 'LOCATION_HAS_NO_CASH_REGISTER'` to `t('terminal.locationHasNoCashRegister')`; `ApiRequestError` carries `code` (`apps/pos/src/lib/api.ts:41-51`, thrown @171). **All** POS locales present — `apps/pos/src/locales/` contains exactly `en` and `fr`, both updated. The recovery the message names is real: `LocationController.php:239-251` provisions on store @215 and update @293. |
| 6 [MINOR] claim predicate vs resolver disagree | ADDRESSED | Both now `cash_register|safe` + `is_active` + `gl_account_id NOT NULL` (`LocationCashRegisterProvisioner.php:196-205`, `TenderRepositoryResolver.php:296-306`). |
| 7 [MINOR] claim gate ordering | ADDRESSED | `TERMINAL_ALREADY_CLAIMED` moved ahead of the drawer check (`TerminalController.php:393-401` then @418-424). |
| 8 [MINOR] `is_active` ignored | ADDRESSED | `TenderRepositoryResolver.php:159` (fallback) and @303 (arming predicate); pinned `BranchCashRepositoryRoutingTest.php:466-482`. |
| 9 [MINOR] fixture hand-rolls the canonical encoder | **NOT ADDRESSED** | `canonicalEncode()`/`sortRecursive()` still at `BranchCashRepositoryRoutingTest.php:867-888`; the unrelated `terminal_id` literal survives at @806 (the branch fixture @836/845 was fixed). Still harmless. |

Also re-verified clean in the fix diff: no `apps/api/app/Modules/Fiscal/**` production file touched (only `tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php`); no canonical-payload, `previous_hash`, `signature_version` or Event-class change (rule 8); no `onQueue(`; no new money/quantity math and no scale resolution added (rule 19); `TreasuryReceiptBridge` still reads the sealed payload only and takes the location from `pos_terminals`; the already-written-leg branch @1300-1313 still wins over re-resolution.

## New findings (fix diff only)

### [CRITICAL] `apps/api/database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php:191-217` — step 2 provisions on AMBIGUOUS companies too, against its own stated invariant, and the test that should catch it is shaped so it cannot

The docblock is explicit (@60-66): *"STEP 2 … Runs only for a company whose attribution was unambiguous. On an ambiguous company nothing is armed, every location still resolves through tier 2, and minting per-location drawers there would strand the existing balances in the unattributed rows while new sales went to empty ones."* The code does not implement it. `$ambiguous` gates step 1 only (@178); step 2 @191-217 runs unconditionally.

On an ambiguous company (>1 unattributed drawer of a type AND >1 location — precisely the "operator added a second till through the repositories UI, which leaves `location_id` NULL" shape the same docblock calls out @46-49):

- step 1 correctly leaves `CASH-01` and `CASH-OPERATOR` at `location_id = NULL`;
- step 2 then mints a fresh, empty, GL-linked drawer for **every** POS-capable location including Main;
- `TenderRepositoryResolver::locationOwnsDrawer()` @296-306 is now true for each of them, so `scoped()` @276-278 drops the `orWhereNull('location_id')` arm — the money-bearing legacy tills become unreachable for every location;
- Main's physical cash still contains the legacy drawer's balance while Main's new drawer reads `0.000`, so the next per-branch cash count is off by exactly the stranded amount, and `PostShiftCashVarianceAdjustment` will book that as a variance against the new drawer.

This is the author's own stated harm, shipped fleet-wide by `tenants:migrate` on push to `origin/dev` with no human step.

The pinning test cannot catch it: `BackfillPaymentRepositoryLocationN12Test::test_it_leaves_two_unattributed_tills_alone_on_a_multi_location_company` @164-182 builds the company with `$this->company()` (@361), **not** `companyWithChart()` (@350). With no `cash` purpose account, `LocationCashRegisterProvisioner::provision()` @101-109 logs and returns null, so step 2 silently no-ops and the three `assertNull` assertions pass for the wrong reason. The test asserts nothing about how many repositories exist afterwards.

**Fix:** wrap @196-217 in `if ($ambiguous === []) { … }` (or hoist the `continue`), and re-point the ambiguity test at `companyWithChart()` plus an assertion that the repository count is unchanged.

### [IMPORTANT] `…backfill_payment_repository_location_n12.php:118, 143, 174, 226` — the ambiguity census is dead code; it always reports zero

`$ambiguousCompanies` is initialised @118 and logged @226 but never incremented anywhere, and the `$ambiguous[$type] = $codes` collected @174 is never logged. The docblock promises the opposite (@56-58: *"listed in the census line so an operator can attribute them deliberately"*). Under `tenants:migrate` every tenant shares one log, so the `N-12-REPOSITORY-LOCATION-BACKFILL` grep line — the only operator-facing signal that a company was deliberately skipped — will read `ambiguous_companies: 0` on a fleet where companies were in fact skipped. The documented manual-remediation path therefore does not exist. Fix: increment when `$ambiguous !== []` and emit a per-company `status=ambiguous-skipped` line carrying the codes.

### [IMPORTANT] `apps/api/app/Modules/Treasury/Application/Services/TenderRepositoryResolver.php:270` + `TreasuryReceiptBridge.php:1320-1341` — a CASH tender at a drawer-less location can now resolve to a BANK repository instead of refusing

The r1-4 fix rewrote `scoped()` so that `whereNotIn('type', self::DRAWER_TYPES)` makes **every** `bank_account` / `virtual` row a candidate from every location, regardless of its `location_id`. For a *cash* tender the fallback ordering @175-178 ranks non-drawers `ELSE 2` — reachable whenever no drawer qualifies. So a cash receipt at a location with no own drawer and no unattributed drawer resolves to a bank account rather than producing the loud refusal r1 verified at `TreasuryReceiptBridge.php:1320-1341`: physical cash booked against the bank GL, with no drawer movement and nothing to reconcile. `PostShiftCashVarianceAdjustment.php:865-872` has exactly this guard (`resolved_repository_is_not_a_cash_till`); the bridge has none.

Partly pre-existing — an *unattributed* bank was already a candidate before this diff — but the fix widens it to other locations' banks, and the lane's whole premise is that a drawer-less location must refuse. Fix: assert the resolved repository is a drawer for a cash tender in the bridge, mirroring the listener's guard; pin with a drawer-less-branch + bank-account fixture.

### [MINOR] `TreasuryReceiptBridge.php:1603-1606` docblock is now false; `:1338` names the wrong location

*"`$locationId` is null only when the terminal row could not be read at all … in which case the resolver keeps its historical company-wide behaviour"* — no longer true: the caller @1321 passes `resolveRepositoryLocationId()`, which falls to the default location. And the refusal message @1338 interpolates `$terminalLocationId ?? '(none)'`, i.e. the *attribution* location, not the location resolution actually ran against — the dead-lettered job can name `(none)` while the refusal was really about the default location's missing drawer.

### [MINOR] `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:1005-1006` — claims a web handler that does not exist

*"Both clients translate the code rather than echoing this English string (`apps/pos` TerminalSetupPage, `apps/web` POS/Terminals)."* `apps/web/src/pages/POS/Terminals.tsx:120` maps only `LOCATION_POS_DISABLED`; nothing in `apps/web` references `LOCATION_HAS_NO_CASH_REGISTER`. Harmless — `noCashRegisterResponse()` is reachable only from `claim()`, a device endpoint — but the comment asserts a guarantee that is not there.

### [MINOR] `apps/pos/src/__tests__/terminalClaimNoCashRegister.test.tsx:39-49` — asserts on source text, not on behaviour

`readFileSync` + `expect(src).toContain("err.code === '…'")` passes on a string match rather than rendering `ClaimTab` and asserting the translated message surfaces. Any refactor of the same logic breaks the test while the behaviour is intact, and vice-versa. The locale-key cases @27-37 are real.

## One line to fix before merge

Gate the migration's step-2 provisioning on `$ambiguous === []` and re-point the ambiguity test at a chart-seeded company (it currently passes only because provisioning silently no-ops), then make the census actually count.
