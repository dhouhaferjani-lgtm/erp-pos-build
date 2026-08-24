# Gate record — Session B lane Q-10 "fiscal-period quick fixes" — treasury lens, ROUND 2 (fix round)

- **Lane / branch:** `fix/sb-q10-fiscal-period-quickfixes`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q10-fiscal-periods`
- **r1 target:** `ea15dca28` · **fix round under review:** `8a285c495` ("fix(fiscal-periods): scheduler respects a human reopen; typed reopen refusal codes")
- **Diff reviewed:** `git diff ea15dca28..8a285c495` — 9 files, +406/-17. No `.github/**`, no `feature-lane-manifest.json`, no new test class (both instructions honoured).
- **dev at gate time:** `a9d615d75`
- **r1 record:** `docs/superpowers/reviews/2026-08-24-sb-q10-fiscal-periods-gate-r1.md` · **fix brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q10-fixround.md`
- **Reviewer lens:** treasury-reviewer (adversarial, code-grounded). Date: 2026-08-24. MIGRATION-BEARING (unchanged from r1).

## Verdict

**spec ✅ + quality APPROVED (ACCEPT).** C-2 is discharged in substance, not in appearance: I re-derived every
one of the implementer's five claims from the files, and I additionally **probed the behaviour by path on both
drivers** with a reviewer-authored throwaway test (outside the repo, `scratchpad/GateQ10R2ProbeTest.php`) rather
than trusting the lane's own two pins. No money arithmetic anywhere in the diff (no bcmath, no float, no
`parseFloat`, no `getScale()`), no journal entry / treasury row / VAT period touched, no cross-module Eloquent
import, deptrac unchanged. **Critical findings: none. Important findings: none.** Four minors, all residual-class.

C-1 (manifest union) and C-3 (CI allowlist append) remain **parent actions at merge** — restated below with
freshly measured numbers.

## Disposition of the r1 conditions and minors

| r1 item | Disposition | Evidence |
|---|---|---|
| **C-2 / I-1** (auto-lock undoes a reopen) | **DISCHARGED** — three exclusion arms + 2 lane pins + 3 reviewer probes green on sqlite AND PG | `FiscalPeriodAutoLockService.php:188`, `:235-239`, `:281-284`; `FiscalPeriodAutoLockServiceTest.php:570,620` |
| **M-1** (no machine-readable refusal code) | **DONE** — 4-case backed enum + typed exception + house envelope, each code pinned | `FiscalPeriodReopenRefusalCode.php:39-46`, `FiscalPeriodReopenRefusedException.php:29-72`, `FiscalPeriodController.php:54-66`, endpoint test `:155,168,180,192,211` |
| **M-2** (`->whereUuid('id')`) | **DONE** (optional item taken) | `app/Modules/Company/routes.php:72` |
| **M-3** (stale command docblock) | **DONE** — the "bulk updates" prose is replaced with an accurate per-row/idempotency paragraph that names the surviving pin | `LockExpiredFiscalPeriodsCommand.php:16-20,49-65` |
| **C-1** (manifest union 1164 / Company 31) | **OPEN — parent at merge.** Lane correctly left the file untouched | see "Merge conditions" |
| **C-3** (ci.yml:969 allowlist) | **OPEN — parent at merge.** Re-grepped: `FiscalPeriodReopenEndpointTest` is still absent from the `:969` filter | `.github/workflows/ci.yml:969` |

## A. The three exclusion arms — verified precisely

**(i) Can a reopened period ever be auto-closed or auto-locked now? No — proved by path, not by reading.**
- STEP 1 `FiscalPeriodAutoLockService.php:188` `->whereNull('reopened_at')` (inside `closeOldPeriods()`, `:174`).
- STEP 3 `:281-284` `->whereNot(fn => status = Open AND reopened_at IS NOT NULL)` (inside
  `lockPeriodsInClosedFiscalYears()`, `:273`).
- Reviewer probe `test_probe_reopened_period_survives_two_runs` ran `lockExpiredPeriods()` **three times** against a
  reopened period sitting in a fiscal year that ENDED two years ago (the worst case: STEP 2 + STEP 3 both eligible)
  and asserted after each pass: `status = Open`, `status_actor = user:<uuid>` (human preserved), `locked_at` NULL,
  `closed_at` NULL, `fiscal_years.is_closed = false`. **Green on sqlite and on PostgreSQL 16.** Idempotency holds.

**(ii) Is the STEP-2 hold company-scoped? Yes — code and probe.**
`:235-239` — the sub-select is `FiscalPeriod::query()->where('company_id', $companyId)->where('status', Open)
->whereNotNull('reopened_at')->select('fiscal_year_id')`, and the outer `FiscalYear::query()` is itself
`->where('company_id', $companyId)` (`:227`); the whole three-step body runs per-company inside the
`Company::chunkById` loop (`:117-142`). Probe `test_probe_reopen_hold_is_company_scoped` builds company A with a
reopened period in an ended year and company B with an ordinary ended year: **A's year stays open, B's year closes
and B's period LOCKS.** Green on both drivers. No cross-company bleed.

**(iii) NOT-IN NULL-poisoning claim: CONFIRMED on the live schema, not just the migration.**
Migration `2025_11_30_132000_create_compliance_tables.php:107` declares `$table->uuid('fiscal_year_id');` (no
`->nullable()`), and only one later migration touches the table
(`2026_08_24_140000_add_fiscal_period_transition_audit_columns.php:64-71`, seven *nullable* additive columns, no
alter of `fiscal_year_id`). Verified against the migrated throwaway PG DB `autoerp_gate_q10r2` (port 5433):
`information_schema.columns` → `fiscal_year_id | NO | uuid`. The sub-select cannot emit NULL, so the `NOT IN`
cannot silently return zero rows. Claim TRUE.

**(iv) Reopened-then-re-closed by a human: locks with its year — TRUE, documented, but NOT pinned by the lane.**
Documented at `FiscalPeriodAutoLockService.php:265-270`. The STEP-3 predicate is a conjunction
(`status = Open AND reopened_at IS NOT NULL`), so a row with `reopened_at` set and `status = Closed` is not
excluded; and STEP 2 no longer holds the year because the sub-select requires `status = Open`. Reviewer probe
`test_probe_reopened_then_reclosed_still_locks` confirms: the year closes and the settled period reaches
`Locked`. Green on both drivers. This is the correct semantics (a settled correction must not block the seal
forever) — but see finding N-1: the lane has no test for it, only prose.

**(v) RULING on the side effect (a reopened period holds its fiscal YEAR open indefinitely): the trade is CORRECT for launch — ACCEPT, with a mandatory follow-up.**
Grounds, all code-checked:
1. `Locked` is terminal and has **no in-product reverse edge** (r1 residual R-5; `FiscalPeriodReopenService.php:86`
   refuses on Locked). A wrongly locked correction is unrecoverable without direct SQL. An un-closed period is
   recoverable. Recoverable beats terminal — the parent ruling is right.
2. **The fiscal cost of the held YEAR close is close to zero**, which is the load-bearing fact the implementer did
   not name: the posting guard is `FiscalPeriodResolverService::isDateInClosedPeriod()`
   (`app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php:287-291`), which treats
   **Closed and Locked identically** (`whereIn('status', [Closed, Locked])`). Holding the year open therefore does
   NOT re-open any sibling period to posting — STEP 1 still closes the siblings (pinned by the lane's own
   `test_it_does_not_lock_a_reopened_period_when_its_fiscal_year_has_ended` and by my probe). It only defers the
   terminal seal. `fiscal_years.is_closed` has exactly one other reader in the whole app
   (`FiscalPeriodResolverService.php:87`, a comment/optional filter) — grep-verified.
3. The genuine cost is the **reopened period itself staying postable with no automatic end**, and it is unbounded:
   I grep-verified that **no other code path in `apps/api/app` ever writes `PeriodStatus::Closed`/`Locked` or
   `fiscal_years.is_closed = true`** outside `FiscalPeriodAutoLockService` (only `FiscalPeriod::isClosed()` reads it
   at `Domain/FiscalPeriod.php:151`). So the only way back to Closed today is the manual `UPDATE fiscal_periods`
   that lane item (c) existed to abolish — the same operation, now needed in the opposite direction.
4. Blast radius today is small and attributable: the edge is permission-gated (`fiscal-periods.reopen`, accountant/
   admin), stamps actor + reason, only the newest closed period of an OPEN fiscal year is reachable (ordering
   invariant), and **no frontend calls the endpoint yet** (r1 residual R-4, re-grepped: zero hits for
   `fiscal-periods/` in `apps/web/src` and `apps/pos/src`).
**Ruling:** accept as-is; record as LEDGER residual **R-7** with follow-up **"build a manual single-period close
(the mirror of the reopen: permissioned, actor-stamped, sets `closed_at`/`status_actor`)"**, and treat that
follow-up as a **prerequisite for shipping the reopen UI** (R-4), not merely a nice-to-have — the moment an
accountant can reopen from the UI without being able to close from the UI, the unbounded window becomes a real
control gap.
**Documented in BOTH docblocks — confirmed:** `FiscalPeriodAutoLockService.php:76-83` ("KNOWN GAP, stated rather
than hidden: the product has NO manual close endpoint for a single period …") and
`FiscalPeriodReopenService.php:18-33` ("HONEST CONSEQUENCE: there is currently NO manual close endpoint …").
Both say it plainly and neither overstates the fix.

## B. Refusal codes

Four distinct codes, one per refusal, in an **unchanged order** — `FiscalPeriodReopenService.php:87` PeriodLocked
→ `:91` PeriodNotClosed → `:97` FiscalYearClosed → `:101` SuccessorSettled (identical ordering to r1's `:67/71/77/81`,
so the "most specific refusal wins" invariant r1 checked is preserved). Enum values are namespaced and stable
(`FiscalPeriodReopenRefusalCode.php:39-46`: `FISCAL_PERIOD_REOPEN_{LOCKED,NOT_CLOSED,FISCAL_YEAR_CLOSED,SUCCESSOR_SETTLED}`).
The exception is `final`, private-constructor + 4 named factories, carries `refusalCode` and `fiscalPeriodId` as
readonly promoted properties (`FiscalPeriodReopenRefusedException.php:33-72`), extends `\DomainException` so the
generic `bootstrap/app.php` 422 remains a safety net. Both new classes are `App\Modules\Company\Domain\*` — no
Application-layer import (deptrac 182, unchanged).
Controller `FiscalPeriodController.php:54-66` catches the typed exception (narrowed from `\DomainException`) and
emits `{error: {code, message, fiscal_period_id}}` with HTTP 422. All four codes pinned in
`FiscalPeriodReopenEndpointTest.php:155, 168, 180, 192, 211` (SuccessorSettled pinned twice: closed-successor and
locked-successor arms). **Contract-break check:** the 422 body changed from `{message}` to `{error:{…}}`; grep found
**zero consumers** in `apps/web/src` / `apps/pos/src` and no OpenAPI document under `apps/api` (none exists — cannot
verify a contract file, because there isn't one), so nothing breaks.

## Findings (ordered by severity)

**Critical:** none. **Important:** none. Specifically re-checked and clean: no `(float)`/`parseFloat`/`Number()`/
`number_format` on money anywhere in the diff; no `CurrencyScaleResolverInterface` and no `getScale()` (this lane does
no money arithmetic at all, including in the queued/console path the scheduler uses); no journal entry, no
`payment_repositories`, no VAT period; no cross-module Eloquent import (all new symbols are
`App\Modules\Company\*`; the only foreign import is `App\Modules\Identity\Domain\User` in a **test** fixture,
`FiscalPeriodAutoLockServiceTest.php:13`); device/fiscal chain untouched.

### [MINOR] N-1 — the (iv) semantics (reopened-then-re-closed still locks) is prose-only, unpinned
`apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:269-270` asserts the behaviour
in a docblock; no test in `FiscalPeriodAutoLockServiceTest` covers it. I proved it true by probe on both drivers,
so this is not a defect — but it is the one arm of the new predicate that is a **conjunction**, i.e. the arm a future
"simplify to `whereNull('reopened_at')`" refactor would silently break, converting settled corrections into rows that
never lock. Suggested fix: fold my probe case in when the file is next touched (10 lines), or accept as residual R-8.

### [MINOR] N-2 — the company scoping of the STEP-2 hold is unpinned
`apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:235-239`. The scoping is present
and correct and my probe confirms it, but no lane test has two companies, so a future edit that drops
`->where('company_id', $companyId)` from the sub-select — turning one tenant's correction into a fleet-wide freeze of
every company's year close — is caught by nothing. Same suggested fix as N-1 (residual R-8).

### [MINOR] N-3 — unbounded reopen window / no manual single-period close
See ruling A(v). Not a defect of this diff (it is the ruled trade, and it is documented in both docblocks); recorded
as residual **R-7** with the follow-up, and gated against the reopen UI landing.

### [MINOR] N-4 — `use` statement that exists only to satisfy a docblock `{@see}`
`apps/api/app/Modules/Company/Domain/Enums/FiscalPeriodReopenRefusalCode.php:7` imports
`FiscalPeriodReopenRefusedException` although no code in the enum references it (only the class docblock at `:33`).
Pint and PHPStan L8 both accept it (PHP-CS-Fixer's `no_unused_imports` honours annotation references), and both
classes sit in `Domain`, so there is no layering consequence. Cosmetic; note only. It also creates a mutual
enum↔exception `use` pair, which is legal but slightly awkward to read.

### Non-finding, recorded because I checked it
Refusal **messages** are literal English on the exception, not `messages.*` keys. Rule 11 governs the frontend, and
the enum docblock (`:35-40`) states the choice and the reason (no fiscal-period lang namespace exists). The CODE is
the contract; localisation is the FE's job when R-4 lands. Not a violation.

## Gate verified — per file, per driver (run BY PATH, one process at a time; full suite never run)

SQLite (`phpunit.xml` defaults, `:memory:`):

| Path | Result |
|---|---|
| `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php` | **17 tests, 39 assertions — OK** |
| `tests/Unit/Company/Application/Services/CountryFiscalRulesProviderTest.php` | **7 tests, 35 assertions — OK** (5 PHPUnit deprecations, pre-existing `/** @test */` style) |
| `tests/Feature/Company/FiscalPeriodReopenEndpointTest.php` | **12 tests, 41 assertions — OK** |
| `tests/Feature/Fiscal/LockExpiredFiscalPeriodsCommandTest.php` | **5 tests, 15 assertions — OK** |
| `tests/Architecture/FeatureLaneManifestCheckerTest.php` | **76 tests, 431 assertions — OK** (in-branch EXIT=0, as required) |
| reviewer probe `GateQ10R2ProbeTest` (scratchpad, NOT committed) | **3 tests, 10 assertions — OK** |

PostgreSQL 16 on 127.0.0.1:5433, throwaway DB `autoerp_gate_q10r2` (created, migrated by `RefreshDatabase`,
**DROPPED at end of gate**):

| Path | Result |
|---|---|
| `FiscalPeriodAutoLockServiceTest` | **17 / 39 — OK** |
| `CountryFiscalRulesProviderTest` | **7 / 35 — OK** |
| `FiscalPeriodReopenEndpointTest` | **12 / 41 — OK** |
| `LockExpiredFiscalPeriodsCommandTest` | **5 / 15 — OK** |
| reviewer probe `GateQ10R2ProbeTest` | **3 / 10 — OK** |
| schema proof | `information_schema.columns` → `fiscal_year_id NO uuid`, `company_id NO uuid`, `status NO`, `reopened_at YES timestamp` |

The implementer's aggregate claim **"sqlite 24/74 + 17/56, PG 24/74 + 17/56"** reconciles exactly:
17+7 = 24 tests / 39+35 = 74 assertions (Unit pair) and 12+5 = 17 / 41+15 = 56 (Feature pair), identical on both
drivers. Claim TRUE.

**RED→GREEN provenance:** the two new `FiscalPeriodAutoLockServiceTest` cases were **not** re-run at `ea15dca28`
(checkout is forbidden in this gate); their red-ness is re-derived from the diff — at the r1 commit STEP 1 had no
`reopened_at` predicate (so the re-close case fails on `status`), and STEP 2 had no hold + STEP 3 no exclusion (so the
ended-year case reaches `Locked`). The implementer's "the red run proved STEP 3 previously LOCKED a reopened period
(terminal)" is consistent with the code that was removed; recorded as *derived*, not *observed*.

Static / structural:

- `pint --test` on all 9 changed files → `{"result":"pass"}`
- `phpstan analyse` (level 8, project `phpstan.neon`, live-DB env) on the 6 changed/new app files → **[OK] No errors**
- `deptrac analyse` — **lane 182 violations / dev `a9d615d75` 182 violations**, both measured this session. Unchanged.
- `FeatureLaneManifestCheckerTest` green in-branch (76/431); `.github/**` and the manifest untouched by the fix round
  (`git diff --stat` shows 9 files, none of them).

## Merge conditions for the parent (restated, freshly measured)

| | gated_ceiling | Company | Voucher |
|---|---|---|---|
| `dev` `a9d615d75` (live) | 1163 | 30 | 11 |
| lane `8a285c495` | 1163 | **31** | 10 |
| **post-merge union (REQUIRED)** | **1164** | **31** | **11** |

- **C-1 (blocking, merge mechanics).** Resolve `apps/api/tests/feature-lane-manifest.json` to
  **gated_ceiling 1164 / Company 31 / Voucher 11**. Both sides literally read `1163`; the naive "keep 1163"
  resolution leaves `FeatureLaneManifestCheckerTest` RED on dev. Every other group is taken from dev verbatim.
- **C-3 (parent action at merge).** Append `FiscalPeriodReopenEndpointTest` to the live `--filter` allowlist at
  `.github/workflows/ci.yml:969` (B-3 / Q-6 / Q-7 precedent). Re-grepped this round: the class is **still absent**
  from that filter, and it is the only regression pin on the four refusal codes, the Locked-terminal guard, the
  ordering invariant and the accountant/admin permission. The reviewer did NOT edit `.github/**`.
- **Deploy steps unchanged from r1** (S-19 migration-bearing bundle): `tenants:migrate` → re-seed
  `RolesAndPermissionsSeeder` per tenant → permission cache reset. No POS device bump, no FE deploy.

## Residuals for the LEDGER

- **R-1 … R-6** — carried unchanged from the r1 record (hardcoded `CountryFiscalRulesProvider`; no seam to pin a
  country's window value; no domain event / audit row for the reopen; no frontend surface; `Locked` terminal +
  year-level reopen missing; longer write transaction + soft-deleted companies skipped). R-6's "stale command
  docblock" leg is now **closed** (M-3).
- **R-7 (NEW, from ruling A(v)) — no manual single-period close.** A reopened period stays Open, and holds its
  fiscal year open, until someone runs `UPDATE fiscal_periods` by hand. Follow-up: **build the mirror of the reopen —
  a permissioned, actor-stamped single-period close** — and treat it as a prerequisite for shipping the reopen UI
  (R-4). Blast radius today is curl-only.
- **R-8 (NEW, from N-1/N-2) — two arms of the new predicate are unpinned:** reopened-then-re-closed still locks, and
  the STEP-2 hold is company-scoped. Both verified true by reviewer probe this round; neither is guarded by a
  committed test. Fold in when the file is next touched.
- **Sibling gap (carried from r1 M-2):** `app/Modules/Taxation/routes.php:111` (VAT period reopen) still lacks
  `->whereUuid('id')`. Deliberately out of scope for this lane.
