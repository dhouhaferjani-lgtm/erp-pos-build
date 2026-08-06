# L3 multi-branch cash-visibility — adversarial authz/scoping merge gate

- **Date:** 2026-08-06
- **Worktree / branch:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l3-cashvis`, `fix/l3-multibranch-cash`
- **Commits reviewed:** `8015caed9`, `34990c5f1`, `5d8bbdafc` (real merge base = `b54f45160`, NOT `a84c1b53e` — see N-1)
- **Suites run by the reviewer:** `tests/Feature/Accounting/CashMovementsReportTest.php` → 22/22 OK; vitest `useCashMovementsReport.test.tsx` (4) + `CashMovementsReportPage.test.tsx` (4) → 8/8 OK; pint `pass`; phpstan on the 3 changed backend files → `No errors`. E2E not run (needs live stack).
- **Method:** a scratch probe suite (`ZzScratchCashScopeProbeTest`, 8 probes) was added, run, and **deleted**; worktree left byte-clean (`git status` empty).

## VERDICT: spec ❌ / quality CHANGES-REQUESTED

The controller/request/FE half of the lane is correct and matches the aged-* pattern exactly.
The **journal-leg scope predicate is defeated by the default chart of accounts** and turns
unattributed cash into per-branch duplicates, which breaks the very invariant the flipped
MTP-MLC-08 tripwire now asserts.

---

## Findings

### [CRITICAL] `CashMovementsReportService.php:323-337` — the journal-leg location predicate is a no-op (and a 4x over-count) whenever cash registers share one GL account, which is the default configuration

The leg is scoped by `whereExists(payment_repositories WHERE gl_account_id = journal_lines.account_id
AND location_id IN (:scope))`. `payment_repositories.gl_account_id` is **many-to-one**: it is only
indexed, never unique (`database/migrations/tenant/2026_01_10_120001_add_gl_account_to_payment_repositories.php:27`),
and both provisioning paths assign the **single company-wide** `SystemAccountPurpose::Cash` account to
**every** `cash_register`/`safe`:

- backfill `database/migrations/tenant/2026_03_02_400000_backfill_gl_account_on_payment_repositories.php:32-44`
- `database/seeders/PaymentRepositorySeeder.php:63,74-75,102-103`

so for any tenant on the default wiring the EXISTS matches *some* in-scope repository for *every* branch.

Probe A (2 branches, shared cash account, one `manual_cash_sale` JE of 30.00):
`scopeA = 30.00 (1 row)`, `scopeB = 30.00 (1 row)`, `unscoped = 30.00 (1 row)` → Σ(branches) 60 > All 30.

Probe H, the real production shape — a petty-cash **expense settlement**
(`ExpenseService.php:619-621` credits the company-wide Cash account;
`GeneralLedgerService.php:984` stamps `source_type = 'expense_settlement'`, which is NOT in
`PAYMENT_BACKED_SOURCE_TYPES:43-49`, so it is emitted by the journal leg) with **4 branches**, i.e.
tenant #1's exact shape:

```
[PROBE H] per-branch={"A":{"count":1,"out":"25.00"},"B":{...25.00},"C":{...25.00},"D":{...25.00}}
          unscoped out="25.00" count=1
```

One 25.00 cash outflow reported in full under all four branches — a **4x** overstatement of branch cash out.

Why it matters:
1. It contradicts the lane's own stated contract. Unattributed cash is **hidden** under a strict scope
   when the register has no location (probe C: `scopeA=0`, `unscoped=1`) and when the payment has no
   location (probe B: `scopeA=0`, `unscoped=1`) — but **replicated to every branch** when registers
   share a GL account. Two opposite behaviours for the same "no location dimension" row.
2. It breaks the invariants `5d8bbdafc` just asserted in MTP-MLC-08: pairwise disjointness
   (`w7-multilocation.spec.ts:566-575`) and Σ ≤ All (`:594-601`) both fail on any tenant that has a
   cash expense settlement plus ≥2 branch registers on the shared Cash account. The tripwire is
   therefore either **red in staging** or **vacuous** (fixture has no journal-only cash) — neither is
   the claimed "FIXED".
3. A branch-restricted principal (clamped read) is shown cash lines that no branch owns.
   Not cross-tenant and not cross-company (`companyId` predicates at `:316-317,327` hold; probe D
   returns 403 for a same-tenant/other-company location), so this is a **branch-scope correctness /
   money-integrity** break, not an isolation hole.

Suggested fix (small, local, keeps the doc rationale): make the leg **fail-closed** — under a strict
scope, include a journal line only if *every* cash repository owning that GL account is inside the
scope. i.e. keep the existing `whereExists`, and add
`whereNotExists(payment_repositories WHERE gl_account_id = journal_lines.account_id AND type IN CASH_TYPES AND company_id = :c AND (location_id IS NULL OR location_id NOT IN (:scope)))`.
Shared/ambiguous cash then behaves exactly like NULL-location cash (hidden strict, visible unscoped),
Σ ≤ All and disjointness hold, and probes A/H collapse to 0 rows per branch. Pin it with a test that
gives **two** repositories the **same** `gl_account_id` — the current fixture gives each repository its
own account (`CashMovementsReportTest.php:111-117`), which is exactly why
`test_journal_only_cash_lines_are_scoped_by_the_owning_repository_location` (`:833-856`) passes.

### [IMPORTANT] `LocationScopeBoundary.php:24-27` + `ReportsController.php:872` — a grant that covers all ACTIVE locations collapses to unrestricted and leaks a DEACTIVATED branch's cash

`isUnrestricted()` compares only `is_active = true` locations, but the scope it collapses to (`[]`)
applies **no** predicate at all. Probe F: user granted `[Shop A]`, Shop B deactivated, payments
A=10.00 and B=99.00 →

```
[PROBE F] clampedRead status=200 count=2 totals={"EUR":{"in":"109.00",...}}
[PROBE F] explicit inactive-B status=403
```

The same principal is **403'd** when asking for Shop B explicitly and is **served Shop B's 99.00**
on the implicit read. Pre-existing in the shared helper (aged-*/`CashPosition` have it too), but this
lane newly routes **cash** through it. Ticket the helper; do not silently inherit it. Fix shape:
`isUnrestricted()` should compare against *all* company locations (matching
`LocationScopeResolver::allCompanyLocationIds():72-80`), or `reportLocationScope()` should keep the
explicit id list instead of `[]` and add `orWhereNull` like
`CashPositionController.php:89-93` / `MaturingInstrumentsController.php:64-66` do.

### [IMPORTANT] `CashMovementsReportService.php:323-337` — the scope EXISTS ignores `payment_repositories.is_active`

`PaymentRepository` has `is_active` (`app/Modules/Treasury/Domain/PaymentRepository.php:42,217`) and no
soft-deletes. A **deactivated** register at Shop B still satisfies the EXISTS and keeps granting Shop B
visibility of every journal line on its GL account. Compare `CashPositionController.php:87`, which does
filter `is_active`. Pre-existing for the `repository_id` filter, but it is now an authorization-scope input.

### [IMPORTANT] `CashMovementsReportService.php:277-279` vs `:334-336` — the two legs answer to two different definitions of "branch"

Payments are attributed by `payments.location_id` (the settled **document's** location,
`PaymentController.php:596-607`); journal lines are attributed by the **register's**
`payment_repositories.location_id`. For the same physical cash move the two legs can disagree
(their own test `:858-895` builds exactly that: register at A, payment at B). The dedup keeps it from
double-counting, but the report's "branch" column means "document branch" for one row and
"register branch" for the next. Acceptable only if written down in the ticket's residual list — it
currently is not.

### [IMPORTANT] `w7-multilocation.spec.ts:516,553-601` — the flipped tripwire cannot fail on a total blackout

Assertions are subset (`:551-560`), pairwise-disjoint (`:562-575`), "not all three equal unscoped"
(`:578-586`), Σ ≤ All (`:588-601`). Every one of them is satisfied by **three empty scoped payloads**.
Only `allBefore.length > 0` (`:516`) is checked. A regression that scopes on the wrong column and
returns nothing per branch stays green. Add: at least one branch scope returns a non-empty payload
that is a *strict* subset of the unscoped read. Also `:578-586` (`every(...)).toBe(false)`) passes if
only ONE of the three scopes differs — assert per-scope.

### [MINOR] `ReportsController.php:761-768, 814-821, 846-853` — aged-*/upcoming-payments swallow the 403 into a 500 (their claim: CONFIRMED, pre-existing)

`reportLocationScope()` is called INSIDE `try { … } catch (\Exception $e)`, and
`AuthorizationException extends \Exception`. Probe G:

```
aged-receivables=500 body={"error":{"code":"REPORT_GENERATION_ERROR",
  "message":"Failed to generate aged receivables report: Requested location is outside your allowed scope."}}
aged-payables=500   upcoming-payments=500
```

Real, pre-existing (untouched by this diff), and it also leaks the authz message in a 500 body.
Ticket — NOT this lane. `cashMovements` (`:245`) correctly sits outside its catch and returns a clean 403.

### [MINOR] N-1 — the stated base is wrong

`git merge-base a84c1b53e HEAD` = `b54f45160`; `git diff a84c1b53e..HEAD` shows ~3 100 unrelated
deletions (Accounting GL preflight, deposit-resolution, seeders) because the branch is **behind** dev.
Review the lane as `HEAD~3..HEAD` (8 files, +565/−56) and rebase onto `origin/dev` before promoting.

### [MINOR] `GetCashMovementsRequest.php:33-34` — `location_ids[]=` (empty item) → 422, `location_ids=` (empty scalar) → 200/unrestricted

Probe E: `ghost-uuid=403, mixed valid+invalid=403, emptyItem=422, emptyScalar=200`. All three refuse
rather than drop — matches the claim and the aged-* siblings. The scalar-empty asymmetry is inherited
from `nullable|array` and is harmless; noted only so nobody "fixes" it later thinking it is a drop.

---

## Verdicts on the lane's open questions

**Q1 — clamp/refuse semantics match aged-*/CashPosition?** YES for the resolver path, verified end-to-end:
unscoped → clamped (`CashMovementsReportTest.php:797-830`, and probe F); explicit out-of-grant → 403
(`:781-795`); cross-company same-tenant location → 403 (probe D); shape-valid nonexistent uuid → 403;
mixed valid+invalid → 403 (never silently dropped); malformed uuid → 422 (`:895-901`). Under db-per-tenant
another tenant's location id cannot exist in the tenant DB's `locations` table, so it takes the same
403 path as the ghost uuid. The `uuid` rule is also what keeps a PG `uuid` column comparison from 500-ing.
**Caveat:** the clamp itself is unsound when a location is deactivated — see the second finding.

**Q2 — can a cash journal line evade / double-count the scope?** BOTH, and the pinned
non-resurrection test does not reach either. Evade: probe B (payment `location_id` NULL on a
Shop-A register → payment row dropped by `:277-279`, GL twin suppressed by the scope-independent dedup
`:338-348` → the move disappears from *every* branch) and probe C (register with NULL `location_id` →
its journal-only cash invisible under any branch). Double-count: probes A/H above. The dedup being
scope-independent is CORRECT and should stay; the defect is upstream, in the EXISTS.

**Q3 — repository-derived attribution vs populating `journal_entries.location_id`?**
The dead-column claim is TRUE (`2025_12_27_150002_add_location_id_to_journal_entries_table.php` creates it;
zero occurrences of `location_id` in `app/Modules/Accounting/Domain/JournalEntry.php`; no write site anywhere).
**Verdict for launch: repository-derived attribution is acceptable ONLY with the fail-closed guard from
finding 1.** As written it is not shippable to a 4-branch daily-use tenant: every petty-cash expense
settlement is counted four times. With the guard, the leg degrades honestly (ambiguous cash is
unattributed, not multiplied) and needs no schema work. **Yes — the dead column becomes a ticket**
(P2, post-launch): populate `journal_entries.location_id` at the posting sites, then scope the leg on
it and drop the repository indirection. Until then the column should be documented as reserved, so a
future reader does not assume it is authoritative.

**Q4 — company-level cash hidden under a strict branch scope: acceptable?**
**Acceptable-documented for launch, plus a P2 ticket for an explicit bucket.** Hiding NULL-location rows
under a strict scope is the established convention (`ReportsController.php:856-860`) and every sibling
surface behaves the same way. But the siblings *name* the residue: `CashPositionController.php:139-146`
emits an `unattributed` group and `MaturingInstrumentsController.php:137-145` an `unattributed` key —
cash-movements just silently omits it, and it is the surface a branch manager reconciles a drawer
against. Ticket: add an `unattributed` count/total to `meta` (visible in both modes) so a strict-scope
reader can see that something was withheld. Condition on launch: the FE must show a
"company-level cash not included in this branch view" note, otherwise the omission is undetectable.
This verdict is contingent on finding 1 being fixed — today the "hidden" contract is only honoured for
NULL-location rows and inverted for shared-account rows.

**Q5 — aged-*/upcomingPayments swallow the 403 into a 500?** CONFIRMED real and pre-existing (probe G
output above; `ReportsController.php:746/761`, `799/814`, `837/846`). **Ticket, not this lane** — and the
ticket should also strip the exception message from the 500 body. `cashMovements` is the only one of the
four that gets it right; the comment at `:242-244` is accurate.

**Q6 — no authz regression on the unscoped path?** CONFIRMED. A full-grant principal produces
`effective ⊇ active` → `isUnrestricted` → `[]` (`ReportsController.php:872`) → neither `:277-279` nor
`:334-336` adds a predicate → byte-identical SQL to pre-lane. The real guard is the **14 pre-existing
tests in the file, unchanged and green** (22/22 run by the reviewer). The 2 new "always-green" tests
(`:735-777`, `:779-... /:806-830`) are honest regression guards but, as the lane itself says, vacuous as
proof of the fix — they would have passed before the lane too. FE: `useViewScope` sends every allowed id
when `scope === 'all'`, which the backend collapses back to `[]`, so the full-grant FE path is unchanged too.

**Q7 — does the MTP-MLC-08 flip genuinely prove scoping?** PARTLY. The set-theoretic framing is the right
call (the payload carries no location field) and is strictly stronger than the old fingerprint tripwire.
Nothing was weakened. Two gaps: (a) every assertion survives three EMPTY scoped payloads — a blackout
regression is invisible (see finding); (b) the disjointness and Σ ≤ All assertions encode an invariant the
implementation does **not** satisfy on the default chart of accounts, so on a fixture that has any
journal-only cash (a cash expense settlement) this case goes red — the flip is premature until finding 1 lands.

---

## What to fix before merge

Make the journal leg fail-closed on shared/ambiguous cash GL accounts (finding 1) with a two-repositories-
one-account test; everything else (deactivated-location clamp, `is_active` in the EXISTS, the 403→500
swallow, the tripwire's blackout blind spot, the dead column, the unattributed bucket) is a ticket.

---

# ROUND 2 — narrow re-gate (2026-08-06, post-rebase)

**Basis:** branch rebased onto `dev` @ `a84c1b53e`, 6 commits; fix = `33842c147`, spec = `03f3a066c` + `b2006a33e`.
`git diff a84c1b53e..HEAD` is now clean (11 files, +1495/−74) — round-1 finding N-1 resolved.

**Re-verified by the reviewer:** `CashMovementsReportTest.php` **24/24 OK**; vitest
`useCashMovementsReport.test.tsx` (4) + `CashMovementsReportPage.test.tsx` (4) = **8/8 OK**;
pint `pass`; phpstan on the service `No errors`. A 7-probe scratch suite (`ZzScratchRegateProbeTest`)
was run and **deleted**; worktree left clean.

## VERDICT: spec ✅ / quality APPROVED — cleared for merge

### Round-1 findings — disposition

| # | Round-1 finding | Status |
|---|---|---|
| 1 | **CRITICAL** shared-GL-account replication | **FIXED, independently verified** |
| 2 | IMPORTANT `isUnrestricted()` unsound on a deactivated location | Ticketed `(a)`, correctly out of lane |
| 3 | IMPORTANT scope EXISTS ignored `is_active` | **FIXED, verified** |
| 4 | IMPORTANT two definitions of "branch" undocumented | **FIXED** (class docblock `CashMovementsReportService.php:21-51`) |
| 5 | IMPORTANT tripwire green on a total blackout | **FIXED** (`w7-multilocation.spec.ts:560-563`) |
| 6 | MINOR 403→500 in aged-*/upcoming | Ticketed `(b)` |
| 7 | MINOR wrong diff base | **FIXED** (rebased) |
| 8 | MINOR empty-param asymmetry | Noted, no change needed |

### Independent verification of the fail-closed clause

**SQL semantics (RG-1, emitted SQL dumped from `journalLinesQuery`).** The NULL disjunct sits INSIDE
the `NOT EXISTS` subquery, not on the outer side of the negation:

```
and not exists (select 1 from "payment_repositories" as "competing_repositories"
  where "competing_repositories"."gl_account_id" = "journal_lines"."account_id"
    and "competing_repositories"."company_id" = ? and "competing_repositories"."is_active" = ?
    and "competing_repositories"."type" in (?, ?, ?)
    and ("competing_repositories"."location_id" is null
         or "competing_repositories"."location_id" not in (?)))
```

so a NULL-location competitor **matches** the subquery and the line is withheld. The three-valued
`NOT IN` trap is genuinely avoided — the clause cannot be defeated by an unlocated register.
Confirmed behaviourally by **RG-2**: an unlocated HQ safe on the shared Cash account suppresses the
25.00 settlement under both branch scopes (`scopeA` shows only its own single-owner line, 30.00;
`scopeB` 0) while the unscoped read still shows both (2 rows, in 30.00 / out 25.00).

**RG-5, deactivated competitor:** a deactivated Shop-B register neither grants Shop B (`scopeB` 0
rows) nor blocks Shop A's legitimate line (`scopeA` 1 row, in 30.00). Claim (2) holds in both directions.

**RG-6, unscoped regression:** on the shared-account fixture the unscoped read is unchanged
(2 rows, in 10.00 / out 25.00).

**Short-circuit — the "byte-unchanged" claim is literally FALSE, semantically true.** The unscoped
SQL now carries an extra `and not exists (select 1 where 1 = 0)`. Verified on **real PostgreSQL 16.10**
(`autoerp_postgres`) that the construct is valid (`select 1 where 1 = 0` → 0 rows) and that the
planner reduces it to `InitPlan → Result → One-Time Filter: false`, evaluated once at zero cost.
Behaviour and plan cost are unaffected; only the wording should be corrected (NOTE-1).

**Performance (item 1, EXISTS-in-EXISTS).** `EXPLAIN (ANALYZE, BUFFERS)` of the scoped journal-leg
shape against a real local tenant DB (`tenant019fbe86-…`: 3 947 `journal_lines`, 131
`payment_repositories`, ~6x the 630-movement live shape) — PostgreSQL compiles BOTH correlated
subqueries into **hash semi-join / anti-join** over a 131-row seq scan (`shared hit=5`), not a
per-row loop. `Execution Time: 0.236 ms`. Non-issue.

## Adjudication — their open edge (register-less location)

**Their reading is CORRECT; keep it as-is. Do not touch `isUnrestricted()` for this.**

RG-7 pins what actually differs. Fixture: Shop A + Shop B (each with a register on the shared Cash
account) + a register-less Warehouse, one shared-account `expense_settlement` (25.00 out) and one
pure advance (7.00 in, NULL `payments.location_id`):

```
[RG-7] strict A+B   count=1  totals={"EUR":{"in":"0.00","out":"25.00","net":"-25.00"}}
[RG-7] unscoped     count=2  totals={"EUR":{"in":"7.00","out":"25.00","net":"-18.00"}}
```

The strict "all register-owning branches" scope **admits** the shared-account journal line (every
owner is in scope → the fail-closed clause is satisfied) and **hides** the pure advance. So the
strict-vs-unrestricted delta is entirely the **pre-existing NULL-location *payments* convention**,
not a new journal-leg inconsistency introduced by the guard.

Teaching `LocationScopeBoundary::isUnrestricted()` a "register-owning locations" notion would leak
cash-report-specific semantics into a helper shared by aged-AR/AP, upcoming-payments,
`CashPositionController` and `PaymentInstrumentController`, silently moving four other surfaces —
and it would collide with residual `(a)`, whose fix changes `isUnrestricted()` for the whole family.
**Verdict: docblock note + the line already in residual `(d)`. No code change, no new ticket.**

## MTP-MLC-08 split — accepted

Both round-1 objections are answered. `:560-563` asserts Σ of the scoped `meta.total` > 0, so three
empty payloads can no longer satisfy the case (my MAJOR blackout hole). `:569-573` asserts
Σ counts ≤ unscoped count and `:604-617` Σ per-currency-per-leg totals ≤ All, both off `meta`, which
`generate()` computes over the whole filtered set (`CashMovementsReportService.php:118-150`) — so the
falsifying half is genuinely page-independent. Per-scope distinctness (`:681-687`) replaces the weak
`every(...).toBe(false)`. The row-level half is gated on a real `last_page === 1` window and
**annotated, not skipped**, when unavailable. Their live falsifiability run (Σ 1890 > 630 against a
dev stack lacking the fix) is exactly the right evidence.

**Spec-diff containment confirmed:** two adjacent hunks, `@@ -496,24 +496,80 @@` and
`@@ -533,72 +589,103 @@`, both entirely inside MTP-MLC-08 (test starts `:494`, file ends `:712`).
MLC-01..07 (`:83,148,203,267,335,379,459`) and the file header are untouched.

## Ticket fidelity — confirmed

`docs/superpowers/tickets/2026-08-06-l3-cash-scope-residuals.md` carries probe F verbatim (`:28-31`)
and probe G verbatim (`:53-57`), and both `LocationScopeBoundary` fix shapes (`:40-43`: compare
against all company locations à la `allCompanyLocationIds():72-80`, OR keep the explicit list and add
`orWhereNull` like `CashPositionController.php:89-93`). Residuals (c) dead column, (d) unattributed
bucket, (e)/(f) web items are all present and accurately cited.

## New, non-blocking notes from this round

- **NOTE-1 [MINOR, wording].** `33842c147`'s message and the docblock say the unrestricted read's
  "SQL is unchanged". It is not — it gains `and not exists (select 1 where 1 = 0)`. Say
  "semantically unchanged (a constant-false no-op the planner folds)". Proven safe on PG 16.10 above.
- **NOTE-2 [IMPORTANT — pre-launch DATA task, not a code defect].** On real data the guard makes the
  journal leg contribute *nothing* under any branch scope until registers are located. In the local
  tenant DB `tenant019fbe86-…`, of the cash-type repositories **126 share ONE `gl_account_id` and ALL
  126 have `location_id IS NULL`**:
  ```
  gl_account_id                        | owners | distinct locations | null locations
  24d2d5fb-8bfc-4734-93e7-e945c6d16072 |    126 |                  0 |            126
  ```
  Correct and fail-closed, but tenant #1's four branch cash views will show **payment-backed cash
  only**, and Σ(branches) will fall materially short of the company view. Add a pre-launch task —
  assign `payment_repositories.location_id` for each of the 4 branches' registers — to residual (d)
  or the launch runbook, so the shortfall is a known state rather than a support ticket.
- **NOTE-3 [MINOR].** `RepositoryType::Virtual` is outside `CASH_REPOSITORY_TYPES:54-58`, so a virtual
  repository at another branch pointed at a real cash account neither grants nor blocks (RG-4:
  scope A still reports the shared-account 25.00). Symmetric with the scope EXISTS and currently
  unreachable (the backfill maps Virtual→Bank), but worth one line in residual (c).
- **NOTE-4 [trivia].** 8 vitest across the two cash-movements files, not 9.

**Cleared for merge.** None of NOTE-1..4 blocks; NOTE-2 is a deploy-data prerequisite, not a code change.
