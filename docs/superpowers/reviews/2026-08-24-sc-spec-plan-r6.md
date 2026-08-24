# Session C spec+plan adversarial review r6

## Verdict line

CHANGES-REQUESTED

## Findings

**F-88 · Critical · area E/F** — The retirement sequence cannot compile or run. C-2a removes the live `Paid` case before allocation, refund, tolerance, reporting, and web readers are routed; C-2b1c then demands a four-value generated contract while `Received` remains live; C-D0a2 removes `Received` before C-P1 removes the GoodsReceipt writer (`PLAN-phase2-3-P-R.md:38-49,58,102-119`). Today those later lanes still reference `DocumentStatus::Paid` (`PaymentController.php:1024,1604,1704,1770`; `MultiPaymentService.php:582`) and `DocumentStatus::Received` (`GoodsReceiptService.php:727-733`; `AgedPayablesService.php:192,363`). Consequence: C-2a produces undefined enum-case references, the four-value contract test is impossible before C-D0a2, and GoodsReceipt writes an absent case after C-D0a2. Required change: keep both legacy PHP cases and the Phase-1 CHECK until all writers/readers are routed; make C-D0a4 the single final retirement lane that runs the residual census, migrates both values, tightens the CHECK, removes both cases, regenerates the exact four-value TS contract, and updates Slice D parity.

**F-89 · Critical · area A/B/C** — POS account-charge invoices cannot follow the native Invoice posting/payment path. The bridge creates a normal Draft Invoice carrying the already-issued fiscal event’s totals (`POSAccountChargeDraftService.php:49-73`), while the same ACCOUNT_CHARGE event has already created Dr 411 / Cr revenue / Cr VAT (`GeneralLedgerService.php:4077-4080,4111-4166`; `TreasuryAccountChargeBridge.php:59-100`). SPEC nevertheless treats this provenance “as Invoice” for fiscal and payment behavior (`SPEC-document-lifecycle-dimensions.md:54-55`); standard posting dispatches another invoice GL (`InvoicePostedListener.php:18-33`), and a payment while Confirmed is classified as a new Cr-419 advance (`SPEC-document-lifecycle-dimensions.md:79-93`) even though POS already created 411. Consequence: posting can duplicate AR, revenue and VAT, while pre-post payment can leave both 411 and 419 outstanding. Required change: add a POS-account-charge provenance lane before C-R0p: the derived document must adopt the immutable POS fiscal event and existing `pos_account_charge` JE as its fiscal/GL authority, must never run native Invoice GL posting, and payments must clear the existing 411. Add `PosAccountChargeNoDuplicateFiscalOrGlTest` and `PosAccountChargePaymentClearsExistingReceivableTest`.

**F-90 · Critical · area B/E/F** — The new acceptance-before-chaining protocol is not crash-safe and its “immutable attempt row” ordering is impossible. SPEC says the posting transaction creates an attempt containing request, response, and outcome before it makes the request, then keeps rejected evidence durable (`SPEC-document-lifecycle-dimensions.md:100-109`). An external acceptance followed by DB rollback leaves the Ministry committed and AutoERP unaware; retrying can issue a second legal acceptance. Updating the row with the later response contradicts “immutable.” Current posting also refreshes without locking the document (`DocumentPostingService.php:96-104`), and the plan places transactional transition locking after F-2 (`PLAN-phase2-3-P-R.md:67-72`). Consequence: concurrent posts can create duplicate attempts or submit different payloads, and an accepted-but-uncommitted request cannot be recovered deterministically. Required change: specify two durable phases: commit an immutable request attempt with a stable authority idempotency key and payload digest; call the authority outside the DB transaction; then lock attempt and document, append an immutable response/result, revalidate the digest, take the chain lock, and seal once. Retrying after a crash must query/reuse the same authority identity. Move the document-locking transition core before F-2. Add accepted-response/DB-rollback, timeout-after-acceptance, concurrent-attempt and callback/retry tests.

**F-91 · Critical · area A/B/E** — Separate “sole writers” make fiscal posting impossible under the trigger-safe one-UPDATE rule. Lifecycle is owned by `DocumentStatusService`, fiscal fields by each sealing service, and `confirmed→posted` requires fiscal completion (`SPEC-document-lifecycle-dimensions.md:13-18,26-33`). Yet acceptance, sealing and posting must happen together (`SPEC-document-lifecycle-dimensions.md:103-109`). Phase 1 explicitly passes fiscal fields through `DocumentStatusService` so status and seal land in one UPDATE because a second UPDATE is trigger-unsafe (`fix/campaign-n6-payment-advance:DocumentStatusService.php:30-41,61-81`); current sealing writers also write status and fiscal data together (`DocumentPostingService.php:496-504`; `DeliveryNoteService.php:156-166`; `ReturnNoteService.php:631-660`). Consequence: validating fiscal completion against the pre-update row rejects the edge, while sealing first and transitioning second is unsafe; letting sealing services write status violates the claimed sole path. Required change: state that sealing services produce a typed fiscal post-image/intent, while `DocumentStatusService::transitionWithFiscalSeal()` validates the proposed post-image and writes lifecycle, fiscal fields, timestamps, and transition log in one UPDATE. Apply the same contract to DN/RN `draft→confirmed`.

**F-92 · Critical · area A/C** — The exhaustive classifier still misclassifies historical AP openings. It is normative over only `(DocumentType,status)` and admits every Posted Invoice as `ReceivableClearing` (`SPEC-document-lifecycle-dimensions.md:78-88`), but historical AP openings remain type Invoice/CreditNote and are distinguished only by `opening_side=ap` (`SPEC-document-lifecycle-dimensions.md:63,156-160`). Current treasury likewise treats only `SupplierInvoice` as supplier-side and sends every other document down the customer branch (`PaymentController.php:453-459,509-588,1098-1128`); `ArApOpeningService` confirms AP batches still mint Invoice/CreditNote (`ArApOpeningService.php:159-170,276-328`). Consequence: a Posted historical AP Invoice can become a customer collection, Dr bank / Cr 411, rather than a vendor payment, Dr 401 / Cr bank. Required change: classifier input must include immutable provenance/`opening_side`, not just type and status; explicitly admit `Invoice+posted+opening_side=ap` as vendor payment and AR as collection, and refuse historical CreditNotes per OQ-37. Expand every-entry-point applicability tests with AR/AP opening variants.

**F-93 · Critical · area A/C/F** — C-P2b promises a same-document repost that the lifecycle and immutability model forbid. `cancelled→∅` is normative (`SPEC-document-lifecycle-dimensions.md:29-33`), SI cancellation writes terminal Cancelled while preserving its posting seal fact (`SPEC-document-lifecycle-dimensions.md:244-254`), yet both SPEC and PLAN require “repost-after-cancel” success (`SPEC-document-lifecycle-dimensions.md:254`; `PLAN-phase2-3-P-R.md:125`). Consequence: the test can pass only by adding an illegal Cancelled→Posted edge or bypassing the single write path and persistent immutability trigger. Required change: replace the claim with “reposting the cancelled SI is refused; a replacement SI is a new document linked to the cancelled original.” Rename the test to `CancelledSupplierInvoiceRepostRefusedTest` and add a replacement-document workflow test.

**F-94 · Critical · area B/F/G** — C-QR0 cannot be policy-seeded and existing TN documents can bypass the later `required` flip. Authority state is initialized at document creation and `not_required` satisfies fiscal completion (`SPEC-document-lifecycle-dimensions.md:26-27,100-114`). But C-QR0 runs before authority-mode/status schema, while C-3a1 and the TN `required` seed arrive much later (`PLAN-phase2-3-P-R.md:28,67-68`). C-QR0 therefore either hardcodes `country_code=TN` or has no authority setting. More importantly, TN documents created before F-2 retain `not_required`; flipping the country row later does not convert them to `pending`, so they can satisfy completion without QR. Required change: move minimal authority-mode schema and the TN `required` seed into C-QR0; represent missing client availability separately and refuse. At F-2, migrate every unposted TN fiscal document to `pending`, resolve pre-existing Posted TN rows from immutable QR evidence or quarantine them, and make posting consult the current country mode as well as row state. Test a document created before the mode flip and legacy Posted rows.

**F-95 · High · area B/I** — The “VAT-free” proforma still mentions VAT and uses the tax-inclusive label `TTC`. SPEC requires no VAT label but then prints “VAT not invoiced — proforma” and “totals shown TTC” (`SPEC-document-lifecycle-dimensions.md:116-119`). The adopted expert ruling requires proforma documents without VAT mention (`2026-08-10-expert-rulings-deferred-revenue-vat-issuance.md:18-21`). Consequence: C-F0 preserves the Art. 18 exposure it is supposed to remove. Required change: normative wording should be: “Confirmed-unposted output contains no `VAT`, `TVA`, `tax`, or `TTC` label or amount; it displays `Estimated total` and `Proforma — non-fiscal document` only.” Extend `ProformaOutputTest` to assert absence of these tokens in en/fr/ar.

**F-96 · High · area A/C/F** — The SalesOrder payment formula has no durable monetary conversion fact, and the existing converter contradicts it. SPEC says the payment base drops by invoiced value and allocations move across one or several invoices (`SPEC-document-lifecycle-dimensions.md:142-148`), but current partial conversion transfers every allocation wholesale to the first invoice and immediately clears 419→411 (`SalesOrderToInvoiceConverter.php:529-588`). Converted invoice totals may also differ after discount stripping and recalculation (`SalesOrderToInvoiceConverter.php:241-256`), so quantity provenance cannot reconstruct “invoiced value.” SPEC explicitly leaves converter clear-at-conversion timing out of scope (`SPEC-document-lifecycle-dimensions.md:310-312`). Consequence: the order formula becomes wrong after the first partial invoice, later invoices lose their prepayment share, and 411 is created before the invoice posts. Required change: add a durable order-to-invoice monetary allocation ledger, define capped splitting of each prepayment with the untransferred residue remaining on the order, and remove 419→411 clearing from conversion—clearing occurs only when each target invoice posts. This cannot remain out of scope.

**F-97 · High · area A/D/F** — SupplierInvoice fulfilment uses `not_applicable` for missing evidence, contradicting the quarantine rule, and new unledgered posts remain possible. SPEC reserves `not_applicable` for positively proven no physical line (`SPEC-document-lifecycle-dimensions.md:16,163-169`) but assigns every SI `not_applicable` until the receipt ledger exists (`SPEC-document-lifecycle-dimensions.md:59`). Current SI posting has a compatibility path that increments PO counters even when no receipt line exists (`SupplierInvoicePostingService.php:169-198`), while C-P1 precedes the ledger lane (`PLAN-phase2-3-P-R.md:58-59`). The per-slice ledger is also an unsafe home for the sole pre-post match snapshot when an SI has zero slices (`SPEC-document-lifecycle-dimensions.md:244-249`). Consequence: missing receipt provenance masquerades as service-only, and post-cutover SIs can remain uncached and unreversible. Required change: unresolved/missing SI receipt provenance must be `not_fulfilled + fulfilment_quarantined`; eliminate or refuse the compatibility path for new posts once C-P2a lands; keep the header match snapshot in a posting-header record that exists even with zero receipt slices.

**F-98 · High · area A/C/F** — P-2’s supplier-advance target is unreachable. SI posting performs `draft→confirmed→posted` atomically, leaving no durable Confirmed interval (`SPEC-document-lifecycle-dimensions.md:29-33,59`; `PLAN-phase2-3-P-R.md:58`). P-2 nevertheless admits only `SupplierInvoice+confirmed` as the advance target (`SPEC-document-lifecycle-dimensions.md:97-98`; `PLAN-phase2-3-P-R.md:145`). Consequence: no user can record the modeled advance unless a request races inside the posting transaction. Required change: either add a durable SI confirm endpoint/UI and make posting accept only persisted Confirmed, or model supplier advances against a different explicit object such as a confirmed PO. The choice needs an owner ruling because it changes procurement workflow.

**F-99 · High · area A/D/I** — The new per-`(product,location)` returned verdict does not define how unlocated ordered capacity maps to delivery tuples. Deliveries are keyed by their actual line/document location (`DeliveredQuantityResolver.php:108-130`), but root document lines may have nullable location and central invoicing explicitly yields null (`2025_12_27_150000_add_location_id_to_document_lines_table.php:22-32`; `DocumentLine.php:220-240`). SPEC merely says `ordered=Σ root lines` per tuple (`SPEC-document-lifecycle-dimensions.md:170-179`). Consequence: an unlocated 10-unit order delivered 5 from L1 and 5 from L2 has no ordered tuple matching either delivery tuple and can remain partial forever—or be called returned by an invented allocation. Required change: define ordered capacity per product independently of location unless durable source-line provenance allocates it to locations; retain location tuples for delivery/return netting. Add multi-location delivery/return tests with unlocated roots.

**F-100 · High · area A/B/F** — r5 drops the program’s generic posting instant for non-fiscal documents. The handover requires persisted `posted_at` (`HANDOVER-document-lifecycle-dimensions-program.md:39-42`), but r5 adds only `sealed_at` for fiscal chains and historical-only `posted_at` (`SPEC-document-lifecycle-dimensions.md:18,59-63,156-160,208-216`). It then says SupplierInvoice’s reconstructed posting time should read `sealed_at` (`SPEC-document-lifecycle-dimensions.md:293-299`), although SI never seals; today the controller reconstructs `posted_at` from its JE (`SupplierInvoiceController.php:419-429`). Consequence: SI/Expense/Income have no stored posting instant and the planned reader cannot work. Required change: add immutable `documents.posted_at` for every lifecycle Posted type; fiscal Invoice/CN stamp `posted_at` and `sealed_at` in the same trigger-safe UPDATE, non-fiscal posters stamp `posted_at` with `posting_journal_entry_id`, and the hash serializer continues using the canonical date projection rather than silently changing historical bytes.

**F-101 · High · area B/E** — The proposed immutability whitelist allows accepted authority state to be rewritten after sealing. The persistent seal fact freezes fiscal core fields, but the whitelist explicitly includes `fiscal_authority_status` (`SPEC-document-lifecycle-dimensions.md:213-219`). Consequence: raw SQL can turn an accepted chain member into rejected/pending/not-required without changing its immutable hash or attempt history, violating fiscal completion and the “never reseal” rule. Required change: authority status may change while unsealed only; once the seal fact exists it must be frozen. Remove it from the sealed-row whitelist and add an authority-status raw-tamper case to `ImmutabilityWhitelistMatrixTest`.

**F-102 · High · area B/F** — The strict chain makes the compliance-critical QR unblock depend on unrelated, owner-blocked VAT reporting. C-R1b2 cannot merge until the owner acknowledges declaration deltas (`PLAN-phase2-3-P-R.md:65-66,126-130`), but F-2 is sequenced after it and after numerous unrelated purchase/return lanes (`PLAN-phase2-3-P-R.md:14-18`). Consequence: one unanswered reporting decision can keep every TN invoice blocked indefinitely, contradicting the claim that F-2 is the first fiscal lane after C-QR0 prerequisites (`SPEC-document-lifecycle-dimensions.md:110-114`). Required change: create an independent critical path `C-QR0 → transition-lock core → C-3a1 → F-2`; do not depend on C-R1b2, closure, SI reversal, CN settlement, or VAT-delta acknowledgement.

**F-103 · High · area F/G/I** — FR provisional policy can be bypassed by a company override. The owner default says provisional FR rows refuse posting until approved (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:15`), but SPEC gives company override precedence over the country row without defining `provisional` as a higher-order gate (`SPEC-document-lifecycle-dimensions.md:261-267`). Consequence: an FR company override can activate receipt-first or `allow_409` behavior that country expertise has not approved. Required change: `country_default.provisional=true` must refuse before override resolution; no company override may bypass it. Add `ProvisionalCountryPolicyCannotBeOverriddenTest`.

**F-104 · High · area C/I** — The paid-CreditNote repair still describes the wrong GL action. C-2p says it reverses the inbound allocation and “its JE” (`PLAN-phase2-3-P-R.md:37`), but that original customer-payment JE is Dr bank / Cr 411 (`PaymentController.php:1116-1128`; `GeneralLedgerService.php:337-385`). If the cash was actually received, reversing the whole JE falsely credits bank; the correct repair normally preserves cash and reclassifies Cr 411 to Cr 419 until an outward CN settlement is authored. Multi-document payment JEs make whole-entry reversal even less safe. Required change: specify per-allocation reclassification: remove/reverse only the bad CN allocation, preserve the repository/cash movement, post Dr 411 / Cr 419 for that amount, and quarantine any JE whose legs cannot be isolated. Add bank-unchanged, 411/419 and multi-allocation tests.

**F-105 · Medium · area F** — Several lanes still exceed the promised one-day/one-invariant size. C-2a combines multi-type backfill, enum retirement, seeders and factories; C-3a1 combines three schema surfaces, initializer, attempts and posting guard; C-3b1 creates country defaults, adds company/source columns, migrates existing rows, adds supplier advance policy, and removes the sales fallback (`PLAN-phase2-3-P-R.md:38,67,78`). Consequence: migration, policy, and runtime failures cannot be gated independently. Required change: split schema/backfill from runtime routing and final constraints for each of these lanes.

**F-106 · Medium · area I** — The red-first list does not prove the new r5 architecture at its dangerous seams. Missing tests include status-case removal only after all source references disappear; POS account-charge no-double-GL; QR accepted-but-local-rollback recovery; pre-mode-flip TN drafts; fiscal post-image same-UPDATE behavior; historical AP classification through every entry point; same-document SI repost refusal; multi-location unlocated-root fulfilment; generic non-fiscal `posted_at`; sealed authority-state tampering; and provisional-country override refusal (`PLAN-phase2-3-P-R.md:84-140`). Required change: add these exact tests to the owning lanes before dispatch.

## r1..r5 findings disposition

`S` = `SPEC-document-lifecycle-dimensions.md` r5; `P` = `PLAN-phase2-3-P-R.md` r5.

| Prior | Status | Current evidence |
|---|---|---|
| F-1 | RESOLVED | Per-type closure replaces the universal conjunction (`S:46-74`). |
| F-2 | PARTIAL | Type-specific bases exist, but historical AP classification and the SO monetary base remain unsound (`S:132-160`; F-92/F-96). |
| F-3 | RESOLVED | VAT predicate is grouped; Expense remains status-posted and SI is deferred (`S:286-288`). |
| F-4 | RESOLVED | Four chains, VOIDED membership and persisted seal time are normative (`S:199-212`). |
| F-5 | RESOLVED | Unsupported payment pairs default-refuse (`S:78-88`; `P:26`). |
| F-6 | RESOLVED | Shared fulfilment foundations precede P/R consumers (`P:46-59`). |
| F-7 | PARTIAL | Exact SI reversal is detailed, but same-document repost is impossible and zero-slice provenance remains (`S:244-254`; F-93/F-97). |
| F-8 | PARTIAL | All 13 types are tabled, but POS account-charge provenance is fiscally/accountingly wrong (`S:46-63`; F-89). |
| F-9 | PARTIAL | Canonical aggregation replaced direct-line caching, but ordered-location mapping is incomplete (`S:163-179`; F-99). |
| F-10 | RESOLVED | Procurement uses one inherited country/company authority (`S:261-267`). |
| F-11 | RESOLVED | Exact four-method status allowlist and raw-writer census are stated (`S:231-237,301-308`). |
| F-12 | RESOLVED | Deferred-revenue work and balanced VAT/stamp matrix are split (`S:269-275`; `P:77-82`). |
| F-13 | OPEN | C-F0 exists, but `TTC` and “VAT not invoiced” still violate the adopted no-VAT-mention rule (`S:116-119`; F-95). |
| F-14 | PARTIAL | QR is in scope and fail-closed, but protocol, mode migration and writer composition are unsafe (`S:100-114`; F-90/F-91/F-94). |
| F-15 | RESOLVED | Event emits on locked non-paid→paid cache edge without schema rename (`S:35-44`). |
| F-16 | RESOLVED | Overpaid is defensive-only (`S:132-137`). |
| F-17 | OPEN | Final CHECK ownership is named, but enum/CHECK/runtime ordering is impossible (`P:38-49`; F-88). |
| F-18 | PARTIAL | Lanes were split, but C-2a/C-3a1/C-3b1 remain oversized (`P:38,67,78`; F-105). |
| F-19 | RESOLVED | Reconciliation is a DTO boolean pending owner choice (`S:152`). |
| F-20 | PARTIAL | Coverage expanded, but F-106 lists remaining unproved claims. |
| F-21 | RESOLVED | Expense/Income use metadata basis, no allocations/event (`S:61,149`). |
| F-22 | PARTIAL | Receipt-slice ledger exists, but missing-slice compatibility and snapshot placement remain (`S:244-254`; F-97). |
| F-23 | RESOLVED | Explicit terminal conditions and durable expiry facts exist (`S:46-74`). |
| F-24 | RESOLVED | Sales-CN base is ex-stamp (`S:138-141`). |
| F-25 | RESOLVED | One recompute port and executable writer census are normative (`S:35-44`). |
| F-26 | RESOLVED | Advisory serialization and unique sequence are explicit (`S:199-207`). |
| F-27 | RESOLVED | Seal-time provenance, quarantine and tamper rules are explicit (`S:208-212`). |
| F-28 | PARTIAL | Persistent seal facts protect cancelled/non-fiscal rows, but authority status is wrongly whitelisted (`S:213-219`; F-101). |
| F-29 | RESOLVED | SI VAT snapshot/backfill is isolated in P-4 (`P:150`). |
| F-30 | RESOLVED | Materialized defaults migrate to inherited state (`S:261-264`). |
| F-31 | RESOLVED | `transition()` owns lock, transaction and audit row (`S:235-237`). |
| F-32 | PARTIAL | Root locking/index budget exists, but ordered-location attribution is incomplete (`S:163-179`; F-99). |
| F-33 | RESOLVED | Posted GR remains irreversible until explicit P-5 (`S:190-194`; `P:151`). |
| F-34 | RESOLVED | Gate behavior differs correctly under require versus allow (`S:195-197`). |
| F-35 | PARTIAL | Acceptance writer and retry protocol are planned, but crash/concurrency semantics are unsafe (`S:100-114`; F-90). |
| F-36 | RESOLVED | DeferredRevenue purpose/TN mapping/refusal are explicit (`S:269-275`). |
| F-37 | RESOLVED | CorrectingEntry is SEALED immutability-only, not a chain member (`S:62,199-204`). |
| F-38 | PARTIAL | Program lanes have dependencies/tests, but strict sequencing remains defective (`P:9-20`; F-88/F-102). |
| F-39 | PARTIAL | Reader census exists, but SI is incorrectly redirected to nonexistent `sealed_at` and TS timing is impossible (`S:293-299`; F-88/F-100). |
| F-40 | PARTIAL | Most old tests are named; new gaps remain in F-106. |
| F-41 | RESOLVED | Closure uses durable per-line facts and per-tuple DN returns (`S:65-74`). |
| F-42 | PARTIAL | `opening_side` exists, but the type/status classifier ignores it (`S:156-160`; F-92). |
| F-43 | RESOLVED | Billing marks and line provenance outrank legacy source inference (`S:163-169`). |
| F-44 | RESOLVED | Scheduled idempotent expiry supplies the fact (`S:70-72`). |
| F-45 | RESOLVED | `goods_received_at` is promoted while the boolean is retired (`S:190-194`). |
| F-46 | RESOLVED | 472 matrix includes full VAT, stamp and rounding (`S:269-275`). |
| F-47 | RESOLVED | Original VAT, all original JE periods and reversal period are guarded (`S:238-242`). |
| F-48 | RESOLVED | Historical `posted_at` arms immutability (`S:156-160,213-217`). |
| F-49 | OPEN | Target four-value contract is correct, but the removal sequence cannot pass (`S:21-25`; `P:38-49`; F-88). |
| F-50 | OPEN | Per-line POS fulfilment provenance is fixed, but POS fiscal/GL/payment provenance is still wrong (`S:181-189`; F-89). |
| F-51 | RESOLVED | P-3 is split through authoring, posting, application and cancellation (`P:146-149`). |
| F-52 | RESOLVED | One locked settlement service owns combined CN cap (`S:138-141`). |
| F-53 | RESOLVED | Payment identity stores side plus operation (`S:121-130`). |
| F-54 | PARTIAL | SI waits for the ledger, but the interim `not_applicable` value and compatibility posts are unsafe (`S:59,244-254`; F-97). |
| F-55 | PARTIAL | Residual preflight exists, but enum removal occurs before runtime cleanup and CN correction remains ambiguous (`P:37-44`; F-88/F-104). |
| F-56 | PARTIAL | Migration recomputes net receipt facts, but `Received` is removed before its live writer (`S:190-194`; `P:47,58`; F-88). |
| F-57 | RESOLVED | Draft/historical/reversal creation APIs are explicit (`S:231-234,306-307`). |
| F-58 | RESOLVED | No country activates `allow` without owner approval (`S:274-275`). |
| F-59 | RESOLVED | Classifier is intended to default-refuse (`S:78-88`). |
| F-60 | PARTIAL | Paid CNs quarantine, but repair must reclassify rather than reverse cash (`S:277-283`; F-104). |
| F-61 | PARTIAL | C-QR0 restores refusal, but pre-mode-flip and legacy TN rows bypass it (`S:100-114`; F-94). |
| F-62 | PARTIAL | Side+operation is correct for Payment rows, but classifier admission ignores historical `opening_side` (`S:121-130,156-160`; F-92). |
| F-63 | RESOLVED | Deposit application must clear 419→411 atomically (`S:94-96`; `P:36`). |
| F-64 | PARTIAL | C-F0 exists, but its normative output still mentions VAT/TTC (`S:116-119`; F-95). |
| F-65 | RESOLVED | Missing sales country row is a typed refusal (`S:256-260`). |
| F-66 | RESOLVED | POS kind now derives from immutable per-line projection identity (`S:181-189`). |
| F-67 | RESOLVED | `createPostedReversal()` and one-JE/two-hop semantics exist (`S:61,231-234`). |
| F-68 | RESOLVED | Triple-period rule matches the owner default (`S:238-242`). |
| F-69 | RESOLVED | r5 artifacts are self-contained (`S:1-9`; `P:1-7`). |
| F-70 | RESOLVED | Payment backfill ladder, quarantine and writer census are specified (`S:121-130`; `P:33-35`). |
| F-71 | PARTIAL | JE backfill is specified for major types, but generic `posted_at` and CorrectingEntry coverage are incomplete (`S:213-217`; F-100). |
| F-72 | RESOLVED | DN closure is per physical tuple (`S:57`). |
| F-73 | RESOLVED | Existing `valid_until` is backfilled rather than recreated (`S:51,70-72`; `P:50`). |
| F-74 | PARTIAL | Many lanes were split, but current oversized lanes remain (`P:38,67,78`; F-105). |
| F-75 | PARTIAL | Most r4 cases gained names; F-106 remains. |
| F-76 | RESOLVED | C-QR0 now refuses TN until F-2 (`S:110-114`; `P:28`). |
| F-77 | PARTIAL | Rejection no longer reseals a chain member, but the attempt protocol is not crash-safe (`S:103-109`; F-90). |
| F-78 | RESOLVED | Posting initializes only NULL/no-fact balances (`S:132-137`). |
| F-79 | PARTIAL | SO formula exists, but monetary split/provenance and conversion clearing are undefined (`S:142-148`; F-96). |
| F-80 | PARTIAL | Delivered-before-returned is required, but multi-location ordered capacity is undefined (`S:170-179`; F-99). |
| F-81 | RESOLVED | Quarantine is a separate blocking fact, not `not_applicable` (`S:16,163-169`). |
| F-82 | RESOLVED | Stable per-line receipt reference and duplicate-SKU tests are specified (`S:181-189`; `P:117`). |
| F-83 | PARTIAL | Snapshot/downstream refusal exist, but per-slice snapshot placement and repost semantics remain wrong (`S:244-254`; F-93/F-97). |
| F-84 | RESOLVED | Supplier advance mode has seeded storage, override and precedence (`S:265-267`). |
| F-85 | RESOLVED | Exact four-method allowlist and CorrectingEntry/historical guards are explicit (`S:221-237`). |
| F-86 | PARTIAL | Writer lanes and queue edges improved, but F-2 ordering and several lanes still violate the limits (`P:18-20`; F-102/F-105). |
| F-87 | RESOLVED | The F-77–F-84 regression names were added (`P:99-130,138`). |

## OQ dispositions table

### Legacy OQ-1..11 and R-OQ-1..12

| Question | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Add SupplierInvoice Confirmed | ACCEPT, conditionally | It must be a durable state/action if P-2 allocates advances to it; a transient atomic hop is insufficient. |
| OQ-2 | Census supplier payment typing | REJECT — persist side+operation | Census alone cannot govern GL direction or reversal. |
| OQ-3 | Defer wrong PO-prepayment direction | REJECT — immediate 422 | Keep PO allocation refused until a correct supplier-advance path exists. |
| OQ-4 | Retire lifecycle Received | ACCEPT, conditionally | Route writers/readers first; retire it only in the final status-domain migration. |
| OQ-5 | Keep unposted SI 422 until P-2 | ACCEPT | Safe while Dr 409/Cr bank and clearing are absent. |
| OQ-6 | Build minimal SCN path | ACCEPT | Minimum remains create/confirm/post/apply/cancel-unapplied/reverse/residue. |
| OQ-7 | Separate FE match vocabulary | REJECT — prerequisite | Must precede SI workflow exposure. |
| OQ-8 | Add country layer beside existing policy | REJECT — one inherited authority | Company override must sit over one country-seeded vocabulary. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT, conditionally | Freeze authority acceptance and include every persistent posting fact. |
| OQ-10 | Retire `getPaymentStatus()` | ACCEPT | Do so only after all runtime readers/writers are routed. |
| OQ-11 | Remove `in_payment` | NEEDS-OWNER | Keep `has_unreconciled_payments`; presentation remains a product choice. |
| R-OQ-1 | CN lifecycle Paid is a defect | ACCEPT | CN settlement belongs only in outward payment state. |
| R-OQ-2 | CN cash refund in R-3 | ACCEPT | Must use shared cap and customer-refund direction. |
| R-OQ-3 | Standalone CN application in R-2 | ACCEPT | Must use the same lock/cap/recompute service. |
| R-OQ-4 | Refuse applied-CN cancellation | ACCEPT | Safest without compensating allocation events. |
| R-OQ-5 | Direct source-line return cache | REJECT — canonical root aggregate | Invoice/DN unions cannot be represented on one source line. |
| R-OQ-6 | RN reversal document | ACCEPT | Preserves immutable fiscal and stock history. |
| R-OQ-7 | Seal RN at Confirmed | ACCEPT | Chain membership must remain lifecycle-independent. |
| R-OQ-8 | Delete/revive return metadata | NEEDS-OWNER | Refund disposition and live FE fields make this product scope. |
| R-OQ-9 | Correct CN VAT declaration | NEEDS-OWNER | Merge only after tenant/period deltas are acknowledged. |
| R-OQ-10 | Net dashboard revenue | NEEDS-OWNER | It changes an owner-visible metric. |
| R-OQ-11 | Historical rows take internal Confirmed hop | ACCEPT, conditionally | Emit no false event and classify from `opening_side`. |
| R-OQ-12 | Add no B2B returns policy | ACCEPT | Do not invent policy; future restrictions must be country-seeded. |

### Current owner-sheet OQ-11..45

| Question | Disposition | Reason |
|---|---|---|
| OQ-11 | NEEDS-OWNER | DTO boolean is safe; enum presentation is product policy. |
| OQ-12 | ACCEPT | Reserved identifier is harmless. |
| OQ-13 | ACCEPT | Supplier returns reduce net fulfilment; wording should say “eligible for replacement receipt,” not “receivable.” |
| OQ-14 | ACCEPT, conditionally | Proforma is correct, but r5 must remove `VAT`/`TTC` wording. |
| OQ-15 | ACCEPT | Synchronous refusal until acceptance remains binding. |
| OQ-16 | ACCEPT | Existing immutable event may retain “fully settled” semantics. |
| OQ-17 | ACCEPT, conditionally | Provisional must outrank and block company overrides. |
| OQ-18 | NEEDS-OWNER | Leave untouched until refund disposition is decided. |
| R-OQ-9 | NEEDS-OWNER | Delta acknowledgement remains mandatory. |
| OQ-19 | NEEDS-OWNER | Dashboard net revenue is an owner-visible metric. |
| OQ-20 | ACCEPT | It is now aligned to OQ-28. |
| OQ-21 | ACCEPT | Returned counts only after every applicable tuple was delivered and returned. |
| OQ-22 | ACCEPT | Cancelled is permanently closed. |
| OQ-23 | ACCEPT | Ex-stamp base matches production CN application. |
| OQ-24 | ACCEPT | Default-equivalent company rows may become inherited. |
| OQ-25 | ACCEPT | Preserve Expense/Income metadata settlement. |
| OQ-26 | ACCEPT | No FR `allow` without an approved mapping. |
| OQ-27 | NEEDS-OWNER | P-4 periods and delta need explicit approval. |
| OQ-28 | ACCEPT | RN closes at confirm independently of refund disposition. |
| OQ-29 | ACCEPT | No country activates `allow` by default. |
| OQ-30 | ACCEPT | `opening_side` is safer than historical type rewriting. |
| OQ-31 | ACCEPT | DN closure requires every physical tuple returned. |
| OQ-32 | ACCEPT | Scheduled expiry supplies a durable closure fact. |
| OQ-33 | ACCEPT, conditionally | Receipt provenance is authoritative, but must also prevent duplicate document GL/fiscal posting. |
| OQ-34 | ACCEPT | Retained future supplier credit is fail-safe. |
| OQ-35 | ACCEPT | All original VAT/JE and reversal periods must be open. |
| OQ-36 | ACCEPT, conditionally | Quarantine is correct; repair must preserve bank cash and reclassify 411→419. |
| OQ-37 | ACCEPT | Historical credits remain non-settleable. |
| OQ-38 | ACCEPT | Refusal stands; no unsigned seal-only override. |
| OQ-39 | ACCEPT | Quarantine only the affected chain. |
| OQ-40 | ACCEPT, conditionally | New immutable attempts are right, but require crash-safe external idempotency. |
| OQ-41 | ACCEPT, conditionally | “Uninvoiced remainder” needs a durable monetary conversion ledger. |
| OQ-42 | ACCEPT | Every tuple must first be delivered, then returned. |
| OQ-43 | ACCEPT | Refuse SI cancellation until children are explicitly reversed. |
| OQ-44 | ACCEPT | Nullable company override is acceptable outside provisional jurisdictions. |
| OQ-45 | ACCEPT | Quarantine blocks posting and closure. |

## Missed owner questions

- Is a POS ACCOUNT_CHARGE-derived Invoice merely the document/read-model representation of the already-posted POS fiscal event and JE, or is the owner authorizing a second fiscal invoice and second GL entry? The safe default is the former.
- Must SupplierInvoice confirmation be a durable user-visible state/action so supplier advances can target it, or should advances target PurchaseOrders?
- How are order prepayments divided across several partial invoices: FIFO capped to invoice total, proportional, or explicitly user-selected?
- What is the disposition of legacy TN Posted documents with no provable Ministry acceptance when authority mode becomes `required`: quarantine, grandfather under signed override, or tenant-by-tenant correction?
- For unlocated ordered lines fulfilled from multiple locations, is ordered capacity allocated by source-line provenance or evaluated product-wide?
- Does an SI replacement after cancellation require an explicit `replaces_document_id` relationship and copied supplier-reference audit?
- Should country `provisional=true` be an absolute jurisdiction gate that no company override can bypass? The fail-safe answer should be yes.

## Lane resequencing

1. Merge N-6, then immediately land C-0a and corrected C-F0.
2. Split a minimal authority-foundation lane from C-3a1: authority-mode schema, TN `required` seed, post-time mode resolver, and C-QR0 unavailable refusal. Do not hardcode TN.
3. Land transaction/locking and fiscal post-image transition core before any QR call.
4. Run the chain serialization/seal-time work required by F-2, then C-3a1 attempts and F-2 on an independent critical path. Do not wait for C-R1b2 or its owner delta acknowledgement.
5. Land C-T0 before cache migration.
6. Add `payment_status` schema/backfill while retaining live `Paid`; route all payment writers, reversers, event edges and readers; only later remove `Paid`.
7. Add fulfilment schema and canonical roots while retaining `Received`; route GoodsReceipt, AP readers, converters and FE; perform the net migration and remove `Received` only in the final status-domain lane.
8. Regenerate the exact four-value TypeScript contract and update Slice D parity only after both retired cases and all references are gone.
9. Resolve POS account-charge fiscal/GL/payment provenance before C-R0p and C-R1a.
10. Correct historical-opening classifier admission before enabling any opening payment path.
11. Make SI confirmation durable if P-2 retains `SupplierInvoice+confirmed`; then land the receipt ledger, SI fulfilment, and cancellation. Replace same-document repost with replacement-document tests.
12. Add the SalesOrder monetary conversion/prepayment ledger and remove clear-at-conversion before asserting the new SO payment formula.
13. Keep provisional-country gating above company override resolution.
14. Split C-2a, C-3a1 and C-3b1 into schema/backfill, runtime, and final-constraint lanes.

## Claims verified TRUE in the research sweeps that the spec relies on (short list) and claims found FALSE/stale

Verified TRUE against the current tree:

- SupplierInvoice still posts Draft→Posted directly, seeds `balance_due=total`, and stores match status in the same save (`SupplierInvoicePostingService.php:286-292`).
- Gross full receipt still writes lifecycle `Received`; partial receipt leaves the prior status (`GoodsReceiptService.php:723-733`).
- The generic PG balance trigger subtracts both payment allocations and sales credit-note applications (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-52`), with the CN trigger corrected separately (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-70`).
- Current chain head and verifier still depend on lifecycle status; the verifier covers only Invoice/CreditNote and substitutes `document_date` (`DocumentPostingService.php:462-504`; `VerifyFiscalChainsCommand.php:252-315`).
- Return quantities are currently netted per actual `(product,location)` over Invoice plus backing DNs (`DeliveredQuantityResolver.php:79-167,479-540`).
- Supplier payments remain persisted without production writes of `PaymentType::SupplierPayment`; the only app references are readers/refusal logic (`PaymentRefundService.php:1379`; `Payment.php:313,351`; `CashMovementsReportService.php:97,328`).
- POS ACCOUNT_CHARGE already recognizes AR, revenue and VAT before its derived document is posted (`GeneralLedgerService.php:4077-4080,4111-4166`).
- Partial SO conversion currently moves every prepayment allocation to the invoice and clears 419 immediately (`SalesOrderToInvoiceConverter.php:529-588`).

FALSE, stale, or incomplete:

- “Removing Paid in C-2a and routing writers later is a valid staged rollout” is false; the later writers still reference the removed case.
- “Generated status contract is exactly four values in C-2b1c” is false while `Received` remains live until C-D0a2.
- “SupplierInvoice can be reposted after cancellation” is false under the normative terminal adjacency.
- “`sealed_at` replaces posting time for SupplierInvoice” is false; SI never seals and currently reconstructs `posted_at` from its JE.
- “POS account-charge Invoice behaves as a native Invoice” is false without duplicating fiscal/GL recognition.
- “SupplierInvoice+confirmed advances are implementable” is false while Confirmed exists only as an in-transaction hop.
- “C-QR0 is seeded policy” is false while it precedes authority-mode schema.
- “An immutable attempt row can be created with its eventual response before the external request” is internally impossible.
- “All r1–r5 findings are applied” is false: F-13/F-17/F-49/F-50 remain open, and numerous others are only partial as tabled above.

CHANGES-REQUESTED