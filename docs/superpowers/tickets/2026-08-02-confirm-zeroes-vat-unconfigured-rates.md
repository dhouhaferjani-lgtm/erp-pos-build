# ✅ CLOSED 2026-08-02 — fixed by 7258a409f (documents fix lane), both arms; gate-verified (docs/superpowers/reviews/2026-08-02-documents-fixlane-gate.md: "Both parent-ticket arms are closed", tests at :155/:245/:303; configured rates byte-identical; no injection hole — 0-100/2dp ceilings on every ingress)

# Ticket: confirm() silently ZEROES VAT for any line whose rate has no active TaxConfiguration row (invoices) — and likely for quotes/orders entirely

From the W1b money-campaign defect-fix lane (2026-08-01, docs/sessions/W1B-DEFECT-FIXES-REPORT.md
"New findings" 1–2). Discovered while fixing the draft stamp-duty defect (18e61a554); deliberately
NOT fixed there — the fix consumes only `documentTaxTotal` precisely to avoid this trap.

## Finding 1 — invoices, VERIFIED by code + existing tests (money-affecting)

`TaxCalculationService::calculateDocumentTaxes()` STEP 1 only accumulates line tax for a rate it
can match to an active LINE_ITEMS `TaxConfiguration` row for that country + fiscal category + date
(apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:104-157). An unmatched
rate contributes NOTHING. `InvoiceController::confirm()` then writes
`tax_amount = $taxResult->totalTax` unconditionally.

Reachable: the API accepts an arbitrary per-line `tax_rate`
(`DocumentLineTaxResolver::resolveTaxRate()` returns a caller-supplied rate verbatim). A draft
carrying real VAT on an unconfigured rate has that VAT silently dropped at confirm — the document
total SHRINKS on confirmation. Proof-shaped fixtures already exist:
`DocumentLineTaxConfigurationResolutionTest` (explicit 13.00 rate, no TN config row),
`CreateDocumentTest` / `InvoiceDocumentTest` (tenant with zero tax_configurations).

## Finding 2 — quotes / sales orders, CODE-READ ONLY (needs live verify before severity)

Both are `FiscalCategory::NonFiscal`. `TaxConfiguration::scopeForDocumentType()` only matches rows
whose `applicable_document_types` contains the token (or is empty); the TN/FR seeders list
`TAX_INVOICE`/`FISCAL_RECEIPT`/`CREDIT_NOTE`/`DELIVERY_NOTE` (FR also `QUOTATION`, which is not a
`FiscalCategory` token and can never match). So `applicableTaxes` is empty for a quote and
`QuoteController::confirm()` / `SalesOrderService` write `tax_amount = 0` — confirming a quote or
order would zero its VAT entirely. NOT verified live (campaign MTP-DOC-06 was BLOCKED). Verify
against demo-pharmacy-tn before ticketing severity.

## Disposition

- Same root cause both arms: unmatched rate ⇒ silent zero instead of pass-through or refusal.
- Candidate fix direction (needs a ruling): STEP 1 should either fall back to the line's explicit
  `tax_rate` when no configuration row matches, or confirm() should REFUSE (422) a document whose
  lines carry unmatchable rates — silent zeroing is the only unacceptable branch.
- Draft-vs-confirm consistency: 18e61a554 made draft totals match confirm for every CONFIGURED
  rate; whatever fix lands here must keep that identity (the residual divergence today exists only
  where confirm() is itself wrong).
- Money-affecting; pre-launch triage recommended before tenant #1 onboarding.
