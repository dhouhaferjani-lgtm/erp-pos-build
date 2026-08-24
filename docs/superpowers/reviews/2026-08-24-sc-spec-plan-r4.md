# Session C spec+plan adversarial review r4

## Verdict line

CHANGES-REQUESTED

## Findings

**F-59 · Critical · area C/E/I** — The allocation boundary remains default-open for payment-inapplicable document types. SPEC marks Quote, RFQ, DeliveryNote, ReturnNote and CorrectingEntry payment `n/a`, and gives Expense/Income metadata-only settlement (`SPEC-document-lifecycle-dimensions.md:28-43`). N-6’s classifier nevertheless returns `ReceivableClearing` from its default arm for every unlisted type (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Treasury/Application/Services/DocumentAllocationClassifier.php:65-84`), and the direct payment endpoint accepts any company-scoped document UUID before invoking it (`PaymentController.php:401-405`; branch `PaymentController.php:1025-1039`). C-0 names only PurchaseOrder refusal (`PLAN-phase2-3-P-R.md:23`). Consequence: confirmed Quote/RFQ/DN/RN and posted Expense/Income/CorrectingEntry/SCN can receive customer cash-in/411 allocations despite `payment_status=not_applicable` or outward-credit semantics. Required change: make classification exhaustive and default-refuse; explicitly admit only Invoice+posted, Invoice+confirmed, SalesOrder+confirmed, and the separately guarded SupplierInvoice path. Expand C-0 into `C-0a DocumentPaymentApplicability` with a 13-type × lifecycle rejection matrix and exercise every payment entry point.

**F-60 · Critical · area C/E/I** — C-2a’s `paid` migration would legitimize already-corrupt paid CreditNotes. CreditNote is currently eligible for lifecycle Paid (`DocumentType.php:123-128`), and the direct payment path accepts a bare CreditNote ID, subtracts its balance and writes Paid (`PaymentController.php:401-405,1012-1030`). Such a CN was previously posted and therefore normally has a fiscal hash, so C-2a’s only named anomaly—“fiscal + NULL hash”—does not catch it (`PLAN-phase2-3-P-R.md:25,62-63`). Consequence: a backwards customer cash-IN/Cr-411 transaction becomes `status=posted,payment_status=paid`, permanently presenting bad GL as valid settlement. Required change: C-2a must census `paid` by type and ledger footprint. Every paid CN must be quarantined unless its outward settlement is proven by application/refund facts; wrong-direction payment allocations require an explicit reversal/reclassification disposition before the CHECK is tightened. Add `PaidCreditNoteMigrationRefusalTest` on PG.

**F-61 · Critical · area B/F/H** — The TN authority model violates the binding fail-closed ruling. The confirmed model says TN Posted requires both seal and Ministry QR (`BRIEF-N6-payment-advance.md:8-12`), and OQ-15’s fail-safe is to refuse posting until acceptance (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:12`). Yet the normative dimension row omits authority state (`SPEC-document-lifecycle-dimensions.md:17`), TN is seeded `not_required` with “pending activation” (`SPEC-document-lifecycle-dimensions.md:71-72`), and activation remains outside the strict chain as F-2 (`SPEC-document-lifecycle-dimensions.md:121-123`; `PLAN-phase2-3-P-R.md:91`). Consequence: TN invoices can reach lifecycle Posted, close, print and report without the QR condition the owner defined as part of Posted. Required change: add `fiscal_authority_status` to the normative fiscal storage/completion predicate, prohibit the TN `not_required` seed, and either bring QR acquisition/acceptance into the strict chain or block TN `confirmed→posted` until F-2 lands. Anything else needs an explicit owner override of OQ-15.

**F-62 · High · area A/C/E** — `payments.side` cannot determine cash direction as specified. SPEC says repository direction derives from `customer|supplier` side (`SPEC-document-lifecycle-dimensions.md:67-70`), but customer Invoice settlement is cash IN while customer CreditNote refund is cash OUT; supplier Invoice payment is OUT while supplier CreditNote refund is IN. Historical imports compound this because AP batches may contain both Invoice and CreditNote (`ArApOpeningService.php:159-170,306-328`). The current binary runtime branch demonstrates the unsafe assumption (`PaymentController.php:1225-1230`). Consequence: C-H0/C-Q12 can fix AP invoices while reversing historical AP credit notes and future CN/SCN refunds. Required change: define direction from `(counterparty_side, settlement_operation)`—for example `collection`, `vendor_payment`, `customer_refund`, `vendor_refund`, `non_cash_application`—not side alone. Fail closed for historical credit notes until application/refund behavior exists. Add four-quadrant AR/AP Invoice/CreditNote GL and movement tests.

**F-63 · High · area C/F/I** — N-6 leaves an allocation writer that can mark a posted invoice settled without clearing 419 to 411. `MultiPaymentService::applyDepositToDocument()` creates the allocation and may mark the document Paid, but writes no clearing JE (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:273-334`). The planned recompute port would merely cache that wrong settlement (`PLAN-phase2-3-P-R.md:26,64`). Consequence: `payment_status=paid` while customer advance and receivable both remain outstanding in GL. Required change: add a prerequisite treasury lane that locks deposit and document, clears Dr 419/Cr 411 immediately for `ReceivableClearing`, records an idempotent clearing identity, then invokes recompute. Test posted-invoice application, confirmed-invoice deferral, replay and reversal.

**F-64 · High · area B/F/I** — The VAT-free proforma correction has no implementation lane. N-6 deliberately ships VAT normally on confirmed-unposted output (`BRIEF-N6-payment-advance.md:45-48`), whereas the expert ruling says VAT mention creates liability and recommends VAT-free proforma output (`expert-rulings-deferred-revenue-vat-issuance.md:12-21`). SPEC changes the rule (`SPEC-document-lifecycle-dimensions.md:71`), but PLAN contains no print/proforma lane or test; the only PLAN hit for printing is C-2a’s migration “printed” quarantine list (`PLAN-phase2-3-P-R.md:62`). Consequence: merging N-6 leaves the known Art. 18 exposure live indefinitely. Required change: add `C-F0` immediately after N-6 to replace the marker-only behavior with explicitly non-definitive, VAT-free output across HTML/PDF/templates and translations; test that no VAT label, amount, seal, hash or QR appears before posting.

**F-65 · High · area G/F** — A hardcoded sales policy choice survives the “seeded per country, nothing hardcoded” mandate. The resolver explicitly falls back to `PreDeliveryInvoicingPolicy::systemDefault()` when no country row exists (`PreDeliveryInvoicingPolicyResolver.php:22-28,89-95`), and that method hardcodes `require_delivery_first` (`PreDeliveryInvoicingPolicy.php:51-58`). The seeder also states that a missing country silently uses this fallback (`CountryDocumentSettingsSeeder.php:38-52`). PLAN’s country-policy work is scoped to procurement (`PLAN-phase2-3-P-R.md:51-52`). Consequence: an unseeded country acquires a legal invoicing policy from code, contrary to the owner’s one-authority requirement. Required change: extend C-3b1 to remove this fallback; missing `country_document_settings` must raise a typed configuration refusal. Keep conservative values as seeded rows, including provisional rows, not enum behavior.

**F-66 · High · area D/F/I** — POS `line_kind` has no immutable source. SPEC says it is imported from the sealed receipt (`SPEC-document-lifecycle-dimensions.md:35,86`), but the signed line-item schema has an exact key set with no `line_kind` (`FiscalPayloadConstraintValidator.php:2341-2368`). The account-charge draft then deliberately discards `product_id` (`POSAccountChargeDraftService.php:75-94`). C-R1a only adds a column and three POS tests (`PLAN-phase2-3-P-R.md:34,75`). Consequence: implementation must either infer kind from mutable catalogue data or alter a fiscal payload/projection surface that the plan does not own; historical POS invoices remain unclassifiable. Required change: specify an immutable provenance source—versioned signed payload, or an already-persisted receipt/stock-movement decision—and add its producing lane/dependency. Include backfill/quarantine rules and prove ordinary, mixed, service and legacy account-charge rows.

**F-67 · High · area A/E/F** — `createDraft()` as the only creation boundary omits legitimate posted reversal documents. Expense reversal directly creates a new Expense at Posted (`ExpenseService.php:841-853`), while C-2c-c1 lists only Draft creation, historical Posted creation and generic two-hop creation (`PLAN-phase2-3-P-R.md:45`). Consequence: enforcement either blocks linked-cost reversal or routes it through normal Expense posting, duplicating number/GL/treasury side effects. Required change: add an explicit `createPostedReversal()`/`createReversalDocument()` API with its own guarded provenance and no normal posting side effects, or model the two hops inside the reversal transaction. Add a linked-cost reversal regression asserting exactly one reversal JE and transition history.

**F-68 · High · area B/C/F/I** — C-X0 checks the wrong accounting-period concept relative to OQ-35. The owner default refuses when the posted document’s accounting period or VAT period is closed (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:33`). SPEC instead names only “the reversal JE’s period” (`SPEC-document-lifecycle-dimensions.md:99-103`), and current reversal entries are deliberately dated `now()` (`AccountingService.php:1137-1151`). Consequence: a document whose original accounting period is closed can still be cancelled whenever today’s period is open, which is precisely the current-period-reversal policy the owner did not authorize. Required change: require three explicit checks where applicable: original VAT period, every original/correcting JE’s accounting period, and the new reversal JE period. Tests must distinguish original-closed/current-open from original-open/current-closed.

**F-69 · High · area F/I** — SPEC r3 and PLAN r3 are not self-contained implementation objects. Normative behavior is repeatedly replaced by “As r2” (`SPEC-document-lifecycle-dimensions.md:75,94,100,106,110,117`; `PLAN-phase2-3-P-R.md:56-79,84-91`), but no versioned r2 spec/plan path is named; the current session files overwrite that content. Consequence: implementers cannot recover transition guards, settlement formulas, reversal requirements or red-first tests from the approved artifacts without Git archaeology. Required change: inline every retained r2 clause and test into r4, or commit immutable `SPEC-...-r2.md`/`PLAN-...-r2.md` artifacts and link exact sections. No lane should dispatch against “as r2.”

**F-70 · High · area E/F/I** — C-Q12’s side migration and writer coverage are under-specified. PLAN says repair comes “from allocation targets” and names only two tests (`PLAN-phase2-3-P-R.md:60-61`), but production has payment rows with no document allocation, including payment-on-account (`MultiPaymentService.php:409-425`) and POS receipt projections (`TreasuryReceiptBridge.php:1399-1422`), plus refunds and reversals. Consequence: a NOT NULL side backfill must guess, leave NULLs, or install a hardcoded default; later writers can omit the new field. Required change: give C-Q12 an executable census of every `Payment::create` writer, a provenance ladder for unallocated/POS/refund/reversal rows, quarantine on ambiguity, DB CHECK/parity coverage, and one fixture per production writer.

**F-71 · High · area B/E/F** — C-8 adds `posting_journal_entry_id` without specifying repair of existing non-fiscal Posted rows. SPEC makes that field the immutability fact (`SPEC-document-lifecycle-dimensions.md:93-97`), but PLAN’s C-8 row names only “column + trigger” (`PLAN-phase2-3-P-R.md:47`) and no backfill/census. Existing SupplierInvoice posting proves a source JE exists independently (`SupplierInvoicePostingService.php:116-124,276-292`); Expense and Income likewise post before this column exists (`ExpenseService.php:309-340`; `IncomeService.php:145-178`). Consequence: legacy Posted rows retain NULL and remain mutable after the advertised trigger widening. Required change: C-8 must backfill exact JE identities by type/source contract, quarantine ambiguous/missing footprints, abort on unresolved eligible rows, and test pre-migration SupplierInvoice/Expense/Income plus cancellation.

**F-72 · Medium · area A/D/I** — DeliveryNote closure reintroduces a cross-line aggregate. SPEC closes a DN when total confirmed return quantity is at least total DN quantity (`SPEC-document-lifecycle-dimensions.md:37`), although the canonical fulfilment model is per `(product,location)` (`SPEC-document-lifecycle-dimensions.md:80-86`) and current return correctness depends on product/location attribution (`DeliveredQuantityResolver.php:438-540`). Consequence: heterogeneous lines/UOMs can be substituted in the closure calculation even when canonical fulfilment would not call every tuple returned. Required change: define “fully returned DN” as every physical tuple exhausted by the canonical aggregate; `DnClosureBillingMarkOrFullReturnTest` must include a cross-product deficit case.

**F-73 · Medium · area E/F** — C-C0 treats `valid_until` as a new column although it already exists. The original documents migration created it (`2025_11_30_080000_create_documents_table.php:19-24`), while PLAN lists “RFQ `valid_until` column” in its migration (`PLAN-phase2-3-P-R.md:31,68`). Consequence: a literal implementation attempts duplicate DDL or wastes the lane’s single migration on an already-existing field. Required change: say “backfill RFQ payload `validity_date` into existing `documents.valid_until`”; add a guarded data migration and preserve Quote readers.

**F-74 · Medium · area F** — Multiple lanes still violate the plan’s one-day/one-invariant rule. C-H0 combines migration/backfill, treasury classification, GL, statements, ageing and reversal (`PLAN-phase2-3-P-R.md:22,57-59`); C-C0 combines three header facts, line provenance, converters and a scheduled command (`PLAN-phase2-3-P-R.md:31,68-70`); C-R1b combines CN settlement, cancel enforcement and VAT reporting/delta (`PLAN-phase2-3-P-R.md:41`); P-3 is a whole SCN product lifecycle (`PLAN-phase2-3-P-R.md:85`); C-3b4 combines recognition, reversal and CN proportionality (`PLAN-phase2-3-P-R.md:54`). Consequence: gates cannot isolate regressions, and hot-file collision claims become aspirational. Required change: split these as set out under Lane resequencing below.

**F-75 · Medium · area I** — The named tests still do not prove the new r3 claims. PLAN lacks tests for default-refusal across the 13-type payment matrix, paid-CN migration quarantine, four-quadrant cash direction, deposit-to-posted-invoice 419 clearing, VAT-free proforma, TN QR fail-closed behavior, every payment-side writer, signed `line_kind` provenance/backfill, Expense reversal creation, original-vs-current accounting periods, C-8 JE backfill, and DN cross-line closure (`PLAN-phase2-3-P-R.md:56-79`). Required change: add each named regression to its owning lane before dispatch; references to unspecified r2 tests do not satisfy this gate.

## r1+r2+r3 findings disposition

| Prior | Status | Current evidence |
|---|---|---|
| F-1 | RESOLVED | The universal closure predicate identified at `2026-08-24-sc-spec-plan-r1.md:9` is replaced by the per-type table and recompute rules (`SPEC:28-62`). |
| F-2 | PARTIAL | Outward CN and opening bases exist (`SPEC:36,43,74-78`), but historical credit direction and paid-CN repair remain wrong—F-60/F-62. |
| F-3 | RESOLVED | The rewrite keeps Expense and gates the grouped CN VAT correction on owner delta acknowledgement (`SPEC:116-119`; `PLAN:41`). |
| F-4 | PARTIAL | Four-chain/VOIDED/sealed-time semantics exist (`SPEC:93-97`), but their operative details remain hidden behind “As r2”—F-69. |
| F-5 | OPEN | PO refusal exists only after C-H0 and the classifier remains default-open (`PLAN:22-24`; branch classifier `:65-84`)—F-59. |
| F-6 | RESOLVED | Shared fulfilment schema and root lanes precede P/R consumers (`PLAN:30-36`). |
| F-7 | PARTIAL | Receipt allocation ledger and SI cancel lane exist (`PLAN:36,40`), but exact reversal remains only “per r2” (`SPEC:105-107`). |
| F-8 | PARTIAL | All 13 types are tabled (`SPEC:26-43`), but Expense reversal creation and historical credits are omitted—F-62/F-67. |
| F-9 | PARTIAL | Canonical root/locking/cost are specified (`SPEC:80-91`), but POS kind provenance and DN closure remain incomplete—F-66/F-72. |
| F-10 | RESOLVED | Procurement uses country defaults plus explicit company source/override (`SPEC:109-114`; `PLAN:51-52`). |
| F-11 | PARTIAL | Draft-only creation and raw census are named (`PLAN:45-46`), but the reversal creation exception remains absent—F-67. |
| F-12 | PARTIAL | Stamp/residual is now in the 472 matrix (`SPEC:109-114`), but retained recognition/reversal details are not self-contained and C-3b4 is oversized. |
| F-13 | PARTIAL | SPEC chooses VAT-free proforma (`SPEC:71`), but no lane reverses N-6’s VAT-visible output—F-64. |
| F-14 | OPEN | TN is still seeded `not_required` pending an out-of-chain activation (`SPEC:71-72,121-123`)—F-61. |
| F-15 | RESOLVED | Locked payment edge and immutable event behavior remain normative (`SPEC:74-78`; `PLAN:27,64`). |
| F-16 | RESOLVED | `overpaid` is defensive-only (`SPEC:74-78`). |
| F-17 | RESOLVED | C-2a and C-D0a own successive CHECK versions; final Slice-D parity is in C-D0a (`PLAN:25,30`). |
| F-18 | OPEN | C-H0, C-C0, C-R1b, P-3 and C-3b4 remain oversized—F-74. |
| F-19 | RESOLVED | `in_payment` is excluded with `has_unreconciled_payments` retained pending owner ruling (`OWNER-DECISIONS:9`; `SPEC:74-78`). |
| F-20 | PARTIAL | Several tests were added, but the claims listed in F-75 remain unproved. |
| F-21 | RESOLVED | Expense/Income use metadata and emit no paid event (`SPEC:41,74-78`; `PLAN:27`). |
| F-22 | RESOLVED | Immutable receipt-slice ledger precedes SI fulfilment/reversal (`SPEC:105-107`; `PLAN:36,40`). |
| F-23 | RESOLVED | Closure terminal conditions, cancellation and expiry command are now explicit (`SPEC:28-62`). |
| F-24 | RESOLVED | Sales-CN base is ex-stamp and shared across applications/refunds (`SPEC:36,77-78`). |
| F-25 | PARTIAL | One recompute port is specified (`SPEC:48-53`), but the current PLAN does not actually list its promised executable writer census and misses deposit GL—F-63. |
| F-26 | RESOLVED | Advisory serialization and unique company/type sequence are normative (`SPEC:93-95`; `PLAN:37`). |
| F-27 | PARTIAL | Backfill precedence/quarantine/frozen `sealed_at` are named (`PLAN:38`), but operative rules remain only “As r2”—F-69. |
| F-28 | PARTIAL | Persistent seal facts cover cancelled rows/lines/deletes in principle (`SPEC:95-97`), but JE backfill is absent—F-71. |
| F-29 | RESOLVED | SupplierInvoice VAT snapshot/backfill is isolated in P-4 (`PLAN:86`). |
| F-30 | RESOLVED | Procurement inherited/override state and missing-row refusal are specified (`SPEC:109-114`; `PLAN:51-52`). |
| F-31 | RESOLVED | C-2c-a owns transaction, lock and transition log (`PLAN:43`). |
| F-32 | RESOLVED | Root authority, lock order, quarantine, indexes and cost test are explicit (`SPEC:80-91`; `PLAN:32,71-72`). |
| F-33 | RESOLVED | Receipts remain irreversible in the strict chain; P-5 owns reversal (`SPEC:91`; `PLAN:87`). |
| F-34 | RESOLVED | The compliance gate is policy-aware and 472 handles partial fulfilment (`SPEC:91,109-114`). |
| F-35 | OPEN | Pending activation currently permits TN posting without authority acceptance—F-61. |
| F-36 | RESOLVED | DeferredRevenue purpose, TN mapping, provisioning and refusal have an owning lane (`PLAN:50`). |
| F-37 | RESOLVED | CorrectingEntry is SEALED immutability-only and outside document hash chains (`SPEC:42,93-97`). |
| F-38 | PARTIAL | Program lanes now have dependencies/migrations/tests (`PLAN:81-92`), but P-3 and several “as r2” lanes remain non-executable/oversized. |
| F-39 | PARTIAL | Exact four-value TS contract and FE census are named (`PLAN:28,64`), but POS `line_kind` and ordinary-line migration are incomplete—F-66. |
| F-40 | PARTIAL | The rewritten test list is stronger, but F-75 enumerates remaining proof gaps. |
| F-41 | RESOLVED | SO/PO/DN closure now uses per-type durable facts and free quantities (`SPEC:32-38,55-62`; `PLAN:31,48,77-78`). |
| F-42 | PARTIAL | `opening_side` is persisted (`SPEC:43`; `PLAN:22`), but side alone mishandles AP/AR credit notes—F-62. |
| F-43 | RESOLVED | Billing marks and line provenance precede legacy source fallback; ambiguity quarantines (`SPEC:80-85`). |
| F-44 | PARTIAL | Scheduled expiry and persistent facts are selected (`SPEC:60-62`), but C-C0 incorrectly treats existing `valid_until` as new—F-73. |
| F-45 | RESOLVED | Only the boolean is retired; `goods_received_at` is preserved/promoted with reader tests (`SPEC:87-90`; `PLAN:30,65-67`). |
| F-46 | RESOLVED | 472 matrix now includes stamp/rounding absorber and balance assertion (`SPEC:109-114`; `PLAN:53,79`). |
| F-47 | PARTIAL | C-X0 adds dual guards (`PLAN:39,76`), but it checks reversal period rather than the owner-required original accounting period—F-68. |
| F-48 | RESOLVED | Historical openings receive frozen `posted_at`, and the trigger predicate includes the historical fact (`SPEC:43,95-97`; `PLAN:22`). |
| F-49 | RESOLVED | Paid/Received leave the live enum and the generated contract is asserted at four values (`SPEC:20-24`; `PLAN:28,62-64`). |
| F-50 | PARTIAL | POS account-charge provenance is recognized (`SPEC:35`), but no immutable source for `line_kind` exists—F-66. |
| F-51 | PARTIAL | P-3 now covers create/confirm/post/apply/cancel/reversal/residue (`PLAN:85`), but remains an oversized single lane and relies on hidden r2 detail. |
| F-52 | RESOLVED | One locked settlement service enforces combined application/refund cap and races (`SPEC:77-78`; `PLAN:88-89`). |
| F-53 | OPEN | `payments.side` is planned, but side cannot determine direction and its backfill/writer census is incomplete—F-62/F-70. |
| F-54 | RESOLVED | SI fulfilment is deferred until receipt-allocation ledger exists (`SPEC:105-107`; `PLAN:36,73-74`). |
| F-55 | PARTIAL | Zero-residual preflight exists (`PLAN:25,62-63`), but hashed paid CNs wrongly pass—F-60. |
| F-56 | RESOLVED | Received migration recomputes net of supplier returns (`SPEC:87-90`; `PLAN:65-67`). |
| F-57 | PARTIAL | Creation boundary is split from PHPStan enforcement (`PLAN:45-46`), but posted Expense reversal lacks an explicit API—F-67. |
| F-58 | RESOLVED | The strict chain ends at C-3b4 and `allow` activation is owner-gated (`PLAN:15-17,92`). |

## OQ dispositions table

| Question | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Add SupplierInvoice Confirmed hop | ACCEPT | Preserves one adjacency map if both hops, GL and logs share the locked posting transaction. |
| OQ-2 | Census supplier-payment typing | REJECT — repair with side + operation | Census alone is insufficient; persisted semantics and reversal classification must be repaired. |
| OQ-3 | Defer PO-prepayment defect | REJECT — immediate default refusal | Split C-0a immediately after N-6 and refuse every unsupported type, not only PO. |
| OQ-4 | Retire lifecycle Received | ACCEPT, conditionally | Net recompute, payload-reader migration and final CHECK parity are mandatory. |
| OQ-5 | Keep unposted SI 422 until P-2 | ACCEPT | Safest behavior while correct Dr 409/Cr cash and clearing machinery are absent. |
| OQ-6 | Build “minimal” SupplierCreditNote path | ACCEPT, conditionally | Minimum means full create/confirm/post/apply/unapplied-cancel reversal/residue lifecycle. |
| OQ-7 | Leave FE match vocabulary separate | REJECT — prerequisite | It must land before the SI Confirmed hop is exposed. |
| OQ-8 | Add a second country policy layer | REJECT — one inherited authority | Country defaults must feed `procurement_policies`; no duplicate posting-policy column or vertical fallback. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT, conditionally | Existing rows require JE backfill/quarantine, not merely a new trigger predicate. |
| OQ-10 | Retire `Document::getPaymentStatus()` | ACCEPT | One cached recompute authority avoids the existing purchase-blind helper. |
| OQ-11 | Remove `in_payment` | NEEDS-OWNER | Keep `has_unreconciled_payments`; presentation remains a product decision. |
| R-OQ-1 | Treat lifecycle Paid on CN as a defect | ACCEPT | Settlement belongs only in outward `payment_status`; legacy paid CNs require quarantine. |
| R-OQ-2 | Build cash refund in R-3 | ACCEPT, conditionally | It must use the shared ex-stamp cap and explicit customer-refund direction. |
| R-OQ-3 | Add standalone CN application in R-2 | ACCEPT | Correct with the shared lock/cap/recompute service. |
| R-OQ-4 | Refuse cancellation of applied CN | ACCEPT | Safest until compensating allocation events exist. |
| R-OQ-5 | Cache return on directly sourced line | REJECT — canonical root aggregate | Invoice/DN unions require billing/provenance-root aggregation and locking. |
| R-OQ-6 | Add RN reversal document | ACCEPT | Preserves the sealed original and append-only stock history. |
| R-OQ-7 | Keep RN seal at Confirmed | ACCEPT | Lifecycle-independent chain membership is the correct model. |
| R-OQ-8 | Delete/revive ReturnNote metadata | NEEDS-OWNER | Live FE fields and refund-disposition semantics prevent a technical default. |
| R-OQ-9 | Correct CN VAT declaration | NEEDS-OWNER | Merge only after the per-tenant/per-period delta is acknowledged. |
| R-OQ-10 | Net dashboard revenue | NEEDS-OWNER | It changes a daily owner-visible figure. |
| R-OQ-11 | Historical rows take internal Confirmed hop | ACCEPT, conditionally | Emit no false event and handle all four AR/AP Invoice/CreditNote directions. |
| R-OQ-12 | Add no B2B returns policy | ACCEPT | Do not invent one; any future policy must be seeded per country with company override. |

## Missed owner questions

- What is the disposition of already-`paid` sales CreditNotes whose settlement is a backwards customer receipt: reverse/reclassify automatically, quarantine for manual correction, or tenant-by-tenant ruling?
- For historical AR/AP CreditNote openings, are open credits only applicable to future invoices, cash-refundable, or deliberately non-settleable in this program?
- If TN’s QR client is unavailable, does OQ-15’s fail-closed refusal stand immediately, or is the owner explicitly authorizing a temporary noncompliant `not_required` period?
- For unrecoverable legacy `sealed_at` rows, does quarantine block the tenant’s whole fiscal-chain rollout or only the affected chain?
- Administrative: the owner sheet jumps from OQ-11 to OQ-13 (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:9-10`); reserve or define OQ-12 to prevent later identifier collision.

## Lane resequencing

1. Merge N-6, then immediately land **C-0a**: exhaustive default-refusal payment applicability. Do not wait for C-H0.
2. Add **C-F0**: VAT-free confirmed-unposted proforma, before any tenant rollout.
3. Split C-H0 into:
   - C-H0a schema/backfill/census for `opening_side`, historical `posted_at`;
   - C-H0b AR/AP Invoice/CreditNote semantic matrix and readers;
   - C-H0c payment/GL/reversal behavior.
4. Add **C-0b** supplier-side generic-reversal refusal after C-H0a/b.
5. Expand C-Q12 into schema/backfill, production-writer routing, then reversal use; define side separately from cash-flow operation.
6. Add paid-CN ledger remediation before C-2a; only then retire Paid.
7. Add the posted-deposit 419→411 clearing lane before C-2b recompute can declare such invoices paid.
8. Keep C-D0a, but rewrite C-C0 as:
   - existing-column RFQ validity backfill;
   - conversion/line provenance writers;
   - expiry command;
   - conversion reversal.
9. Give POS `line_kind` an explicit fiscal/projection prerequisite before C-R1a.
10. Order fiscal work as chain serialization/UNIQUE → `sealed_at` add/backfill/freeze → verifier/head-scope conversion; do not expose the new verifier before seal-time quarantine is available.
11. C-X0 must validate original VAT period, original ledger periods and reversal period before C-P2b/C-R1b/R-4.
12. Split C-R1b into CN settlement/cancel enforcement and VAT predicate/delta gate.
13. Split C-8 into JE identity writer/backfill/census and trigger installation.
14. Expand C-2c creation APIs to cover historical and reversal creation before final PHPStan enforcement.
15. Split P-3 into SCN authoring/confirmation, posting, application/residue, and cancellation/reversal.
16. Split C-3b4 into delivery recognition/idempotency and proportional reversal/CN behavior.
17. TN authority activation cannot remain a post-program option under the present OQ-15 default: deliver F-2 in-chain or block TN posting.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE:

- SupplierInvoice currently posts Draft→Posted and mutates paid/free PO and receipt counters (`SupplierInvoicePostingService.php:153-254,286-292`).
- PurchaseOrder `Received` is written only on full gross receipt; partial receipt stays Confirmed (`GoodsReceiptService.php:723-733,876-922`).
- Invoice `balance_due` includes sales credit applications through the dedicated PG trigger (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:38-70`).
- ReturnNotes can source Invoice or DeliveryNote, and traversal alone is ambiguous for consolidated/sibling shapes (`ReturnNoteService.php:296-335`).
- Fiscal verification currently handles only Invoice/CreditNote and filters lifecycle Posted (`VerifyFiscalChainsCommand.php:252-315`).
- Supplier payments are persisted as `DocumentPayment`, not `SupplierPayment` (`PaymentController.php:888-916`).
- Existing procurement policy resolution is company row → hardcoded vertical default (`ProcurementPolicyResolver.php:14-41`).
- Posted GoodsReceipts have no cancellation/reversal operation (`GoodsReceiptStatus.php:7-10`; `GoodsReceiptController.php:101-157`).
- Expense and Income write Posted directly today (`ExpenseService.php:309-319`; `IncomeService.php:145-154`).
- N-6 closes the direct CreditNote payment door, but deliberately retains a default receivable-clearing fallthrough (`fix/campaign-n6-payment-advance:DocumentAllocationClassifier.php:65-84`).

FALSE, stale, or incomplete:

- “C-0 closes the allocation-direction defect” is false: it names only PO, while N-6’s default admits every other unlisted type.
- “A hashed `paid` row is safely mappable” is false for CreditNotes; hashing proves prior fiscal posting, not correct settlement direction.
- “`payments.side` determines movement direction” is false for both customer and supplier credit refunds.
- “TN authority is fail-closed” is false while TN is seeded `not_required` pending F-2.
- “The plan implements VAT-free proforma” is false; SPEC states it but no lane owns it.
- “All policy is seeded” is false while the pre-delivery resolver hardcodes a missing-country fallback.
- “POS account-charge line kind is imported from the receipt” is false; the exact signed line schema has no such field.
- “Every posted creation is historical or two-hop” is false; linked-cost Expense reversal creates Posted directly.
- “Dual period guard matches OQ-35” is incomplete; it names the reversal period, not the original accounting period.
- “RFQ needs a new `valid_until` column” is false; `documents.valid_until` already exists.
- “C-8 protects existing posted non-fiscal documents” is incomplete without `posting_journal_entry_id` backfill.
- “r2 content is retained by reference” is operationally false unless a durable r2 artifact is committed and linked.

CHANGES-REQUESTED