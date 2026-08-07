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
