# Session C spec+plan adversarial review r8

## Verdict line (whole program)

CHANGES-REQUESTED

## Verdict line (critical-path slice)

CHANGES-REQUESTED

## Findings

**F-119 · HIGH · SLICE · B/F — the critical-path migration order can make fiscal posting fail between C-F1a and C-F1h** · C-F1a adds tuple uniqueness before C-F1h replaces the status-filtered chain-head query (`PLAN-phase2-3-P-R.md:37-38`). Today the head excludes non-`posted` sealed rows (`DocumentPostingService.php:460-469`), while cancellation preserves the old fiscal sequence and hash (`DocumentPostingService.php:217-246`). After uniqueness lands, a paid/voided tail can therefore make the old allocator reuse an occupied sequence · the intermediate deployment can reject otherwise valid posting and is not safely rollback-neutral · rewrite the lane order as: “C-F1h deploys and proves lifecycle-independent sealed-head allocation first; C-F1a adds the UNIQUE constraint only after the new allocator is live,” or ship both atomically with a preflight collision test.

**F-120 · HIGH · SLICE · B/E/F — C-L0c depends on authority-attempt state that C-3a1 has not created yet** · C-L0c owns the transition guard that freezes documents with an open fiscal-authority attempt, but the authority attempt schema/protocol is introduced later by C-3a1 (`PLAN-phase2-3-P-R.md:36,39`). The test allocation repeats that impossible ordering (`PLAN-phase2-3-P-R.md:105,108`), while the normative protocol explicitly makes attempts durable and immutable (`SPEC-document-lifecycle-dimensions.md:124-136`) · the critical slice either cannot implement its declared guard or will substitute a weaker status-column inference · split C-3a1 into “C-3a1a authority-attempt schema and read port” before C-L0c and “C-3a1b acquisition protocol” later, or move the freeze guard and its test out of C-L0c.

**F-121 · HIGH · SLICE · G/I — C-QR0’s country seed contract contains an invalid country code and an impossible pseudo-country row** · the spec names TN, FR, UK, IT and “generic” (`SPEC-document-lifecycle-dimensions.md:111-115`), and C-QR0 adopts that contract (`PLAN-phase2-3-P-R.md:33`). The repository country catalog uses `GB`, not `UK` (`CountriesSeeder.php:201-213`), and policy country keys are two-character foreign keys (`2026_05_28_100001_create_country_document_settings_table.php:39-51`), so literal `generic` cannot be inserted · the slice’s policy migration is not executable as written and still does not define what “all supported countries” means · replace the wording with: “Seed every provisionable ISO-3166 country from the authoritative country catalog; use GB; do not invent a generic country key. Missing row always refuses.” Add a registry-versus-policy parity test using the actual catalog.

**F-122 · MEDIUM · SLICE · A/E/I — the transition ledger is write-routed but not append-only** · the spec requires two transition rows for two-hop operations and introduces `document_status_transitions` (`SPEC-document-lifecycle-dimensions.md:269-275,354-356`); C-L0a/b own the schema and writer (`PLAN-phase2-3-P-R.md:34-35`), but the only named coverage is transition creation/parity (`PLAN-phase2-3-P-R.md:105`) · an ORM, query-builder or raw SQL path can update/delete history without violating the document CHECK, defeating the program’s immutable-event/audit rule · add: “transition rows are append-only; UPDATE and DELETE are rejected by a DB trigger except explicit migration maintenance mode,” plus `DocumentStatusTransitionImmutabilityTest` covering ORM, builder and raw SQL update/delete.

**F-123 · HIGH · MAIN · A/C/D — service-only and mixed supplier invoices have no valid Phase-2 posting path** · PO/SI lines may contain a service without a product (`CreateDocumentRequest.php:100-112`; `CreateSupplierInvoiceRequest.php:113-120`). Goods receipt creation skips lines lacking `product_id` (`GoodsReceiptService.php:143-155`), so these invoices necessarily use today’s no-receipt compatibility branch (`SupplierInvoicePostingService.php:188-208`). The new design quarantines missing receipt provenance (`SPEC-document-lifecycle-dimensions.md:224-228`) and C-P2a removes that compatibility path (`PLAN-phase2-3-P-R.md:76`), despite the applicability matrix promising `not_applicable` where physical fulfilment does not apply (`SPEC-document-lifecycle-dimensions.md:61-63`) · valid service invoices will become permanently unpostable, and mixed invoices have no rule separating physical from service quantities · define durable `line_kind`; require receipt slices only for physical lines; classify service-only SIs as fulfilment `not_applicable`; state the mixed-document aggregation rule; and add service-only and mixed SI posting/GL tests before C-P2a.

**F-124 · HIGH · MAIN · C/D/F/I — P-5 is a feature label, not an implementable goods-receipt reversal specification** · posting a goods receipt changes stock, WAC/batch provenance, PO received/free quantities and GR-IR (`GoodsReceiptService.php:614-714,723-755`). The plan gives P-5 only “goods-receipt reversal document” and one broad test (`PLAN-phase2-3-P-R.md:147`) · an implementer cannot determine whether invoiced receipt slices, supplier returns, closed periods, stock shortages or partially consumed batches block reversal, nor which counters and journals must invert · expand P-5 with an immutable `reverses_document_id`, idempotency key, dual-period guards, exact inverse stock/WAC/batch/GR-IR/counter effects, downstream-dependency refusal rules, and recomputation-port invocation. Split it if necessary so no lane exceeds one migration/implementer-day.

**F-125 · HIGH · MAIN · A/F — SalesOrder prepayment timing is simultaneously in scope and out of scope** · the normative rule removes clear-at-conversion and retains allocations until invoice posting (`SPEC-document-lifecycle-dimensions.md:171-180`), and C-SO0 implements that change (`PLAN-phase2-3-P-R.md:69`), but §12 declares “converter clear-at-conversion timing” out of scope (`SPEC-document-lifecycle-dimensions.md:358-360`) · two conforming implementations can make opposite treasury postings · delete that phrase from §12 and state explicitly: “C-SO0 replaces clear-at-conversion; only unrelated converter behavior is out of scope.”

**F-126 · MEDIUM · MAIN · A/E/F — C-PROV0 duplicates schema already present or owned by another lane** · C-PROV0 lists `is_historical` and `posted_at` schema work (`PLAN-phase2-3-P-R.md:43`), but `is_historical` already exists (`2025_12_11_100001_add_is_historical_to_tables.php:29-35`) and C-L0a already owns `posted_at` (`PLAN-phase2-3-P-R.md:34`) · parallel implementation risks duplicate-column migrations and violates single schema ownership · rewrite C-PROV0 to own only new provenance/opening-side fields and historical backfill; reference, rather than recreate, `is_historical` and C-L0a’s `posted_at`.

**F-127 · MEDIUM · MAIN · B/F — fiscal verifier parity is unnecessarily delayed behind procurement work** · C-F1b and C-F1c wait until after C-P2a in the strict chain (`PLAN-phase2-3-P-R.md:76-78`), although the current verifier handles only Invoice/CreditNote and filters `status=posted` (`VerifyFiscalChainsCommand.php:252-258,278-285`), while the backfill command has the same two-type limitation (`BackfillFiscalHashesCommand.php:51-72,122-133`) · DN/RN and lifecycle-independent chain verification remain knowingly wrong throughout unrelated supplier-invoice development · move C-F1b/c immediately after F-2b and the corrected chain-head/hash contract.

**F-128 · HIGH · MAIN · B/C/G/I — SupplierCreditNote settlement base assumes a country-specific stamp rule that no lane seeds** · the spec makes SCN settlement depend on country-defined stamp treatment (`SPEC-document-lifecycle-dimensions.md:61-64,167-170`), but P-3 contains no country tax-policy seed lane (`PLAN-phase2-3-P-R.md:142-145`). Tunisia’s existing stamp configuration targets `CREDIT_NOTE`, not `SUPPLIER_CREDIT_NOTE` (`TunisiaTaxConfigurationSeeder.php:116-123`) · SCN “fully settled” can be computed from an undefined or accidentally inherited base · add a country-seeded SCN stamp/refundability policy before P-3c, with missing-policy typed refusal. If TN SCNs are unstamped, say and seed that explicitly.

**F-129 · HIGH · MAIN · B/D/F/I — R-4 leaves delivery/return reversal semantics materially unspecified** · DN confirmation seals a fiscal chain while issuing stock/accounting effects (`DeliveryNoteService.php:121-166`); RN confirmation likewise computes its chain hash and seals in the confirmation transaction (`ReturnNoteService.php:585-660`). R-4 is only “DN/RN cancellation reversal documents” (`PLAN-phase2-3-P-R.md:150`) · implementations may mutate sealed originals, fail to invert stock/COGS/WAC, or reopen fulfilment without handling downstream invoices and credit notes · specify separate immutable reversal documents with `reverses_document_id`, their own fiscal-chain participation, exact inverse stock/GL/batch effects, downstream-reference refusal, dual-period guards, idempotency and root recomputation.

**F-130 · MEDIUM · MAIN · I — the red-first matrix does not prove several newly normative claims** · the named tests (`PLAN-phase2-3-P-R.md:101-136,147-150`) contain no explicit test for GB/catalog policy parity, transition-ledger update/delete refusal, service-only/mixed SI posting, the complete GR/DN/RN inverse effects, or SCN stamp-base policy · these are precisely the areas where the written lanes remain ambiguous · add at minimum `FiscalAuthorityCountrySeedParityTest`, `DocumentStatusTransitionImmutabilityTest`, `ServiceSupplierInvoicePostingTest`, `MixedSupplierInvoiceFulfilmentTest`, `GoodsReceiptReversalAccountingTest`, `DeliveryReturnReversalChainTest`, and `SupplierCreditNoteSettlementBasePolicyTest`.

## r1..r7 findings disposition

Status is against SPEC r7 and PLAN r7, not merely against an asserted response in a prior review.

| Prior finding | Status | Prior finding | Status | Prior finding | Status | Prior finding | Status |
|---|---|---|---|---|---|---|---|
| F-1 | RESOLVED | F-2 | RESOLVED | F-3 | RESOLVED | F-4 | RESOLVED |
| F-5 | RESOLVED | F-6 | RESOLVED | F-7 | RESOLVED | F-8 | PARTIAL |
| F-9 | RESOLVED | F-10 | RESOLVED | F-11 | RESOLVED | F-12 | RESOLVED |
| F-13 | RESOLVED | F-14 | RESOLVED | F-15 | RESOLVED | F-16 | RESOLVED |
| F-17 | RESOLVED | F-18 | PARTIAL | F-19 | RESOLVED | F-20 | PARTIAL |
| F-21 | RESOLVED | F-22 | RESOLVED | F-23 | RESOLVED | F-24 | RESOLVED |
| F-25 | RESOLVED | F-26 | RESOLVED | F-27 | RESOLVED | F-28 | RESOLVED |
| F-29 | RESOLVED | F-30 | RESOLVED | F-31 | RESOLVED | F-32 | RESOLVED |
| F-33 | PARTIAL | F-34 | RESOLVED | F-35 | RESOLVED | F-36 | RESOLVED |
| F-37 | RESOLVED | F-38 | PARTIAL | F-39 | RESOLVED | F-40 | PARTIAL |
| F-41 | RESOLVED | F-42 | RESOLVED | F-43 | RESOLVED | F-44 | RESOLVED |
| F-45 | RESOLVED | F-46 | RESOLVED | F-47 | RESOLVED | F-48 | RESOLVED |
| F-49 | RESOLVED | F-50 | RESOLVED | F-51 | PARTIAL | F-52 | RESOLVED |
| F-53 | RESOLVED | F-54 | PARTIAL | F-55 | RESOLVED | F-56 | RESOLVED |
| F-57 | RESOLVED | F-58 | RESOLVED | F-59 | RESOLVED | F-60 | RESOLVED |
| F-61 | RESOLVED | F-62 | RESOLVED | F-63 | RESOLVED | F-64 | RESOLVED |
| F-65 | RESOLVED | F-66 | RESOLVED | F-67 | RESOLVED | F-68 | RESOLVED |
| F-69 | RESOLVED | F-70 | RESOLVED | F-71 | RESOLVED | F-72 | RESOLVED |
| F-73 | RESOLVED | F-74 | PARTIAL | F-75 | PARTIAL | F-76 | RESOLVED |
| F-77 | RESOLVED | F-78 | RESOLVED | F-79 | RESOLVED | F-80 | RESOLVED |
| F-81 | RESOLVED | F-82 | RESOLVED | F-83 | RESOLVED | F-84 | RESOLVED |
| F-85 | RESOLVED | F-86 | PARTIAL | F-87 | RESOLVED | F-88 | RESOLVED |
| F-89 | RESOLVED | F-90 | RESOLVED | F-91 | RESOLVED | F-92 | RESOLVED |
| F-93 | RESOLVED | F-94 | RESOLVED | F-95 | RESOLVED | F-96 | PARTIAL |
| F-97 | PARTIAL | F-98 | RESOLVED | F-99 | RESOLVED | F-100 | RESOLVED |
| F-101 | RESOLVED | F-102 | RESOLVED | F-103 | RESOLVED | F-104 | RESOLVED |
| F-105 | RESOLVED | F-106 | RESOLVED | F-107 | RESOLVED | F-108 | RESOLVED |
| F-109 | PARTIAL | F-110 | PARTIAL | F-111 | RESOLVED | F-112 | PARTIAL |
| F-113 | RESOLVED | F-114 | RESOLVED | F-115 | RESOLVED | F-116 | RESOLVED |
| F-117 | RESOLVED | F-118 | PARTIAL |  |  |  |  |

The remaining partials cluster around lane sizing/test sufficiency, physical reversal detail, service-SI provenance, C-SO0’s scope contradiction, fiscal lane ordering and executable country seeding.

## OQ dispositions table

### Legacy OQ-1..11 and R-OQ-1..12

| Question | Recommended default | Disposition | Reasoning |
|---|---|---|---|
| OQ-1 | Durable SI confirmation | ACCEPT | Required to give supplier advances a stable target. |
| OQ-2 | Census-only opening classification | REJECT — persist `opening_side` and operation provenance | Type/status inference is not independent or durable. |
| OQ-3 | Defer PO prepayment correction | REJECT — retain immediate typed 422 until the correct advance path exists | Current PO classification would create the wrong receivable/GL direction. |
| OQ-4 | Staged retirement of `received` | ACCEPT | Safe only after all readers and fiscal-chain dependencies are migrated. |
| OQ-5 | Keep supplier excess-payment 422 | ACCEPT | Prevents silent supplier-advance misposting. |
| OQ-6 | Minimal SCN lifecycle | ACCEPT WITH CONDITION | Requires explicit stamp base, application cap and reversal semantics. |
| OQ-7 | Fiscal authority as a separate future program | REJECT — keep it prerequisite to TN posting | “Posted = sealed + authority accepted” is binding. |
| OQ-8 | Parallel procurement-policy inheritance | REJECT — use one country-row/company-override mechanism | Parallel fallback systems recreate hardcoded defaults. |
| OQ-9 | Widen sealed immutability | ACCEPT | Seal facts and authority facts must freeze together. |
| OQ-10 | Retire direct posting helpers | ACCEPT | Only after all writers route through creation/transition APIs. |
| Legacy OQ-11 | Remove `in_payment` from status | NEEDS-OWNER | Reconciliation fact is objective; its presentation remains product policy. |
| R-OQ-1 | Fix CN paid/lifecycle conflation | ACCEPT | Settlement must not mutate lifecycle. |
| R-OQ-2 | Cash-refund path in R-3 | ACCEPT | Keeps application and refund accounting distinct. |
| R-OQ-3 | Standalone CN application service | ACCEPT | One cap/locking/event path is necessary. |
| R-OQ-4 | Refuse cancelling applied CN | ACCEPT | Explicit child reversals must precede cancellation. |
| R-OQ-5 | Write line-level returned cache directly | REJECT — recompute from root facts | Direct cache updates drift under reversal and concurrency. |
| R-OQ-6 | RN reversal documents | ACCEPT WITH CONDITION | R-4 needs the exact inverse and dependency contract in F-129. |
| R-OQ-7 | Seal RN at confirmation | ACCEPT | Matches its terminal fiscal issuance edge. |
| R-OQ-8 | Delete/revive refund metadata | NEEDS-OWNER | This is product-scope, not a safe technical default. |
| R-OQ-9 | Correct CN VAT filter with delta report | NEEDS-OWNER | Existing-tenant declared figures change. |
| R-OQ-10 | Net dashboard revenue | NEEDS-OWNER | Changes a watched business metric. |
| R-OQ-11 | Historical rows use internal confirmed hop | ACCEPT | Preserves adjacency without inventing an external event. |
| R-OQ-12 | Add no B2B-return policy | ACCEPT | Do not invent a restriction without country policy. |

### Current owner sheet OQ-11..58

| Question | Recommended default | Disposition | Reasoning |
|---|---|---|---|
| OQ-11 | DTO `has_unreconciled_payments`; no status value | NEEDS-OWNER | Correct fact, but user-facing status remains product policy. |
| OQ-12 | Reserved identifier | ACCEPT | No behavior. |
| OQ-13 | Supplier return reduces net PO fulfilment | ACCEPT | Use replacement receipt when replacement is later ordered/linked. |
| OQ-14 | TN confirmed-unposted output is VAT-free proforma | ACCEPT | Consistent with the adopted Art. 18 ruling. |
| OQ-15 | Refuse posting until authority acceptance | ACCEPT | Required fail-closed behavior. |
| OQ-16 | Reuse immutable `DocumentFullyPaid` schema | ACCEPT | “Fully settled” semantics can remain without event mutation. |
| OQ-17 | FR policy provisional | ACCEPT | Missing expertise must refuse before overrides. |
| OQ-18 | Leave refund metadata untouched | NEEDS-OWNER | Deletion/revival changes product scope. |
| R-OQ-9 | VAT correction only after owner acknowledges delta | NEEDS-OWNER | Historical declaration figures change. |
| OQ-19 | Leave dashboard gross | NEEDS-OWNER | Owner must approve metric semantics. |
| OQ-20 | Per-type closure table | ACCEPT | Subject to OQ-28’s RN ruling. |
| OQ-21 | Returned invoice closes when posted and settled | ACCEPT | Credit does not substitute for undelivered fulfilment. |
| OQ-22 | Cancellation closes document | ACCEPT | Stable terminal rule. |
| OQ-23 | TN CN base excludes stamp | ACCEPT | Matches current production application basis. |
| OQ-24 | Migrate only rows equal to defaults to inherited | ACCEPT | Preserves genuine overrides. |
| OQ-25 | Expense/Income use metadata settlement basis | ACCEPT | No allocation model exists for them. |
| OQ-26 | TN 472 only; FR `allow` unavailable | ACCEPT | No unapproved FR account mapping. |
| OQ-27 | SI VAT delta acknowledged in P-4 | NEEDS-OWNER | Historical periods and financial impact require approval. |
| OQ-28 | RN closes at confirmation | ACCEPT | Terminal return-document lifecycle. |
| OQ-29 | No country activates `allow` | ACCEPT | Correct fail-safe. |
| OQ-30 | Keep historical types; add `opening_side` | ACCEPT | Avoid destructive type migration. |
| OQ-31 | DN returned only per fully returned tuple | ACCEPT | Durable quantity fact, not mere linkage. |
| OQ-32 | Scheduled expiry command | ACCEPT | Needed for durable `closed_at`. |
| OQ-33 | POS receipt proves fulfilment | ACCEPT WITH CONDITION | It proves fulfilment only; authority evidence remains independent. |
| OQ-34 | Retain SCN residue as supplier credit | ACCEPT | Manual closure remains refused. |
| OQ-35 | Both GL and VAT periods must be open | ACCEPT | Prevents asymmetric reversal. |
| OQ-36 | Quarantine backwards paid-CN accounting | ACCEPT | Automatic repair would alter historical cash direction. |
| OQ-37 | Historical credit openings non-settleable | ACCEPT | Avoids inferred cash/refund semantics. |
| OQ-38 | No temporary TN authority override | ACCEPT | Fail-closed ruling is explicit. |
| OQ-39 | Quarantine only affected chain | ACCEPT | Other independently valid chains can verify. |
| OQ-40 | Rejection creates a new immutable attempt | ACCEPT | Document remains unsealed until acceptance. |
| OQ-41 | SO becomes `not_applicable` once all lines invoiced | REJECT — only after final residue is detached | Otherwise an attached advance disappears from payment semantics. |
| OQ-42 | Delivered-then-returned required per tuple | ACCEPT | A CN cannot manufacture fulfilment. |
| OQ-43 | Refuse SI cancellation until children reversed | ACCEPT | Avoid implicit cascades. |
| OQ-44 | Company-overridable supplier-advance mode | ACCEPT | Valid seeded-country/default/override hierarchy. |
| OQ-45 | Fulfilment quarantine blocks posting and closure | ACCEPT | Fail closed on ambiguous facts. |
| OQ-46 | POS invoice adopts existing POS fiscal/GL event | ACCEPT | Avoids double seal and double GL. |
| OQ-47 | Durable SI confirmed state | ACCEPT | Necessary for advance targeting and adjacency. |
| OQ-48 | FIFO capped order prepayment transfer | REJECT — FIFO capped plus mandatory final residue detachment | The stated default is incomplete without OQ-56’s terminal transfer. |
| OQ-49 | Quarantine legacy TN rows lacking authority proof | ACCEPT | Do not fabricate historical acceptance. |
| OQ-50 | Product-wide unlocated capacity | ACCEPT | Correct until durable location allocation exists. |
| OQ-51 | Replacement SI linkage and copied reference | ACCEPT | Required audit trail. |
| OQ-52 | Provisional gate precedes override | ACCEPT | Company override cannot waive missing jurisdiction expertise. |
| OQ-53 | Authority applies to Invoice/CreditNote only | ACCEPT | DN/RN would need a different draft-preserving protocol. |
| OQ-54 | All non-TN rows `not_required`; missing refuses | REJECT — use every actual provisionable ISO code, including GB, with no `generic` pseudo-row | Current wording cannot satisfy the FK and leaves the country universe ambiguous. |
| OQ-55 | TN POS account-charge invoice blocked without compatible proof | ACCEPT | Tenant QR token is not Ministry acceptance. |
| OQ-56 | Detach final residue to partner advance | ACCEPT | Required before SO payment becomes not applicable. |
| OQ-57 | Digest mismatch requires corrective document | ACCEPT | Never rewrite or reseal accepted history. |
| OQ-58 | Expense/Income correction only by posted reversal | ACCEPT | Avoids an under-specified cancellation edge. |

## Missed owner questions

1. For service-only and mixed supplier invoices, should service lines bypass receipt-first and post directly to expense/payable, while only physical lines use GR-IR? The current default is not inferable safely.

2. What is the authoritative set of “supported/provisionable countries” for fiscal-authority policy parity? The owner sheet’s `UK`/`generic` examples are incompatible with the repository’s ISO-keyed catalog.

3. Are SupplierCreditNotes stamped in TN, and if so is that stamp refundable/application-eligible? Existing policy only names sales credit notes.

4. May a goods receipt be reversed after any quantity has been invoiced or supplier-returned, or must all downstream documents first be reversed? The answer materially changes P-5.

5. For DN/RN reversal, is the corrective document required to remain in the same fiscal chain/type, or is a distinct corrective document type authorized?

## Lane resequencing

Recommended critical-path order:

1. C-0a0 → C-F0 → corrected C-QR0.
2. C-L0a → C-L0b.
3. Split C-3a1a: create immutable authority-attempt schema/read port.
4. C-L0c, now able to enforce the open-attempt guard.
5. C-F1h: deploy lifecycle-independent chain-head allocation.
6. C-F1a: add tuple uniqueness only after C-F1h is live.
7. C-3a1b: authority acquisition/protocol implementation.
8. F-2a → F-2b.
9. Move C-F1b/c here, before unrelated procurement lanes.

Main-program changes:

- Rewrite C-PROV0 to stop recreating `is_historical`/`posted_at`.
- Add service/mixed SI semantics before C-P2a.
- Add an SCN country stamp-policy lane before P-3c.
- Expand and, if necessary, split P-5 and R-4 before dispatch.
- Keep C-SO0 in scope and remove the contradictory §12 statement.

## Claims verified TRUE in the research sweeps that the spec relies on

- The N-6 branch’s machine is type-aware and preserves direct posting for SupplierInvoice, SupplierCreditNote, Expense and Income (`DocumentStatusMachine.php:54-105`).
- The allocation classifier is ordered/exhaustive, but its current PO arm is wrong and defaults fail closed (`DocumentAllocationClassifier.php:88-113`).
- `balance_due` already subtracts posted credit-note allocations, including the corrected credit-note trigger (`2026_01_08_214145_add_credit_note_allocations_and_balance_triggers.php:27-60`; `2026_05_27_100001_fix_credit_note_balance_due_trigger.php:37-69`).
- Supplier payments require a posted SI, create the 401-side entry and reject excess allocations (`PaymentController.php:509-584,773-786`).
- Fiscal head selection and verification are status-based today, and the verifier/backfill cover only Invoice/CreditNote (`DocumentPostingService.php:460-469`; `VerifyFiscalChainsCommand.php:252-285`; `BackfillFiscalHashesCommand.php:51-72`).
- The CN VAT repository is currently lifecycle-blind (`EloquentVatDataRepository.php:27-65`).
- The sealed-document trigger protects listed fields only after `SEALED`, allows voiding and blocks sealed/voided deletion (`2025_12_11_054716_add_document_immutability_trigger.php:24-57,73-85`).
- Existing `DocumentFullyPaid` payload shape does not embed a `paid` lifecycle status and need not be renamed (`DocumentFullyPaid.php:9-34`).

Claims found FALSE or stale:

- Research P’s statement that Phase 1 forbids SupplierInvoice Draft→Posted is stale against the actual N-6 branch’s type guard (`RESEARCH-P-purchases.md:572`; `DocumentStatusMachine.php:72-105`).
- The earlier claim that DN/RN lack `fiscal_category` CHECK support is stale; a later migration includes both (`2026_01_08_205902_add_delivery_return_fiscal_categories.php:18-24`).
- SPEC r7’s assumption that `UK` and `generic` are viable country-policy keys is false: the catalog uses GB and the FK key is two characters (`CountriesSeeder.php:201-213`; `2026_05_28_100001_create_country_document_settings_table.php:39-51`).
- PLAN r7’s claim that C-L0c can enforce/test open authority attempts before C-3a1 is false by its own lane order (`PLAN-phase2-3-P-R.md:36,39,105,108`).
- The claim that converter clear-at-conversion timing is out of scope is inconsistent with the normative SO treasury rule and C-SO0 (`SPEC-document-lifecycle-dimensions.md:171-180,358-360`; `PLAN-phase2-3-P-R.md:69`).

Critical-path slice: CHANGES-REQUESTED

Whole program: CHANGES-REQUESTED