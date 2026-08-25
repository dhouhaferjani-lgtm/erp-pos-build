# Gate record — Session B2 lane B2-5 / C-24 (i) "manual single-period close" — treasury lens, ROUND 1

**VERDICT: spec ✅ + quality ACCEPT-with-conditions. MERGE-BLOCKING: NO** (conditions C-1 and C-2 are a
one-line precedence pin and a residual-wording correction; neither changes behaviour, both can land in the
merge commit or as a follow-up commit on the same branch before promotion).

- **Lane / branch:** `fix/sb2-c24-manual-period-close`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c24-period-close`
- **Commit under review:** `77984ddb3` (single commit) on base `8bdff9e93`; diff = `git diff 8bdff9e93..77984ddb3`
- **Reviewer lens:** treasury-reviewer (adversarial, code-grounded). Date: 2026-08-25.
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-C24-manual-period-close.md`
- **Implementer report:** `docs/sessions/session-B-2026-08-23/REPORT-C24-implementer.md`
- **MIGRATION-BEARING:** **no.** But it is migration-DEPENDENT: every column it writes
  (`reopened_at`, `status_actor`, `status_changed_from`) comes from Q-10's
  `apps/api/database/migrations/tenant/2026_08_24_140000_add_fiscal_period_transition_audit_columns.php:69-71`.
  `f5cae1f12` verified to be an ancestor of `77984ddb3`, so the pair promotes together; C-24 must never be
  cherry-picked ahead of Q-10.
- **Verification host:** own throwaway PG DB `autoerp_treasurygate_test` on `127.0.0.1:5433`
  (`autoerp`/`autoerp_secret`), created fresh (DROP + CREATE) for this gate. Left in place; drop when the
  gate closes. The implementer's `autoerp_c24_test` was NOT reused.
- **Branch files:** not edited. One untracked probe file was created, run, and deleted;
  `git status --porcelain` in the worktree is empty at the end of this gate.

---

## 1. What was re-derived, by execution

All runs: `php artisan test -c phpunit-pgsql.xml <path>` from
`.worktrees/sb2-c24-period-close/apps/api`, `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_treasurygate_test`,
`CACHE_STORE=array`, one file per run, by path, never the suite.

| Run | Result |
|---|---|
| `tests/Feature/Company/FiscalPeriodCloseEndpointTest.php` | **15 passed (51 assertions)**, 28.62 s — green reproduced independently |
| `tests/Feature/Company/FiscalPeriodReopenEndpointTest.php` | 12 passed (41 assertions) — the `payload()` extraction did not move the reopen response |
| `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php` | 17 passed (39 assertions) |
| `tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` | 3 passed (19 assertions) — **FE map hash test green**, incl. "the committed frontend map is fresh against the seeder" |
| `tests/Feature/Accounting/PostingClosedPeriodGuardTest.php` | 3 passed (11 assertions) |
| `php tools/feature-lane-manifest-check.php` | **OK** — "every `--filter` entry is anchored and uniquely matched against 1830 test classes"; 1181 parked classes |
| `./vendor/bin/pint --test` on all 7 touched files | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` on the 5 changed/new source files | `[OK] No errors` |
| `./vendor/bin/deptrac analyse` | Violations 183 / Errors 0; **grep for `FiscalPeriodClose` in the violation list returns nothing** — the implementer's claim is true |

Plus a throwaway probe class (`TreasuryGateC24ProbeTest`, since deleted) that exercised the six things the
branch's own tests do not. Its stderr output, verbatim:

```
[P1] both guards hold -> code = FISCAL_PERIOD_CLOSE_NOT_ENDED
[P2] locked in closed year -> code = FISCAL_PERIOD_CLOSE_LOCKED
[P2b] end_date == today -> status 422 code = FISCAL_PERIOD_CLOSE_NOT_ENDED
[P3] draft JE post after manual close threw ClosedFiscalPeriodException: true
[P4] after nightly: status=locked from=closed actor=system:auto-lock closed_by='be0944d7-…' year_closed=true
[P4] draft JE still Draft inside a LOCKED period: 'draft'
[P5] stuck period -> FISCAL_PERIOD_CLOSE_FISCAL_YEAR_CLOSED ; later period -> FISCAL_PERIOD_CLOSE_PREDECESSOR_OPEN
[P5] after nightly, stuck period status = locked
[P6] after the stale scheduler write: actor=system:auto-lock closed_by='f6d69d25-…'
```

---

## 2. The eight questions the parent asked

### (1) State machine, refusal ORDER, and whether `PREDECESSOR_OPEN` can strand a company

**Machine — correct.** `FiscalPeriodCloseService.php:91-114`: Locked is checked FIRST and refuses
(`:91-93`), so `Locked` keeps zero outgoing edges and never falls through to the generic "not open"
message. `Open → Closed` only (`:95-97`). Executed: P2 — a Locked period inside a CLOSED fiscal year
returns `FISCAL_PERIOD_CLOSE_LOCKED`, not `…_FISCAL_YEAR_CLOSED`, i.e. the terminal state wins over the
year guard, matching the reopen sibling (`FiscalPeriodReopenService.php:86-92`).

**Order — the CODE and its own DOCBLOCK disagree.** The docblock at `FiscalPeriodCloseService.php:50-58`
and the enum doc at `FiscalPeriodCloseRefusalCode.php:26-31` both list `PREDECESSOR_OPEN` before
`PERIOD_NOT_ENDED`, and the brief lists them in that order too. The code checks not-ended at `:108` and
predecessor at `:112` — the reverse. Executed (P1): with BOTH conditions true the API returns
`FISCAL_PERIOD_CLOSE_NOT_ENDED`. Nothing pins this. See finding **I-1**.

**Stranding — ruled NOT reachable under well-formed data, for three independent reasons.**
- Periods are non-overlapping 12×1-month blocks inside a 12-month year
  (`FiscalYearCreationService.php:73-74`, `:100-118`), so `start_date` order implies `end_date` order.
  An ENDED period therefore cannot have a NOT-ENDED predecessor: the `PERIOD_NOT_ENDED` guard can never
  be the thing that makes a predecessor unclosable.
- The one shape that could strand — an **Open** period inside an **is_closed** year, which refuses with
  `FISCAL_YEAR_CLOSED` while blocking every successor with `PREDECESSOR_OPEN` — was constructed and run
  (P5): both refusals fire as predicted, **and the very next nightly run self-heals it**, because
  `lockPeriodsInClosedFiscalYears()` (`FiscalPeriodAutoLockService.php:278-288`) excludes only
  `Open AND reopened_at IS NOT NULL`; the stuck period went to `locked`. The permanent variant
  (`reopened_at` set inside a closed year) is unreachable: the reopen refuses inside a closed year
  (`FiscalPeriodReopenService.php:96-98`) and STEP 2 holds the year close while such a row exists
  (`FiscalPeriodAutoLockService.php:235-239`). `FiscalYearCreationService.php:129-135` creates all 12
  periods `Closed` when the year is created closed, so the seeded path does not produce it either.
- `closeOldPeriods()` has **no** ordering assumption to protect: `:180-189` filters on company + status +
  `end_date < cutoff` + `whereNull('reopened_at')` and nothing else. It will happily close period 5 while
  a reopened period 3 stays Open. So the new `PREDECESSOR_OPEN` guard is **stricter than the scheduler's
  own behaviour**, and the docblock rationale at `:50-53` ("both the scheduler and every downstream report
  assume oldest-first settlement") overstates what the scheduler actually does. The guard is still the
  right mirror of reopen's `successorSettled` — it is refusal-only and can never create a bad state — but
  the justification prose is not accurate. Minor, folded into **M-4**.

### (2) `PERIOD_NOT_ENDED` — keep or drop, TN/FR practice

**KEEP. Ruled explicitly.** Three reasons, in ascending weight:
- Accounting practice in both PCG-TN and PCG-FR is that a period is settled after it ends; nothing in
  either regime asks for a same-day close, and TN monthly obligations key off the completed month.
- The product's own only other closer waits a country window (`FiscalPeriodAutoLockService.php:132`,
  `:176`) — one month for TN. A guard of "must have ended" is already far weaker than the automatic path.
- Most important, from the treasury lens: the close takes effect on GL posting IMMEDIATELY (see (4)), and
  the POS is offline-first. Dropping the guard would let an operator settle a period on the day it is
  still accepting device-authored sales. `PERIOD_NOT_ENDED` is the floor, not the ceiling — the residual
  risk it does NOT cover is **I-3**.

Boundary confirmed by execution (P2b): `end_date == today` refuses, i.e. the comparison is strict
`end_date < today` (`:108`). The docblock at `:105-107` says "the app timezone is the honest boundary";
`config/app.php:68` pins that to **UTC**. For a TN/FR company (UTC+1/+2) this means the just-ended period
stays unclosable for the first 1–2 local hours of the next day. The drift is in the conservative
direction only (all target countries are ≥ UTC), so it is a wording nit, not a defect — **M-3**.

### (3) Scheduler interaction, by execution

- **reopen → manual close → `lockExpiredPeriods()`**: the branch's own integration pin
  (`FiscalPeriodCloseEndpointTest.php:339-377`) is real and green, and it asserts the pre-state too
  (step 2 proves the "stays Open forever" hold before the close). Re-run independently: pass.
- **Manually closed, NEVER reopened**: probed (P4). The nightly run does NOT re-process it wrongly —
  STEP 1 only matches `status = Open` (`:182`), so the row is untouched; STEP 2 closes the year
  (`year_closed=true`); STEP 3 locks it. Final stamps: `status=locked`, `status_changed_from=closed`,
  `status_actor=system:auto-lock`, and **`closed_by` still carries the human's UUID** — the lock arm never
  fills `closed_by` (`:292-301`), so the human attribution of the close survives the lock. Correct.
- **Hold predicate released**: proven twice (their integration pin; my P4/P5). The scheduler needed no
  change, exactly as the brief predicted.
- **`status_actor` / `status_changed_from` on every arm**: manual close writes `user:<uuid>` / `open`
  (`FiscalPeriodCloseService.php:118-119`, asserted at `FiscalPeriodCloseEndpointTest.php:145-146` and
  re-derived in P4); nightly close writes `system:auto-lock` / `open` (`:194-195`); nightly lock writes
  `system:auto-lock` / previous status (`:294-297`, observed as `from=closed` in P4). `status_actor` is
  `varchar(64)` (migration `:70`) and `'user:'+36` = 41 chars — no truncation risk.

### (4) Posting refusal after the close, and the fiscal-year lock over unposted work

**Refusal is immediate and real — verified by execution.** P3: before the close,
`FiscalPeriodResolverService::isDateInClosedPeriod()` (`:278-292`) returns false for a date inside the
period; the instant the manual close returns 200 it returns true, and
`GeneralLedgerService::sealAndPersistEntry()` (`:3805-3807`) throws `ClosedFiscalPeriodException` on a
balanced Draft entry dated inside it, leaving the entry `Draft`. No caching, no lag — the guard reads the
`fiscal_periods` row directly.

**The fiscal-year lock over Draft JEs — ruled ACCEPTABLE (Q-10 precedent), but it must be a NAMED
residual, which the report omits.** P4: a Draft JE dated inside the period survives the manual close and
then the nightly run puts the period into terminal `Locked` — the draft is still `Draft` and is now
permanently unpostable, because `Locked` has no in-product outgoing edge. This is **inherited**: the
nightly `closeOldPeriods` has always done the same to drafts, with no preflight. What C-24 changes is that
it is now reachable **on demand, from a button, with no warning and no minimum age**, instead of only via
a one-month-old sweep. Accept, with condition **C-2**. See **I-4**.

### (5) Authz

Clean. Verified point by point:
- `fiscal-periods.close` seeded at **both** places: catalogue `RolesAndPermissionsSeeder.php:300`, role
  grant `:828` (accountant; admin inherits via `Permission::all()`). Grep across `apps/` finds it only in
  those two seeder lines, the route, and the generated FE map — no third source of truth.
- Route `Company/routes.php:90-93` inside the group at `:24` carrying `['api','auth:sanctum',
  SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (rule 12), with
  `->middleware('can:fiscal-periods.close')` and **`->whereUuid('id')`** — the Q-10 M-2 precedent, so a
  malformed id is a 404, not a PG 22P02 500.
- viewer 403 / manager 403 / admin 200 pinned (`FiscalPeriodCloseEndpointTest.php:261-294`), re-run green.
  The manager exclusion matches the 2026-08-06 I-1 ruling and the reopen sibling.
- **Cross-company 404 precedes 422**: `FiscalPeriodController::close()` does the company-scoped
  `findOrFail` (`:86`, `:90-93`) BEFORE the service is called, so another company's period is unreachable rather
  than refused. Pinned at `FiscalPeriodCloseEndpointTest.php:323-335`.
- **FE map**: `permissionsMap.generated.ts:83` + source-hash bump; `ExportFrontendPermissionsMapCommandTest`
  green (3 passed), i.e. the committed map is fresh against the seeder. Only web file touched.
- Own permission rather than reusing `fiscal-periods.reopen` — correct call, and the rationale at
  `routes.php:83-88` is sound: settling and reversing a settlement must be separately grantable.

### (6) Concurrency

- **Two concurrent manual closes / close vs reopen ON THE SAME ROW: safe.** `FiscalPeriodCloseService.php:84-97`
  opens a transaction, re-reads `whereKey(...)->lockForUpdate()`, and re-evaluates status AFTER the lock.
  Identical shape to `FiscalPeriodReopenService.php:79-92`. The loser observes the new status and refuses.
- **Close vs the nightly scheduler: a real but bounded lost-update on `status_actor` only.**
  `closeOldPeriods()` reads its chunk (`:190`) and then issues a plain `save()` (`:192-199`) with **no
  status precondition and no `lockForUpdate`** — an `UPDATE … WHERE id = ?`. If the scheduler's SELECT
  saw the row as Open and a human close commits before the scheduler's UPDATE lands, the UPDATE applies
  anyway. Reproduced in-process (P6, labelled a SIMULATION — a single PHP process cannot host two real
  concurrent transactions): `status_actor` ends as `system:auto-lock`, overwriting `user:<uuid>`.
  `closed_by` **survives** (the scheduler fills `closed_by => null` on a row whose original is already
  null, so the attribute is not dirty and is not in the UPDATE). Terminal status is `Closed` either way.
  Impact: an audit-label degradation in a ~seconds-wide window around 01:00, not a money or state defect,
  and inherited (the same hole lets a stale chunk silently undo a reopen). **M-1.**
- **The ordering invariant is NOT lock-protected across rows.** The close locks only its own row
  (`:86-89`) and the predecessor test is an unlocked `exists()` (`:151-155`); the reopen is symmetric
  (`:81-84`, `:138-142`). Two transactions touching different rows never conflict under READ COMMITTED,
  so a concurrent "close P3" + "reopen P2" can both commit and leave a Closed P3 behind an Open P2 —
  the exact state both guards exist to prevent. Not executed (needs two connections); grounded in the
  lock scope above. Inherited from Q-10, but C-24 completes the pair that makes it reachable. **I-2.**
- **Head-of-line blocking:** `lockExpiredPeriods()` wraps EVERY company in ONE transaction
  (`:113-144`). A manual close that collides with it waits on the row lock for the whole scheduler
  transaction, with no `NOWAIT`/lock timeout — a 01:00 request can hang for the length of the sweep.
  **M-2.**

### (7) ci.yml allowlist, manifest raise

Both correct.
- The append is `|FiscalPeriodCloseEndpointTest)::/` at the tail of the `backend-test-pgsql` `--filter`
  alternation (`ci.yml:1004`), plus a 6-line comment block at `:960-965` carrying the S-17 caveat, placed
  immediately above the `FiscalPeriodReopenEndpointTest` sibling entry. `feature-lane-manifest-check.php`
  independently confirms "every `--filter` entry is anchored and uniquely matched against 1830 test
  classes" — so the anchoring/uniqueness question is answered by the tool, not by eyeball.
- The entry is **necessary**: `feature-lane-tenancy` is gated on
  `vars.SELF_HOSTED_RUNNER_READY == 'true'` (`ci.yml:1893`), and the sqlite `backend-test` job runs only
  `--testsuite=Unit` plus named paths (`:420`, `:423`, `:468`, `:478-496`) — it never runs
  `tests/Feature/Company/`. Without the allowlist line the new class would execute in no CI job at all.
  Exactly the Q-10/C-3 precedent; the implementer's flagged step-beyond-the-brief is **endorsed**.
- Manifest raise Company 32→33 and `gated_ceiling` 1180→1181 is **justified, and proven by execution**:
  with my one extra probe class in that directory the checker failed with
  "group Company now holds 34 … ceiling is 33" and "1182 … ceiling is 1181"; with it removed the checker
  is OK. The note text records the raise, the lane, the parked state and the gate record path.
- **S-14 promotion leg:** the promotion that carries this branch must carry the `ci.yml` hunk and the
  manifest hunk together (a manifest-only or filter-only merge is red on either side), and the
  ci.yml `--filter` line is a single very long line — a conflict there must be resolved by re-appending
  the name, never by taking one side wholesale.

### (8) Residuals

Confirmed as stated: no `close_reason` column (accepted, validated 3..500, logged at
`FiscalPeriodCloseService.php:126-135`, never persisted — pinned by
`FiscalPeriodCloseEndpointTest.php:298-321`); no domain event (C-24 (iv), symmetric with the reopen);
no FE page (C-24 (iii)); English refusal strings (inherited from the reopen family — the CODE is the
contract, `FiscalPeriodCloseRefusalCode.php:33-36`).

**One residual is stated WRONG and one is missing** — see **C-2**.

---

## 3. Findings

### Conditions (satisfy before or at merge; neither blocks the merge decision)

- **C-1 — pin the refusal precedence (finding I-1).** One assertion + one comment.
- **C-2 — correct residual #3 and add the missing residual (finding I-4).**

### Important

- **[Important] `apps/api/app/Modules/Company/Application/Services/FiscalPeriodCloseService.php:108` and `:112`
  (vs its own docblock `:50-58`, `FiscalPeriodCloseRefusalCode.php:26-31`, and the brief) — I-1: the refusal
  precedence is the reverse of the documented one and nothing pins it.** Executed: when a period is both
  not-ended and behind an open predecessor, the API returns `FISCAL_PERIOD_CLOSE_NOT_ENDED`. Why it
  matters: the enum doc is an API contract that tells the FE which remedy to render — `NOT_ENDED` means
  "wait", `PREDECESSOR_OPEN` means "close the earlier period first". Shipping a documented order that the
  code does not honour means the first FE built against it renders the wrong remedy, and a later reorder
  is a silent wire-contract change. Fix (either is acceptable, pick one and make the docs match):
  (a) move the `hasOpenPredecessor` check above the `end_date` check to match the docs, or (b) keep the
  code order and swap the two bullets in both docblocks. **Either way add one test** —
  `test_not_ended_wins_over_predecessor_open` (or the reverse) — asserting `error.code` when both hold.

- **[Important] `FiscalPeriodCloseService.php:86-89` + `:151-155` (and the symmetric
  `FiscalPeriodReopenService.php:81-84` + `:138-142`) — I-2: the oldest-first ordering invariant is not
  lock-protected across rows.** `lockForUpdate()` covers only the row being transitioned; the
  predecessor/successor tests are unlocked `exists()` reads, so a concurrent close-of-P3 and reopen-of-P2
  do not conflict and both commit, producing a Closed period behind an Open one. Why it matters: that is
  precisely the state the two guards exist to prevent, and it is the state the GL posting guard keys off —
  a posting can then land in P2 after P3 has been reported on. Likelihood is low (two humans, adjacent
  periods, same instant) and it is inherited from Q-10, which is why this is not merge-blocking. Fix: take
  the company-scoped transaction advisory lock the GL already uses — the exact idiom is
  `DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$companyId])` at
  `GeneralLedgerService.php:3796` — as the first statement in BOTH the close and the reopen
  transaction. One line each, no schema change. File as a C-24 follow-up covering both edges.

- **[Important] `FiscalPeriodCloseService.php:108` (the guard's ceiling, not its floor) — I-3: the manual
  close has no minimum age, while every automatic close waits the company's country window
  (`FiscalPeriodAutoLockService.php:132`, `:176`), and the POS is offline-first.** Executed (P3): the close
  makes `isDateInClosedPeriod` true instantly and `GeneralLedgerService.php:3805-3807` then throws
  `ClosedFiscalPeriodException` for any entry dated inside the period. Grep across
  `app/Modules/PosCore`, `app/Modules/Treasury`, `app/Modules/Fiscal` finds **no** catch of that exception
  — only `Accounting` raises and handles it. So an accountant closing January on 1 February blocks the GL
  projection of every device-authored receipt, expense payment or instrument settlement dated in January
  that is still queued or still sitting on an offline terminal. Why it matters: the device chain is the
  fiscal source of truth and the GL is the downstream projection — a projection that cannot be written is
  a reconciliation break, not a device problem. Mitigations that keep this off the blocking list: it is
  operator-initiated, and it is reversible — `fiscal-periods.reopen` exists, is permissioned, and would be
  the recovery. Fix (choose): (a) runbook line in the S-20 deploy note — "do not close a period while any
  terminal is unsynced for it"; (b) better, a preflight in the 200/refusal payload counting unsynced
  device events + Draft JEs dated inside the period; (c) an optional country-derived minimum age reusing
  `CountryFiscalRulesProvider`. At minimum (a).

- **[Important] `FiscalPeriodCloseService.php:116-124` → `FiscalPeriodAutoLockService.php:278-301` — I-4:
  a Draft journal entry inside the closed period becomes permanently unpostable one nightly run later, and
  this is not in the residual list.** Executed (P4): manual close → nightly run → period `locked`, draft
  still `Draft`. `Locked` has no in-product outgoing edge (the reopen refuses it,
  `FiscalPeriodReopenService.php:86-88`; this close refuses it, `:91-93`), so the draft can never be
  posted and never be dated into another period without a manual `UPDATE`. **Ruled acceptable** on the
  Q-10 precedent — the nightly auto-close already does exactly this and the lane brief scoped no preflight
  — but the report's §6 residual list does not mention it at all, and that list is what feeds the LEDGER.
  Fix (**C-2**): add it as residual, and correct residual #3 while there — see M-5.

### Minor

- **[Minor] `FiscalPeriodAutoLockService.php:190-199` — M-1: the nightly close can clobber a human
  `status_actor`.** Chunk-read then unconditional `save()`; no `where('status', Open)` precondition and no
  `lockForUpdate`. Simulated (P6): `status_actor` becomes `system:auto-lock`, `closed_by` survives.
  Inherited (it lets a stale chunk undo a reopen too). Fix: add `->where('status', PeriodStatus::Open)
  ->whereNull('reopened_at')` to the per-row update, or re-read `lockForUpdate` inside the chunk loop.
  Out of this lane's scope; file against the scheduler.

- **[Minor] `FiscalPeriodAutoLockService.php:113-144` — M-2: single all-company transaction blocks the
  manual close.** A close racing the sweep waits for the whole transaction; no lock timeout. Fix:
  per-company transactions, or `lockForUpdate()->skipLocked()`/a statement timeout on the endpoint.

- **[Minor] `FiscalPeriodCloseService.php:105-107` — M-3: "app timezone" is UTC (`config/app.php:68`).**
  For TN/FR the just-ended period is unclosable for the first 1–2 local hours of the next day. Direction
  is conservative for every target country (all ≥ UTC), so behaviour is fine; the docblock should just
  say UTC and name the consequence instead of implying a company-local boundary.

- **[Minor] `FiscalPeriodCloseService.php:50-53` — M-4: the `PREDECESSOR_OPEN` rationale overstates the
  scheduler.** `closeOldPeriods()` (`:180-189`) has no ordering predicate and routinely closes a later
  period while a reopened earlier one is Open. The guard is still correct (refusal-only, mirrors reopen);
  only the "the scheduler assumes oldest-first" sentence is not true of the code. Reword.

- **[Minor] `FiscalPeriodCloseRefusedException.php:38` — M-6: the `PERIOD_LOCKED` message asserts "and the
  fiscal year they belong to is closed".** True for scheduler-produced Locked rows, not guaranteed for
  seeded/imported ones. Drop the second clause.

- **[Minor] REPORT-C24-implementer.md §6 residual 3 — M-5: the `FISCAL_YEAR_CLOSED` dead end is
  overstated.** Executed (P5): an Open period inside a closed year is refused by both human edges, but the
  next nightly run **locks** it (`FiscalPeriodAutoLockService.php:278-288` excludes only
  `Open AND reopened_at IS NOT NULL`), so the state is transient for non-reopened rows and, as shown in
  §2(1), unreachable for reopened ones. Reword to "transient; the nightly STEP 3 resolves it by locking
  the row Open → Locked, skipping Closed entirely" — that skipped-state transition is itself the thing
  worth carrying to the PeriodStatus adjacency work (C-24 (vii)).

### Explicitly checked and CLEAN

- **Rule 19 / money precision: N/A and verified.** `git diff … | grep -E '(float)|floatval|parseFloat|
  number_format|getScale\(\)|bcadd|bcsub|bcmul'` over `apps/api/app`, `apps/api/database`, `apps/web`
  returns **nothing**. No money or quantity value is read, written or compared anywhere in the lane; no
  scale resolver is touched; no `journal_lines` assumption is made.
- **No treasury row is touched.** `payment_repositories.balance`, `account_id`/`gl_account_id`, cash
  drawers, instruments: untouched. This is a period-status lane only.
- **Module boundaries (rule 6):** the four new classes import only `App\Modules\Company\…` +
  `Carbon`/`Illuminate` (+ the Domain enum's `{@see}`-only import of its own Domain exception, mirroring
  `FiscalPeriodReopenRefusalCode.php:7`). The exception NAMES the Application service in prose rather than
  importing it, preserving the Q-10 deptrac shape. deptrac: 183 violations, none naming a new class.
- **Rule 13 (constructor injection):** `FiscalPeriodController.__construct` (`:37-41`) takes the service
  as `private readonly`; no `app()` in production code. The `app()` calls are in tests only, which is fine.
- **Response shape:** the `payload()` extraction (`FiscalPeriodController.php:128-146`) preserves the
  reopen's 15 keys in the same order; the 12 reopen tests re-run green, so the reopen wire bytes did not
  move.
- **Test quality:** every test asserts real behaviour against real models with `RefreshDatabase` +
  `RolesAndPermissionsSeeder`; no `assertTrue(true)`; no mocking of the thing under test; the integration
  pin at `:339-377` asserts the PRE-state (hold in place) before asserting the release, which is what
  makes it a real regression pin rather than a tautology. The `setUp` deletion of auto-created fiscal years
  (`:69`) is documented and justified. No fake API payloads.

---

## 4. Deploy / promotion notes

1. **S-20 must be extended**, exactly as the report says: per tenant, re-run `RolesAndPermissionsSeeder`
   (idempotent) and reset the permission cache so `fiscal-periods.close` exists and is granted — without
   it the endpoint is a silent 403 for everyone including admin. This can and should be done in the same
   pass as the `fiscal-periods.reopen` step S-20 already owes for Q-10.
2. **No migration in this lane**, but it is migration-dependent on Q-10's
   `2026_08_24_140000_add_fiscal_period_transition_audit_columns.php`. `f5cae1f12` is an ancestor of
   `77984ddb3` — do not split the pair.
3. **S-14 leg:** the `ci.yml --filter` append and the `feature-lane-manifest.json` raise are one unit.
   Resolve any conflict on the `--filter` line by RE-APPENDING `|FiscalPeriodCloseEndpointTest`, never by
   taking one side; and reconcile `gated_ceiling`/Company `classes` additively (the Q-10 C-1 precedent).
4. **S-17 caveat applies:** CI has not been observed green on this branch; all evidence above is local,
   by path, on PG 5433.
5. Throwaway DBs to drop when the gate closes: `autoerp_treasurygate_test` (mine) and `autoerp_c24_test`
   (the implementer's).

---

## 5. One line

**Fix before merge: pin the refusal precedence (C-1) and correct + complete the residual list (C-2);
everything else on this record is a follow-up ticket, not a blocker.**
