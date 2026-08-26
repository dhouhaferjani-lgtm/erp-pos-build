# B-19 gate r1 — treasury/GL lens

Lane: B-19 (P0) supplier-invoice deductible VAT absent from the TN declaration
Branch: `fix/b19-supplier-invoice-deductible-vat` · worktree `.worktrees/b19-supplier-invoice-vat`
Range: `9d0d08ae5..6e7fe62bd` (4 commits, 7 files, +1038/-13) · review READ-ONLY, 2026-08-26

**VERDICT: spec ❌ + quality CHANGES-REQUESTED**

Spec ❌ on one deliverable only: the brief's expense-arm leg says "fix if same root cause, **else file**".
The investigation is sound (§6 of the report, corroborated below) but *nothing was filed* —
`git diff --name-only 9d0d08ae5..6e7fe62bd` is 7 files, all code/tests; no ticket, no deploy-step record
for the owner-executed backfill (report C-3). The two code locks themselves are genuinely closed and
proven end-to-end.

---

## What is correct (verified, not taken on trust)

* **INPUT sign is right.** `EloquentVatDataRepository.php:71,77,89` adds `supplier_invoice` to the
  `whereIn` and to the `'INPUT'` branch of both the `selectRaw` and `groupByRaw` `CASE`, with **no**
  negation. `TunisiaVatStrategy::mapToDeclaration()` sums recoverable INPUT breakdowns into
  `total_deductible_vat` (`TunisiaVatStrategy.php:70-79`), which nets against output —
  pinned by `SupplierInvoiceVatDeclarationTest` asserting `netVat === '95.000'` (= 190 − 95).
  A negation here would have *increased* the amount payable. Correct as written.
* **Recognition point `post()` is the right one** (the orchestrator's ruling holds up). The repository
  applies no status predicate (`EloquentVatDataRepository.php:59-92`), so "a row exists" is the only
  fiscal-recognition proxy; snapshotting at create would declare the 31 unposted demo drafts
  (390.517 TND) against documents with no journal entry. Verified against live data:
  `supplier_invoice|draft|31|390.517`, `supplier_invoice|posted|12|313.884` on
  `tenant019fbe86-…`, and all 12 posted/paid docs do carry a `journal_entries` row with
  `source_type='supplier_invoice'`.
* **Rule 19 clean.** No float anywhere in the new code. Scale is resolved with an explicit currency in
  both console and transaction contexts — `BackfillTaxDetailsCommand.php:772`
  (`getScaleSafe((string) $document->currency, 3)`) and `TaxCalculationService.php:46-52`
  (`getScale($currency)` with a non-null argument). No bare no-arg `getScale()` introduced.
* **GL amounts unchanged.** The call site `SupplierInvoicePostingService.php:281-288`
  (`createSupplierInvoiceGrIrClearingEntry`) is byte-identical; step 9 is additive and inside the same
  `DB::transaction`.
* **Backfill contract honoured**: dry-run default, `--apply`, `--company`, per-tenant under
  `tenants:run`, FILED refusal (`:826-834`), CLOSED reopen/re-close instruction (`:865-874` region),
  and the scope requires zero existing rows so a written document drops out (`:732`).
  `document_tax_details.document_id` is `NOT NULL` (verified in PG), so the `whereNotIn` sub-select
  cannot NULL-poison.
* **No update endpoint** for supplier invoices — `app/Modules/Procurement/Presentation/routes.php:84-117`
  exposes index / duplicate-reference / show / store / match / link-receipts / post only. The report's
  "VAT base and rate are immutable after creation" claim checks out.
* **Rule 6**: `SupplierInvoicePostingService.php:16` imports `Taxation\Domain\Services\TaxCalculationService`.
  `deptrac.yaml:20-23` states cross-module coupling is deliberately NOT enforced, and the module's own
  `CreateSupplierInvoiceService` already injects the same service. Precedent-consistent, not a new
  boundary violation.

---

## Findings

### [IMPORTANT] F1 — the writer declares a figure the ledger does not carry, with no guard
`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:341-345`

The GL posts Σ `document_lines.recoverable_tax_amount` (`:266-273` → `:281-288`), which is the figure
`CreateSupplierInvoiceService.php:97-101` computed with **`CurrencyScale::bcround` (half-up, per line)**.
Step 9 snapshots `TaxCalculationService::calculateDocumentTaxes()`, which accumulates at `scale+1` and
**truncates once per rate bucket** (`TaxCalculationService.php:155-158`, `:209`, `CurrencyScale::bcformat`
truncates). The two disagree whenever the true line VAT has a non-zero 4th decimal.

Confirmed on live data (`tenant019fbe86-…`, `SI-2026-0008` / `SI-2026-0020`):
`qty 10 × 12.601 = 126.010`, `× 19% = 23.9419` → stored & posted to 4456 = **23.942**, engine =
**23.941**. 2 of 12 posted supplier invoices on that tenant.

Which is correct for the declaration: **the stored/GL figure**. 23.942 is both the arithmetically
correct rounding of 23.9419 and the amount actually debited to `4456`; a declaration that cannot be tied
to the 4456 movement is an unexplainable reconciliation break at audit. The backfill agrees — it
*refuses* exactly this case (`BackfillTaxDetailsCommand.php:800-806`). The live writer has no such
guard and no log, so every future divergent invoice silently under-claims by a millime and breaks the
tie, permanently and invisibly.

Two consequences to fix together:
1. **Guard the writer** the way the backfill is guarded: compare `$result->lineItemsTaxTotal` against
   the posted figure and refuse (or at minimum `Log::warning` with document id + both amounts) rather
   than writing silently. Reconciling the two rounding conventions is a treasury-owned lane and is
   correctly out of scope — the *silence* is not.
2. **Name the residual.** The 2 refused demo invoices (47.884 TND) have **no** remediation path in the
   product: there is no supplier-invoice update endpoint, and re-`post()` returns at the step-3
   idempotency no-op (`:120-128`) so it can never rewrite them. Today they simply stay undeclared with
   "needs manual review" and nowhere to go. That belongs in the deploy/owner note, explicitly.

### [IMPORTANT] F2 — nothing stops a normal post from injecting deductible VAT into a FILED period
`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:62-346`

`document_date` is user-supplied (`CreateSupplierInvoiceService.php` → `'document_date' => $validated['issue_date']`)
and `post()` performs no VAT-period check. The only consumer of
`PeriodBackdatingGuardInterface::assertBackdatingPeriodIsOpen()` in the whole app is
`ReturnNoteService.php:565` (grep over `app/`). Before B-19 a back-dated supplier invoice moved only the
GL; after B-19 it also moves the **declaration** — so posting last month's supplier invoice after that
month is FILED silently changes a figure already sent to the DGI.

The asymmetry is the tell: the backfill, a deliberate admin action, refuses precisely this without
`--include-filed` (`BackfillTaxDetailsCommand.php:826-834`), while an ordinary product action does it
with no refusal at all. Either wire `assertBackdatingPeriodIsOpen()` into `post()` (the guard already
exists, is bound at `TaxationServiceProvider.php:109`, and its docblock was *edited by this very lane* to
say supplier invoices are now declaration-bearing — `VatPeriodBackdatingGuard.php:49-52`), or get an
explicit owner ruling that it is accepted for launch.

### [IMPORTANT] F3 — B-19 flips the supplier-return error from under-claim (safe) to over-claim (exposed)
`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:71` +
`apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:337-370`

`SupplierCreditNotePostingService` is a live flow that credits recoverable VAT back out of `4456`
(`GeneralLedgerService::createSupplierCreditNoteEntry():2396-2405` — "`Σ recoverable_tax_amount`"), but
it writes **no** `document_tax_details` row and `supplier_credit_note` is absent from the repository
whitelist. Before this lane both sides declared zero (symmetric under-claim — the DGI-safe direction).
After it, the invoice declares the full deduction and the return never reduces it → **net over-claim**
for any tenant that returns goods to a supplier.

Verified latent, not live: zero `supplier_credit_note` rows on the demo tenant. But this is now a
one-sided ledger in the declaration, created by this change, and the repository docblock note
(`:39-49`) is not a substitute for an owner-visible ticket. File it (or ship both halves).

### [IMPORTANT] F4 — cancelled-after-post: the writer and the backfill take opposite positions
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:428-433` ·
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:417-418`

A supplier invoice is non-fiscal, so `cancel()` takes the `else` branch: status → `Cancelled`, **no GL
reversal** (`AccountingService::reverseDocumentGl():995-999` returns `null` for non-Invoice/CreditNote)
and **no** `document_tax_details` delete. So a posted-then-cancelled supplier invoice keeps declaring
its deduction forever. The backfill excludes `Cancelled` on the stated ground that
"a withdrawn document has no deduction to claim" (docblock `:83-84`).

Same economic state, two opposite answers, decided purely by whether the document was posted before or
after this merge. Note this is *not* the same as the known cancelled-sales-invoice bug the report cites
(report C-5): there the GL **is** reversed while the row survives; here the GL is not reversed at all.
Pick one policy and state it: either delete the snapshot on supplier-invoice cancel (matches the
backfill and the "withdrawn" ground, and does not touch GL amounts), or include cancelled-with-a-JE in
the backfill. Do not ship both.

### [IMPORTANT] F5 — guard 3 does not compare against what the GL actually posted (latent over-claim)
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:783-806`

The docblock says "what the GL posted **as recoverable input VAT** and what the declaration would claim
MUST agree". The code compares `$result->lineItemsTaxTotal` to `documents.line_tax_amount` — the
**total** line tax, not Σ `recoverable_tax_amount`, which is what `SupplierInvoicePostingService.php:266-273`
hands the GL. Same gap in the writer: `snapshotTaxDetails()` persists `tax_base`/`tax_amount` but **not**
`is_recoverable` (`TaxCalculationService.php:516-528`), and the repository re-derives recoverability from
`tax_configurations` with `COALESCE(tc.is_recoverable, true)` (`EloquentVatDataRepository.php:80`) —
never from the line. The moment a supplier-invoice line carries `non_recoverable_tax_amount > 0`, the
declaration claims VAT the ledger capitalised into inventory/charge instead.

Latent today only because `CreateSupplierInvoiceService.php:182-184` hardcodes
`tax_recoverable => true`, `non_recoverable_tax_amount => '0.000'`. The GL signature already carries
the non-recoverable parameter, so the product intends to support it. Either compare against
Σ `recoverable_tax_amount` (cheap, correct, same guard shape) or record the limitation where the next
implementer will hit it.

### [MINOR] F6 — a supplier invoice whose lines all have a NULL `tax_rate` never converges
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:748-759, 836-841` ·
`TaxCalculationService.php:134-138`

The engine skips rate-less lines, so `$result->taxes` is empty, `snapshotTaxDetails()` writes zero rows,
`$touched++` still fires, and the document re-enters scope (`:732`, "zero existing rows") on every
subsequent run — reported as "Snapshotted 1" forever while nothing changes. The only line guard is
`lines->isEmpty()`. Not reachable through `CreateSupplierInvoiceService` (rate is required), so this is
an import/legacy-data case. Add it to the skip reasons alongside "no document lines".

### [MINOR] F7 — the census is presented as a partition but is not one
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:405-420, 720-728`

Buckets are posted/paid-without / posted/paid-with / draft / cancelled. `DocumentStatus`
(`DocumentStatus.php:9-14`) also has `Confirmed` and `Received`; documents in those statuses are counted
in `total`, appear in no bucket, and are never scanned or explained. An operator reading
"Population: N total -- a/b/c/d" will assume a+b+c+d = N.

### [MINOR] F8 — the "TOTAL deductible VAT delta" sums across currencies at a fixed scale
`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php:877, 1051-1052`

`bcadd(..., 3)` with no currency partition. Harmless on a single-currency tenant and consistent with the
pre-existing legs, but the printed total is meaningless if a tenant has both TND (scale 3) and EUR
(scale 2) supplier invoices — and this number is exactly what the owner will eyeball before `--apply`.

### [MINOR] F9 — latent: purchase timbre could now inflate the sales stamp-duty line
`TaxConfiguration.php:122-128` · `TunisiaVatStrategy.php:96-109`

`scopeForDocumentType()` matches any configuration whose `applicable_document_types` is an **empty
array**. A tenant-configured stamp row shaped that way would now produce an `is_stamp_duty = true`
`document_tax_details` row on a supplier invoice, and `getSpecialLineItems()` counts stamp rows with
**no document-type and no `deleted_at` filter** — purchase timbre landing in `stamp_duty_count` /
`stamp_duty_total`, which is the sales timbre collected for the state (and, per PCG-TN, purchase timbre
is a non-recoverable class-6 charge that must never enter that line). Verified not live: every local
tenant's stamp rows are `["TAX_INVOICE"] / ["FISCAL_RECEIPT"] / ["CREDIT_NOTE"]`. No test covers it.

### [MINOR] F10 — two test-falsifiability gaps
`apps/api/tests/Feature/Taxation/SupplierInvoiceVatDeclarationTest.php`

* `test_a_draft_supplier_invoice_is_not_snapshotted` is tautological: `postableSupplierInvoice()` builds
  the document with `Document::create()`, bypassing `CreateSupplierInvoiceService` entirely, so no
  change to the production creation path could ever make it fail. It pins the fixture, not the code.
  Drive the fixture through `CreateSupplierInvoiceService::create()` or drop the claim.
* No test drives a **divergent-rounding** invoice (F1) through `post()` — the one case the backfill
  *does* have a test for (`BackfillTaxDetailsCommandTest`, `..._skips_when_recomputed_vat_differs_...`).
  The untested path is the one that ships silently.

The rest of the new tests are real: `RefreshDatabase`, real models, real `SupplierInvoicePostingService`
/ `VatDataRepositoryInterface` / `VatReportGenerationService`, no mocked subject, no `assertTrue(true)`,
concrete money assertions. The declaration end-to-end (`test_tn_declaration_carries_both_output_and_deductible_vat`)
is the strongest artifact in the package.

### [MINOR] F11 — spec: the expense-arm deliverable was investigated but never filed
The brief requires "verify the `expense` arm (149/156 lacking rows) — fix if same root cause, **else
file**". The verification is correct and reproducible (`ExpenseService.php:620-633`: unconditional stale
delete then an explicit early return on null/zero VAT, called from both `post()` branches), and the
"NULL `tax_amount` silently forfeits the deduction with no warning" observation is a real onboarding
risk. But no ticket exists in the diff, and the owner-executed backfill deploy step (report C-3) is
likewise recorded nowhere in-repo. Both are two-minute artifacts and both are load-bearing for launch.

---

## What to fix before merge
F1 (guard/log the GL-vs-declaration divergence at `post()` and name the 47.884 TND unremediable
residual) and F2 (period guard on `post()`, or an explicit owner ruling); take a single documented
position on F4; file F3, F5 and F11 as owner-visible tickets rather than docblock notes.

---

## r2 scoped re-review

Range `6e7fe62bd..50c09c0c5` (3 commits, 10 files, +1403/-284) · READ-ONLY, 2026-08-26.
Package: `.worktrees/b19-supplier-invoice-vat/.superpowers/sdd/PLAN/review-6e7fe62bd..50c09c0c5.diff`.

**VERDICT: spec ✅ + quality CHANGES-REQUESTED**

Spec flips to ✅: the sole r1 spec gap was "the expense arm was investigated but never filed, and the
owner-executed backfill has no in-repo deploy record". Both are now committed on `dev` (`1624df52c`) —
`docs/handoff/LEDGER.md` rows `D-B19-1` (expense NULL `tax_amount` → onboarding/UX, explicitly NOT the
B-19 root cause), `D-B19-2` (empty `applicable_document_types` stamp leak), `D-B19-3` (cancel-after-post
reversal gap), plus `S-23` (LEDGER.md:72) and `PROMOTION-CHECKLIST-2026-08-26.md` §3 for the backfill run.

### r1 findings disposition — all 11 ADDRESSED

* **F1 ADDRESSED** — `PostedLineTaxSnapshotBuilder.php:114-200` derives base = Σ `line_total`, VAT =
  Σ `recoverable_tax_amount` from the persisted lines; both writers call it
  (`SupplierInvoicePostingService.php:383-395`, `SupplierCreditNotePostingService.php:422-432`) and the
  writer is still the shared `snapshotTaxDetails()`. The live case is pinned AND tied to the ledger:
  `SupplierInvoiceVatDeclarationTest.php:294-315` asserts `23.942` on the snapshot *and* on
  `postedDeductibleVat($invoice)` (the actual 4456 journal line). The r1 "47.884 TND unremediable
  residual" is dissolved rather than documented — those two invoices are now derivable.
* **F2 ADDRESSED** — `assertBackdatingPeriodIsOpen()` wired at `SupplierInvoicePostingService.php:101-105`
  and `SupplierCreditNotePostingService.php:201-205`; typed 422 preserved by the
  `ReturnPeriodLockedException` rethrow at `SupplierInvoiceController.php:357-368`. See N1/N2 below.
* **F3 ADDRESSED** — both halves ship: `EloquentVatDataRepository.php:86,93-97,104` whitelists
  `supplier_credit_note` on the INPUT side, negated at aggregation only. GL/snapshot magnitudes tie:
  `SupplierCreditNotePostingService.php:357-368` sums Σ `recoverable_tax_amount` →
  `GeneralLedgerService.php:2427` `bcround` at scale → Cr 4456; the builder sums the same column over the
  same `$creditNote->lines`. Netting proven end-to-end (`…DeclarationTest.php:392-436`, deductible
  85.500, net 104.500).
* **F4 ADDRESSED** — one documented position, and it is the *live* one:
  `BackfillTaxDetailsCommand.php:1059-1092` puts cancelled-WITH-a-journal-entry in scope
  (`journal_entries.source_id = documents.id AND source_type = documents.type`), cancelled-without out.
  Underlying gap ledgered `D-B19-3`, not silently diverged on.
* **F5 ADDRESSED** — the declared figure is Σ `recoverable_tax_amount`, and `divergences()` check 3
  (`PostedLineTaxSnapshotBuilder.php:277-284`) REFUSES the moment it differs from `line_tax_amount`.
  Tested: `BackfillTaxDetailsCommandTest.php:1490`.
* **F6 ADDRESSED** — check 4 (`:295-303`) refuses rate-less-with-VAT; `hasNothingToDeclare()` (`:323-326`)
  gives genuinely zero-VAT purchases their own census bucket, so nothing re-enters scope forever.
  Tested `:1523`, `:1538`.
* **F7 ADDRESSED** — `supplierDocumentPopulation()` (`BackfillTaxDetailsCommand.php:1008-1041`) is a true
  partition: `already` (any status) + the four disjoint status branches over the non-snapshotted
  remainder. `documents.status` is NOT NULL default `'draft'`
  (`2025_11_30_080000_create_documents_table.php:19`) and `DocumentStatus` has exactly six cases, so
  `Confirmed`/`Received` are now caught by `other`. Tested `:1621-1637` with the exact printed string.
* **F8 ADDRESSED** — `accumulateByCurrency()` (`:1102-1123`) keys on `currency|rate` and sums at that
  currency's own scale; `:918-935` prints one TOTAL per currency. See N8.
* **F9 ADDRESSED** — closed structurally on the purchase arm (the builder emits line tax only and hardcodes
  `isStampDuty: false`, `:179`; `documentTaxTotal: '0'`, `:196`), residual config hole ledgered `D-B19-2`.
* **F10 ADDRESSED (both)** — `postableSupplierInvoice()` now runs the real
  `CreateSupplierInvoiceService::create()` (`…DeclarationTest.php:503-555`), and the divergent-rounding
  case is driven through `post()` at `:294`.
* **F11 ADDRESSED** — `D-B19-1` + `S-23` + checklist §3. See N6 on the evidence path.

### New findings in the fix diff

#### [IMPORTANT] N1 — the period guard runs BEFORE the idempotency no-op on the supplier-invoice arm
`SupplierInvoicePostingService.php:101-105` vs `:149-157` · `SupplierInvoiceController.php:344-348`

The controller deliberately performs no status pre-check and says so: *"A pre-check here would prevent
idempotent retries: on a second POST the invoice is already posted … so assertPostable() sees over-clear
and would return 422 instead of the no-op the service already handles under lock."* The new guard is
inserted at `:101`, ahead of that no-op at `:149`. `assertInvoiceFirstApproval()` (`:619-624`) returns
immediately unless invoice-first approval is configured, so nothing else short-circuits first. Result: a
retried/replayed `POST /supplier-invoices/{id}/post` on an already-posted invoice returns
`PERIOD_CLOSED`/`PERIOD_FILED` 422 once that month closes, instead of the documented 200 no-op — a
declaration-neutral call refused for a declaration reason.

The credit-note arm in this same commit gets it right: its step-0b status guard
(`SupplierCreditNotePostingService.php:170-186`) returns early for Posted-with-entry *before* the period
guard at `:201`. Fix: mirror that — a non-locking `JournalEntry::…exists()` short-circuit ahead of the
guard on the invoice arm. `test_reposting_does_not_duplicate_the_input_vat_snapshot`
(`…DeclarationTest.php:208`) re-posts in an OPEN period and cannot catch this.

#### [IMPORTANT] N2 — the period refusal is unrecoverable for a supplier invoice, and it blocks the AP ledger, not just the declaration
`SupplierInvoicePostingService.php:101-105` · `Procurement/Presentation/routes.php:84-120`

The guard's own contract justifies its use on the return-note path precisely because the refusal is
recoverable: *"its refusal is recoverable by a single `PATCH` of the draft's `document_date`
(`ReturnNoteController::update()`, gated on `isDraft()`)"* — `VatPeriodBackdatingGuard.php:29-34`. That
premise does not transfer. `routes.php:84-120` exposes index / duplicate-reference / show / store / match
/ link-receipts / post — **no update, no PATCH, no delete**. A supplier invoice whose supplier-supplied
`issue_date` lands in a CLOSED or FILED month (the ordinary AP case: last month's invoice arrives after
close) is now permanently stuck in Draft, with no way to correct the date and no way to remove it. And the
refusal blocks the whole `post()`, i.e. the `Dr 408 / Dr 4456 / Cr 401` recognition — a bookkeeping
obligation that exists independently of the VAT declaration.

The guard also consults `fiscal_periods` Closed/Locked (`VatPeriodBackdatingGuard.php:78-84`), widening the
block to any tenant that closes accounting months. The fixture edits in this same commit demonstrate the
sibling trap: `SupplierInvoiceReceiptClearingTest.php:768` had to gain `'tax_rate' => '19.00'` because a
VAT-bearing line with a NULL rate now throws at `divergences()` check 4 — a legacy/imported invoice in that
shape is likewise permanently unpostable with no correction path.
r1 asked for "the guard, or an explicit owner ruling". The guard shipped; the ruling on the trapped-document
consequence has not. Wanted: a `document_date` correction path on Draft, or refusal at CREATE time so the
operator is never handed a dead document, or an owner-accepted ledger row naming the trap.

#### [IMPORTANT] N3 — AR/AP opening documents flood the backfill census with false "needs manual review"
`ArApOpeningService.php:355-375` · `BackfillTaxDetailsCommand.php:753-762, 938-945`

`ArApOpeningService` creates `SupplierInvoice` / `SupplierCreditNote` documents with
`status => DocumentStatus::Posted`, `is_historical => true`, `subtotal => total`, `tax_amount => '0.00'`
and **no `DocumentLine` rows**, each with its own opening journal entry. They therefore satisfy the leg's
scope (Posted, zero tax-detail rows), land in `in_scope`, and hit two `divergences()` reasons at once —
`'lines sum to 0 but the stored subtotal is X'` (`PostedLineTaxSnapshotBuilder.php:261-267`) and
`'no document lines'` (`:305-307`) — printing one
`SKIPPED … -- needs manual review, NOT backfilled` line per opening document.

Nothing wrong is written, but `S-23` and checklist §3 both instruct the owner to *"review the printed
per-tenant census first"* before `--apply`. On any tenant that ran an AP opening batch — a first-tenant
launch flow — that census is the noise. `is_historical` (`Document.php:155,196`, `isHistorical()` at
`:598-601`, migration `2025_12_11_100001_add_is_historical_to_tables.php`) is an unambiguous discriminator:
a historical opening's VAT was declared under the previous system and is by definition not declarable here.
Give it its own census bucket rather than the manual-review warning list.

#### [MINOR] N4 — the builder accumulates at a coarser scale than the GL legs it claims to reproduce
`PostedLineTaxSnapshotBuilder.php:127,146-147` vs `SupplierInvoicePostingService.php:299` /
`SupplierCreditNotePostingService.php:366`

Both GL legs accumulate Σ `recoverable_tax_amount` at `scale+4` / `scale+1` and round ONCE
(`GeneralLedgerService.php:2427`). The builder `bcadd`s at `$scale`, which truncates every partial sum.
`recoverable_tax_amount` is `decimal(N,3)`, so under a scale-2 currency two lines of `0.005` give GL
`0.01` and the builder `0.00`. Check 3 then refuses rather than mis-declaring — but that is a new
unpostable shape, and it contradicts the class's stated contract ("no rounding decision of its own",
`:46-48`). Not reachable through `CreateSupplierInvoiceService` (it rounds each line to `$scale`,
`:94,101`); an import/legacy concern. Sum at `scale+1` and round once, like the legs.

#### [MINOR] N5 — writer and backfill can still disagree, on an empty/NULL-currency document
`PostedLineTaxSnapshotBuilder.php:96-105`

`scaleFor()` falls back to `getScaleSafe(null, 3)` when `currency` is empty. `CurrencyScaleResolver.php:74-80`
returns the fallback **only** on `UnboundCompanyContextException` — so the same document resolves scale 3
in the console backfill (no `CompanyContext`) and the company's `country.currency_decimal_places` in the
HTTP writer (`:44-63`). Every `bccomp` in `divergences()` is taken at that scale, so the shared
implementation can still return different answers in the two callers. Narrow (requires a null/empty
`currency`, which `CreateSupplierInvoiceService` never produces), but it is the exact property the fix
claims to have made impossible.

#### [MINOR] N6 — every new LEDGER row cites an evidence file that cannot exist in the repo
`LEDGER.md:29,30,31,72` · `PROMOTION-CHECKLIST-2026-08-26.md` §3

`D-B19-1`, `D-B19-2`, `D-B19-3` and `S-23` all point at `.superpowers/sdd/PLAN/task-6-report.md`.
`.superpowers/sdd/.gitignore` is `*`, so nothing under it is tracked; and no such file exists — the file at
`.superpowers/sdd/task-6-report.md` in the worktree is a **different lane's** report (the frontend
permissions-map drift guard). The rows are self-contained enough to act on (each carries its own
`file:line`), so this is traceability only — but the Evidence column is dead for the next reader, and
`S-23` is an owner-executed step. Point them at this review file or at an in-repo B-19 report.

#### [MINOR] N7 — `VatPeriodBackdatingGuard` docblock is stale as of this commit
`VatPeriodBackdatingGuard.php:50-53` still says the declaration reads
"invoice / credit_note / expense / supplier_invoice (the last added by B-19, 2026-08-26)".
`EloquentVatDataRepository.php:86` now also reads `supplier_credit_note`.

#### [MINOR] N8 — the per-currency census fix (F8) has no multi-currency test
`BackfillTaxDetailsCommandTest.php` asserts `TOTAL deductible VAT delta (TND): 38.000` and the negated
`-38.000` for a supplier credit note, both single-currency. Nothing exercises two currencies producing two
TOTAL lines — the shape F8 existed to prevent. The `bcsub` negation at
`BackfillTaxDetailsCommand.php:872-875` is otherwise correct and covered.

### Test quality
`RefreshDatabase`, real models, real services, no mocked subject, concrete money assertions throughout.
The strongest additions: `test_snapshot_carries_the_vat_the_gl_posted_when_per_line_rounding_diverges`
(ties the snapshot to the actual 4456 journal line, not merely to a non-engine number),
`test_supplier_leg_census_buckets_partition_the_population` (asserts the exact printed partition string),
and `test_supplier_leg_refuses_a_line_with_a_non_recoverable_share`. No `assertTrue(true)`, no faked
payloads. The r1 tautology is closed.

### What to fix before merge (r2)
N1 (short-circuit the already-posted invoice before the period guard, as the credit-note arm already does);
get an owner ruling or a ledger row for N2 (a back-dated or NULL-rate supplier invoice is now permanently
unpostable with no correction route) and N3 (AP openings poison the census the owner is told to review).
