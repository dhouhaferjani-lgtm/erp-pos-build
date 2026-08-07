# ROUND-2 RE-GATE — R2-F1 `fix/r2f1-cancel-period-refusal` — taxation / VAT-period axis

**Verdict: CLEAR TO MERGE.** Mode: READ-ONLY (no file created/modified/checked out; tree clean before and after). Branch @ `13ab7565b`, 14 commits, +1674/−8.

## Merge conditions — all closed, verified read-only
1. **I-1 boundary CLOSED:** Carbon binding (`EloquentVatPeriodRepository.php:18-30`, precedent form, inline SQLite-cause comment); red evidence matches the round-1 probe prediction exactly incl. the end-day-by-accident detail; 3 tests: start refused (`:276`), end refused (`:302`), over-reach pin (`:328` — days around a locked September stay cancellable). Both directions covered.
2. **I-2 declaration overclaim CLOSED, no residual:** both sites rewritten to AP/trial-balance-integrity grounding + forward compatibility; `Expense`-is-the-only-declared-purchase-type nuance added; re-grep found no residual "same filed declaration" phrasing.
3. **I-3 citation CLOSED, over-delivered:** five stamp sites now cited, every one verified (`AccountingService:441`, `:593`; `GeneralLedgerService:146`, `:230`; GR-IR `:1921`); explicit warn-off of dead `createSupplierInvoiceJournalEntry():684` correct on both counts.
4. **I-4 narrowing CLOSED:** locked set = Invoice/CreditNote + SupplierInvoice/SupplierCreditNote/Expense; PO/PQR removed. Exclusions re-verified against the 12-case enum and the writers: COGS posts via the invoice listener (`PostCOGSOnInvoice.php:73`), not delivery notes; `document_tax_details` has exactly two writers (`TaxCalculationService:484`, `ExpenseService:576`); SupplierCreditNote justified via its posting service. 6 pinned exclusion cases; SalesOrder correctly absent (short-circuits pre-guard at `DocumentPostingService.php:135` — structurally unpinnable).
5. **Relay notes CARRIED FAITHFULLY:** headed "inputs for the expert, NOT rulings"; all three points with code grounding intact; the hard c2-cannot-precede-F2 constraint present and duplicated at the flip seam itself (`:150-158`) — where a flipper will actually look. `AccountingService.php:832-835` verified.
6. **Minors closed:** m-3 overlap pin (genuinely overlapping spans, CLOSED inserted first, guards the `orderBy('status')` refactor by name) · m-7 vacuous AP assert removed + NOTE explaining unfalsifiability + full envelope asserted (`document_number`/`period_label`/`period_status` + message contains both) · m-5 runbook section leads the residuals ticket (contiguous `generatePeriods()`, pre-filing gap assertion, backfilled-document exposure) · m-2 can-cancel FIXED rather than ticketed (single `lockedPeriodFor()`, boolean semantics preserved, `reason_code` additive, 4 tests incl. read-model-predicts-endpoint-code).

Bonus: the reverseDocumentGl-bypasses-fiscal-period docblock + F2 ticket verified both halves; correctly scoped out.

## New finding
### m2-1 (minor, non-blocking) — Income described as posting no journal entry; it does
`VatPeriodCancellationGuard.php:123-127` + provider case `:485`. `IncomeService.php:162` → `createFromIncome()` (`:4056`). No behavioural consequence today (cancel unreachable for Income; reverses nothing; absent from declaration) — the test passes for the right reason, only the justification is wrong. Either correct the wording or move Income into the lock; whichever, it must not stand on a false premise. **(Superseded by the orchestrator ruling adopting the GL gate's I-5 fix: Income moves into the locked set in round 3.)**

## Tests: lane file OK 25/65 (exactly the predicted count); + VatPeriodControllerTest combined OK 34/90.
