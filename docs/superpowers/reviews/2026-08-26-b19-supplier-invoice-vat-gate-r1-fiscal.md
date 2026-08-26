# B-19 adversarial gate r1 — fiscal/declaration lens

**Lane:** B-19 (P0) supplier-invoice deductible VAT absent from the TN declaration
**Branch:** `fix/b19-supplier-invoice-deductible-vat` · worktree `.worktrees/b19-supplier-invoice-vat`
**Range:** `9d0d08ae5..6e7fe62bd` (4 commits, 7 files, +1038/−13)
**Reviewer:** fiscal-pos-reviewer (read-only) · **Date:** 2026-08-26
**Ruling in force:** snapshot at `post()`

## VERDICT: spec ✅ + quality CHANGES-REQUESTED

Every claim below cites a file:line I opened. Paths are worktree-relative to
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b19-supplier-invoice-vat/`.

---

## 1. What I verified GREEN

**Root cause and both locks are real and correctly closed.**
- `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:47`
  now reads `whereIn('d.type', ['invoice','credit_note','expense','supplier_invoice'])`, and the
  `CASE` at `:51` / the mirrored `groupByRaw` `CASE` at `:64` both map `supplier_invoice` to `INPUT`.
  select-list and group-by expressions are byte-identical — no PG grouping error.
- `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:349-353` adds
  step 9 (`load(['company','partner','lines'])` + `snapshotTaxDetails(…, calculateDocumentTaxes(…))`)
  inside the same `DB::transaction` as the GR/IR entry (`:281`) and the status transition (`:296`).
  `TaxCalculationService` is constructor-injected `private readonly` at `:52` (rule 13 clean).

**No sign flip is correct.** `TunisiaVatStrategy::mapToDeclaration()` sums recoverable INPUT
breakdowns into `total_deductible_vat` (`.../Infrastructure/Strategies/TunisiaVatStrategy.php:71-79`)
and `VatReportGenerationService::generateSummary():86-92` only totals `isRecoverable` INPUT rows,
which `VatCreditService` then nets against output. A negation would have increased the payable.

**Period keyed on the supplier's own invoice date — correct for TN.** The declaration filters
`d.document_date` (`EloquentVatDataRepository.php:45`), and `document_date` is populated from the
supplier's `issue_date` at `CreateSupplierInvoiceService.php:130`. TN deduction right attaches to
the supplier invoice's date, so the code uses the right date (not posting date, not entry date).
The GR/IR journal entry uses the same date (`GeneralLedgerService.php:2242` →
`'entry_date' => $supplierInvoice->document_date`), so ledger and declaration agree on period.

**No fiscal-chain / sealed-bytes / Event changes.** `git diff --stat 9d0d08ae5..6e7fe62bd` touches
only the repository, the posting service, two guards (comment-only), the backfill command, and two
test files. No `FiscalHashService`, no `Domain/Events/*`, no migration. Rule 8 clean.
VAT-period close is a plain snapshot + totals write with **no hash or seal**
(`VatPeriodManagementService::closePeriod():84-123`; `reopenPeriod():143-162` deletes breakdowns and
nulls the totals), so the printed reopen/re-close instruction is not a hash-chain hazard — but see
finding B-4 for why it may be *unexecutable*.

**No stamp-duty contamination.** `TunisiaTaxConfigurationSeeder.php:90-121` lists stamp configs only
for `TAX_INVOICE` / `FISCAL_RECEIPT` / `CREDIT_NOTE`; a supplier invoice is `NonFiscal`
(`CreateSupplierInvoiceService.php:126`), so no `is_stamp_duty=true` row is produced and
`TunisiaVatStrategy::getSpecialLineItems():97-109` (which counts stamp rows across ALL document
types, with no `deleted_at` filter) is unaffected. Report §3.1's claim holds.

**Test asserts real figures, not presence.**
`tests/Feature/Taxation/SupplierInvoiceVatDeclarationTest.php` (`test_tn_declaration_carries_both_output_and_deductible_vat`)
asserts `outputVat.total_vat = 190.000`, `inputVat.total_vat = 95.000`,
`fields.total_output_vat = 190.000`, `fields.total_deductible_vat = 95.000`, `fields.base_19 = 1000.000`
and `netVat = 95.000` — output vs deductible vs net, all three. `RefreshDatabase`, real models, real
`ChartOfAccountsService` seed, no faked payloads.

**Expense-arm verdict independently reproduced.** I ran the report's census SQL against the live
demo tenant (`127.0.0.1:5433`, `tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`) and got byte-identical
output: `posted/not-deleted/has-VAT = 7 docs, 7 with a tax-detail row`; the 149 without rows are 64
drafts (48 soft-deleted) and 85 posted expenses with NULL/0 `tax_amount`. The early return at
`ExpenseService.php:631-633` explains it, and it is reached from both `post()` branches. **The report's
"NOT a bug, no fix made" verdict is correct.** I also re-ran the supplier-invoice census: `draft 31 /
390.517`, `posted 12 / 313.884`, `0` with rows, `0` soft-deleted — exactly as reported.

**No float touches money.** `git diff | grep -E '\(float\)|floatval|number_format|round\('` on the
added lines returns nothing. The new leg resolves scale per document via
`getScaleSafe((string) $document->currency, 3)` (`BackfillTaxDetailsCommand.php:790`) — explicit
currency, console-safe (rule 19/20).

---

## 2. Blocking findings

### [B-1 · Important-high] The live writer has NO VAT-period guard — a back-dated supplier invoice can now silently rewrite a CLOSED or FILED declaration
`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:109`
validates `issue_date` as bare `['required','date']` — any past date is accepted.
`SupplierInvoicePostingService::post()` calls no period guard: `assertBackdatingPeriodIsOpen()` has
exactly one caller in the whole app (`ReturnNoteService.php:565`), and
`createSupplierInvoiceGrIrClearingEntry` (`GeneralLedgerService.php:2167`) performs no fiscal-period
lock either. So after this change, `POST /supplier-invoices` with `issue_date = 2026-01-15` followed
by `/post` writes a brand-new deductible row into January — even if January's `vat_periods` row is
`FILED`.

Why this is blocking: the lane's own backfill treats exactly this act as the highest-consequence
case and **refuses it** (`BackfillTaxDetailsCommand.php:834-843`, `--include-filed` required, with
"changes a figure already sent to the DGI" in the docblock at `:203-207`), and
`VatPeriodCancellationGuard` already refuses to *withdraw* a supplier invoice from a locked period
(`:117-119` + `DocumentPostingService.php:398`). The one path left unguarded is the one this lane
just made declaration-bearing. Before B-19 a back-dated post was a ledger-only concern; now it is a
declaration mutation.

*Fix:* inject `PeriodBackdatingGuardInterface` into `SupplierInvoicePostingService` and call
`assertBackdatingPeriodIsOpen($companyId, $supplierInvoice->document_date, $documentNumber)` before
step 7, with a test that a post into a CLOSED and into a FILED period is refused.

### [B-2 · Important] GL and declaration can disagree on newly-posted invoices — the backfill guards it, the writer does not (C-1, confirmed real)
Two different arithmetic conventions produce the two numbers, in the same transaction:
- GL posts `Σ line->recoverable_tax_amount` (`SupplierInvoicePostingService.php:262-285`), and those
  line values are **half-up rounded per line**: `CurrencyScale::bcround($lineTaxHp, $scale)`
  (`CreateSupplierInvoiceService.php:101`), with `line_total` likewise `bcround`ed at `:94`.
- The declaration snapshot uses the engine, which **truncates**: per-line base
  `CurrencyScale::bcformat($lineSubtotal, $scale)` (`TaxCalculationService.php:154`) and per-bucket
  tax `CurrencyScale::bcformat($group['taxAccumulator'], $scale)` (`:208`).

So both the declared **base** and the declared **VAT** can differ from the stored header and from
the GL. The backfill refuses such documents (guard 3, `BackfillTaxDetailsCommand.php:817-822`) and
it already fires on live data (2 of 12 on the demo tenant, `23.941` vs `23.942`). The writer applies
**no equivalent check** — the same document posted today is written silently, backfilled tomorrow is
refused. That asymmetry is itself the defect: the system now has two different truths about whether
a document is safe to declare.

Consequence beyond the millime: the 2 skipped demo invoices carry **47.884 TND of 313.884 (15%)**
that stays permanently undeclared, and the only record is transient console output — no ticket, no
table, no follow-up mechanism.

*Fix (minimum):* mirror guard 3 in `post()` — compare `$result->lineItemsTaxTotal` against
`$supplierInvoice->line_tax_amount` and refuse (or at minimum `Log::error` with document id) on
disagreement, so GL-vs-declaration divergence can never be created silently. Reconciling the two
conventions is correctly out of scope, but shipping an unguarded writer alongside a guarding
backfill is not.

### [B-3 · Important] `--apply` writes into CLOSED periods and the printed remedy can be impossible to execute
The supplier-invoice leg refuses only FILED (`BackfillTaxDetailsCommand.php:834`); a CLOSED period is
written and merely gets the printed "reopen this period then re-close it" instruction (`:889-897`).
But `VatPeriodManagementService::reopenPeriod():136-141` throws
`'Cannot reopen: a successor period is already closed or filed'` whenever
`hasClosedOrFiledSuccessor()` is true — and that check
(`EloquentVatPeriodRepository.php:81-87`) matches **any** later period of the company. A tenant that
has closed February cannot reopen January, so the instruction cannot be carried out and the CLOSED
period's `total_input_vat` / `net_vat` / `credit_carried_forward` (`closePeriod():101-110`) stay
stale — while the live report regenerates from the new rows. The carry-forward chain into every
successor period is then wrong, silently.

The expense leg's precedent is weaker than the docblock claims: it *rewrote* an existing row's base;
this leg *creates* new deductible rows, which is what moves `net_vat`.

*Fix:* refuse CLOSED like FILED (own flag, e.g. `--include-closed`), or at minimum detect
`hasClosedOrFiledSuccessor()` and print the escalate-to-accountant text instead of a reopen
instruction the system will reject.

---

## 3. Important, non-blocking

### [I-1] A supplier invoice cancelled after posting stays fully declared (report C-5 — but the direction matters)
`EloquentVatDataRepository::aggregateByRateAndDirection()` applies **no status predicate** — only
`whereNull('d.deleted_at')` (`:52`). `DocumentPostingService::cancel()` sets
`status = Cancelled` without soft-deleting (`:428-433`), does not delete `document_tax_details` (no
caller anywhere deletes them outside `snapshotTaxDetails` / the expense writer / the backfill), and
does **not** reverse the GL for a supplier invoice (`FISCAL_DOCUMENT_TYPES` is `[Invoice, CreditNote]`
only, `:50-53`). So a posted-then-cancelled supplier invoice keeps claiming its deduction.

Two mitigations make this latent rather than live, and I verified both: (a)
`assertCancellationPeriodIsOpen()` (`:398` → `VatPeriodCancellationGuard:127-139`) refuses the cancel
once the period is CLOSED/FILED, and (b) **there is no cancel route or service caller for
supplier invoices today** — `DocumentPostingService::cancel()` is only reached from `RefundService`
(`:131`, `:281`, `:859`) and `SalesOrderService:221`, and `Document/Presentation/routes.php` exposes
cancel only for invoices (`:224`) and credit notes (`:269`).

But the report's framing ("identical to the known pre-existing behaviour for cancelled sales
invoices") understates it: for a sales invoice the residual row **over-states output VAT** (over-pay,
conservative); for a supplier invoice it **over-states deduction** (under-pay — the direction a DGI
audit penalises). When the supplier-invoice cancel lane is built this becomes live over-claim.
Worth a named ticket rather than a paragraph in a lane report; the cheap durable fix is a
`status != cancelled` predicate in the repository's document arm.

### [I-2] "Right section and rate bucket" cannot be satisfied — the TN mapping has no deductible bucket at all
`TunisiaVatStrategy::mapToDeclaration():62-79` emits `base_{rate}` / `vat_{rate}` fields **only for
output breakdowns**; the entire input side collapses into the single scalar
`fields['total_deductible_vat']`. So supplier-invoice VAT lands in the only deductible slot that
exists, which is *consistent* — but the real DGI TVA form separates deductible VAT by nature
(biens/services/immobilisations) and the exported declaration cannot express that. Pre-existing
(identical for `expense`), not introduced here, and the test correctly asserts only `base_19` on the
output side. Flagging so the gate record does not imply per-bucket deductible correctness was proven.

### [I-3] Recoverability is re-derived from `tax_configurations`, not from the line
The declaration's recoverable flag comes from `COALESCE(tc.is_recoverable, true)` joined on
`dtd.tax_rate = tc.percentage_rate` (`EloquentVatDataRepository.php:24-33, 60`), and
`snapshotTaxDetails()` never persists the engine's `isRecoverable`
(`TaxCalculationService.php:516-528`). Today that is harmless because
`CreateSupplierInvoiceService.php:182-184` hardcodes `tax_recoverable => true`,
`recoverable_tax_amount = lineTax`, `non_recoverable_tax_amount = '0.000'`. It becomes an over-claim
the moment a partially/non-recoverable input-VAT lane exists for supplier invoices (TN: passenger
vehicles, etc.) — the GL would post only the recoverable share (`SupplierInvoicePostingService.php:268-271`)
while the declaration claims the full engine amount. Also note a line rate with no matching
`tax_configurations` row (LEFT JOIN → NULL → `COALESCE(...,true)`) is declared recoverable by
default. Record alongside the report's C-2.

### [I-4] Fiscal-aggregate change exercised only under SQLite (report C-6 — agreed, and it applies to the load-bearing file)
The 17 new tests ran on the default in-memory SQLite config. The changed code is a raw
`selectRaw`/`groupByRaw` aggregate that IS the declaration. The change itself is two string literals
in an existing `whereIn`/`CASE` and I see no PG-specific hazard (the group-by expression matches the
select expression textually; `document_tax_details.document_id` is `NOT NULL`
(`2025_12_30_102000_create_document_tax_details_table.php:15`) so the new
`whereNotIn('id', … select('document_id'))` at `BackfillTaxDetailsCommand.php:735` is not exposed to
PG's `NOT IN (NULL)` trap). Still: run `tests/Feature/Taxation/{SupplierInvoiceVatDeclarationTest,VatDataRepositoryTest,BackfillTaxDetailsCommandTest}.php`
under `phpunit-pgsql.xml` before merge. Cheap, and it is the house rule for fiscal aggregates.

---

## 4. Minor

- **[M-1]** `BackfillTaxDetailsCommand.php:877` — `bcadd($totalVat, $bucket['tax'], 3)` hardcodes
  scale 3 for the census total. Display-only and consistent with the pre-existing `accumulate()`
  (`:1051-1052`) and main leg (`:285`), so not a regression — but the new leg already resolves a real
  `$scale` at `:790` and could use it.
- **[M-2]** `BackfillTaxDetailsCommand.php:790` — `getScaleSafe((string) $document->currency, 3)`
  passes a possibly-empty string; `CurrencyScaleResolver::getScale():38-41` short-circuits any
  non-null value into `CurrencyScale::for('')`, which yields the ISO default (2), not 3. The engine
  deliberately avoids this (`TaxCalculationService::scaleFor():46-52` passes `null` when the currency
  is empty). Guard comparisons would then run at scale 2 against scale-3 values. Edge case only —
  `documents.currency` is always populated on the supplier-invoice path — but the engine's shape is
  the correct one to copy.
- **[M-3]** The leg lazy-loads `company` and `partner` per document inside
  `calculateDocumentTaxes()` (`TaxCalculationService.php:59-60`) while only `lines` is eager-loaded
  (`BackfillTaxDetailsCommand.php:737`). N+1 on a large tenant; correctness unaffected.
- **[M-4]** Report §4 lists a `deptrac` and `phpstan` pass but no `phpunit-pgsql` run and no
  preflight; rule 10 asks for preflight before "complete".

---

## 5. Spec verdict detail

| Brief requirement | Status | Evidence |
|---|---|---|
| Snapshot tax details at the supplier-invoice writer | ✅ (at `post()`, not `create()` — deviation is *correct* and justified) | `SupplierInvoicePostingService.php:349-353`; the repository applies no status filter (`EloquentVatDataRepository.php:45-52`), so snapshotting drafts would declare 31 unposted demo drafts / 390.517 TND |
| Whitelist the type in the VAT repository | ✅ | `EloquentVatDataRepository.php:47, 51, 64` |
| Extend `vat:backfill-tax-details` to reach supplier invoices | ✅ | `BackfillTaxDetailsCommand.php:697-905`, dry-run default, census, 3 guards, FILED refusal |
| Verify the `expense` arm (149/156) | ✅ verdict independently reproduced against the live tenant | 7/7 posted-with-VAT have rows; 64 drafts + 85 NULL-VAT posted; `ExpenseService.php:631-633` |
| No fiscal-chain / Event / sealed-byte change | ✅ | `git diff --stat` — 7 files, none fiscal-chain |
| Declaration test asserts figures | ✅ | output 190.000 / deductible 95.000 / net 95.000 |

**Spec ✅.** The deliverable does what the brief asked, the root-cause analysis is accurate, and every
live figure I could re-run reproduced exactly.

**Quality CHANGES-REQUESTED** on B-1 (unguarded back-dated post into a CLOSED/FILED period), B-2
(writer lacks the backfill's own GL-vs-declaration guard; 47.884 TND left with no remediation path),
B-3 (CLOSED-period write whose printed remedy `reopenPeriod()` will reject).

**What to fix before merge:** guard `SupplierInvoicePostingService::post()` with the VAT-period
backdating check and mirror the backfill's guard-3 GL-vs-declaration equality check in the writer;
make the CLOSED-period path either refuse or print the escalation text when a successor period is
closed/filed.
