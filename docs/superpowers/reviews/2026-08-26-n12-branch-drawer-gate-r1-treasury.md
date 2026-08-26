# N-12 branch cash repository — adversarial gate r1 (treasury lens)

- **Branch:** `fix/campaign-n12-branch-cash-repository`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n12-branch-drawer`
- **Reviewed range:** `8f50d7d54..a6fa9d65d` (merge base, per the package — `dev..HEAD` is misleading; confirmed 10 commits, 16 files, +1818/-18)
- **Mode:** READ-ONLY. Every claim below cites a line I opened in this worktree.

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

The tier rule itself is correct and well pinned. What blocks the merge is the **deploy**: the backfill arms the refusal on live multi-branch tenants where the branch terminal is *already claimed*, so the 422 gate never fires and the refusal lands in the money path instead; and the remediation the refusal advertises does not exist in the product.

---

## BLOCKING

### [CRITICAL] `database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php:139-146` — the backfill arms a money-path refusal for terminals that are already claimed

The claim-time guard is only in `claim()`:

- `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:403-408` → `hasUsableCashRegister(...)` → `noCashRegisterResponse()` (`:992-1000`, 422 `LOCATION_HAS_NO_CASH_REGISTER`).

A device that bound its `hardware_identifier` before this lane never re-enters `claim()`. Meanwhile the migration attributes Main's `CASH-01`/`SAFE-01` to the default location (`:139-146`), which arms `locationOwnsDrawer()` for Main and, for a *drawer-less branch*, leaves the resolver with no candidate at all:

- `apps/api/app/Modules/Treasury/Application/Services/TenderRepositoryResolver.php:106-107` (arm), `:223-232` (`scoped()` excludes the unattributed drawers), `:242-251`.

The consumer then **throws**, not degrades:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1315-1332` — `RuntimeException: ... no GL-linked payment_repository found for tenant … / location …`.

That is inside `apply()`, i.e. the fiscal projection job: every cash sale at that branch fails, retries, dead-letters. The receipt is already device-signed and the customer already paid; the `payments` row, the `repository_movements` row and the GL leg are all absent until an operator intervenes. This is exactly the shape of the tenant the lane was written against — `docs/handoff/PLAYWRIGHT-w4r2-w43-recheck-2026-08-25.md:410-414` records `Boutique Ariana` created via `POST /locations` and taking 200.000 TND on `POS02` *before* this lane, so its terminal is bound and its location has no drawer. `push to origin/dev` auto-migrates the whole fleet.

The implementer flags this as Concern 2 and recommends a deploy note. A deploy note is not a gate. **Fix:** have the backfill also provision a drawer for every POS-enabled location that would be left without one — `LocationCashRegisterProvisioner::provision()` already exists, is idempotent (`app/Modules/Treasury/Application/Services/LocationCashRegisterProvisioner.php:74-77`) and mints at balance 0 (`:108-121`) — **or** make the backfill skip any company with more than one POS-enabled location, leaving those tenants on the pre-N-12 tier-2 shape until an operator opts in.

### [CRITICAL] `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:996-998` — the refusal names a remediation the product does not offer

The message is *"Add a cash repository for the location in Treasury settings"*. There is no such screen:

- `apps/web/src/features/treasury/RepositoryListPage.tsx:35` — `location_id: string | null` is read-only in the list type; the file issues no `apiPost`/`apiPatch`.
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx:136` — the only mutation is `apiPatch('/payment-repositories/{id}', { gl_account_id })`. `location_id` is never sent.
- `grep -rn "location_id" apps/web/src/features/treasury/` returns only read/display sites.

So the operator-facing instruction is false, and the only real recovery is `PATCH /locations/{id} {pos_enabled: true}` (`app/Modules/Company/Presentation/Controllers/LocationController.php:293`), which nothing tells them about. Compounding it, there is no i18n key for the new code (report concern 6) — rule 11. Combined with the finding above, an affected tenant has **no self-service path out of a stopped POS**. Either ship the FE (location select on repository create/edit) in-lane, or change the message to the action that actually works, or hold the lane until the FE lands.

### [IMPORTANT-BLOCKING] `…backfill_payment_repository_location_n12.php:139-146` — every unattributed drawer of a company is bound to one location, irreversibly

The predicate is `company_id = X AND location_id IS NULL AND type IN (cash_register, safe)`. The lane itself documents that the repositories UI leaves `location_id` NULL on operator-created rows (`:38-41`; `tests/Feature/Treasury/BranchCashRepositoryRoutingTest.php:311`). A till an operator created *for a branch* is therefore indistinguishable from `CASH-01` and gets bound to **Main** — after which that branch is refused (finding 1) and its own drawer sits on the wrong location. `down()` is a logged no-op (`:165-172`) and finding 2 shows there is no UI to re-attribute.

The migration's stated safety premise — "safe to re-run after a deliberate re-attribution" (`:35-37`) — rests on an operator action that is not reachable in-product.

`tests/Feature/Treasury/BackfillPaymentRepositoryLocationN12Test.php:41-164` has no case with **two or more unattributed drawers on a multi-location company**, which is precisely this shape. **Fix:** restrict to the seeder's own day-one pair (`code IN ('CASH-01','SAFE-01')`), or to companies with exactly one location, and add the missing test.

### [IMPORTANT-BLOCKING] `apps/api/tests/Feature/Treasury/BranchCashRepositoryRoutingTest.php:371-375` — a non-falsifying assertion over the exact regression this lane fixed

```php
$this->assertNotSame(
    $mainBank->id,
    $this->app->make(TenderRepositoryResolver::class)
        ->resolve($this->tenantId, $this->companyId, $card, $this->branchLocationId)?->id,
);
```

Fixtures: branch till `CASH-02` at the branch (`:357`), `BANK-MAIN` bound to Main (`:358`), CARD mapped to `BANK-MAIN` (`:360-369`). Trace the resolver: the mapped branch is filtered out (`TenderRepositoryResolver.php:111-114` + `:223-232` — Main-bound), and the fallback (`:138-145`) has only a **type** preference, no cash/non-cash discrimination. So it returns **`CASH-02`, the branch cash drawer** — card money in the till, which is byte-for-byte the §3a regression the implementer says they fixed. `assertNotSame` passes anyway. The test greenlights the defect it is named for.

This is also a live behaviour question, not only a test one: N-12 makes the mapped branch fail far more often (any location-bound instrument), and each such failure silently reroutes a non-cash tender into a cash drawer. `PostShiftCashVarianceAdjustment.php:844-851` asserts "is this a cash till?" at its own caller for exactly this reason; the fiscal bridge has no equivalent. **Fix:** assert the concrete expected id (or `null`), and rule on whether a refused *mapped* non-cash instrument should fall through to a drawer at all.

### [IMPORTANT-BLOCKING] brief deliverable 2 (shift-variance leg) is implemented but unpinned, and its two end-to-end test files were not run

The leg is real — `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:780` (`terminalLocationId($event)`), `:804-809` (passed into `resolveByMethodId`), `:989-1002` (the `pos_terminals` lookup, same source as `ResolvesTerminalLocation`). But:

- `grep -rln PostShiftCashVarianceAdjustment tests/` → `Unit/Config/HorizonQueueCoverageTest`, `Architecture/QueuedListenerTenantContextTest`, `Architecture/ProjectorEmissionRatchetTest`, `Feature/POS/CashCountDispatchGuardTest`, `Feature/Treasury/ShiftCashVarianceQueueRetryTest`. **No test asserts a branch shift's variance books to the branch drawer.**
- `tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` and `ShiftCashVarianceOfflineDevicePayloadTest.php` — the only files that drive this listener end-to-end — are absent from the report's §4 run table. Their fixture (`ShiftCashVarianceTriggerPathsTest.php:110-118`) leaves the till unattributed while the terminal carries a location, so they *should* still pass on tier 2, but that is my reading, not a run.

On GL balance: the JE still balances, because a branch drawer links to the **same** `cash` purpose account (`LocationCashRegisterProvisioner.php:29-40`, `:118-119`). There is therefore **no per-branch GL dimension** — per-branch separation lives entirely on `payment_repositories.id` / `location_id`. That is a defensible design (avoids hardcoding 531/532 — matches the seeded-country-accounting rule) but it should be an explicit owner ack, because "branch drawer" reads as a GL split and is not one.

---

## IMPORTANT (non-blocking, but fix before the FE lane)

### [IMPORTANT] claim-gate predicate and resolver predicate disagree on `safe`
`LocationCashRegisterProvisioner::hasOwnCashRegister()` filters `type = CashRegister` only (`:129-137`), while `TenderRepositoryResolver::locationOwnsDrawer()` counts a `Safe` too (`:242-251`, `DRAWER_TYPES` at `:87-90`). A location owning only a safe: the resolver routes its cash into that safe, but `hasUsableCashRegister()` (`:54-70`) says "no" unless a legacy unattributed drawer exists → a 422 on a configuration the resolver supports. Converse case: `hasUsableCashRegister()` returns true off a legacy unattributed drawer while the resolver, at that same safe-owning location, excludes those and books into the safe. Align the two on one predicate.

### [IMPORTANT] `is_active` is not part of the tier — a deactivated branch drawer stays sticky
Neither `locationOwnsDrawer()` (`:242-250`) nor the fallback (`:138-145`) filters `is_active`; only the mapped branch does (`:112`). Deactivating a branch till therefore does **not** release the branch back to the legacy pool — the tier stays armed and the fallback keeps selecting the deactivated drawer, and `hasUsableCashRegister()` still answers yes. Pre-N-12 the missing filter was benign (company-wide set); the tier makes it per-location and sticky.

### [IMPORTANT] `LocationCashRegisterProvisioner::provision()` has no uniqueness backstop
`:74-121` is read-then-`forceCreate` with no lock, no transaction, and no DB unique on `(company_id, location_id, type)`. Two concurrent `PATCH /locations/{id} {pos_enabled:true}` (`LocationController.php:293`) can mint two drawers for one location; the resolver's `orderBy('id')` tie-break (`TenderRepositoryResolver.php:144`) then picks one and the other silently accumulates nothing while showing up in the repositories list. Cheap fix: a partial unique index, or wrap in `DB::transaction` with a `lockForUpdate` on the location row. Note `LocationController::store():215` also calls it outside the location's own write.

### [IMPORTANT] `tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php:114-131` — the test now proves less than its name
The added unattributed drawer means `test_..._persists_terminal_location_over_repository_location...` resolves a drawer with **no** location, so it asserts "terminal location over null", not "over another location's". The named scenario is unreachable by design now. Rename it, or re-target it at a drawer that *is* at the terminal's location.

---

## MINOR

- **[MINOR] rule 6 (new edge on a new file):** `LocationCashRegisterProvisioner.php:9-10` imports `App\Modules\Company\Domain\Company` and `…\Location` — Treasury reaching into Company Eloquent models. It matches 28 pre-existing Treasury files (`grep -rn "use App\\Modules\\Company\\Domain" app/Modules/Treasury/`), so it is not new drift in kind, but deptrac was not run against the known-red 174 baseline (report concern 4). The *new* cross-module edges are correct: `TerminalController.php:27,53` and `LocationController.php:15,30` both depend on `App\Shared\Contracts\Treasury\LocationCashRegisterProvisionerInterface`, bound in `TreasuryServiceProvider.php:104-110`. Rule 13 satisfied — constructor injection, `private readonly`, no `app()` in production code.
- **[MINOR]** The migration's "an operator deliberately unbound this row" rationale (`:35-37`, `:160-163`) is fictional in-product — see blocking finding 2.

## What I verified clean

- **Rule 19 (precision):** no new money arithmetic anywhere in the package. The migration writes only `location_id` + `updated_at` (`:143-146`); `provision()` never touches `balance` (`:108-121`, born at 0, port-managed); `PostShiftCashVarianceAdjustment.php:644` still uses the explicit `getScale($repository->currency)` — no bare no-arg `getScale()` introduced. No `(float)` / `parseFloat` / `Number()` in the diff.
- **`account_id` vs `gl_account_id` trap:** `LocationCashRegisterProvisioner.php:118-119` sets both to the `SystemAccountPurpose::Cash` account, matching `PaymentRepositorySeeder` — the B2B/POS split is not conflated. Account resolved by purpose (`:79`), never by literal code.
- **`currency` NOT NULL:** the provisioner omits it, but `PaymentRepository::booted()` (`app/Modules/Treasury/Domain/PaymentRepository.php:94-120`) defaults it from the owning company, so no insert failure and no currency-mismatch refusal downstream.
- **Backfill self-guarding:** `Schema::hasTable` for both tables (`:87-98`), `Schema::hasColumn('payment_repositories','location_id')` (`:100-108`), single transaction (`:114`), idempotent on `location_id IS NULL` (`:141`), per-company loop so no cross-company attribution (`:125-147`), grep token (`:71`, `:150-156`). `locations.is_default/is_active/pos_enabled` are all `NOT NULL DEFAULT` (`2025_11_30_105000_create_locations_table.php:48-52`), so the PG "NULLs first on DESC" trap does not bite; locations do not soft-delete, so the query-builder path and the seeder's Eloquent path cannot diverge.
- **Seeder/migration ordering parity:** `PaymentRepositorySeeder.php:167-174` and the migration `:127-132` are the same four clauses in the same order.
- **Single-branch tenants are not broken by the 422:** pre-backfill the legacy arm of `hasUsableCashRegister()` (`:63-69`) answers yes; post-backfill the sole location owns the drawer and `hasOwnCashRegister()` answers yes. The guard is fail-closed and sits *after* the `pos_enabled` check (`TerminalController.php:389,403`), and `pos_terminals.location_id` is `NOT NULL` (`2026_01_08_190429_create_pos_terminals_table.php:29`), so no empty-string-into-uuid 500.
- **Refund symmetry:** refunds ride the same `$terminalLocationId` through `projectPaymentLineFromCanonical` (`TreasuryReceiptBridge.php:320,489,1190,1312`), so a branch refund debits the branch drawer — pinned at `BranchCashRepositoryRoutingTest.php:194-222`.
- **Tests are real** (`RefreshDatabase`, real models, HTTP-level claims, balances re-read with `fresh()` / `findOrFail()`); no `assertTrue(true)`, no mocked subject. `BranchCashRepositoryRoutingTest.php:167-192, 225-243, 246-263, 265-278, 284-303, 318-348, 378-389, 395-416, 419-457, 464-523` is genuinely falsifying coverage of the tier rule, the refusal, tier 2, the CARD fix and provisioning idempotency — with the one exception called out above.

## One line before merge

Make the backfill leave no POS-enabled location drawer-less (provision, or skip multi-branch companies) and give the 422 a remediation that exists in the UI — then fix the `assertNotSame` card test and pin the shift-variance branch leg.

---

## r2 scoped re-review

- **Range:** `a6fa9d65d..e8a7a2dca` (4 commits, 17 files, +1399/-218), package `.superpowers/sdd/PLAN/review-a6fa9d65d..e8a7a2dca.diff`
- **Mode:** READ-ONLY on the code. Every line cited was opened in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n12-branch-drawer`.
- **Note:** the fix report the brief names (`.superpowers/sdd/PLAN/task-1-report.md`) does not exist in this worktree — only the two `review-*.diff` packages. Findings below are read off the code, not the report.

### VERDICT: spec ❌ + quality CHANGES-REQUESTED — 1 Critical + 3 Important open

8 of 9 r1 findings are genuinely closed with falsifying tests. Finding 3 is half-closed, and the half that is missing is the half the orchestrator ruled on.

### r1 finding disposition

| # | r1 finding | Status | Evidence |
|---|---|---|---|
| 1 | CRITICAL — backfill arms a money-path refusal for already-claimed terminals | **ADDRESSED** | `…backfill_payment_repository_location_n12.php:191-217` provisions through the same `LocationCashRegisterProvisioner`; pinned end-to-end at `tests/Feature/Treasury/BranchCashRepositoryRoutingTest.php:291-320` (claimed branch terminal sells, branch drawer +200.000, Main stays 0.000) and `tests/Feature/Treasury/BackfillPaymentRepositoryLocationN12Test.php:194-222, 223-252` |
| 2 | CRITICAL — refusal names a remediation that does not exist | **ADDRESSED** | `TerminalController.php:1012` now names Settings → Locations + POS enabled; `apps/pos/src/pages/TerminalSetupPage.tsx:177-180` maps the code to `t('terminal.locationHasNoCashRegister')`; en/fr keys added; `apps/pos/src/__tests__/terminalClaimNoCashRegister.test.tsx` |
| 3 | IMPORTANT-BLOCKING — every unattributed drawer bound to one location | **PARTIALLY ADDRESSED** | ambiguity guard at `…N12.php:156-189` + test `BackfillPaymentRepositoryLocationN12Test.php:164-192`. **But** step 2 still runs on ambiguous companies (`:191-217`, no `$ambiguous === []` guard) and the census is dead (`:118` / `:226`) — see R2-1 and R2-2 |
| 4 | IMPORTANT-BLOCKING — non-falsifying `assertNotSame` card test | **ADDRESSED** | `BranchCashRepositoryRoutingTest.php:406-418` asserts the concrete `$mainBank->id`; new `:420-445` (unusable mapped instrument → live bank, never the till) and `:447-465` (day-one preference floor); `PaymentMethodRepositoryRoutingTest.php:157-177` at the bridge layer |
| 5 | IMPORTANT-BLOCKING — shift-variance leg unpinned | **ADDRESSED** | new `tests/Feature/Treasury/ShiftCashVarianceBranchDrawerTest.php:136-148, 151-167, 180-207, 225-233` — branch variance books to the branch till, drawer-less branch refuses and names the location, replay reuses the original repository, unreadable terminal falls to the default location's drawer. GL single-`cash`-account design is stated explicitly at `LocationCashRegisterProvisioner.php:31-42` — owner ack still owed, but no longer implicit |
| 6 | IMPORTANT — claim-gate and resolver disagree on `safe` | **ADDRESSED** | `LocationCashRegisterProvisioner.php:65-68` + `:196-205` is now byte-identical in predicate to `TenderRepositoryResolver.php:88-91` + `:296-306` (drawer types, `is_active`, `gl_account_id IS NOT NULL`); interface renamed `hasOwnDrawer`/`hasUsableDrawer` |
| 7 | IMPORTANT — `is_active` not part of the tier | **ADDRESSED** | `TenderRepositoryResolver.php:303` (arming) and `:159` (fallback); pinned `BranchCashRepositoryRoutingTest.php:471-483` |
| 8 | IMPORTANT — `provision()` has no uniqueness backstop | **ADDRESSED** | partial unique index `…N12.php:333-338` (self-guarding on pre-existing duplicates `:313-331`); PG-safe recovery confirmed — `LocationCashRegisterProvisioner.php:151-161` wraps the INSERT in `DB::transaction`, which on PG issues a SAVEPOINT because Laravel's Migrator already holds a transaction (`Migration::$withinTransaction` default true, PG grammar supports schema transactions), so the `:167` recovery read runs on a live transaction rather than an aborted one. Pinned `BackfillPaymentRepositoryLocationN12Test.php:254-309` (pgsql-only, skipped on SQLite). **New gap:** no app-layer mapping — see R2-3 |
| 9 | IMPORTANT — `PosBridgeLocationAttributionTest` proves less than its name | **ADDRESSED** | `tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php:117-144, 153-166` — the resolved repository now genuinely sits at another location (a bank account, which the tier deliberately does not restrict), and the test asserts both the resolved id and the terminal-over-repository attribution |

### New findings in the fix diff

#### [CRITICAL] R2-1 `apps/api/database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php:191-217` — step 2 runs on ambiguous companies, which is exactly what the file says it does not do

The docblock at `:60-66` is explicit:

> STEP 2 — … Runs only for a company whose attribution was unambiguous. On an ambiguous company nothing is armed, every location still resolves through tier 2, and minting per-location drawers there would strand the existing balances in the unattributed rows while new sales went to empty ones.

The code has no such guard. `$ambiguous` is computed at `:173-175` and consumed once, at `:178`, to gate step 1 only. Control then falls straight through to `:191-217`, which provisions unconditionally.

Trace the campaign's own shape with one extra operator-created till (2 locations, `CASH-01` + `CASH-OPERATOR` + `SAFE-01`, all `location_id IS NULL`, balances live):
1. `:173` → `count($codes) > 1 && $locationCount > 1` → `$ambiguous['cash_register']` set → step 1 correctly skipped, everything stays NULL.
2. `:198` → `hasOwnDrawer()` (`LocationCashRegisterProvisioner.php:70-73`) is false at **every** location, because no drawer carries a `location_id`.
3. `:202` → a fresh `cash_register` at balance 0 is minted at Main *and* at the branch.
4. `TenderRepositoryResolver.php:104-105` → `locationOwnsDrawer()` is now true at both, so `scoped()` (`:269-281`) drops the `location_id IS NULL` leg and the three legacy drawers — the ones holding the money — become unreachable from every POS location.

Result on a live tenant: the historical cash sits in orphaned rows while every new receipt and every shift-variance adjustment books into an empty drawer. GL is unaffected (all drawers link the same `cash` purpose account, `LocationCashRegisterProvisioner.php:101-109, 158-159`), but per-drawer treasury balances stop describing physical cash, the cash-position and per-branch count screens are wrong, and `allow_negative = false` will start refusing legitimate outflows from a drawer that reads 0. This is the precise harm orchestrator ruling (c) — leave ambiguous attribution NULL — exists to avoid; step 2 arms tier 1 anyway and nullifies the ruling.

**The test that should catch this is neutered:** `BackfillPaymentRepositoryLocationN12Test.php:166` builds the ambiguous company with `$this->company()`, not `$this->companyWithChart()` (`:346-357`). With no chart there is no `SystemAccountPurpose::Cash` account, `provision()` returns null at `LocationCashRegisterProvisioner.php:102-109`, and step 2 silently no-ops. The three `assertNull` calls at `:181-190` pass for the wrong reason.

**Fix:** guard `:196` on `$ambiguous === []` (or hoist the whole step-2 block inside the `:178` branch), and re-point the ambiguity test at `companyWithChart()` plus an assertion that **no** new drawer was minted for that company.

#### [IMPORTANT] R2-2 `…N12.php:118, 226` — the ambiguity census is dead; the log line always reports zero

`$ambiguousCompanies` is initialised at `:118` and emitted at `:226` and is **never incremented** — `grep -n "ambiguousCompanies"` returns exactly those two lines. The `$ambiguous` array built at `:174` (which holds the actual repository codes an operator must attribute by hand) is never logged either. So the `N-12-REPOSITORY-LOCATION-BACKFILL … status=ok` line reports `"ambiguous_companies":0` on a fleet-wide `tenants:migrate` even where companies were skipped, and nothing anywhere names the drawers that need a human. Orchestrator ruling (c) is "left NULL **+ census**"; the census half is not delivered. Fix: `$ambiguousCompanies++` inside the skip path and log `company_id` + the ambiguous codes per type.

#### [IMPORTANT] R2-3 `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:92,115,119` and `:160,164` — the new unique index has no application-layer handling, so a legitimate API write becomes a raw 500

The index added at `…N12.php:333-338` is `UNIQUE (company_id, location_id, type) WHERE location_id IS NOT NULL AND type IN ('cash_register','safe') AND is_active AND gl_account_id IS NOT NULL`. Both repository write paths can violate it and neither catches `QueryException`/`23505` (`grep -n "23505\|QueryException" PaymentRepositoryController.php` → no matches):

- `store()` validates `location_id` (`:92`) and `type` (`:79`), and hard-sets `is_active => true` (`:119`) with a `gl_account_id` (`:118`). Creating a second till for a location that already has one → PG 23505 → unhandled 500 with a raw constraint message.
- `update()` accepts `location_id` (`:160`), `type` (`:151`), `is_active` (`:164`) and `gl_account_id` (`:163`). The one mutation the repositories UI actually issues today — `apiPatch('/payment-repositories/{id}', { gl_account_id })` at `apps/web/src/features/treasury/RepositoryDetailPage.tsx:136` — can trip it: GL-linking a previously unlinked drawer that shares a `location_id` and `type` with an already-linked one flips it into the index predicate. Same for reactivating a drawer.

The codebase's own convention for exactly this is a 422 (`ProductVariantService.php:105` — "race (23505) to a 422 validation error instead of a raw 500"). Fix: catch the 23505 naming `payment_repositories_one_drawer_per_location_type` in `store()`/`update()` and return a typed 422 ("this location already has an active cash register").

Related, worth an owner line: the index makes **one active till per location a hard product rule**. A shop with two physical tills at one location can no longer be modelled. That may be intended, but it is not stated anywhere.

#### [IMPORTANT] R2-4 no deploy note for the balance discontinuity the provisioning arm creates

Ruling (a) is implemented, but nothing in the diff tells an operator what happens to money already booked. On the campaign's own tenant (`docs/handoff/PLAYWRIGHT-w4r2-w43-recheck-2026-08-25.md:410-414`), `CASH-01` holds `452.000` of which `200.000` was physically taken at Ariana. After the migration `CASH-01` is Main's (`…N12.php:178-189`) and Ariana gets a fresh drawer at balance 0 (`LocationCashRegisterProvisioner.php:129-132`). Main's drawer then reads 452.000 against ~252.000 of physical cash and Ariana's reads 0.000 against ~200.000.

This does **not** produce a phantom GL variance — `PostShiftCashVarianceAdjustment` books the device-computed counted-vs-expected figure, not a repository-balance delta (`:558-575, 663-664`) — so it is not a wrong-JE bug. But the per-drawer treasury balances are wrong from the moment the migration lands until a human moves the opening float across with a `RepositoryTransfer`, and no deploy note, census line or ticket in this diff says so. `grep -in "opening balance|deploy|runbook"` over the range returns nothing. Fix: emit the per-company pre-migration drawer balances in the census line, and add the one-time transfer step to the lane's deploy notes.

### Answers to the four scrutiny questions

- **Provisioning arm on PG:** idempotent (`:198` `hasOwnDrawer` probe, plus `provision()`'s own `:96-99` probe and `:162-168` 23505 recovery), per-company (`:132` loop, `:137` company-scoped default location, `:201` company-scoped location filter), currency and GL defaults identical to the day-one path because it is literally the same class the `pos_enabled` flip calls (`LocationCashRegisterProvisioner.php:101-161`; currency defaulted by `PaymentRepository::booted()`, asserted `BackfillPaymentRepositoryLocationN12Test.php:220-221`). No duplicate on re-run given the new index. ✔
- **Unique-violation handler is PG-safe:** confirmed. `LocationCashRegisterProvisioner.php:151` wraps the INSERT in `DB::transaction`, which becomes `SAVEPOINT` because Laravel's Migrator already holds an outer transaction on PG; the `catch` at `:162-168` therefore runs after `ROLLBACK TO SAVEPOINT`, not inside an aborted transaction. Same default connection on both sides (`Migration::getConnection()` is null → default; `PaymentRepository` declares no `$connection`; no global scopes on the model). ✔
- **`is_cash_tender NOT NULL DEFAULT false` consequence:** there **is** a non-seeder writer — `PaymentMethodController::store():127` and `update():246-248`. It is not a silent-non-cash hazard: `assertCashTenderInvariant()` (`:342-355`) refuses `is_cash_tender = true` on any code other than `CASH`, `code` is unique per tenant (`:70-75`), and `update()` preserves the stored value when the key is absent. So an API/import-created method is *necessarily* non-cash and correctly flagged. **Not** a new Important. The residual risk is the inverse and is narrow: a method that is physically cash but not flagged — the case-collision losers the `2026_07_28_100000` backfill deliberately leaves at `is_cash_tender = false` (`:44-48`), or an operator's `ESPECES`-style custom code — now prefer a settlement repository over the drawer (`TenderRepositoryResolver.php:128, 162-172`) where before they took the till. Those methods are already treated as non-cash by `TreasuryReceiptBridge::isCashTenderLeg` (`:1103`) and the device Z aggregation, so this is consistency rather than new drift, but it is a behaviour change worth a line in the lane notes.
- **23505 surfacing in the repositories UI:** unhandled — see R2-3.

### Minor (non-blocking)

- **[MINOR]** `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:9` — new `use App\Modules\Company\Presentation\Controllers\LocationController;` exists solely to satisfy a `{@see LocationController}` at `:1002`. A POS-Presentation → Company-Presentation edge for a docblock. Use the FQCN in the docblock instead. (Whether deptrac counts it against the known-red 174 baseline: cannot verify, not run here.)
- **[MINOR]** `TerminalController.php:1004-1006` claims "Both clients translate the code … `apps/web` POS/Terminals". `grep -rn "LOCATION_HAS_NO_CASH_REGISTER" apps/web/src/` returns nothing and `apps/web` has no claim path at all. The statement is false; drop the `apps/web` half.
- **[MINOR]** `apps/pos/src/__tests__/terminalClaimNoCashRegister.test.tsx:41-51` asserts on `readFileSync` of `TerminalSetupPage.tsx` source text rather than rendering the component against a rejected claim. It is falsifying (it fails if the branch is removed) but it pins the source string, not the behaviour — a rename of the local variable `err` breaks it while the behaviour is fine.
- **[MINOR]** `…N12.php:133-135, 192-194` — `$tenantId` is read from `payment_repositories`, so a company that owns locations but zero repositories is skipped entirely with no census line. `companies.tenant_id` would cover it.
- **[MINOR]** `…N12.php:278-303` — `posCapableLocations()` does not filter `locations.is_active`, so a closed branch that still carries an old terminal row gets a drawer minted.

### Rule-19 recheck on the fix diff

Clean. No new money arithmetic: the migration writes `location_id` + `updated_at` only (`:184-187`); `provision()` never sets `balance` (`:151-161`); `PostShiftCashVarianceAdjustment`'s new helpers (`:1005-1080`) read ids only. No `(float)`, `parseFloat`, `Number()` or bare no-arg `getScale()` anywhere in the range; test comparisons use `bccomp` at explicit scale 3.

### One line before merge

Guard step 2 on `$ambiguous === []` and re-point the ambiguity test at a chart-seeded company (R2-1), then wire the census counter (R2-2) and map the new index's 23505 to a 422 (R2-3).

## r3 scoped re-review

- **Range:** `e8a7a2dca..079ef36f5` (5 commits, 12 files, +688/-151), package `.superpowers/sdd/PLAN/review-e8a7a2dca..079ef36f5.diff`
- **Mode:** READ-ONLY. Every claim below cites a line opened in this worktree. Ran `BackfillPaymentRepositoryLocationN12Test` and `PaymentRepositoryLocationTest` on both SQLite (`php artisan test`) and PostgreSQL (`./vendor/bin/phpunit -c phpunit-pgsql.xml`, `127.0.0.1:5433`) — 11 passed/1 skipped (SQLite) and 17 passed (PG) — plus PHPStan level 8 on the three touched files (`[OK] No errors`).

### VERDICT: spec ✅ + quality APPROVED — R2-1..R2-3 ADDRESSED, R2-4 code-complete but test-unpinned (non-blocking)

### R2-1..R2-4 disposition

| # | r2 finding | Status | Evidence |
|---|---|---|---|
| R2-1 | CRITICAL — step 2 provisions on ambiguous companies against its own docblock; pinning test neutered by `company()` | **ADDRESSED** | The `continue` is restored at `…backfill_payment_repository_location_n12.php:215-224` (gates BOTH the step-1 attribution loop at `:226-234` and falls through to step 2 at `:236-238` in the same iteration — nothing between the `continue` and the loop's closing brace can run for an ambiguous company). Test re-pointed at `companyWithChart()` and asserts the repository count is unchanged: `tests/Feature/Treasury/BackfillPaymentRepositoryLocationN12Test.php:171-198` (`assertSame(3, PaymentRepository::query()->where('company_id', $company->id)->count(), …)`). Re-ran green: 11 passed/1 skipped (SQLite), all 17 passed on PG. |
| R2-2 | IMPORTANT — ambiguity census dead, always zero | **ADDRESSED** | `$ambiguousCompanies++` at `:216`, per-company `status=ambiguous-skipped` line with `company_id`/`locations`/`codes` at `:217-222`, summed into the final `status=ok` line's `ambiguous_companies` key at `:288-293`. Pinned by `test_it_censuses_the_companies_it_skipped_and_the_codes_that_made_them_ambiguous` (`:206-242`) reading real `MessageLogged` events (not a facade mock) — asserts exactly one `ambiguous-skipped` line naming both offending codes and the company id, and `"ambiguous_companies":1` in the summary. Ran green. |
| R2-3 | IMPORTANT — new unique index has no application-layer handling → raw 500 | **ADDRESSED** | `RepositoryWriteRefusal` enum (`app/Modules/Treasury/Domain/Enums/RepositoryWriteRefusal.php:18-53`) owns the constraint name (`payment_repositories_one_drawer_per_location_type`) and the 422 message. `PaymentRepositoryController::store()` wraps the insert in `DB::transaction` and catches `QueryException`, mapping `23505` via `duplicateDrawerRefusal()` to a `422 LOCATION_DRAWER_ALREADY_EXISTS` (`:112-133, 291-312`). `update()`'s `gl_account_id` path does the same, under `lockForUpdate()` (`:210-262`). Both are the *actual* write paths — `store()` for a second till, `update()` for the one PATCH the repositories UI issues today. Tests exercise both, PG-only (partial index is PG-only): `tests/Feature/Treasury/PaymentRepositoryLocationTest.php:85-113` (store) and `:115-144` (update), both `assertJsonPath('error.code', 'LOCATION_DRAWER_ALREADY_EXISTS')`. **Verified the specific concern from r2** — "the INSERT runs inside its own transaction so the 23505 rolls back to a SAVEPOINT, not an aborted outer transaction" — by actually running these two cases under `phpunit-pgsql.xml`: both pass (no `25P02`), confirming the savepoint fix is real and not merely asserted in a docblock. |
| R2-4 | IMPORTANT — no deploy note for the balance discontinuity | **PARTIALLY ADDRESSED (code-complete, untested)** | The docblock DEPLOY NOTE (`…N12.php:63-84`) and the `drawer-provisioned-transfer-owed` census line (`:263-274`) are real: `drawerBalances()` (`:325-345`) reads `pluck('balance', 'code')` straight off the query builder and casts each value with `(string)`, never `(float)` — rule 19 clean, right scale (it's a pass-through of the stored `decimal` column, no arithmetic). **But no test asserts this log line exists or carries the right numbers** — `grep -rn "drawer-provisioned-transfer-owed\|default_location_drawer_balances" tests/` returns nothing outside the migration file itself. The two "provisions a drawer" tests (`BackfillPaymentRepositoryLocationN12Test.php:254-276, 283-308`) exercise the code path that emits this line but assert only the new drawer's balance/currency, not the census log. This is a real code fix an operator can act on today, but it is currently un-pinned — a future refactor of `drawerBalances()` or the log call could silently drop the balances with nothing to catch it. |

### New Critical/Important in the fix diff (treasury lens)

None found. The R2-3 fiscal-side companion fix (cash tender must resolve only to a drawer, `TenderRepositoryResolver.php:134-169`) is correctly scoped and out of my treasury-lens remit (covered by the fiscal review); no money-precision, sign-convention, or GR-IR/PCG-TN issue in it. No `(float)`/`parseFloat`/`Number()`/bare no-arg `getScale()` introduced anywhere in the `e8a7a2dca..079ef36f5` range.

### What to fix before merge

Nothing blocking. Add a test asserting the `drawer-provisioned-transfer-owed` log line and its `default_location_drawer_balances` payload (R2-4) before or shortly after merge — it is the one operator-facing signal for a real money-adjacent step (the manual `RepositoryTransfer`) and currently has no regression protection.
