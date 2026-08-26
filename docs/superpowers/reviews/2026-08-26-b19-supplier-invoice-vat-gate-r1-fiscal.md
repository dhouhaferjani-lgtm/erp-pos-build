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

---

## r2 scoped re-review

**Range:** `6e7fe62bd..50c09c0c5` (3 commits, 10 files, +1403/−284) · **Date:** 2026-08-26
**Scope:** r1 blocking items B-1/B-2/B-3 + I-1/I-4; new `PostedLineTaxSnapshotBuilder`; new
Critical/Important in the fix diff only. Paths worktree-relative to
`.worktrees/b19-supplier-invoice-vat/`.

**Note:** the fix report at `.superpowers/sdd/PLAN/task-6-report.md` does not exist in this
worktree (`.superpowers/sdd/task-6-report.md` is an unrelated lane's "drift-guard" report).
§FR-9 could not be read — **cannot verify** any claim in it. Everything below is from the code.

### r1 items

| r1 item | Verdict | Evidence |
|---|---|---|
| **B-1** period guard on the live writer | **ADDRESSED** | `SupplierInvoicePostingService.php:101-105` — `assertBackdatingPeriodIsOpen($company_id, $document_date, $document_number)`, and it fires BEFORE the first `lockForUpdate()` at `:116`. Symmetric arm on `SupplierCreditNotePostingService.php:201-205`. `VatPeriodBackdatingGuard.php:61-81` refuses `vat_periods` Closed/Filed **and** `fiscal_periods` Closed/Locked, absent-permits. Typed 422 confirmed to survive: `SupplierInvoiceController.php:357-369` rethrows `ReturnPeriodLockedException` ahead of the generic `\DomainException` arm at `:370`, and `bootstrap/app.php:853` registers that renderer BEFORE the generic `DomainException` renderer at `:1022` — Laravel matches render callbacks in registration order (`vendor/.../Foundation/Exceptions/Handler.php:714-724`), so the specific one wins. |
| **B-2** GL-vs-declaration divergence | **ADDRESSED (better than asked)** | Not "mirror the guard" but "remove the second convention": `SupplierInvoicePostingService.php:384` derives from persisted lines via `PostedLineTaxSnapshotBuilder::build()` instead of `calculateDocumentTaxes()`, and `:385-391` throws on `divergences()`. The **same** `build()`/`divergences()` pair is used by the backfill (`BackfillTaxDetailsCommand.php:789-800`) and by the SCN arm (`SupplierCreditNotePostingService.php:418-426`) — one implementation, so writer and backfill can no longer disagree. Pinned by `SupplierInvoiceVatDeclarationTest::test_snapshot_carries_the_vat_the_gl_posted_when_per_line_rounding_diverges` (23.942 vs the engine's 23.941) which also ties the figure to the actual 4456 debit via `postedDeductibleVat()`. |
| **B-3** `--apply` into a CLOSED period | **ADDRESSED** | `BackfillTaxDetailsCommand.php:833-853` refuses UNCONDITIONALLY (no flag) when `periodImpact()['unreopenableClosed'] !== []`; `periodImpact()` at `:1184-1231` calls `hasClosedOrFiledSuccessor()` per overlapping CLOSED period. Escalation text at `:947-957`; reopenable CLOSED keeps the reopen instruction at `:961-975`. `--include-filed` explicitly does NOT unlock it (`:236`). Matches the ruling. |
| **I-1** cancelled-after-post stays declared | **ADDRESSED per ruling** | Repository docblock records it as a pre-existing cross-type defect (`EloquentVatDataRepository.php:66-75`), no status predicate added — parity with sales kept. Backfill made CONSISTENT with live: cancelled-with-a-JE is in scope, cancelled-without-one is not (`BackfillTaxDetailsCommand.php:755-761` + `postedJournalEntryExists()` at `:1086-1092`, which joins `journal_entries.source_type = documents.type`). Pinned by two tests (`…includes a cancelled document that still carries its journal entry`, `…excludes a cancelled document that never posted`). |
| **I-4** PG run for the fiscal aggregate | **ADDRESSED — I ran it** | `php artisan test -c phpunit-pgsql.xml tests/Feature/Taxation/SupplierInvoiceVatDeclarationTest.php` → **13 passed / 41 assertions**; `…/BackfillTaxDetailsCommandTest.php` → **48 passed / 232 assertions**. Both green on PostgreSQL 16 (local `127.0.0.1:5433`). The SQLite-masking risk on this lane is now closed by execution, not by argument. |

### `PostedLineTaxSnapshotBuilder` scrutiny

Green:
- **Rate bucketing** — `:129-147` groups on `(string) $line->tax_rate`. `DocumentLine` casts `tax_rate` to `decimal:2` (`DocumentLine.php:146`), so the key is always the normalised `"NN.NN"` form; no `'19'` vs `'19.00'` split, and the numeric-key int-coercion re-cast at `:157-160` is correctly defensive rather than load-bearing. **Mixed-rate** documents produce one bucket per rate and `Σ buckets == Σ lines`, so divergence checks 2/3 still bind.
- **Zero-rate / bonus line** (`tax_rate '0.00'`, `line_total '0.000'`) — gets its own bucket with base = `line_total`, tax `0`. Harmless on the TN INPUT side (`TunisiaVatStrategy` sums recoverable INPUT **VAT** only). **Rate-less line** (`tax_rate` NULL) is excluded from buckets but still counted into `subtotal` (`:130-138`), which keeps check 2 honest; a wholly rate-less document that nonetheless records header VAT is REFUSED by check 4 (`:295-303`), and one that records zero VAT is routed to `hasNothingToDeclare()` (`:323-326`) instead of churning the backfill forever.
- **`recoverable_tax_amount` NULL** — `?? '0'` at `:145`, and check 3 (`:277-284`) then refuses the document because Σ recoverable ≠ header `line_tax_amount`. The non-recoverable share can never be silently declared. Confirmed the header/line tie it depends on is real: `CreateSupplierInvoiceService.php:103-104` builds `subtotal`/`line_tax_amount` as exactly Σ `line_total` / Σ per-line rounded tax, and `:209` makes `tax_amount = line_tax_amount + stamp_duty_amount`, which is precisely check 1 (`:251-258`).
- **Currency / rule 19** — `scaleFor()` (`:96-105`) passes the explicit document currency to `getScale($currency)` and falls back to `getScaleSafe(null, 3)` on an empty string, copying `TaxCalculationService::scaleFor()` and closing r1's **M-2**. Every arithmetic op in the diff is bcmath at that resolved scale (`:127, :146-147, :162, :198, :251-297`); the only literal-scale call is `CurrencyScale::bcformat($tax->rate, 2)` on a **percentage** (`BackfillTaxDetailsCommand.php:880`), which is correct per rule 19. No float, no `number_format`, no `(float)` anywhere in the added lines. Console leg resolves scale per document (`:863`) and totals the census per currency (`:918-928`) — r1's **M-1** closed too.
- **No re-authoring of device/document facts** — `snapshotTaxDetails()` (`TaxCalculationService.php:509-530`) writes only `document_tax_details`; it does not touch document headers or lines. The builder is a pure aggregator: it applies no rate to any base and makes no rounding decision.
- **SCN negation cannot double-negate** — rows are written POSITIVE (`SupplierCreditNotePostingService.php:417-428`), negation happens only at aggregation (`EloquentVatDataRepository.php:94-95`, mirrored byte-identically in the `groupByRaw` `CASE` at `:101-107`), and the GL leg it must tie to is itself a **positive** Σ `recoverable_tax_amount` (`SupplierCreditNotePostingService.php:362-369`) against a positive `subtotal` (`:372`). Divergence checks 2/3 force the derived figures to equal that same positive header, so a negatively-signed line set cannot pass with a positive header. Pinned end to end: `test_repository_negates_supplier_credit_note_rows_on_the_input_side` (450.000 / 85.500) and `test_tn_declaration_nets_a_supplier_credit_note_out_of_the_deduction` (deductible 85.500, net 104.500). No creation route or service for `supplier_credit_note` exists in `apps/api/app` today, so the arm is forward-looking, not live.

### New findings in the fix diff

#### [N-1 · Important] The period guard runs BEFORE the idempotency no-op, so a harmless re-post of an already-posted supplier invoice now 422s
`SupplierInvoicePostingService.php:101-105` (guard) executes ahead of the "a clearing entry already
exists → `return`" probe at `:150-157`. `SupplierInvoiceController::post()` deliberately performs no
status pre-check precisely so retries reach that no-op (`SupplierInvoiceController.php:345-349`).
After this change, a second `POST /supplier-invoices/{id}/post` on an invoice whose `document_date`
has since fallen into a CLOSED/FILED `vat_period` — or a Closed/Locked `fiscal_period` — returns a
typed 422 instead of the documented no-op, even though the call would have written nothing.
The same route also accepts AR/AP **opening** documents (`ArApOpeningService` creates `HIST-SINV`
rows of `type = supplier_invoice`, `ArApOpeningLedgerService.php:222` gives their journal entry
`source_type = 'supplier_invoice'`), and those are back-dated by construction — exactly the
population most likely to sit inside a closed period.
The sibling arm gets this right: `SupplierCreditNotePostingService.php:171-184` takes its
Posted+entry-exists early return BEFORE the guard at `:201`.
*Fix:* hoist the `$alreadyPosted` probe (a plain `exists()`, needs no lock) above the guard, or move
the guard to just after it — it still lands before the first `lockForUpdate()` at `:116`, preserving
the stated "a refusal never holds those rows" property.

#### [N-2 · Minor] Guard widened posting beyond VAT — `fiscal_periods` Closed/Locked now blocks supplier-invoice posting
`VatPeriodBackdatingGuard.php:74-81` also refuses on `isDateInClosedFiscalPeriod()`. Defensible (the
post writes a GR/IR journal entry dated on `document_date`, `GeneralLedgerService.php:2242`) and
absent-permits, but it is a behaviour expansion beyond the r1 finding: any tenant that closes fiscal
periods monthly will now see back-dated supplier-invoice posts refused. Worth a release note rather
than a code change.

#### [N-3 · Minor] Lineless AP-opening documents will be reported as "skipped" by the backfill on every run
`supplierDocumentQuery()` (`:1046-1057`) matches `type IN (supplier_invoice, supplier_credit_note)`
with no exclusion for `HIST-SINV` opening documents, which are `Posted`, carry no
`document_tax_details` and have no lines. `divergences()` refuses them (`:305-307`, plus check 2),
so nothing is written — correct, but they will print in the skipped list forever. Cosmetic;
pinned green by `supplier leg skips a lineless document`.

#### [N-4 · Minor] `SupplierCreditNotePostingService`'s guard comment overstates its placement
`:199-200` says "Placed before the advisory/row locks below so a refusal never holds them", but the
document row `lockForUpdate()` at `:144-147` is already held when the guard runs at `:201`. The
transaction rolls back immediately, so the practical effect is nil — the comment is what is wrong.

#### [N-5 · Minor] `periodImpact()` caches the period lookup but not `hasClosedOrFiledSuccessor()`
`:1217-1222` calls the successor probe once per closed period per document; only the
`(company|date)` period lookup is memoised (`:1191-1213`). N queries on a large backfill.
Correctness unaffected.

### Gates re-run in this review
- `phpunit-pgsql.xml` — `SupplierInvoiceVatDeclarationTest` 13 passed / 41 assertions; `BackfillTaxDetailsCommandTest` 48 passed / 232 assertions. **Green on PostgreSQL.**
- `pint --test` on all six changed `app/` files — `{"result":"pass"}`.
- PHPStan not run (needs a live-DB env per the worktree gotchas) — **cannot verify**.

### r2 verdict

**spec ✅ + quality APPROVED-WITH-ONE-FIX.** All three r1 blocking items (B-1, B-2, B-3) and both
carried-forward items (I-1, I-4) are ADDRESSED, and the r1 minors M-1/M-2 are closed as a bonus.
The `PostedLineTaxSnapshotBuilder` is a genuine aggregator, not a second engine; its rate bucketing,
NULL/zero/rate-less handling, explicit-currency scale resolution and SCN sign convention all hold
under inspection and under a real PostgreSQL run. One new Important (N-1, guard/idempotency
ordering) and four minors.

**What to fix before merge:** hoist the `$alreadyPosted` idempotency probe above the new period guard
in `SupplierInvoicePostingService::post()` so an already-posted (or AP-opening) supplier invoice
re-post stays a no-op instead of 422-ing on a closed period.
