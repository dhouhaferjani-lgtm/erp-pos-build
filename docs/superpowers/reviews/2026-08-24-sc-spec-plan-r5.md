# Session C spec+plan adversarial review r5

## Verdict line

CHANGES-REQUESTED

## Findings

**F-76 · Critical · area B/F/H** — OQ-38 is not a fail-safe default. Co-landing F-2 with the TN `required` seed correctly resolves F-35’s activation deadlock, but the preceding N-6→F-2 interval still permits TN lifecycle `posted` without Ministry acceptance. The binding mandate defines TN posting as seal plus QR, and OQ-15 already defaults to refusal until acceptance (`HANDOVER-document-lifecycle-dimensions-program.md:3-7`; `BRIEF-N6-payment-advance.md:8-12`; `OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:13`). SPEC instead explicitly permits seal-only TN posting and calls owner acknowledgement the default (`SPEC-document-lifecycle-dimensions.md:104-108`; `OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:37`). Consequence: the plan knowingly ships documents that violate the binding definition of `posted`; an unanswered owner cell cannot override that mandate. Required change: replace §2.3’s window text with: “Until F-2 and its `required` TN seed deploy atomically, TN `confirmed→posted` is refused with `FISCAL_AUTHORITY_UNAVAILABLE`; no seal-only TN posting is permitted. An explicit signed owner ruling may authorize a different temporary posture.” Add an immediate post-N-6 blocking lane if F-2 cannot ship immediately.

**F-77 · Critical · area B/E/I** — The QR state machine and rejected-request retry are internally impossible under the proposed append-only chain. The only listed authority transitions start at `pending`, with no normative initializer from `not_required`, while rejection says the document seal is VOIDED and “resealed on retry” (`SPEC-document-lifecycle-dimensions.md:98-103`). The same spec makes hash, sequence and `sealed_at` immutable, retains VOIDED rows as chain members, and arms immutability permanently from the seal fact (`SPEC-document-lifecycle-dimensions.md:179-199`). Reusing the same row would either rewrite an immutable chain member or leave the old member without an identity. Consequence: rejected TN documents cannot retry without corrupting the fiscal chain or violating C-8b. Required change: define initial authority state by country mode and choose one append-only protocol: preferably obtain acceptance against a pending fiscal payload before the document joins the chain, then seal/post once; alternatively persist immutable authority-attempt rows and mint a new corrective/replacement document after rejection. Delete “VOIDED-and-resealed on retry.” Add `QrRejectedAttemptIsAppendOnlyTest`, `QrRetryNeverRewritesChainMemberTest`, and a test for the initial `not_required|pending` state.

**F-78 · High · area A/C/I** — “Seed `balance_due = total` at posting” destroys confirmed-document prepayment state. The PG trigger already computes `total − payment allocations − credit-note applications` (`2026_01_08_214145_add_balance_due_cache_trigger.php:28-48`; `2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:38-56`), and N-6 treats a non-null cache as authoritative (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Document.php:698-708,739-756`). N-6 then clears advances and decides full prepayment after posting (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:148-156`). SPEC nevertheless mandates resetting Invoice and SI caches to total (`SPEC-document-lifecycle-dimensions.md:126-130`). Consequence: an invoice prepaid while Confirmed can post with `balance_due=total`, fail to become payment-paid, and retain a stale cache because clearing 419→411 does not mutate the allocation table. Required change: replace the seed rule with: “At posting, call the recompute port after all posting/advance-clearing facts; initialize to total only when the cache is NULL and no allocation/application facts exist. Never overwrite a non-null authoritative opening or prepayment balance.” Add zero-, partial-, and fully-prepaid posting tests for Invoice and future SI advances.

**F-79 · High · area A/C/I** — SalesOrder has no payment-status formula. The applicability matrix admits confirmed-order prepayments and says `payment_status` moves (`SPEC-document-lifecycle-dimensions.md:52,88-91`), but the normative bases enumerate Invoice/SI/openings, CN/SCN, Expense/Income and historical rows without SalesOrder (`SPEC-document-lifecycle-dimensions.md:126-138`). The plan merely names “SalesOrder prepaid” in a broad recompute test (`PLAN-phase2-3-P-R.md:92-94`). Consequence: implementations can disagree on whether the base is total, remaining uninvoiced value, transferred allocation residue, or not-applicable after conversion. Required change: add a SalesOrder formula covering draft, confirmed, partial/full prepayment, transfer to one or several invoices, reversal, cancellation and excess-to-advance behavior; give it dedicated tests.

**F-80 · High · area A/D/I** — The aggregate `returned` formula can declare an incompletely delivered root returned. It requires only one confirmed return and `net ≤ 0` for every tuple (`SPEC-document-lifecycle-dimensions.md:155-159`). For lines A and B, delivering and returning A while never delivering B satisfies that formula because both tuples have non-positive net. Invoice closure then accepts `returned` as terminal fulfilment (`SPEC-document-lifecycle-dimensions.md:54`). Consequence: a paid invoice can close although line B was never fulfilled. Required change: define `returned` per ordered physical tuple, requiring evidence that every tuple previously reached the applicable delivered quantity and confirmed returns exhaust that quantity, or adopt an owner-approved linked-credit rule. Add a mixed “A delivered+returned, B never delivered” negative test.

**F-81 · High · area A/D/I** — Quarantine is incorrectly represented as terminal `not_applicable`. Ambiguous roots are assigned `not_applicable` (`SPEC-document-lifecycle-dimensions.md:150-154`), and legacy POS lines with unresolved kind are also NULL plus `not_applicable` (`SPEC-document-lifecycle-dimensions.md:163-169`). Invoice closure accepts `n/a` as fulfilled (`SPEC-document-lifecycle-dimensions.md:54`). Consequence: missing provenance becomes indistinguishable from a positively proven service-only invoice and can permit posting/closure. Required change: reserve `not_applicable` for positively proven no-physical-line documents. Quarantined provenance must be a separate blocking fact or map fail-closed to a non-terminal verdict; posting and closure must refuse it. Add ambiguous-root and unresolved-line-kind refusal tests.

**F-82 · High · area D/F/I** — The proposed POS line-kind source cannot classify individual receipt lines. Account-charge document lines discard `product_id` (`POSAccountChargeDraftService.php:75-94`). Stock movements carry only receipt-level `reference_type/reference_id`, product, variant and location—not receipt-line identity (`StockMovement.php:22-45,62-85`; `PosCoreReceiptProjection.php:2042-2064`). The projection itself acknowledges movements have no line IDs (`PosCoreReceiptProjection.php:2474-2477`). In contrast, `pos_receipt_lines.stock_movement_expected` is an immutable per-line projection decision (`ReceiptLine.php:21-37,70-106`; `PosCoreReceiptProjection.php:1311-1324`). Consequence: duplicate-SKU, mixed physical/service, or no-movement lines can be assigned the wrong kind; absence of a movement is not proof of service. Required change: C-R0p must map account-charge lines to immutable receipt-line projections using receipt line number/stable canonical identity and `stock_movement_expected`; movements may corroborate but not identify a line. Unmatched mappings quarantine and block. Add duplicate-product, mixed-kind and projection-hole tests.

**F-83 · High · area C/D/F/I** — SupplierInvoice cancellation still lacks downstream-credit guards and a durable pre-post match snapshot. §7 promises exact counter/ledger reversal and restoration of the prior `match_status`, but its ledger schema contains no pre-post snapshot (`SPEC-document-lifecycle-dimensions.md:218-225`). Current posting computes a local match result and then overwrites `match_status` (`SupplierInvoicePostingService.php:128-134,286-292`). Current SupplierCreditNote posting independently decrements the same receipt/PO ordinary and bonus counters and creates/confirm a supplier goods-return note (`SupplierCreditNotePostingService.php:301-324,566-625,727-774`). The cancellation guard mentions allocations, periods and quarantined ledger rows, but not live SCNs/SGRNs (`SPEC-document-lifecycle-dimensions.md:207,222-225`). Consequence: cancelling an SI after a posted SCN can double-reverse counters, GR-IR and stock; “restore snapshot” is not implementable. Required change: C-P2a must persist the pre-post match snapshot. C-P2b must refuse while any non-cancelled downstream SCN/SGRN exists, unless a separately specified reverse-in-dependency-order cascade is selected. Add both refusal and snapshot-restoration tests.

**F-84 · High · area F/G/I** — `supplier_advance_mode` has no owning schema, seed, override or precedence lane. SPEC names it only as a country policy (`SPEC-document-lifecycle-dimensions.md:95-96,232-236`). C-3b1 owns procurement defaults and `procurement_policies.source`, but does not name this field (`PLAN-phase2-3-P-R.md:69`); P-2 lists `supplier_advance_mode` while declaring no migration (`PLAN-phase2-3-P-R.md:134`). Today the resolver falls back from a company row to a hardcoded vertical default (`ProcurementPolicyResolver.php:14-17,24-41`). Consequence: P-2 must invent storage or hide another code default, violating the seeded-per-country mandate. Required change: assign the column/table, country seed values, provisional behavior, company override semantics and `override > country row > typed refusal` precedence to C-3b1 or a dedicated migration lane; make P-2 depend on it. Add missing-row, inherited, override and provisional-country tests.

**F-85 · Medium · area A/E/I** — The per-type guard and creation enforcement contracts are not exhaustive. CorrectingEntry has a distinct lifecycle with no cancellation (`SPEC-document-lifecycle-dimensions.md:62`), but it is absent from every family in the guard table (`SPEC-document-lifecycle-dimensions.md:201-208`). The spec also calls `transition()` the sole writer while separately permitting three creation APIs (`SPEC-document-lifecycle-dimensions.md:13-19,269-276`). Phase 1’s PHPStan rule has a single allowed class and explicitly cannot prove raw SQL, unresolved enum expressions, migrations or seeders (`fix/campaign-n6-payment-advance:apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:48-69`). Consequence: implementers must guess whether initial Draft insertion and special posted creation are allowlisted, and CorrectingEntry may accidentally inherit `posted→cancelled`. Required change: add CorrectingEntry and historical-opening rows to the edge-guard table and state the exact allowed classes/methods for initial writes. Require all special posted creators to record the two transitions atomically; keep raw-writer and DB-CHECK coverage explicit.

**F-86 · Medium · area F** — Several r4 lanes still exceed the stated one-day/one-invariant limit, and program lanes are not ordered against the strict-chain hotspots. The plan imposes the limit and serializes several files (`PLAN-phase2-3-P-R.md:3-7`) but C-2b1a routes numerous treasury writers plus adds the port and census (`PLAN-phase2-3-P-R.md:36,95-97`); C-D0a combines two status migrations, three dimensions, timestamp promotion, readers, final CHECK and Slice-D parity (`PLAN-phase2-3-P-R.md:40,101-102`); C-2c writer batches and creation enforcement remain cross-module (`PLAN-phase2-3-P-R.md:60-63`). R-2/R-3/R-4 and P-3 are separately listed without edges preventing collision with later strict lanes (`PLAN-phase2-3-P-R.md:131-144`). Consequence: review gates cannot isolate regressions, and PaymentController, DocumentStatusService, ReturnNoteService and posting services can be edited concurrently despite the prose rule. Required change: split the oversized lanes by writer family/invariant and add explicit queue edges for every program lane touching a declared hotspot.

**F-87 · Medium · area I** — Named tests still omit the newly exposed failure modes. Existing tests cover broad happy-path matrices (`PLAN-phase2-3-P-R.md:92-129`) but not: preservation of confirmed prepayment during posting; the SalesOrder formula; mixed returned/never-delivered lines; quarantine blocking closure; duplicate POS product lines; SI cancellation after SCN/SGRN; match snapshot persistence; QR retry chain immutability; or supplier-advance policy precedence. Consequence: all corresponding normative claims could ship green while wrong. Required change: add the exact regressions required in F-77 through F-84 to their owning lanes before dispatch.

## r1..r4 findings disposition

`S` = current SPEC r4; `P` = current PLAN r4.

| Prior | Disposition | Current evidence |
|---|---|---|
| F-1 | PARTIAL | Per-type closure now exists (`S:48-72`), but `returned` and quarantined `n/a` can still close incorrectly (`S:54,153-169`). |
| F-2 | PARTIAL | Type-specific bases exist (`S:126-138`), but SalesOrder is omitted and posting resets authoritative balances. |
| F-3 | RESOLVED | VAT is grouped by type; Expense remains lifecycle-posted (`S:254-256`; `P:116-117`). |
| F-4 | RESOLVED | Membership is hash/sequence based, includes VOIDED, and covers four chains (`S:179-192`; `P:111-113`). |
| F-5 | RESOLVED | C-0a is exhaustive/default-refuse and PurchaseOrder stays refused (`S:76-86`; `P:76-78`). |
| F-6 | RESOLVED | A shared fulfilment foundation/root path precedes sales and purchase consumers (`P:40-50`). |
| F-7 | PARTIAL | Exact SI reversal is specified (`S:218-225`), but downstream SCN/SGRN and snapshot persistence remain missing. |
| F-8 | RESOLVED | All thirteen types plus historical/POS families have an applicability row (`S:46-63`). |
| F-9 | RESOLVED | Direct source-line cache was replaced by canonical-root aggregation (`S:148-162`). |
| F-10 | RESOLVED | Purchases now use inherited country defaults feeding one existing policy vocabulary (`S:227-236`). |
| F-11 | PARTIAL | Draft/historical/reversal APIs and raw census exist (`S:269-276`), but the exact static-rule creation allowlist remains unstated. |
| F-12 | RESOLVED | 472 work is split and the full balanced posting matrix is normative (`S:237-243`; `P:68-73`). |
| F-13 | RESOLVED | C-F0 makes confirmed-unposted output VAT-free proforma (`S:110-113`; `P:79-80`). |
| F-14 | OPEN | Authority storage exists, but r4 deliberately permits a seal-only TN window (`S:98-108`). |
| F-15 | RESOLVED | Event emission is tied to the locked payment edge without schema renaming (`S:35-44`; `P:98`). |
| F-16 | RESOLVED | Overpaid is expressly defensive-only (`S:126-130`). |
| F-17 | RESOLVED | C-2a and C-D0a own successive constraint domains; Slice D updates only at the final migration (`P:35,40`). |
| F-18 | PARTIAL | Large lanes were split substantially, but C-2b1a, C-D0a and writer/creation batches remain oversized (`P:36,40,60-63`). |
| F-19 | RESOLVED | OQ-11 now acknowledges reconciliation facts and preserves a DTO boolean (`S:138`; owner sheet `:9`). |
| F-20 | PARTIAL | Many explicit tests were added (`P:75-129`), but F-87 identifies remaining unproved claims. |
| F-21 | RESOLVED | Expense/Income use metadata, not allocations or `balance_due` (`S:61,135`). |
| F-22 | PARTIAL | Exact receipt allocation ledger now exists in the design (`S:218-225`), but cancellation’s downstream-credit behavior remains incomplete. |
| F-23 | PARTIAL | The closure table is implementable for most types (`S:48-72`), but the new returned/quarantine predicates are unsafe. |
| F-24 | RESOLVED | Sales CN base is explicitly ex-stamp and combines applications plus refunds (`S:131-134`). |
| F-25 | RESOLVED | The port fact/writer census is executable and broad (`S:35-44`; `P:95-99`). |
| F-26 | RESOLVED | Advisory locking, unique sequence and concurrent genesis/tail tests are specified per chain (`S:185-187`; `P:111-113`). |
| F-27 | RESOLVED | `sealed_at` has provenance precedence, freezing and quarantine rather than guessed dates (`S:188-192`). |
| F-28 | RESOLVED | Non-fiscal seal facts persist after cancellation and protect headers/lines/deletes (`S:193-199`; `P:123-124`). |
| F-29 | RESOLVED | SupplierInvoice VAT snapshot work moved to P-4; current predicate does not pretend SI tax details exist (`S:254-256`; `P:139`). |
| F-30 | RESOLVED | Provisioned rows become inherited; true overrides outrank country defaults (`S:232-235`). |
| F-31 | RESOLVED | `transition()` locks and logs atomically, with stale/concurrent tests (`S:209-211`; `P:120-122`). |
| F-32 | RESOLVED | Root lock order, indexes, query budget and concurrency tests are stated (`S:148-162`; `P:105-106`). |
| F-33 | RESOLVED | Goods-receipt reversal is an explicit P-5 program lane (`P:140`). |
| F-34 | RESOLVED | Partial delivery under `allow` passes ratios into 472; `require_delivery_first` refuses (`S:175-177,237-243`). |
| F-35 | RESOLVED | F-2 supplies the acceptance writer and flips the TN seed in the same commit (`P:58-59`). This resolves activation bricking, not F-61’s earlier window. |
| F-36 | RESOLVED | DeferredRevenue purpose, TN 472 mapping, provisioning and typed missing-account refusal are explicit (`S:237-243`; `P:68`). |
| F-37 | RESOLVED | CorrectingEntry is SEALED for immutability but explicitly excluded from fiscal chains (`S:62,179-182`). |
| F-38 | PARTIAL | Dependencies are much more complete, but lane sizing/program-lane serialization remains deficient (`P:9-18,131-144`). |
| F-39 | RESOLVED | Backend/report/web readers and a FE census are named (`S:261-267`; `P:99`). |
| F-40 | PARTIAL | Test coverage expanded materially, but F-87 remains. |
| F-41 | RESOLVED | Conversion and closure facts are per-line/durable; DN full return is per tuple (`S:65-72`). |
| F-42 | RESOLVED | Historical AP/AR remains Invoice/CN with immutable `opening_side` (`S:63,142-146`). |
| F-43 | RESOLVED | Billing mark and line provenance precede legacy header inference (`S:149-154`). |
| F-44 | RESOLVED | Expiration is persisted by a scheduled idempotent command (`S:70-72`; `P:103-104`). |
| F-45 | RESOLVED | `goods_received_at` is promoted and all named readers move off payload (`S:170-174`; `P:101-102`). |
| F-46 | RESOLVED | The 472 matrix includes full VAT, stamp and rounding absorber (`S:237-241`). |
| F-47 | RESOLVED | Cancellation checks original VAT, all original JE periods and reversal period (`S:212-216`). |
| F-48 | RESOLVED | Historical `posted_at` is a retained seal fact in the widened trigger (`S:193-199`). |
| F-49 | RESOLVED | Live enum/TS contract becomes exactly four values; legacy strings use a read-only wire type (`S:21-25`; `P:99`). |
| F-50 | PARTIAL | A persisted POS source is now named, but receipt-level movement rows cannot classify lines (`S:163-169`). |
| F-51 | RESOLVED | P-3 is split across authoring, posting, application/residue and cancellation (`P:135-138`). |
| F-52 | RESOLVED | One CN settlement service owns the combined application/refund cap (`S:131-134`; `P:116-117`). |
| F-53 | RESOLVED | Payment identity now stores side plus operation; direction derives from operation (`S:115-124`). |
| F-54 | RESOLVED | SI fulfilment waits for the receipt-allocation ledger (`P:49-50,109-110`). |
| F-55 | RESOLVED | CHECK installation aborts while residual/quarantined Paid rows exist (`S:247-251`; `P:90-94`). |
| F-56 | RESOLVED | Received migration explicitly recomputes net of supplier returns (`S:170-174`; `P:101-102`). |
| F-57 | RESOLVED | Creation APIs are split into their own lane and force distinct shapes (`S:274-276`; `P:62`). |
| F-58 | RESOLVED | `allow` activation is outside the strict chain and owner-gated (`S:242-243`; `P:144`). |
| F-59 | RESOLVED | C-0a now defaults every unlisted pair to refusal (`S:76-86`). |
| F-60 | PARTIAL | Paid CNs quarantine and block CHECK tightening (`S:245-251`), but the promised repair command lacks exact wrong-GL correction semantics. |
| F-61 | OPEN | The fiscal-completion predicate is fixed, but r4 still authorizes the pre-F-2 TN violation (`S:26-27,98-108`). |
| F-62 | RESOLVED | `(counterparty_side, settlement_operation)` replaces side-only direction (`S:115-124`). |
| F-63 | RESOLVED | C-T0 requires atomic 419→411 clearing for posted-deposit application (`S:92-94`; `P:88-89`). |
| F-64 | RESOLVED | C-F0 is immediate after N-6 and has absence assertions for VAT/fiscal output (`P:12,79-80`). |
| F-65 | RESOLVED | Missing sales country rows now cause typed refusal; `systemDefault()` is removed (`S:227-231`). |
| F-66 | PARTIAL | Immutable receipt projection facts exist, but r4 chooses the wrong receipt-level fact for per-line classification (`S:163-169`). |
| F-67 | RESOLVED | `createPostedReversal()` and the one-JE/two-transition test are explicit (`S:274-275`; `P:121`). |
| F-68 | RESOLVED | Triple period guards now distinguish original and current periods (`S:212-216`; `P:114`). |
| F-69 | RESOLVED | Both artifacts are explicitly self-contained and contain retained clauses/tests (`S:1-9`; `P:1-7`). |
| F-70 | RESOLVED | Payment identity has a provenance ladder, quarantine, CHECK-on-empty and writer census (`S:115-124`; `P:84-87`). |
| F-71 | RESOLVED | C-8a backfills exact JE identities and aborts on ambiguous/missing footprints (`S:193-197`; `P:64,123-124`). |
| F-72 | RESOLVED | DN full return is now every physical product/location tuple, with a cross-product test (`S:57`; `P:125-126`). |
| F-73 | RESOLVED | RFQ backfills the existing `valid_until`; no duplicate DDL (`S:51,70-72`; `P:41,103-104`). |
| F-74 | PARTIAL | H0/C0/R1b/P3/C3b were split, but several current lanes still exceed the declared limit (`P:27-28,36,40,60-63`). |
| F-75 | PARTIAL | Most r4 gaps gained named tests (`P:75-129`), but the round-5 cases in F-87 are absent. |

## OQ dispositions table

### Legacy OQ-1..11 and R-OQ-1..12

| Question | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Add SupplierInvoice Confirmed hop | ACCEPT | Both hops, GL and logs must share the locked posting transaction. |
| OQ-2 | Census supplier-payment typing | REJECT — persist side + operation and repair | A census does not make reversal or cash direction trustworthy. |
| OQ-3 | Defer PO-prepayment defect | REJECT — exhaustive immediate refusal | Unsupported PO allocations must remain 422 until P-2. |
| OQ-4 | Retire lifecycle Received | ACCEPT | Conditional on canonical net recompute, reader migration and final CHECK parity. |
| OQ-5 | Keep unposted SI 422 until P-2 | ACCEPT | Correct fail-closed behavior while 409 creation/clearing is absent. |
| OQ-6 | Minimal SupplierCreditNote path | ACCEPT | “Minimal” still means author, confirm, post, apply, reverse-unapplied and retain residue. |
| OQ-7 | Separate FE match vocabulary | REJECT — prerequisite | It must precede UI exposure of the SI Confirmed hop. |
| OQ-8 | Add second procurement country layer | REJECT — inherited single authority | Company override must sit over one seeded country default. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT | Requires JE backfill/quarantine and protection after cancellation. |
| OQ-10 | Retire `getPaymentStatus()` | ACCEPT | One recompute authority removes divergent formulas. |
| OQ-11 | Remove `in_payment` | NEEDS-OWNER | Keep `has_unreconciled_payments`; presentation is a product choice. |
| R-OQ-1 | Treat lifecycle Paid on CN as defect | ACCEPT | CN settlement is outward payment state, not lifecycle. |
| R-OQ-2 | CN cash refund in R-3 | ACCEPT | Must share the ex-stamp cap and explicit customer-refund direction. |
| R-OQ-3 | Standalone CN application in R-2 | ACCEPT | Must use the shared lock/cap/recompute service. |
| R-OQ-4 | Refuse applied-CN cancellation | ACCEPT | Safest until compensating allocation events exist. |
| R-OQ-5 | Direct source-line return cache | REJECT — canonical root aggregate | Invoice/DN unions cannot be represented on one direct source line. |
| R-OQ-6 | RN reversal document | ACCEPT | Preserves the original fiscal and stock events. |
| R-OQ-7 | Seal RN at Confirmed | ACCEPT | Chain membership is independent of lifecycle Posted. |
| R-OQ-8 | Delete/revive return metadata | NEEDS-OWNER | Live FE fields and refund disposition make this product scope. |
| R-OQ-9 | Correct CN VAT declaration | NEEDS-OWNER | Merge only after per-tenant/per-period deltas are acknowledged. |
| R-OQ-10 | Net dashboard revenue | NEEDS-OWNER | It changes an owner-visible daily figure. |
| R-OQ-11 | Historical rows take internal Confirmed hop | ACCEPT | No false event; preserve all AR/AP directions and authoritative balance. |
| R-OQ-12 | Add no B2B return policy | ACCEPT | Do not invent policy; any future one must be country-seeded. |

### Current owner sheet OQ-11..39

| Question | Disposition | Reason |
|---|---|---|
| OQ-11 | NEEDS-OWNER | Keep the DTO boolean; enum/presentation remains a product choice. |
| OQ-12 | ACCEPT | Reserved identifier is administratively safe. |
| OQ-13 | ACCEPT | Net PO fulfilment should reopen after supplier return; correct “receivable again” to “receivable for replacement.” |
| OQ-14 | ACCEPT | VAT-free proforma is consistent with the adopted Art. 18 ruling. |
| OQ-15 | ACCEPT | Refusal until authority acceptance is the binding fail-safe. |
| OQ-16 | ACCEPT | Existing immutable event can retain “fully settled” semantics. |
| OQ-17 | ACCEPT | Provisional FR rows with typed refusal are safer than copying TN silently. |
| OQ-18 | NEEDS-OWNER | Deleting or reviving refund disposition changes product behavior. |
| R-OQ-9 | NEEDS-OWNER | Correct predicate only after the delta report is acknowledged. |
| OQ-19 | NEEDS-OWNER | Net revenue changes a closely watched metric. |
| OQ-20 | REJECT — use the explicit current table, remove stale RN wording | Its “RN credit posted or no-credit” wording conflicts with OQ-28 and SPEC’s close-at-confirm rule. |
| OQ-21 | ACCEPT | Returned may count as fulfilled, but only after F-80’s per-tuple correction. |
| OQ-22 | ACCEPT | Cancellation is permanently closed with reason `cancelled`. |
| OQ-23 | ACCEPT | Ex-stamp settlement matches the production application basis. |
| OQ-24 | ACCEPT | Default-equivalent rows may become inherited; non-equivalent rows remain overrides. |
| OQ-25 | ACCEPT | Expense/Income metadata is the existing authoritative settlement fact. |
| OQ-26 | ACCEPT | No FR `allow` seed without an approved deferred-revenue mapping. |
| OQ-27 | NEEDS-OWNER | No historical SI period may be assumed; keep P-4 blocked pending named periods and delta acknowledgement. |
| OQ-28 | ACCEPT | RN close-at-confirm is coherent while refund disposition remains separate. |
| OQ-29 | ACCEPT | No country activates `allow` without an owner-approved account mapping. |
| OQ-30 | ACCEPT | `opening_side` avoids a dangerous historical type rewrite. |
| OQ-31 | ACCEPT | Full return must be proven for every physical tuple. |
| OQ-32 | ACCEPT | A scheduled idempotent stamp gives closure a durable fact. |
| OQ-33 | ACCEPT | The receipt can be authoritative, but implementation must use per-line immutable projection facts, not receipt-level movements. |
| OQ-34 | ACCEPT | Retained future supplier credit is the safe default. |
| OQ-35 | ACCEPT | Refuse unless original VAT, original JE and reversal periods are all open. |
| OQ-36 | ACCEPT | Quarantine is safe, conditional on specifying the actual correction workflow. |
| OQ-37 | ACCEPT | Non-settleable historical credits are safer than inventing cash/application behavior. |
| OQ-38 | REJECT — block TN posting until F-2 | Acknowledging known noncompliance is not a fail-safe and contradicts OQ-15. |
| OQ-39 | ACCEPT | Quarantine only the affected chain while reporting its verification incomplete. |

## Missed owner questions

- After authority rejection, is the legal artifact retried as an immutable authority-attempt against the same unchained payload, or must a replacement/corrective document be minted? Rewriting a VOIDED chain member is not an option.
- What is the exact SalesOrder payment base and what happens to its payment state after partial transfer to multiple invoices?
- Does “returned invoice” require every ordered physical tuple to have first been delivered, or may a linked fully settled CN close undelivered quantities?
- When cancelling an SI with a downstream SCN/SGRN, is the rule unconditional refusal or a cascade that reverses children first?
- Is `supplier_advance_mode` company-overridable, or a country-mandated rule? The schema and resolver precedence depend on this answer.
- Resolve the OQ-20/OQ-28 owner-sheet contradiction explicitly; both cannot remain normative.

## Lane resequencing

1. After N-6, run C-0a and C-F0 immediately.
2. Add `C-QR0`: until the QR client and F-2 exist, refuse TN `confirmed→posted`. This discharges the binding fail-safe without inventing `not_required`.
3. Run C-T0 before broader cache migration so N-6 cannot settle a posted deposit allocation while 419 remains open.
4. Correct and advance C-F1a/F1b/F1c; then land corrected C-3a1 + F-2 + TN `required` seed atomically. Do not use VOID/reseal retry.
5. Split C-2b1a into allocation writers, refund/reversal writers, tolerance/CN writers, and posting/cancel seed/recompute.
6. Split C-D0a into fulfilment/closure schema, Received net migration, `goods_received_at` promotion/readers, and final CHECK/Slice-D parity.
7. Correct C-R0p to use `pos_receipt_lines.stock_movement_expected`, then run C-R1a.
8. Add a pre-post match snapshot to C-P2a; add downstream SCN/SGRN refusal to C-P2b.
9. Put `supplier_advance_mode` storage/seeding/precedence into C-3b1 or a dedicated predecessor of P-2.
10. Serialize R-2/R-3 against PaymentController, PaymentRefundService and the recompute port; serialize R-4 against DN/RN/fiscal-chain lanes; serialize P-3 against SupplierInvoicePostingService, DocumentStatusService and C-8.
11. Do not dispatch C-3a2 closure until F-80/F-81 predicates and their negative tests are incorporated.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE:

- SupplierInvoice currently posts directly Draft→Posted and seeds `balance_due=total` (`SupplierInvoicePostingService.php:286-292`).
- Current purchase fulfilment writes lifecycle `Received` only when fully received (`GoodsReceiptService.php:728-731`).
- Exact SI ownership is not present in cumulative receipt counters; the proposed immutable allocation ledger is genuinely required (`SupplierInvoicePostingService.php:169-254,300-373`).
- SupplierCreditNote posting already performs substantial GL, counter and supplier-goods-return work but has no production caller; P-3 is integration/lifecycle work, not a greenfield posting algorithm (`SupplierCreditNotePostingService.php:126-140,301-373`).
- Native CN settlement is outward through `credit_note_allocations`, while the PG trigger reduces the target invoice through `invoice_id` (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:38-70`).
- The VAT query is status-blind and groups Invoice, CreditNote and Expense together today (`EloquentVatDataRepository.php:27-65`).
- The current verifier covers only Invoice/CreditNote and filters lifecycle Posted (`VerifyFiscalChainsCommand.php:252-285`).
- POS stock movements are receipt-level and lack line identity; the immutable per-line `stock_movement_expected` fact exists (`StockMovement.php:22-45`; `ReceiptLine.php:21-37`).
- Expense and Income still write lifecycle Posted directly (`ExpenseService.php:309-319,841-853`; `IncomeService.php:145-154`).
- Historical openings still directly create Posted Invoice/CreditNote rows with authoritative open balance (`ArApOpeningService.php:299-332`).

FALSE/stale:

- No material semantic claim in the P/R sweeps was falsified after re-reading the current tree; their snapshot line numbers must not be copied because `dev` has moved.
- SPEC r4’s new claim that receipt stock movements can classify each POS account-charge line is false: those rows have no receipt-line key.
- Treating P-3b as new posting implementation would be stale: the existing `SupplierCreditNotePostingService` must be adapted and routed, not duplicated.
- Current lifecycle/chain filters remain the defects reported by the sweeps; SPEC describes planned behavior, not already-delivered behavior.

CHANGES-REQUESTED