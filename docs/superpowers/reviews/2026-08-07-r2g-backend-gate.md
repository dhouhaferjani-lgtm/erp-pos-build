# R2-G backend gate — `fix/r2g-vat-zero-deductible-and-backfill` (2026-08-07)

Scope: backend commits `238c10278` (I-3 writer), `4905cc69b` (backfill remediation + m-7/m-8/m-9),
`3be92dafd` (m-3 pin). Diff base `8d864566d`. FE `3cc698631` gated separately.
Files touched: 2 app + 2 test (650 +/- lines).

**VERDICT: spec ✅ — quality APPROVE-WITH-FIXES**

Everything the ruling and the four minors asked for is implemented and verified against live
PostgreSQL (`phpunit-pgsql.xml`). Three IMPORTANTs: one one-line code asymmetry, one
operator-safety decision on a destructive command, one operator-facing docs gap.

---

## Verification performed (all on live PG unless noted)

| Suite | Result |
|---|---|
| `tests/Feature/Taxation/BackfillTaxDetailsCommandTest.php` (pgsql) | **OK 24/24, 146 assertions** |
| `tests/Feature/Accounting/ExpenseVatPostingTest.php` (pgsql) | 11 tests, **3 errors** — all pre-existing, see §Probe 7 |
| `tests/Feature/Taxation/VatDeclarationCorrectnessTest.php` + `VatDataRepositoryTest.php` (pgsql) | green |
| `tests/Feature/Expense/LinkedCostExpenseTest.php` (pgsql) | 2 errors — **PG-only pre-existing fixture bug**, green on sqlite. `stock_movements.reference_id = 'sale-after-receipt'` is not a UUID (`SQLSTATE[22P02]`). Unrelated to this lane; ticket it. |
| PHPStan L8, `ExpenseService.php` + `BackfillTaxDetailsCommand.php` | **[OK] No errors** |
| Pint `--test`, all 4 touched files | `{"result":"pass"}` |

Nine throwaway probe tests were written, executed, and deleted; the worktree is byte-clean
(`git status --porcelain` empty except this record).

### 1. WRITER — `ExpenseService::writeDeductibleVatSnapshot()`
- 0% → **no row**: pinned at `ExpenseVatPostingTest.php:221-224`; green.
- Delete-half on re-post at 0% → pinned at `:238-268`; green.
- **Probe A** `vat_deductible_percent = null` → still 100%, row written base `100.000` / amount
  `19.000`. **No regression on the common case.** (`ExpenseService.php:519` `?? '100.00'`.)
- **Probe B** `'0'` (bare) → Eloquent `decimal:2` cast (`ExpenseMetadata.php:90`) normalises to
  `'0.00'`; `bccomp(…,'0',2)===0` → no row. Correct.
- **Probe C** `'0.01'` → row written, base `100.000`. Boundary just above zero is safe.
- Negative / garbage / 3-decimal percents are **unreachable**: `ExpenseRequest.php:54`
  `['nullable','numeric','min:0','max:100','regex:/^\d+(\.\d{1,2})?$/']`
  (recurrence twin at `ExpenseRecurrenceRequest.php:71`). No `ValueError` vector into `bccomp`.
- **Probe D** an unrelated `sequence_order = 7` row survives the 0% delete-half (1 row left,
  seq 7). Slot-scoping holds.
- 80% / 100% paths byte-identical: `:96-133` and `:135-187` untouched and green.
- Both call sites (`:349` Generic, `:386` LinkedCost) are inside the `post()` transaction at
  `:315` — the delete is rollback-safe.

### 2. BACKFILL REMEDIATION (release/data axis)
- **Dry-run deletes nothing** — probe compared `COUNT(*)` *and* a full
  `id|tax_base|tax_amount` fingerprint of every row before/after; identical.
- **`--apply` deletes exactly the right row** — probe: a 0%-deductible expense with an extra
  `sequence_order = 2` row → seq-1 gone, seq-2 survives, global count −1. The delete acts on
  the `$detail` model fetched from a query filtered `sequence_order = 1 AND is_stamp_duty = false`
  (`:361-365`), not a blind `where()->delete()`.
- **Idempotent** — pinned at `BackfillTaxDetailsCommandTest.php:...` (`Deleted 1` then
  `Deleted 0`); mechanism verified: the leg's scan query (`:326-329`) requires a matching
  seq-1 non-stamp row, so the document drops out of the scan entirely on run 2.
- **Duplicate-slot still I-1-skips** — `:367-378` runs *before* the percent is read; pinned test
  asserts `SKIPPED …`, `2 rows at sequence_order=1`, **and** `Deleted 0`, with both rows intact.
- **Both legacy shapes** explicitly seeded and covered: V5 `0.000/0.000` and interim
  `100.000/0.000`. The branch keys on `vat_deductible_percent` only (`:418`), never on
  `tax_base` — confirmed by reading, and by the interim-shape test passing unchanged.
- **Counts accurate** — `count($zeroDeductibleRemediated)` plus a per-document `number (id)`
  audit list (`:488-495`).
- Declaration read path is `EloquentVatDataRepository::aggregateByRateAndDirection()`
  (`:30-56`) — it reads `document_tax_details` joined to `documents` with
  `whereIn('d.type', ['invoice','credit_note','expense'])`. Deleting the row therefore
  removes the expense from the INPUT side **entirely**, which is exactly the ruling. No second
  read path derives the purchases annex from `expense_metadata`.
- Main leg is `Invoice`/`CreditNote` only (`:176`) — it cannot re-create a deleted expense row.
- `DocumentTaxDetail` has **no `SoftDeletes`** (`:29-31`) — the delete is a hard delete. See
  IMPORTANT-3.

### 3. m-7 (FILED periods)
- Lookup now `whereIn('status', [Closed, Filed])` (`:560`).
- Own section at `:520-532`, `FILED-PERIOD IMPACT`, remedy = "amended/corrective filing —
  ESCALATE TO THE ACCOUNTANT". It never *suggests* reopen; the only occurrence of the word is
  the prohibition "do NOT attempt reopen+re-close".
- `VatPeriodManagementService::reopenPeriod()` really does refuse filed:
  `:134-137` `if (! $period->isClosed()) throw new \DomainException('Only closed periods can be reopened')`.
- **Probe:** a Closed and a Filed period both overlapping one document → **both sections print**,
  each period named in the right bucket. (The shipped test only proves the negative direction.)

### 4. m-8 (multi-period)
`recordPeriodImpact()` `:558-571` uses `->get()` and merges by `$period->id` into two
by-reference buckets. No `->first()` remains on that lookup — the only `->first()` left in the
leg is `$details->first()` on an already-materialised Collection (`:380`). Pinned by the
monthly+quarterly test; green.

### 5. m-9 (dry-run coverage)
Not vacuous. Both new dry-run tests assert `[DRY-RUN]`, the specific report strings
(`reopen`/`re-close`, `SKIPPED …`, `2 rows at sequence_order=1`) **and** that the underlying
rows are byte-unchanged. My independent fingerprint probe agrees.

### 6. m-3 (scale-2 pin)
No false-rewrite vector on any reachable path.
- Backfill: `$subtotal` and `$storedBase` are **both** put through
  `CurrencyScale::bcformatStrict(…, $scale)` (`:452`, `:454`) before `bccomp(…, $scale)`
  (`:456`), so stored `'100.000'` vs EUR-scale `'100.00'` compares equal. Pinned end-to-end by
  the EUR test (rewrite once → `Rewrote 1`; second run → `Rewrote 0`).
- Writer: the truncate-vs-round docblock claim ("no sub-scale precision to lose") is **true**,
  but only because `subtotal` is derived as `bcsub($total, $vatAmount, $scale)` at
  `ExpenseService.php:120` / `:221` on every path that can reach the writer. See minor-4.
- No float, no `parseFloat`, no `number_format` anywhere in the diff; both `getScale()` calls
  pass an explicit currency (`ExpenseService.php:514`, `BackfillTaxDetailsCommand.php:450`) —
  console-context safe per rule 19.

### 8. `// precision-ok:` suppression
`ExpenseService.php:533`. Marker and format match the convention declared in
`app/PHPStan/Rules/ForbidHardcodedBcmathScale.php:33` and `:50`, and match the ~20 existing
sites (`PartnerBalanceService.php:63`, `BatchStockService.php:71`, …). The justification —
"`vat_deductible_percent` is decimal(5,2), a percentage — not currency-scaled" — is correct and
matches rule 19's explicit "percent is NOT currency-scaled" carve-out. **Justified.**

---

## Findings

### IMPORTANT-1 — the "unconditional" delete is not unconditional: a re-post that removes VAT leaves a phantom deductible-VAT row
`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:513-523`

```php
if ($vatAmount === null || bccomp($vatAmount, '0', $scale) !== 1) {
    return;                      // <-- :513-515, returns BEFORE the delete
}
...
DocumentTaxDetail::where('document_id', $expense->id)
    ->where('sequence_order', 1)
    ->delete();                  // <-- :521-523, "unconditionally FIRST"
```

**Failure scenario (probe-verified).** Post an 80%-deductible expense (row written:
base `100.000`, amount `15.200`). Reopen it to Draft and clear its VAT, then re-post. The
early return at `:513` fires, the delete never runs, and the stale row survives:
`seq=1 base=100.000 amt=15.200`. The expense now claims **15.200 of input VAT it no longer
has** in the declaration — strictly worse than the interim zero-VAT shape the ruling just
abolished.

**Why it matters.** The docblock at `:469-473` asserts "The delete-then-create block below
still runs its DELETE half … even when the create half is skipped, so a re-post of a
previously-snapshotted expense … cleans up its own stale row". That is true for the *percent*
skip and false for the *vat-amount* skip, which is the sibling case in the same method. The
lane's own pinning test uses exactly this correction workflow, so the reachability argument
that justifies one test justifies the other.

**Fix.** Move the delete above the `$vatAmount` guard (one line). It is safe: the delete is
slot-scoped to `sequence_order = 1`, unrelated details live at other sequence orders
(pinned by `test_unrelated_percentage_detail_does_not_absorb_the_expense_tva_snapshot`), and
a vatless expense simply has nothing to delete
(`test_vatless_expense_keeps_the_exact_legacy_two_line_shape_and_has_no_tax_detail` stays green).

*Reachability note (fair to the author):* there is no public `unpost()`/reopen on
`ExpenseService` today, so this — like the shipped 0% re-post test — is a latent/defensive
case, not a live production bug.

### IMPORTANT-2 — `--apply` irreversibly deletes rows inside an ALREADY-FILED VAT period, then warns about it
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:418-431` (delete) vs `:520-532` (warning)

**Probe-verified:** seed a 0%-deductible expense dated inside a `FILED` period, run
`--apply` → the row is deleted. The "these periods are ALREADY FILED … **ESCALATE TO THE
ACCOUNTANT before taking any action**" message is emitted at the end of the *same run*, after
the mutation it is warning about. There is no confirmation prompt, no opt-in flag, no
non-zero exit.

**Why it matters.** m-7 was raised precisely because "FILED is the highest-consequence case".
The lane converted the leg from *rewrite* to *irreversible delete* in the same round, so the
blast radius of the un-gated FILED path grew while the gate stayed at "print a message".

**Mitigations (real, and why this is IMPORTANT and not CRITICAL):** the filed declaration's own
figures live in the `vat_period_breakdowns` snapshot taken at close, which this leg never
touches — so the filed numbers themselves are not destroyed, only a later re-derivation would
diverge. And a dry-run-first operator does see the FILED section before applying.

**Fix (pick one).** Either (a) emit the FILED-PERIOD IMPACT section *before* any mutation,
or (b) make `--apply` refuse (non-zero exit) when any affected document falls in a FILED
period unless an explicit `--include-filed` is passed. (b) is the safer contract for a
fiscal-source-of-truth deletion.

### IMPORTANT-3 — the command's own `--help` text and the owner runbook never say it now DELETES rows
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:147` and
`docs/handoff/OWNER-INDEX-2026-08-03.md:50-53`

`$description` still reads "… plus a separate expense leg that **rewrites the declared VAT
base** to the full facial subtotal per the Q2 expert-comptable ruling." That is what
`artisan list` and `artisan vat:backfill-tax-details --help` show an operator. Nothing in it
mentions deletion of `document_tax_details` rows.

The owner runbook line (`OWNER-INDEX-2026-08-03.md:50-53`) tells the operator to "resolve
EVERY reported *skipped* document" — written when 0%-deductible rows were a *skip* line. It
now needs the deletion instruction.

**Why it matters (release/data axis).** `DocumentTaxDetail` is a hard delete —
no `SoftDeletes` (`DocumentTaxDetail.php:29-31`), no audit event, no `deleted_at`. The
per-document `number (id)` list printed at `:493-495` is the **only** record that a given
expense's declaration row ever existed. If the operator does not tee the output, the
remediation is unauditable.

**Fix.** Update `$description`; add a runbook line: run dry-run first, capture the full output
(`| tee`), keep the "0%-deductible … Deleted N" list with the filing evidence.

### minor-1 — writer's delete and backfill's slot query disagree on what "the writer's own slot" means
`ExpenseService.php:521-523` filters only `sequence_order = 1`;
`BackfillTaxDetailsCommand.php:361-365` filters `sequence_order = 1 AND is_stamp_duty = false`.
Inert today (grep: nothing writes `is_stamp_duty => true` for an expense —
`snapshotTaxDetails()` is invoice/credit-note only), but a future TN timbre-on-purchase row
landing in slot 1 would be silently destroyed by the writer while the backfill would leave it
alone. Add `->where('is_stamp_duty', false)` to the writer's delete for symmetry.

### minor-2 — no test pins the closed+filed coexistence
`BackfillTaxDetailsCommandTest.php` proves a FILED period alone does not print
`CLOSED-PERIOD IMPACT`, which does not prove the two sections coexist when both periods
exist. Verified correct by probe; a test would lock the behaviour.

### minor-3 — `recordPeriodImpact()` is an N+1 inside the `cursor()` loop
`BackfillTaxDetailsCommand.php:428` and `:477` each fire a fresh `VatPeriod` query per touched
document. Acceptable for an operator-run command; a per-company memoised period list is
trivial if the leg ever scans a large tenant.

### minor-4 — the m-3 truncate-vs-round docblock claim is correct but narrower than stated
`ExpenseService.php:546-551` says truncate and half-up "agree trivially" because the base is
copied verbatim from an already-scaled stored subtotal. True — but only because every path
that reaches the writer computes `subtotal = bcsub($total, $vatAmount, $scale)` (`:120`,
`:221`). The `$vatAmount === null` branch at `:120` stores the raw un-normalised `$total`,
which for a scale-2 currency *could* carry a third decimal — that branch is unreachable here
only because the writer early-returns on null VAT. Worth half a sentence so a future edit to
the early return does not silently invalidate the claim.

---

## Probe 7 — the "3 pre-existing failures" claim: RULING

**Reproduced.** `git checkout 8d864566d -- <the 4 touched files>` then re-run:
identical 3 errors, identical exception, identical test names. Same 3 on live PG. And
`git diff origin/dev 8d864566d -- tests/Feature/Accounting/ExpenseVatPostingTest.php
app/Modules/Treasury/Application/Services/TreasuryMovementService.php` is **empty** — so
these 3 are red on `origin/dev` right now, not just on this branch. Claim accepted.

**But it is NOT the trap the brief guessed.** Repobal §4 describes
`PaymentRepository::create(['balance' => …])` silently dropping a non-fillable `balance`.
`ExpenseVatPostingTest::cashRepository()` (`:449-456`) **never passes `balance` at all** — the
factory just makes an unfunded `CashRegister`. The W-5b outflow guard
(`TreasuryMovementService.php:528`, commit `eaad416df`) then refuses the 119.000 outflow.
⇒ **repobal §4's prescribed sweep `grep "balance' =>" tests/ database/` would MISS this file.**
The sweep must be widened to "every test that drives an outflow through the movement port",
not just those that tried to set `balance`.

**And it is not a cheap in-lane one-liner.** Funding the fixture through the movement port
(the established repair) changes four pinned expectations in a *treasury-reconcile golden
test*: `ExpenseVatPostingTest.php:381` `assertSame('-119.000', $repository->balance)`,
`:382` `assertSame(1, $repository->next_movement_ordinal)`, `:389`
`assertSame('-119.000', $movement->balance_after)`, `:390` `assertSame(1, $movement->ordinal)`
— the funding movement becomes ordinal 1 and every expected value shifts. Re-pinning a
treasury-reconcile golden inside a *taxation* lane's fix round would put that change through
the wrong gate.

**RULING: strictly out of scope — do NOT fix in this lane.**
1. Route to the repobal lane (`docs/superpowers/tickets/2026-08-07-repobal-lane-followups.md` §4);
   amend §4 to widen the sweep predicate as above and to name
   `tests/Feature/Accounting/ExpenseVatPostingTest.php` explicitly.
2. Treat "`origin/dev` carries 3 red treasury/expense tests" as a **dev-branch red owed by the
   treasury lane**, not an accepted baseline.
3. This lane must not merge with a bare "3 pre-existing failures" note — this record names
   them, their cause, and their owner.

Bonus red found while gating: `tests/Feature/Expense/LinkedCostExpenseTest.php` has **2
PG-only** failures (green on sqlite) from a non-UUID `stock_movements.reference_id`
(`'sale-after-receipt'`) — pre-existing, unrelated, own ticket.

---

## What to fix before merge

Move the delete above the `$vatAmount` early return (IMPORTANT-1), decide the FILED-period
gate (IMPORTANT-2), and update the command `$description` + owner runbook to say the leg now
deletes and that its output is the only audit record (IMPORTANT-3). The four minors and the
probe-7 routing can ship as tickets.

---

# Fix-round re-verify — `c24a1267e` (2026-08-07)

Narrow re-verify of the three IMPORTANTs and the minors only. All checks on live PostgreSQL
(`phpunit-pgsql.xml`). Eleven throwaway probes written, run, deleted; worktree byte-clean.

| Check | Result |
|---|---|
| `BackfillTaxDetailsCommandTest.php` (pgsql) | **OK 29/29, 181 assertions** (was 24) |
| `ExpenseVatPostingTest.php` (pgsql) | 12 tests, new test green, **same 3 pre-existing errors** (probe-7 lane, unchanged) |
| PHPStan L8 on both touched app files | **[OK] No errors** |
| Pint `--test` on all 4 touched files | `{"result":"pass"}` |

## IMP-1 — ✅ CLOSED
`ExpenseService.php:532-535` (delete) / `:519-531` (rationale comment) — the delete now sits at the very top of the method, above the
`$vatAmount`/`$scale` lines, with `->where('is_stamp_duty', false)` added (minor-1 folded in).

Probe results (5/5 green):
- **Original gate probe re-run**: post 80%-deductible → reopen → clear VAT → re-post ⇒ **0 rows**.
  The phantom `seq=1 base=100.000 amt=15.200` is gone.
- Percent-skip path (percent → 0%) still deletes and writes nothing.
- **No over-deletion**: normal 80% path still writes `base=100.000 / amount=15.200 / seq=1`;
  `null` percent still writes `100.000 / 19.000`.
- **minor-1 verified positively**: a `is_stamp_duty = true` row planted in slot 1 now **survives**
  the writer's delete, and the VAT row is written alongside it (2 rows). Writer and backfill
  (`:396-400`) now agree on the slot predicate.
- Docblock `:470-486` rewritten and now accurate ("FIRST and TRULY UNCONDITIONALLY … ABOVE every
  other guard in this method including the vat-amount check").

## IMP-2 — ✅ CLOSED
`--include-filed` added to the signature (`:169`); both mutation paths call
`recordPeriodImpact()` **before** the write decision (0% branch `:454-470`, rewrite branch
`:531-546`) and `continue` with an explicit skip reason when filed and the flag is absent.

Probe results (5/5 green):
- **Flag OFF, `--apply`, filed period**: `FILED-PERIOD IMPACT` prints, `Rewrote 0`, row untouched,
  and **`THIS RUN PASSED` is absent**. Flag ON: `THIS RUN PASSED --include-filed` appears and
  `Rewrote 1`. The annotation is correctly conditional (`:610-613`).
- **Dry-run also refuses filed**: `[DRY-RUN]` + `FILED-PERIOD IMPACT` + `deletion refused` +
  `Would delete 0`, no annotation. Gate applies in both modes as specified.
- **`--include-filed` does NOT bypass I-1**: a duplicate-slot 0%-deductible doc inside a filed
  period still reports `2 rows at sequence_order=1`, `Deleted 0`, both rows intact. Structurally
  guaranteed — the I-1 check at `:396-409` runs before the percent is even read.
- **`--include-filed` does NOT delete non-0% rows**: an 80%-deductible doc in a filed period is
  **rewritten** (`Rewrote 1`, `Deleted 0`, `tax_base` → `100.000`, `tax_amount` untouched at
  `15.200`). The delete lives only in the 0% branch.
- Ordering detail confirmed by reading: the rewrite path's `recordPeriodImpact()` sits **below**
  the `bccomp($storedBase, $subtotal)` no-op `continue` (`:516-521`), so a document that needs
  nothing does not spuriously raise a FILED/CLOSED section. Correct.

## IMP-3 — ⚠️ PARTIALLY CLOSED — one residual (see B-1)
Closed: `$description` (`:171`) now says "**AND DELETES the row entirely for 0%-deductible
expenses**" and names the `--include-filed` default; the snapshot is captured into
`$zeroDeductibleRemediated` with `tax_base` / `tax_amount` / `tax_rate` **before** `$detail->delete()`
(`:474-483`, before the `if ($apply)` at `:484`); it prints in both dry-run and `--apply` (`:577-584`); the owner runbook
(`docs/handoff/OWNER-INDEX-2026-08-03.md:55-71`) now carries the deletion notice, the
`| tee` capture instruction, and the "no `--include-filed` without accountant sign-off" line.

### B-1 [IMPORTANT] — the snapshot is captured before the delete but PRINTED only after the whole scan, so a crashed run loses the audit record it exists to provide
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:485-487` (per-document commit) vs
`:577-584` (print, after the `cursor()` loop closes at `:556`)

**Probe 1 — output ordering (FAILED as predicted).** In a completed `--apply` run the snapshot
line `tax_base=0.000 tax_amount=0.000` appears **after** `Scanned 1 expense document(s)`, i.e.
after every delete in the run has already committed.

**Probe 2 — simulated crash mid-scan (confirms the loss).** Two 0%-deductible expenses; a
`DB::listen` hook throws on the second `delete from "document_tax_details"`. Result: exactly
**one deletion committed** (each delete is its own `DB::transaction` at `:485-487`, committed
immediately), and the captured output contains **no snapshot line at all** — the committed
deletion has no surviving record anywhere.

**Why it matters.** This is precisely the property IMP-3 exists to guarantee: the printed output
is the ONLY record a hard-deleted row existed (no `SoftDeletes`, no audit event). Capture-before-
delete protects a *completed* run; it does nothing for an operator `Ctrl-C`, a DB blip, or a PHP
fatal partway through a large tenant — the exact conditions under which an operator most needs
to know what was already destroyed.

**Fix (≈5 lines).** Emit the snapshot line at the moment of capture, inside the loop, immediately
before the `if ($apply) { … delete … }` block. Keep the end-of-run list as the summary. That makes
the record stdout-flushed ahead of the mutation it describes, so any abort still leaves it.

### minor-5 [minor] — FILED refusals share the `$skipped` bucket with data-quality skips
`:539-543` and `:456-462` push FILED refusals into the same `$skipped` list whose warning line
(~`:589-596`) reads "needs manual review, NOT backfilled", and into the same `Skipped N` count as
duplicate-slot / missing-metadata / null-subtotal cases. The reason strings are explicit so no
information is lost, but an operator counting "Skipped" can no longer tell a *data problem* from
a *deliberate policy refusal*. Consider a separate counter/line.

## Minors from round 1 — status
- **minor-1** (writer/backfill slot predicate asymmetry) — ✅ closed, probe-verified positively
  (stamp-duty row in slot 1 survives).
- **minor-2** (closed+filed coexistence untested) — ✅ closed,
  `test_expense_leg_reports_both_closed_and_filed_sections_when_both_periods_overlap` green.
- **minor-3** (N+1 in `recordPeriodImpact`) — ✅ closed. Cache key is
  `$document->company_id.'|'.$documentDate` (`:662`) — full date, not month, so no
  same-month/different-date bleed; company-qualified, so no cross-company bleed; and
  `$periodLookupCache` is a local of `handleExpenseLeg()`, so nothing survives a run (tenancy is
  db-per-tenant and the command is per-tenant anyway). **Cross-contamination probe:** two
  companies in one tenant, same `document_date`, company A inside a FILED period and company B
  with no periods at all, run with **no `--company` scope** ⇒ A refused, **B deleted** (`Deleted 1`).
  B did not inherit A's cache entry. Correct.
- **minor-4** (m-3 docblock claim narrower than stated) — ✅ closed, caveat added at
  `ExpenseService.php:568-572` naming the `bcsub($total, $vatAmount, $scale)` dependency.

## Probe 7 (the 3 red treasury/expense tests) — unchanged
Still 3 errors, still `InsufficientRepositoryBalanceException`, still identical on `origin/dev`.
Ruling from round 1 stands: out of scope here, route to the repobal lane and widen its §4 sweep
predicate (the `grep "balance' =>"` sweep does not match `ExpenseVatPostingTest::cashRepository()`,
which never sets `balance` at all).

## Fix-round verdict

**CLEAR TO MERGE once B-1 lands** (one ≈5-line move: print the snapshot at capture time, before
the delete). IMP-1 and IMP-2 are fully closed and probe-verified; IMP-3's docs/description/capture
halves are closed; all four round-1 minors are closed. No other blockers. minor-5 and the probe-7
routing are ticket material.
