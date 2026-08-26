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
