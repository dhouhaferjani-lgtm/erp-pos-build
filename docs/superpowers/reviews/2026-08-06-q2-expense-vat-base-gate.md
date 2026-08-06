# Gate record — Q2 expense VAT declared-base fix (`fix/expense-vat-declared-base`)

Reviewer: treasury-reviewer (adversarial, code-grounded). Date: 2026-08-06.
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.fix-q2-vat-base`
Diff reviewed: `git diff 695f6814d..HEAD` — 2 commits (`ece59e78a`, `d139a3866`), 4 files, +334/-47.
Spec: `docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md` §Q2 (items 1–5).
Prior gate: `docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md` §V5/V6.

## VERDICT: **APPROVE-WITH-FIXES**

The core change is correct, minimal, and spec-faithful. No CRITICAL defect. Three IMPORTANT
findings — one real remediation-correctness hole in the new backfill leg (I-1), one incomplete
remediation surface the command neither fixes nor warns about (I-2), and one uncalled-out
behaviour change on 0%-deductible expenses (I-3). None of the three blocks the *writer* fix;
I-1 and I-2 must be fixed or explicitly ruled on **before any operator runs `--apply` on a live
tenant**.

---

## What I verified (not trusted)

### Writer — claim 1 CONFIRMED
- `ExpenseService.php:499-500` — `$subtotal = (string) ($expense->subtotal ?? '0'); $taxBase =
  CurrencyScale::bcformatStrict($subtotal, $scale);`. Full facial subtotal, bcmath only, no float,
  no `number_format`, no `(float)` cast anywhere in the hunk.
- `ExpenseService.php:481` — scale resolved with an **explicit currency**
  (`$this->scaleResolver->getScale((string) $expense->currency)`), not a bare no-arg `getScale()`.
  `documents.currency` is `string(3) NOT NULL DEFAULT 'EUR'`
  (`2025_11_30_080000_create_documents_table.php:24`), so the resolver can never take the
  `UnboundCompanyContextException` branch (`CurrencyScaleResolver.php:37-40`). Safe in console/queue.
- `ExpenseService.php:515` — `tax_amount` still `ExpenseVatSplit::deductible(...)` (`:489`),
  untouched. Decorrelation is exactly what the ruling asks for.
- **Both branches covered** (claim 1's harder half): `writeDeductibleVatSnapshot()` is called at
  `ExpenseService.php:349` (Generic) and `:386` (LinkedCost). The fix lives inside the shared
  private method, so it cannot diverge between branches.
- **Semantics of the source field are right**: `documents.subtotal` for an expense is written as
  `bcsub($total, $vatAmount, $scale)` on create (`ExpenseService.php:119,134`) and update
  (`:221,231`) — i.e. the FULL facial HT, since `documents.tax_amount` holds the FULL facial VAT,
  not the deductible share. So `tax_base` really is the supplier's declared sale base. This is the
  load-bearing check for the DGI cross-match rationale, and it holds.
- **Null subtotal**: unreachable through the service (always computed at `:119`/`:221`); the
  `?? '0'` fallback is defensive only. See m-3 for the asymmetry with the backfill leg.
- **V5 regressions NOT reintroduced**: the delete-then-create keyed on `sequence_order = 1`
  (`ExpenseService.php:502-517`) is byte-identical to pre-diff (the diff hunk ends above it);
  `firstOrCreate` is gone; the LinkedCost call site survives. V5 problems 1–4 all remain fixed.

### Read side — claim 3 CONFIRMED, and I swept the PRODUCTION paths, not just tests
No production consumer recomputes VAT from `base × rate` on the INPUT side. Every one sums the
two columns independently:
- `EloquentVatDataRepository.php:51-52` — `SUM(tax_base)` and `SUM(tax_amount)` are separate
  aggregates in the same select; nothing derives one from the other.
- `TunisiaVatStrategy.php:70-79` — input side emits only `total_deductible_vat` = Σ `vatAmount`;
  it never emits an input base at all.
- `FranceVatStrategy.php:88-97` — CA3 line 19 = Σ `vatAmount` (recoverable only); no input base.
- `UkVatStrategy.php:75-82` (box 4 = Σ vatAmount) and `:101-107` (box 7 = Σ `baseAmount`) — two
  independent sums.
- `FecExporter.php:82-98` — input entries debit `vatAmount` only; `baseAmount` is never written,
  so no unbalanced FEC entry can arise from the decorrelation.
- `MtdJsonExporter.php:144-152`, `CsvVatExporter.php:46,80`, `TeifXmlExporter.php:87-90`,
  `PdfVatExporter.php:60,122` — pass-through rendering of the stored strings.
- `VatReportGenerationService.php:85-93`, `VatSummaryData.php:57-60`, `VatReportController.php:91-94`
  — independent `bcadd` accumulators.
- `DocumentTaxBreakdownResource.php:49-58` and `DocumentController.php:340-349` read
  `TaxCalculationResult` (live, line-derived), **not** stored `document_tax_details`; expenses have
  no lines and never reach them. No PDF/FE regression on the document surface.
- No fiscal/hash-chain coupling: the 2026-08-03 gate already proved `tax_base` is an unsigned
  derived projection; nothing in this diff touches `total`/`currency`/`posted_at`.

### Tests / gates — claim 4 CONFIRMED by re-running them myself
- `php artisan test tests/Feature/Taxation/BackfillTaxDetailsCommandTest.php
  tests/Feature/Accounting/ExpenseVatPostingTest.php` → **17 passed, 89 assertions**.
- `php artisan test tests/Feature/Expense/LinkedCostExpenseTest.php
  tests/Feature/Taxation/VatDeclarationCorrectnessTest.php
  tests/Feature/Taxation/VatDataRepositoryTest.php` → **12 passed, 101 assertions**.
- `./vendor/bin/phpstan analyse` on both changed app files (level 8, project neon) → **No errors**.
- `./vendor/bin/pint --test` on all four changed files → **pass**.
- Tests are real: `RefreshDatabase`, real models, real Artisan invocation, no `assertTrue(true)`,
  nothing mocked that is under test. The legacy-shape fixture
  (`BackfillTaxDetailsCommandTest.php:373-412`) reconstructs the V5 writer's output arithmetically
  rather than hardcoding it — good.

### Spec fidelity (ticket items 1–5)
| Item | Status |
|---|---|
| 1. `tax_base` = full attested subtotal | DONE (`ExpenseService.php:499-500`) |
| 2. `tax_amount` stays deductible share | DONE (`:489`, `:515`) |
| 3. Sweep consumers for identity assertions | DONE for production; one test message left stale (m-1) |
| 4. Docblock resolves the V5 FLAG + cites ticket | DONE (`ExpenseService.php:434-465`) |
| 5. Backfill decision | DONE — extended `vat:backfill-tax-details` (see I-1/I-2 for the gaps) |

Nothing from the change list was silently dropped.

---

## Findings

### IMPORTANT

**I-1 — `BackfillTaxDetailsCommand.php:288-299` — `->first()` on a slot that legacy data can
legitimately hold TWICE; nondeterministic pick, silent partial remediation, misleading delta.**

The leg selects the row to fix with
`DocumentTaxDetail::query()->where('document_id',…)->where('sequence_order',1)->where('is_stamp_duty',false)->first()`
— no `ORDER BY`, no duplicate detection. But:
- the PRE-V5 writer used `firstOrCreate` keyed on `(document_id, tax_type, tax_name, tax_rate,
  tax_base, tax_amount)` with `sequence_order => 1` in the **create-only** attributes — verified at
  `git show 8a71a72bf:apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:387-402`;
- `document_tax_details` has **no unique index** on `(document_id, sequence_order)` — only
  `index('document_id')` and `index(['document_id','tax_type'])`
  (`2025_12_30_102000_create_document_tax_details_table.php:29-31`), and
  `2026_01_02_100005_enhance_document_tax_details.php:18-20` adds `sequence_order` with a plain
  default, no constraint.

So a pre-V5 expense whose value changed between two posts can carry **two** rows at
`sequence_order=1, is_stamp_duty=false`. Concrete failure: expense subtotal 100.000, 80%
deductible, rows A(`base 100.000`) and B(`base 80.000`). PostgreSQL gives no ordering guarantee
without `ORDER BY`:
- picks A → `bccomp` says already-full → `continue`; B keeps 80.000; the report prints
  "Would rewrite 0 / delta 0" and the operator concludes there is nothing to fix;
- picks B → rewritten to 100.000; the expense now declares base 200.000 while the printed delta
  claims +20.000. The true anomaly (+100.000) is never surfaced.

This is exactly the class of legacy data the command exists for, and the main leg's entire design
philosophy is "assert an invariant per document, skip + report otherwise"
(`BackfillTaxDetailsCommand.php:148-211`). The expense leg has no structural sanity check at all.
Honest caveat on reachability: `post()` is Draft-guarded (`ExpenseService.php:310-312`), so
duplicates require an unpost path — the V5 record calls this "fragile" rather than observed. Low
probability, but the failure is silent and the operator has no way to detect it from the output.

**Fix:** replace `->first()` with `->get()`; if `count() > 1`, push to `$skipped` with reason
"N rows at sequence_order=1 — duplicate legacy snapshot, manual review required" and do not write.

---

**I-2 — Remediation is incomplete for already-CLOSED VAT periods, and the command does not say so.**

`vat_period_breakdowns.base_amount` is a **materialized snapshot** taken at period close:
`VatPeriodManagementService::persistBreakdowns()` (`:196-228`) is called from `closePeriod()`
(`:90-99`), and `vat_periods.declaration_data` is frozen at `:111`. The expense leg touches only
`document_tax_details`. Failure scenario: a tenant closed (not filed) March; the operator runs
`vat:backfill-tax-details --apply`, sees "Rewrote 3, delta +60.000", then exports March's
PDF/CSV/TEIF — which read `vat_period_breakdowns`, not `document_tax_details` — and still gets the
old prorated input base. The class docblock at `:54-58` still asserts this command is the
remediation path.

Reopen is the only fix (`reopenPeriod()` `:207-243`, permitted only when no successor period is
closed or filed — `:213-215`). **Fix:** after `--apply`, print an explicit instruction to reopen +
re-close every CLOSED period overlapping a rewritten expense's `document_date`, and record it in
the pre-filing runbook item (`2026-08-03-vat-regate-carryovers.md` §N2). Ideally the leg also
reports the affected `document_date` range so the operator knows which periods to reopen.

---

**I-3 — 0%-deductible expenses change declaration behaviour, outside the ticket's stated scope and
with zero test coverage.**

`writeDeductibleVatSnapshot()` early-returns only when the document's own `tax_amount` is null or
zero (`ExpenseService.php:483-485`). A **fully non-deductible** expense (`vat_deductible_percent =
0.00`) therefore still writes a row — and now writes `tax_base = 100.000` with `tax_amount =
0.000`, where V5 wrote `0.000 / 0.000`. The backfill leg rewrites those rows too (its predicate is
`bccomp($pct,'100',2) >= 0 → skip`, `:324-328`, so 0% is in scope).

Downstream: the declared INPUT base at rate 19% now includes 100%-non-deductible purchases —
`UkVatStrategy.php:101-107` box 7 (`total_purchases_ex_vat`) grows; TN's DGI achats base grows;
FR CA3 is unaffected (it emits no input base). This is *arguably* what the expert wants (facial
value of the transaction), but the ticket only ever discusses the 80% case, and nothing asserts it:
`ExpenseVatPostingTest::test_zero_percent_deductible_vat_omits_the_input_vat_line` (`:204-218`)
asserts `tax_amount` only, never `tax_base`.

**Fix:** get an explicit owner/expert nod on the 0%-deductible case, then add
`assertSame('100.000', $detail->tax_base)` to that test (or `'119.000'`/whatever the fixture's
subtotal is) so the behaviour is pinned either way.

---

### minor

**m-1 — `tests/Feature/Expense/LinkedCostExpenseTest.php:348-355`** still asserts
`base × rate == tax_amount` with the message *"identity must hold on the LinkedCost branch too"*.
It passes only because the fixture is 100% deductible (`:334`). Post-ruling the message states a
contract that no longer exists; the next dev who changes that fixture's percent will be told the
writer is broken. Reword to record that it holds for a 100%-deductible fixture and is deliberately
decorrelated for partial deductibility (cite the Q2 ticket).

**m-2 — `BackfillTaxDetailsCommand.php:341`** — `$scanned++` sits after every skip/`continue`
branch, so 100%-deductible rows (`:327`), metadata-less rows (`:309`) and null-percent rows
(`:320`) are counted nowhere. "Scanned N partially-deductible expense document(s)" gives the
operator no idea whether the leg examined 3 or 3,000 expenses — bad for a manual, owner-executed
remediation whose output is the only evidence. Add a separate `examined` counter.

**m-3 — scale handling at `:343-347` and `ExpenseService.php:500`.** Both compare and write at the
**currency** scale, while `document_tax_details.tax_base` and `documents.subtotal` are both
`decimal(15,3)` (`2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php:23`). For a
scale-2 currency both sides get truncated to 2dp before `bccomp`, so a row differing from the
subtotal only in the third decimal is treated as already-correct and skipped, and a rewritten row
loses its third decimal. It is also a quiet convention change vs V5: `bcformatStrict` **truncates**,
`ExpenseVatSplit::deductible()` used `CurrencyScale::bcround()` (half-up). Consistent with rule 19
("round once at the boundary; bcformat truncates"), so acceptable — but untested:
`ExpenseVatPostingTest::test_eur_half_cent_split_rounds_at_two_decimal_currency_scale` (`:254-275`)
asserts only `tax_amount`, never `tax_base`.

**m-4 — the leg's advertised "self-guarding" contract is entirely unasserted.** The three new tests
cover dry-run, apply, and the 100% no-op. None covers double-`--apply` idempotence (correct by
construction via `:349`, but unpinned), nor any of the three skip-and-report branches
(`:302-310` null metadata, `:312-321` null percent, `:330-339` null subtotal) that the class
docblock at `:91-93` sells as the leg's safety property.

**m-5 — `:113`** guards `Schema::hasTable('documents')` and `'document_tax_details'` but not
`'expense_metadata'`, which `:275` eager-loads. On a tenant DB missing that table the leg throws
rather than skipping, contradicting `:91-93`.

**m-6 (pre-existing, OUT of diff scope — flagged only because this lane changes the magnitude of
the number it renders):** `apps/web/src/features/vat-reporting/components/VatBreakdownTable.tsx:22`
does `parseFloat(b.base_amount)` on money — a rule-19 violation on the very declaration surface
whose input bases this lane rewrites. Not introduced here; ticket separately.

---

## Explicitly checked and CLEAN (no finding)

- No float/`parseFloat`/`Number()`/`number_format` on money anywhere in the diff.
- No bare no-arg `getScale()` introduced; both new call sites pass an explicit currency
  (`ExpenseService.php:481`, `BackfillTaxDetailsCommand.php:343`).
- `CurrencyScaleResolverInterface` is **constructor-injected** (`BackfillTaxDetailsCommand.php:106`),
  no `app()` helper. Module boundary respected (`App\Shared\Contracts\*`).
- Dry-run truly writes nothing: the only write is inside `if ($apply)` (`:356-361`); verified live
  by `test_expense_leg_dry_run_reports_the_base_fix_without_writing` (base still `80.000` after the
  run).
- Idempotent on re-apply by construction: `bccomp($storedBase, $subtotal, $scale) === 0 → continue`
  (`:349-354`).
- Tenancy: the leg uses the currently-bound tenant connection and scopes only by `--company`,
  identical to the main leg (`:133-135` vs `:276-278`) — correct for database-per-tenant.
- Soft deletes: `Document` uses `SoftDeletes` (`Document.php:112`), so `Document::query()` excludes
  trashed rows, matching `EloquentVatDataRepository.php:44`'s `whereNull('d.deleted_at')`.
- `$detail->save()` on an update-timestampless model is safe: `DocumentTaxDetail::UPDATED_AT = null`
  (`DocumentTaxDetail.php:35`); `updated_at` was dropped in
  `2026_01_02_100005_enhance_document_tax_details.php:32-34`. Confirmed by the passing apply test.
- Main leg untouched: expenses are still excluded from it (`whereIn('type',[Invoice,CreditNote])`
  at `:131`), and its three invariant guards (`:165-173`) never see an expense row.
- The `accumulate()` duplicate docblock at `:389-396` is **pre-existing** (verified against
  `695f6814d`), not introduced here.

## What to fix before an operator runs `--apply`

Fix I-1 (`->get()` + duplicate skip-and-report) and I-2 (print the reopen/re-close instruction for
already-CLOSED periods); get an owner ruling on I-3.

---

# Fix-round re-verify — commit `4ee9042e6` (2026-08-06)

Scope: NARROW re-verification of the findings named above only. No new full pass.
Diff re-reviewed: `git diff d139a3866..4ee9042e6` — 5 files, +462/-11.

## RE-VERIFY VERDICT: **CLEAR TO MERGE**

All three IMPORTANT findings and all four in-scope minors are genuinely closed in code, each with a
test that fails without the fix. Two NEW minors surfaced from adversarially probing the I-2 fix
itself (m-7, m-8) — both are ticket-and-ship, neither blocks.

### Gates re-run by me (not trusted)
- `php artisan test tests/Feature/Taxation/BackfillTaxDetailsCommandTest.php
  tests/Feature/Accounting/ExpenseVatPostingTest.php tests/Feature/Expense/LinkedCostExpenseTest.php`
  → **29 passed, 174 assertions** (15 of them in the backfill suite, up from 7).
- `./vendor/bin/phpstan analyse` (level 8) on `BackfillTaxDetailsCommand.php`,
  `ExpenseService.php` **and** `BackfillTaxDetailsCommandTest.php` → **No errors**.
- `./vendor/bin/pint --test` on all five touched files → **pass**.
- "8 pre-existing PHPStan errors in `LinkedCostExpenseTest`" claim spot-checked: the 8 errors sit at
  lines 272 / 394×2 / 406×2 / 414 / 499 and are `property.nonObject`, `argument.type`
  (numeric-string) and `return.type` — none is inside the fix-round hunk (351-363, comments plus one
  assertion message) and none is of a kind that hunk could introduce. Claim holds.

### I-1 — CLOSED (`BackfillTaxDetailsCommand.php:336-353`)
`->first()` replaced by `->get()` (`:336-340`); `if ($details->count() > 1)` pushes a named
`$skipped` entry ("N rows at sequence_order=1 — duplicate legacy snapshot, manual review required")
and `continue`s (`:342-353`). `$detail = $details->first()` (`:355`) now only ever runs on a
0-or-1 result. Docblock records the missing unique index (`:96-105`).

Adversarial probes, both answered from the code:
- **Does the skip fire in BOTH dry-run and `--apply`?** YES. The branch is at `:342`, the loop's
  first decision after `$scanned++`; the only `$apply` reference in the whole loop is at `:429`.
  The skip is structurally unreachable-around. (Test covers `--apply` only — see m-9.)
- **Does the cumulative delta exclude skipped docs?** YES. `continue` at `:352` precedes both
  `$touched++` (`:435`) and `$baseDelta = bcadd(...)` (`:436`). A duplicate doc also never reaches
  the closed-period collector (`:444`), which is correct — nothing was rewritten.
- Test `test_expense_leg_skips_and_reports_a_document_with_duplicate_sequence_order_one_rows`
  seeds two real rows in the slot (bases `100.000` and `80.000`), runs `--apply`, and asserts BOTH
  survive unchanged plus the report names the document. It asserts real behaviour, not output alone.

### I-2 — CLOSED (`BackfillTaxDetailsCommand.php:438-452`, `:474-486`)
Per rewritten document, looks up a CLOSED `VatPeriod` for the same company covering
`document_date`, collects it, and after the scan prints a `CLOSED-PERIOD IMPACT` block naming each
period (label, id, start, end) with an explicit reopen-then-re-close instruction and "This command
does NOT do so automatically." Nothing is reopened programmatically — verified by grep: the command
has no reference to `reopenPeriod`/`closePeriod`.

Adversarial probes:
- **Inclusive boundaries?** YES — `period_start <= $documentDate` AND `period_end >= $documentDate`
  (`:447-448`). A document dated exactly on `period_end` (or `period_start`) is matched.
- **Multiple documents, one period → reported once?** YES — `$affectedClosedPeriods[$closedPeriod->id]`
  (`:451`) is an id-keyed map, iterated once at `:477`.
- **Null-safety:** `$document->document_date->toDateString()` (`:443`) is safe —
  `documents.document_date` is `$table->date('document_date')` NOT NULL
  (`2025_11_30_080000_create_documents_table.php:21`) and cast to `date`
  (`Document.php:179`). `$period->period_start/period_end` are cast `date` (`VatPeriod.php:81-82`)
  and `label` is a plain string column (`:58`), so the sprintf at `:479-484` cannot fatal.
- **Dry-run also previews the impact** (periods are collected off `$touched`, which increments
  regardless of `$apply` at `:435`) — desirable; untested (m-9).
- Two residual gaps found here → m-7, m-8 below.

### I-3 — CLOSED as an interim, correctly labelled (`ExpenseService.php:449-464`,
`BackfillTaxDetailsCommand.php:387-397`, `ExpenseVatPostingTest.php:214-226`)
Writer behaviour deliberately UNCHANGED (0%-deductible still declares the full facial base with
`tax_amount = 0.000`); the docblock now states this is CURRENT behaviour with an owner/expert
ruling PENDING, not a resolved decision, and says the assertion may flip. The backfill leg skips
0%-deductible rows via `bccomp($deductiblePercent, '0', 2) === 0` (`:393-397`) with a dedicated
`0%-deductible: awaiting ruling, skipped N document(s).` report line (`:463`), so it will not bake
an unruled semantic into historical rows. `test_expense_leg_skips_and_reports_zero_percent_deductible_rows_pending_owner_ruling`
asserts the legacy row is left at `0.000 / 0.000`; `ExpenseVatPostingTest:226` pins the writer's
`tax_base = '100.000'` at 0% deductible. This is the right shape for an open question.

**Carry-forward (accepted, not a finding):** until the ruling lands, 0%-deductible expenses are
knowingly INCONSISTENT between newly-posted rows (full facial base) and legacy rows (prorated
`0.000` base). That is the deliberate cost of not guessing; it must be resolved in the same motion
as the ruling, and re-running the leg with the 0% skip lifted is the remediation. Confirm it is on
`docs/superpowers/tickets/2026-08-06-q2-gate-minor-followups.md`.

### m-1 — CLOSED (`LinkedCostExpenseTest.php:351-363`)
Message is now "at 100% deductible the full facial base and the deductible VAT amount coincide on
the LinkedCost branch too", with a comment stating the Q2 decorrelation and that the identity holds
HERE ONLY because the fixture is 100% deductible. No longer asserts an abolished contract.

### m-2 — CLOSED (`BackfillTaxDetailsCommand.php:324-327`, `:455-461`)
`$scanned++` is the loop's first statement, ahead of every skip branch; the report line reworded to
"Scanned N expense document(s) carrying an eligible input-VAT row". Pinned by
`test_expense_leg_skips_and_reports_a_document_with_no_expense_metadata`, which asserts
`Scanned 1` on a document that is skipped — exactly the case the old counter dropped.

### m-4 — CLOSED
Five new tests: double-`--apply` idempotence (asserts `Rewrote 1` then `Rewrote 0`, with the row
re-read and both columns re-asserted), plus all three skip branches (no metadata / null
`vat_deductible_percent` / null subtotal) each asserting BOTH the named report line AND that the
stored base is untouched.

### m-5 — CLOSED (`BackfillTaxDetailsCommand.php:296-300`)
`Schema::hasTable('expense_metadata')` guard warns and returns instead of throwing.
`test_expense_leg_skips_when_expense_metadata_table_is_unavailable` genuinely `Schema::drop()`s the
table — a real red-first proof, not a mock. The DDL rolls back with `RefreshDatabase`'s transaction
(PostgreSQL DDL is transactional); empirically confirmed by the 14 sibling tests in the same file
and the two other suites in the same run all passing after it.

---

## NEW minors from this round (ticket, do not block)

**m-7 — `BackfillTaxDetailsCommand.php:446` — a FILED period is silently NOT reported.**
The closed-period lookup filters `status = VatPeriodStatus::Closed` only, but the enum also has
`Filed` (`VatPeriodStatus.php:10`). Failure scenario: a tenant has FILED March; the operator runs
`--apply`; three March expenses are rewritten; the output contains no mention of March at all, and
the filed declaration + its frozen `vat_period_breakdowns` now silently disagree with
`document_tax_details`. Currently vacuous — the ticket states no tenant has filed
(`2026-08-06-expert-comptable-rulings-q2-q3.md:51-52`) — but it is the highest-consequence case and
it produces ZERO output. Note the remedy is NOT the same one-liner: `reopenPeriod()` refuses a filed
period ("Only closed periods can be reopened", `VatPeriodManagementService.php:174-176`), so a
FILED hit needs its own, louder message — "filed-declaration correction, escalate to owner before
proceeding" — not the reopen/re-close instruction.

**m-8 — `BackfillTaxDetailsCommand.php:444-449` — `->first()` on a set that can hold more than one.**
This is the same pattern I-1 just eliminated, reintroduced one scope over: the period lookup takes
`->first()` with no `ORDER BY`. Two CLOSED periods can cover one date for a company (a monthly and
a quarterly period after a `period_type` switch, or two `country_code` rows — `vat_periods` carries
both columns and nothing in the schema forbids overlap). The second stale period would go
unreported and the operator would re-close only one. Low probability (a config anomaly), one-line
fix: `->get()` and merge each into `$affectedClosedPeriods`.

**m-9 — dry-run behaviour of the two new report paths is untested.** Both the I-1 duplicate skip
and the I-2 closed-period block are exercised only under `--apply`
(`Artisan::call(..., ['--apply' => true])` in both new tests). The code paths are structurally
`$apply`-independent (`:342`, `:444` both sit outside any `$apply` branch), so I am satisfied by
reading — but dry-run IS the mode an operator runs first, and a regression that made either block
apply-only would ship silently. One extra assertion in the existing dry-run test would close it.

## Still open (unchanged, previously deferred by the coordinator)
- **m-3** (currency-scale truncation vs the `decimal(15,3)` columns; `bcformatStrict` truncate vs
  V5's `bcround` half-up; no scale-2 `tax_base` assertion) and **m-6**
  (`VatBreakdownTable.tsx:22` `parseFloat` on money) → ticketed on
  `docs/superpowers/tickets/2026-08-06-q2-gate-minor-followups.md`.
- **I-3's owner/expert ruling on the 0%-deductible case** — the one substantive open question. The
  interim handling is safe; the ruling still has to land before the 0% population is remediated.

## Merge condition
None blocking. Ship `4ee9042e6`. File m-7/m-8/m-9 alongside m-3/m-6 on the followups ticket, and do
not let the 0%-deductible ruling (I-3) fall off the owner queue.
