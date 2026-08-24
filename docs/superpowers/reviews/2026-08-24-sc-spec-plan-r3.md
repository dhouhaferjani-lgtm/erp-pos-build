# Session C spec+plan adversarial review r3

## Verdict line

CHANGES-REQUESTED

## Findings

**F-41 · Critical · area A/D/H/I** — The new per-type closure table still relies on a universal “fully invoiced” fact that production does not maintain, and its DeliveryNote rule contradicts the owner default. SPEC defines fully invoiced as per-line `quantity_invoiced` (`SPEC-document-lifecycle-dimensions.md:72-76`), but SalesOrder conversion records `payload.fully_invoiced[_at]` (`SalesOrderToInvoiceConverter.php:264-270`), DN billing is represented by `delivery_note_billing_marks.invoice_id` and payload markers (`DeliveryNoteBillingClaimService.php:55-89,107-139`), and PO posting separately maintains paid and bonus counters (`SupplierInvoicePostingService.php:153-254`). The aggregate predicate can also hide a deficient line, while omitting `free_quantity_invoiced`. Finally, SPEC says returns do not close a DN (`SPEC-document-lifecycle-dimensions.md:32`), whereas the owner default is “invoiced or returned” (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:18`). Consequence: SOs and DNs can remain open forever; POs with bonus goods can close incorrectly; the closure cache violates its binding owner semantics. Required change: replace §1.3 with explicit source facts:

- SO: durable conversion/provenance proves every ordered line invoiced.
- PO: every line satisfies both paid and free invoiced quantities.
- DN: a finalized billing mark exists, or the owner-default returned disposition holds.
- No cross-line aggregate substitution.

Add per-line deficit, free-quantity, consolidated-DN, returned-DN, and conversion-reversal tests before C-3a2.

**F-42 · Critical · area A/C/F/I** — Historical AP openings are not SupplierInvoices, despite the applicability table claiming they are. `ArApOpeningService` accepts only Invoice/CreditNote (`ArApOpeningService.php:159-170`) and creates those types as Posted non-fiscal historical documents even for AP batches (`ArApOpeningService.php:277-328`). Treasury recognizes only `DocumentType::SupplierInvoice` as supplier-side (`PaymentController.php:509-588`); everything else follows the non-supplier partition, including an inward repository movement (`PaymentController.php:1225-1230`). Consequence: settlement of a historical AP invoice can take the customer/411 cash-in direction instead of supplier/401 cash-out. This is a pre-existing accounting defect that r2 would preserve and legitimize. Required change: add a prerequisite historical-opening remediation lane that persists an authoritative AR/AP side—prefer native SupplierInvoice/SupplierCreditNote types, otherwise an immutable `opening_side` discriminator—and routes payment classification, GL accounts, statements, ageing, and reversal from that fact. Migrate existing AP openings and add historical AP payment/refund tests before C-2a.

**F-43 · High · area D/I** — The canonical root algorithm is keyed to the wrong relation. SPEC says `resolveRoot()` walks `source_document_id` chains (`SPEC-document-lifecycle-dimensions.md:104-114`), but DN→invoice ownership is finalized in `delivery_note_billing_marks.invoice_id` (`DeliveryNoteBillingClaimService.php:107-139`). Existing return logic documents that SalesOrder siblings sharing `source_document_id` are indistinguishable by traversal alone (`ReturnNoteService.php:321-335`). Consequence: an invoice-sourced and DN-sourced return can lock different roots, race the same quantity cap, or aggregate an unrelated sibling invoice. Required change: state that billing marks and line-level conversion provenance are authoritative; `source_document_id` is only a fallback for unambiguous legacy shapes. Resolve that owner before cap validation, lock it first, quarantine ambiguous legacy graphs, and add sibling-invoice, consolidated-DN, and cross-source concurrent-return tests.

**F-44 · High · area A/I** — Quote/RFQ expiration closure has no persistent mutation that can invoke the recompute port. SPEC names `expired_at` and an “expiry” recompute event (`SPEC-document-lifecycle-dimensions.md:26-27,70`), but documents store `valid_until` (`2025_11_30_080000_create_documents_table.php:23`), Quote expiry is evaluated dynamically at read time (`DocumentConversionController.php:165-184`), and RFQ validity lives in payload (`PurchaseQuoteRequestService.php:63-71`). Time passing does not write a row or call the port. Consequence: `closed_at` cannot become true merely because the clock crosses the deadline. Required change: choose one model: derive expiry/closure at read time without a cached timestamp, or add a scheduled, idempotent expiry command that locks eligible documents, stamps the authoritative expiry fact, and calls the port. Define RFQ’s equivalent field and add time-travel and rerun tests.

**F-45 · High · area D/E/F/I** — C-P1 says `payload.fully_received` is retired without migrating all readers or preserving the distinct receipt timestamp. The current receipt writer stores `fully_received` and `goods_received_at` together (`GoodsReceiptService.php:723-734`). Landed-cost mutability is frozen by `goods_received_at` (`LandedCostService.php:449-458`), and operation output exposes the same timestamp (`OperationResolver.php:51-63`). PLAN’s reader census names only GoodsReceipt/AgedPayables around the `Received` migration (`PLAN-phase2-3-P-R.md:74-85`). Consequence: deleting the payload fields can reopen landed-cost editing and erase an operational timestamp; retaining them without semantics leaves two fulfilment authorities. Required change: retire only the boolean verdict, preserve/rename the immutable “first/full receipt occurred at” fact, enumerate every backend and frontend reader, and migrate them deliberately. Add landed-cost-freeze and operation-output regressions.

**F-46 · High · area B/C/I** — The 472 JE matrix is incomplete for stamped invoices. SPEC debits 411 for the full total but credits only delivered revenue, 472, and VAT (`SPEC-document-lifecycle-dimensions.md:163-167`). Current posting separately credits the document-level stamp/rounding residual to its absorbing liability account (`AccountingService.php:629-689`). Consequence: a stamped TN pre-delivery invoice is unbalanced or misclassifies timbre as VAT/revenue. Required change: amend the normative matrix to include `Cr SalesStampDutyPayable/rounding absorber` for the residual and require debit equals all credits at currency scale. Extend `PreDeliveryDeferralPostingTest` with stamped physical, mixed physical/service, and last-line rounding cases.

**F-47 · High · area B/C/F/I** — “Period open” is still an incomplete cancellation guard. SPEC applies one generic period guard (`SPEC-document-lifecycle-dimensions.md:135-145`), but VAT and accounting periods are independent, and the existing cancellation reversal writes directly without the fiscal-period posting guard (`VatPeriodCancellationGuard.php:49-69`). Q-10 does not by itself repair this direct-write hole. Consequence: C-P2b or CN cancellation can pass VAT-period validation and seal a reversal JE in a closed accounting period. Required change: add a prerequisite cancellation-period lane requiring both the original VAT-period rule and the accounting entry-period rule, or define an owner-approved current-period reversal policy. Test open/closed combinations for both period tables.

**F-48 · High · area A/B/E/I** — C-8’s persistent immutability facts still exclude historical openings. SPEC arms non-fiscal immutability with `sealed_at` or `posting_journal_entry_id` (`SPEC-document-lifecycle-dimensions.md:120-133`), while historical openings intentionally create no GL entry (`ArApOpeningService.php:246-257`) and remain fiscal Draft (`ArApOpeningService.php:311-328`). Consequence: a Posted historical receivable/payable remains mutable after C-8. Required change: include an immutable historical-posting fact—such as `is_historical && posted_at IS NOT NULL` backed by a protected timestamp—or assign a posting record during import. Add header, line, delete, and post-cancellation immutability tests for AR and AP openings.

**F-49 · High · area E/F/I** — Keeping `Paid` and `Received` enum cases “deprecated for event payloads” contradicts the promised generated TypeScript contract. SPEC retains the PHP cases (`SPEC-document-lifecycle-dimensions.md:41-44`), while PLAN regenerates the frontend enum (`PLAN-phase2-3-P-R.md:67-72`). The transformer currently exports every PHP enum case, including `paid` and `received` (`generated.d.ts:840-855`). Immutable stored event strings do not require live PHP enum cases. Consequence: frontend code can continue writing/branching on retired lifecycle values after the DB CHECK rejects them. Required change: remove the live cases after data migration and leave historical event payloads unchanged, or introduce a separate legacy-event wire type/custom transformer. Add a generated-contract assertion that `DocumentStatus` contains exactly draft, confirmed, posted, and cancelled.

**F-50 · High · area A/B/D/I** — POS-originated account-charge invoices will be misclassified as service-only. The service creates a normal Invoice (`POSAccountChargeDraftService.php:49-66`), but its product lines deliberately have `product_id=null` and only retain SKU/name (`POSAccountChargeDraftService.php:75-94`). SPEC defines service-only fulfilment as `not_applicable` (`SPEC-document-lifecycle-dimensions.md:104-116`). Consequence: physical POS goods can bypass fulfilment and pre-delivery compliance or receive a permanently incorrect fulfilment verdict. Required change: define POS fiscal-event provenance as a recognized fulfilled-goods source, or persist an explicit physical/service classification on imported lines. Add POS account-charge physical, mixed, and genuine service tests.

**F-51 · High · area A/C/F/I** — P-3 is not a complete SupplierCreditNote lifecycle. Production has a posting service but no routed creation/cancellation surface; procurement routes expose only supplier invoices (`Procurement/Presentation/routes.php:83-120`). PLAN adds creation, application, a balance trigger, and only an applied-cancellation refusal test (`PLAN-phase2-3-P-R.md:111-116`). It does not define cancellation of an unapplied SCN, reversal of its JE/counters, cash refund/credit retention, or how unapplied residue reaches `paid`. Consequence: the table’s `posted ∧ paid` closure can be unreachable, and an unapplied error cannot be corrected. Required change: expand P-3 to create, confirm, post, apply, cancel-unapplied with exact reversal, refuse cancel-applied, and explicitly dispose of residual supplier credit. Add balance, GL, cache, and concurrency tests.

**F-52 · High · area C/I** — The sales-CN lanes do not state the combined settlement cap. SPEC correctly defines settled value as applications plus refunds (`SPEC-document-lifecycle-dimensions.md:92-101`), but R-2 and R-3 are separate writers with separate tests (`PLAN-phase2-3-P-R.md:118-120`). Consequence: concurrent application and cash refund can each observe sufficient residue and jointly exceed the ex-stamp base. Required change: both lanes must lock the CN first and enforce `Σ live applications + Σ completed refunds ≤ total − stamp_duty_amount` in one shared service, with deterministic secondary locks. Add application-vs-refund and refund-vs-refund PG races.

**F-53 · High · area C/E/F** — Supplier payments remain semantically typed as customer-style `DocumentPayment`. `PaymentController` chooses only Advance or DocumentPayment (`PaymentController.php:888-916`), while `PaymentType::SupplierPayment` deliberately has different, unsupported generic-reversal semantics (`PaymentType.php:165-190`). Runtime movement direction currently depends on an in-memory document-type flag (`PaymentController.php:1225-1230`). Consequence: persisted payment classification lies to reporting/reversal logic and P-2 would build supplier advances on an unstable discriminator. Required change: Q-12 must produce a migration/versioned classifier, not only a census: persist supplier-side payment type/side, repair existing rows, and keep generic reversal blocked until repaired. Make this a prerequisite to P-2.

**F-54 · High · area D/F/I** — Linked SupplierInvoice fulfilment is scheduled before exact receipt provenance exists. SPEC says GR/SGRN mutations recompute the PO and linked SI (`SPEC-document-lifecycle-dimensions.md:67-69`), and C-P1 claims SI fulfilment (`PLAN-phase2-3-P-R.md:83-85`), but exact receipt-slice ownership is not added until later C-P2a (`PLAN-phase2-3-P-R.md:91-94`). Current posting can derive only cumulative receipt counters (`SupplierInvoicePostingService.php:169-213,229-254`). Consequence: two invoices consuming overlapping receipt pools cannot receive correct independent fulfilment verdicts. Required change: move C-P2a’s immutable receipt-allocation ledger before SI fulfilment, or restrict C-D0c/C-P1 to PO verdicts until that ledger exists. Add two-invoice, partial-receipt, and supplier-return tests at the SI level.

**F-55 · Medium · area E/F/I** — C-2a can tighten the CHECK while leaving unrepairable `paid` rows behind. PLAN says the migration “lists” unhashed fiscal Paid rows while also removing Paid from the allowed status domain (`PLAN-phase2-3-P-R.md:54-59`). The current trigger permits status changes on SEALED rows (`2025_12_11_054716_add_document_immutability_trigger.php:24-57`), so the trigger is not the blocker; residual classification is. Consequence: migration failure or silent orphaning depends on operation order. Required change: make the migration preflight abort if any `paid` row cannot be deterministically mapped, emit the quarantine list before DDL, and prove zero residuals before installing the CHECK. Add a PG migration test with an unhashed fiscal Paid row.

**F-56 · Medium · area D/E/I** — The `received→confirmed + fully_fulfilled` migration freezes a gross historical verdict without recomputing supplier returns. PLAN specifies a literal backfill (`PLAN-phase2-3-P-R.md:74-76`), while current Received is set when gross paid and free receipt counters reach ordered quantities (`GoodsReceiptService.php:723-734,928-942`). The r2 model instead defines purchase fulfilment net of supplier returns (`SPEC-document-lifecycle-dimensions.md:109-112`). Consequence: a historically Received PO with returned goods is backfilled as fully fulfilled when the new canonical verdict is partial. Required change: status migration must invoke or reproduce the canonical net recompute from GR and SGRN provenance; do not assign fully fulfilled solely from old status. Add full-receipt-then-return migration coverage.

**F-57 · Medium · area E/F/I** — `createDraft()` is still bundled into an oversized enforcement lane and its contract is not normative. PLAN combines the PHPStan rule, raw-writer census, seeders/factories, transition routing, and the creation API in C-2c-c (`PLAN-phase2-3-P-R.md:100-102`). Direct production creation remains widespread, including POS (`POSAccountChargeDraftService.php:49-73`) and historical imports (`ArApOpeningService.php:311-332`). Consequence: allowing a caller-supplied status, or failing to route a creation shape, reopens the bypass after the static rule is widened. Required change: split a creation-boundary lane; define `createDraft()` as always forcing Draft and rejecting/removing caller status. Give historical openings and transactional two-hop creators separate explicit APIs and census fixtures.

**F-58 · Medium · area F/G/H** — C-3b5 requires an owner-ruled `allow` country, but no such country exists in the rulings. TN is bound to `require_delivery_first` (`expert-rulings-deferred-revenue-vat-issuance.md:23-31`), and FR has no approved deferred-revenue mapping (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:24`). PLAN nevertheless places `allow` activation in the strict chain (`PLAN-phase2-3-P-R.md:15,49`). Consequence: the strict program ends in an unauthorized seed flip or an undischargeable lane. Required change: stop the strict chain at C-3b4; make C-3b5 owner-gated and name the approved country/account mapping before dispatch.

## r1+r2 findings disposition

| Prior finding | Disposition | Evidence |
|---|---|---|
| F-1 | PARTIAL | r1 identified unreachable universal closure (`2026-08-24-sc-spec-plan-r1.md:9`). A per-type table exists (`SPEC:24-39`), but F-41 shows its “fully invoiced” facts remain wrong. |
| F-2 | RESOLVED | Type-specific CN, historical-opening, metadata, and tolerance bases are now explicit (`SPEC:92-102`). The AP-side modeling defect is separately F-42. |
| F-3 | RESOLVED | VAT predicate is grouped, Expense retained, SI excluded, and delta approval required (`SPEC:169-176`; `PLAN:95-97`). |
| F-4 | RESOLVED | Four chains, VOIDED membership, hash/sequence membership, sealed-time provenance, and verifier tests are specified (`SPEC:120-129`; `PLAN:86-90`). |
| F-5 | RESOLVED | C-0 immediately refuses PO allocations and supplier-side generic reversal (`PLAN:22,52-53`). |
| F-6 | RESOLVED | Schema, sales aggregate, and purchase aggregate lanes now precede their consumers (`PLAN:11-13,29-31`). |
| F-7 | RESOLVED | Exact receipt-slice ledger, quarantine, counter reversal, JE reversal, and period/allocation refusals are specified (`SPEC:147-153`; `PLAN:91-94`). |
| F-8 | PARTIAL | All 13 types are tabled (`SPEC:22-39`), but historical AP and POS-origin semantics remain false or absent: F-42/F-50. |
| F-9 | PARTIAL | Canonical aggregation, locking, indexes, and cost limits were added (`SPEC:104-114`), but root authority still incorrectly relies on `source_document_id`: F-43. |
| F-10 | RESOLVED | Purchase policies now have one vocabulary, explicit inherited/override state, country rows, and typed missing-row refusal (`SPEC:155-162`). |
| F-11 | PARTIAL | Raw-writer census and `createDraft()` are named (`PLAN:100-102`), but the creation contract and lane size remain unresolved: F-57. |
| F-12 | PARTIAL | 472 work is split and full VAT is stated (`SPEC:163-167`), but the matrix omits stamp/residual: F-46. |
| F-13 | RESOLVED | Confirmed-unposted output is explicitly proforma/non-definitive with no VAT block (`SPEC:83`). |
| F-14 | RESOLVED | Authority sub-state, fail-closed guard, pending activation, and a later QR activation lane are defined (`SPEC:84-90`; `PLAN:98-99,121`). |
| F-15 | RESOLVED | The locked non-paid→paid event edge and replay/re-pay tests are specified (`SPEC:99-101`; `PLAN:64-66`). |
| F-16 | RESOLVED | Overpaid is explicitly defensive-only, with a seeded-row test (`SPEC:92-94`; `PLAN:58-59`). |
| F-17 | RESOLVED | C-2a retains Received temporarily; C-D0a owns the final CHECK and Slice D parity (`PLAN:23,32,74-76`). |
| F-18 | PARTIAL | Hot-file serialization and many lane splits were added (`PLAN:3-16`), but C-2c-c and several program lanes remain oversized/incomplete: F-51/F-57. |
| F-19 | RESOLVED | Reconciliation is exposed as a DTO boolean pending owner ruling (`SPEC:99`; owner sheet `:9`). |
| F-20 | PARTIAL | The test matrix is much stronger (`PLAN:51-109`), but F-41–F-58 identify untested source facts and races. |
| F-21 | RESOLVED | Expense/Income now use their metadata facts, no balance seeding, and no fully-paid event (`SPEC:36-37,97-101`). |
| F-22 | RESOLVED | Exact supplier-invoice reversal ledger and effects are normative (`SPEC:147-153`). |
| F-23 | PARTIAL | Per-type closure and recompute links exist (`SPEC:24-39,58-76`), but expiry and invoicing facts remain incomplete: F-41/F-44. |
| F-24 | RESOLVED | Sales-CN denominator is explicitly ex-stamp and tests cover stamped/application/refund shapes (`SPEC:31,95-96`; `PLAN:54-59`). |
| F-25 | RESOLVED | One transaction-local port and an executable mutation census are specified (`SPEC:46-56`; `PLAN:60-63`). |
| F-26 | RESOLVED | Advisory genesis serialization and unique company/type/sequence constraint are specified (`SPEC:121-125`; `PLAN:86-88`). |
| F-27 | RESOLVED | Deterministic sealed-time provenance, quarantine, frozen field, and tamper/backdating tests are present (`SPEC:126-129`; `PLAN:89-90`). |
| F-28 | PARTIAL | Persistent seal/JE facts and line/delete triggers fix normal non-fiscal documents (`SPEC:130-133`), but historical openings remain unarmed: F-48. |
| F-29 | RESOLVED | SupplierInvoice is removed from C-R1b and moved to snapshot/backfill lane P-4 (`SPEC:173-176`; `PLAN:116`). |
| F-30 | RESOLVED | Materialized defaults are migrated to inherited state; missing rows fail typed (`SPEC:157-162`). |
| F-31 | RESOLVED | `transition()` owns transaction, scoped reload, `FOR UPDATE`, atomic audit write, stale/concurrency tests (`SPEC:143-145`; `PLAN:100-102`). |
| F-32 | PARTIAL | Lock order, indexes, and query budget are explicit (`SPEC:104-114`), but the chosen root relation is unsound: F-43. |
| F-33 | RESOLVED | Posted receipts are explicitly irreversible in the strict program and P-5 owns reversal (`SPEC:117-118`; `PLAN:117`). |
| F-34 | RESOLVED | Delivery gate is policy-aware; `allow` passes partial ratios to deferral (`SPEC:115-116`; `PLAN:81-82`). |
| F-35 | RESOLVED | TN seed stays inactive until QR client activation; transitions and callback idempotency are tested (`SPEC:84-90`; `PLAN:98-99,121`). |
| F-36 | RESOLVED | DeferredRevenue is a semantic purpose with TN mapping, provisioning/backfill, typed refusal, and FR absent (`SPEC:163-167`; `PLAN:43-49,107-109`). |
| F-37 | RESOLVED | CorrectingEntry is SEALED for immutability but excluded from hash-chain membership (`SPEC:38,121-122`; `PLAN:86-88`). |
| F-38 | PARTIAL | Program lanes now have dependencies, migrations, and tests (`PLAN:111-121`), but P-3/R-3 still omit complete disposition/cap semantics: F-51/F-52. |
| F-39 | PARTIAL | Generated contract and FE census are planned (`PLAN:67-72`), but deprecated PHP cases still regenerate retired values and receipt payload readers remain: F-45/F-49. |
| F-40 | PARTIAL | Most previously missing regressions are now named (`PLAN:51-109`); closure source facts, AP openings, POS, dual-period cancellation, combined CN races, and migration residuals still lack tests. |

## OQ dispositions table

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Add SupplierInvoice Confirmed hop | ACCEPT | Valid when both hops, posting, and transition logs share one locked transaction. |
| OQ-2 | Census before trusting supplier payment type | REJECT — migrate/type supplier payments | Census alone leaves persisted `DocumentPayment` semantics wrong; repair is required before P-2. |
| OQ-3 | Defer PO-prepayment direction defect | REJECT — immediate refusal | C-0’s typed refusal is mandatory until a correct 409 path exists. |
| OQ-4 | Retire lifecycle Received | ACCEPT, conditionally | Backfill must use canonical net fulfilment and migrate payload readers; a literal fully-fulfilled mapping is unsafe. |
| OQ-5 | Keep SI prepayment 422 until P-2 | ACCEPT | This is fail-safe while supplier advance GL is absent. |
| OQ-6 | Build minimal SupplierCreditNote path | ACCEPT, conditionally | “Minimal” must include creation, posting, application, unapplied cancellation/reversal, residue disposition, and tests. |
| OQ-7 | Leave FE match vocabulary to another ticket | REJECT — prerequisite | The FE contract must land before C-P1 exposes the Confirmed hop. |
| OQ-8 | Add a country policy layer | REJECT — one inherited authority | Country defaults must feed the existing vocabulary through explicit inherited/override state. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT | Include historical postings, child lines, deletion, and protection after cancellation. |
| OQ-10 | Retire `Document::getPaymentStatus()` | ACCEPT | One cached recompute authority eliminates divergent formulas. |
| OQ-11 | Remove `in_payment` from status | NEEDS-OWNER | Keep `has_unreconciled_payments` as the fail-safe DTO fact pending product presentation choice. |
| R-OQ-1 | CN lifecycle Paid is a defect | ACCEPT | Settlement belongs only in `payment_status`. |
| R-OQ-2 | Cash refund in R-3 | ACCEPT, conditionally | R-3 must share the combined ex-stamp settlement cap and locking service. |
| R-OQ-3 | Standalone application in R-2 | ACCEPT | Correct once it shares the same cap and recompute authority. |
| R-OQ-4 | Refuse cancellation of applied CN | ACCEPT | Correct until compensating allocation events exist. |
| R-OQ-5 | Cache return on directly sourced line | REJECT — billing/provenance-root aggregate | Invoice/DN sibling unions cannot be represented safely by the directly referenced line. |
| R-OQ-6 | RN reversal document in R-4 | ACCEPT | Preserves the sealed original and append-only stock history. |
| R-OQ-7 | Seal RN at Confirmed | ACCEPT | Chain membership is correctly independent of lifecycle Posted. |
| R-OQ-8 | Delete ReturnNote metadata/refund method | NEEDS-OWNER | Live product disposition semantics prevent a safe technical default. |
| R-OQ-9 | Correct CN VAT predicate | NEEDS-OWNER | Proceed only after the per-period delta report is acknowledged. |
| R-OQ-10 | Leave dashboard revenue gross | NEEDS-OWNER | Netting CNs changes an owner-watched business figure. |
| R-OQ-11 | Historical postings take internal Confirmed hop | ACCEPT, conditionally | Preserve adjacency without false confirmation events, after the AR/AP-side defect is fixed. |
| R-OQ-12 | Add no B2B return policy | ACCEPT | Do not invent one; any future restriction must be country-seeded with company override. |

## Missed owner questions

- Which country, if any, is authorized to activate `allow` after the 472 engine lands? TN is `require_delivery_first`, while FR lacks an approved account mapping.
- Must historical AP openings be migrated to native SupplierInvoice/SupplierCreditNote types, or may an immutable AR/AP-side discriminator remain on Invoice/CreditNote?
- For a returned DeliveryNote, what durable event proves the owner-approved “returned closes DN” branch—RN confirmation, fully returned quantity, or linked CN disposition?
- Should Quote/RFQ expiry be persisted by a scheduled command or remain a clock-derived read-time condition?
- For POS account-charge invoices, is the fiscal event itself authoritative proof of physical fulfilment, or must imported lines retain product/service identity?
- For unapplied SupplierCreditNote residue, is the terminal disposition future supplier offset, supplier cash refund, or owner-approved manual closure?

## Lane resequencing

1. Merge N-6 and Q-11; finish Q-12 and the FE match-vocabulary prerequisite.
2. Add **C-H0 historical AR/AP shape remediation** before C-0/C-2a.
3. C-0: refuse PO allocations and supplier-side generic reversal.
4. Split Q-12 remediation into supplier-side payment typing/migration before P-2.
5. C-2a only after a zero-residual Paid preflight; then C-2b1a/b/c and C-2b2.
6. C-D0a must preserve receipt timestamps and recompute old Received rows from net facts.
7. C-D0b must use billing marks/line provenance as root authority.
8. C-D0c initially owns PO fulfilment only; move C-P2a’s receipt-allocation ledger before linked-SI fulfilment.
9. Add closure-fact lanes for SO/PO/DN conversion and Quote/RFQ expiry before C-3a2.
10. Add the dual VAT/accounting-period cancellation guard before C-P2b, CN cancellation, or R-4.
11. Run C-F1a/F1b and all `DocumentPostingService` work serially.
12. Split C-2c-c into creation boundary, production writer batches, seeder/factory census, and final PHPStan enforcement.
13. Stop the strict policy chain at C-3b4. C-3b5 remains owner-gated until a country is authorized.
14. Expand P-3 and R-3 as required by F-51/F-52 before their closure predicates become authoritative.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE:

- SupplierInvoice currently posts directly from Draft and updates separate paid/free receipt counters (`SupplierInvoicePostingService.php:153-254`).
- PurchaseOrder `Received` is a gross fulfilment verdict set by GoodsReceipt (`GoodsReceiptService.php:723-734`).
- `balance_due` computation includes sales-credit applications; the CN’s own outward residue still needs separate recompute semantics (`Document.php:698-708`; `2026_01_08_214145_add_balance_due_cache_trigger.php:27-48`).
- Returns can originate from an Invoice or DeliveryNote, and sibling traversal is ambiguous (`ReturnNoteService.php:321-335`).
- Existing fiscal verification is limited to Invoice/CreditNote and reconstructs from lifecycle/date assumptions (`VerifyFiscalChainsCommand.php:258-315`).
- Company procurement policy rows currently shadow hardcoded vertical defaults (`ProcurementPolicyResolver.php:24-51`; `TenantProvisioningService.php:137-160`).
- Supplier payments are persisted as DocumentPayment despite runtime supplier direction (`PaymentController.php:888-916,1225-1230`).
- The N-6 branch does close the direct CN-payment door: its classifier rejects CreditNote and is invoked by payment paths (`fix/campaign-n6-payment-advance:DocumentAllocationClassifier.php:65-84`; branch `PaymentController.php:1031,1471,1798,1881`).

FALSE, stale, or incomplete:

- “Historical AP openings are SupplierInvoices” is false (`ArApOpeningService.php:159-170,306-328`).
- “`source_document_id` identifies the canonical invoice root” is false for billing-mark and sibling-fan-out shapes (`DeliveryNoteBillingClaimService.php:107-139`; `ReturnNoteService.php:321-335`).
- “One `quantity_invoiced` predicate works for SO, PO, and DN closure” is false (`SalesOrderToInvoiceConverter.php:264-270`; `SupplierInvoicePostingService.php:153-254`; `DeliveryNoteBillingClaimService.php:83-89`).
- “`payload.fully_received` can be retired without semantic migration” is false because receipt timestamps still gate operations (`LandedCostService.php:449-458`; `OperationResolver.php:51-63`).
- “The stated 472 JE matrix is balanced for stamped invoices” is false (`AccountingService.php:672-689`).
- “Deprecating Paid/Received produces a clean generated status contract” is false (`generated.d.ts:848-855`).
- “One period-open check protects cancellation reversals” is false (`VatPeriodCancellationGuard.php:59-69`).
- “C-3b5 has an authorized activation country” is false under the current owner rulings (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:24`; expert ruling `:23-31`).

CHANGES-REQUESTED