# Session C spec+plan adversarial review r10

## Verdict line (whole program)

CHANGES-REQUESTED

## Verdict line (critical-path slice)

ACCEPT-WITH-CONDITIONS

Conditions for the critical-path slice:

1. Make C-QR0a schema-only; seed the complete country matrix before any initializer/backfill in C-QR0b.
2. Keep F-2a dark-deployed and move public accepted-post activation and its test to F-2b.
3. Either route historical openings through `createHistoricalPosted()` in C-L0b0 or remove `HistoricalOpeningTwoHopTest` and the historical-opening claim from the slice.
4. Rewrite C-F1h as the residual fiscal-chain work not already delivered by N-6, with a genuinely red test on the N-6 base.

## Findings

**F-144 · Critical · MAIN · A/B/E** — POS fiscal adoption has no valid representation in the proposed document fiscal model. The spec says a POS-originated invoice adopts the POS event/JE and does not produce a second fiscal event, while also requiring complete document fiscal tuples and chain verification (`SPEC-document-lifecycle-dimensions.md:61`, `:253-264`). The current POS projection creates a Draft invoice without a document number, fiscal hash, or sequence (`POSAccountChargeDraftService.php:49-73`), while the immutable POS event already owns the journal entry (`GeneralLedgerService.php:4077-4080`, `:4111-4166`; `TreasuryAccountChargeBridge.php:59-100`). The database requires number, total, currency, hash, and sequence for non-DRAFT fiscal documents (`2026_03_10_300000_fix_fiscal_constraints_for_drafts.php:20-34`). Copying the receipt hash/sequence makes the document verifier hash the wrong canonical bytes; minting a document tuple creates a second seal; leaving the tuple null violates the constraints. Consequence: C-POS0 cannot satisfy both “adoption” and the stated fiscal invariants. Required change: add an explicit authority representation such as `adopted_fiscal_event_id` plus an authority-kind discriminator; define adopted-event fiscal completion separately from document-chain membership; prohibit copying receipt hashes into the document chain; exclude adopted projections from document-chain heads/verifiers; and add constraint and verifier tests for all three invalid representations.

**F-145 · Critical · MAIN · A/C/E/I** — the proposed historical-opening model loses its authoritative open balance on the first allocation. The spec assigns `balance_due` to the PostgreSQL trigger family and says historical openings enter via `createHistoricalPosted()` (`SPEC...md:40-42`, `:71`, `:181-185`, `:207-211`). Today `ArApOpeningService` deliberately creates a historical posted invoice with `total` equal to the historical gross amount but `balance_due` equal to the imported open amount (`ArApOpeningService.php:311-332`). The trigger recomputes `balance_due` as `total - payments - credit allocations` (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-48`). A historical invoice with total 1,500 and imported open balance 300 therefore jumps toward 1,500 after its first allocation. Consequence: imported AR/AP ageing and settlements become materially false. Required change: add a pre-settlement provenance lane persisting an immutable opening balance basis—or an equivalent immutable original-settled amount—and make the trigger formula branch on that basis. Add PostgreSQL tests covering first allocation, reversal, credit application, and full settlement for both AR and AP openings.

**F-146 · High · MAIN · A/C/E/I** — credit-note `balance_due` still has two competing owners. The spec says the trigger family is the sole balance owner except posting/import initialization (`SPEC...md:40-42`) but later assigns sales credit-note balance maintenance to the recomputation port (`SPEC...md:186-189`). The plan consequently spreads balance behavior across C-PAY1, C-PAY2, and P-3c (`PLAN-phase2-3-P-R.md:58-61`, `:153`). The existing credit-allocation trigger updates the target invoice, not the source credit note (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:41-56`). Consequence: controller/service recomputation can race or disagree with trigger-side target recomputation, especially during refunds and reversed allocations. Required change: state one owner unambiguously. Prefer expanding the trigger family to update both source and target balances for inserts, updates, deletes, and reversals, with the application port reading the cache only. Add source/target parity and concurrent reversal tests for sales and supplier credits.

**F-147 · High · SLICE · B/E/F/G/I** — C-QR0a backfills a country-derived mode before C-QR0b creates the complete country policy matrix. The plan puts schema, initializer, and existing-row backfill in C-QR0a, then complete rows, seeding, and removal of the DDL default in C-QR0b (`PLAN...md:33-34`). The current default catalogue only defines TN and FR (`CountryDocumentDefaults.php:28-42`), whereas the spec forbids a silent default and requires complete country policy rows (`SPEC...md:117-125`). Consequence: QR0a must either hardcode missing countries, preserve the forbidden provisional default, or produce incomplete/null backfill. Required change: rewrite C-QR0a as nullable schema plus constraints that permit an unactivated state. Move initializer and backfill into C-QR0b after the complete country rows are seeded, and activate the non-null/check constraint atomically. Add a staged-deployment test proving application startup remains safe between the two migrations.

**F-148 · Medium · SLICE · F/I** — F-2a’s test asserts behavior owned by F-2b. F-2a implements the accepted internal seal/post path, while F-2b removes the public refusal and activates the endpoint (`PLAN...md:43-44`); nevertheless the named test under the slice expects accepted posting in F-2a (`PLAN...md:117`). The spec itself distinguishes implementation from activation (`SPEC...md:151-154`). Consequence: either F-2a silently exposes policy before the complete row is active or its prescribed test cannot pass. Required change: make F-2a’s test invoke the internal seam while asserting the public route remains refused; move the public accepted-post test and activation assertion to F-2b.

**F-149 · High · MAIN · A/E/I** — the exact status-writer allowlist contradicts the status-service contract. The lifecycle table names both `transition()` and `transitionWithFiscalSeal()` as sole writers (`SPEC...md:13-16`), but the enforcement section allows exactly four methods and omits `transitionWithFiscalSeal()` (`SPEC...md:291-294`). N-6 also retains a maintenance repair path that directly writes Confirmed (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:197-216`), while its PHPStan rule currently allows the whole service class (`fix/campaign-n6-payment-advance:apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:72`, `:194-219`). Consequence: tightening the rule either rejects the intended fiscal transition or preserves a class-wide escape hatch. Required change: state the exact final method set. Either allow five narrowly identified methods, or require `transitionWithFiscalSeal()` to delegate its only status mutation to `transition()`. Explicitly retire or narrowly gate the repair method before enabling the method-level PHPStan rule, with one negative fixture for every non-allowed method.

**F-150 · High · MAIN · B/G/I** — an unauthorized jurisdiction can activate expert-only `allow` through a company override. The spec permits company overrides and settings UI, defines the `allow` engine, and says no country is currently authorized (`SPEC...md:322-342`). The owner decision also keeps `allow` owner-gated (`OWNER-DECISIONS-document-lifecycle-dimensions.md:28`). The current resolver gives the company override precedence over country policy (`PreDeliveryFiscalDocumentPolicyResolver.php:81-95`). Consequence: implementing only the documented precedence lets a tenant bypass the country/legal gate and issue pre-delivery VAT invoices without the required 472 treatment. Required change: add a seeded country capability such as `allow_authorized=false`; check it before resolving any company override; reject persisted `allow` overrides where the capability is false; and hide or disable the UI choice. Add API, resolver, and database tests proving a company override cannot elevate jurisdiction capability.

**F-151 · High · MAIN · G/I** — purchase-policy country seeding remains underspecified. The spec requires country-seeded purchase policy with company override (`SPEC...md:327-334`), but provides neither values nor an explicit refusal state for every catalogue country. The current procurement policy contains several behavioral fields and falls back to hardcoded vertical defaults (`ProcurementPolicy.php:20-43`, `:99-137`; `ProcurementPolicyResolver.php:14-17`, `:24-51`). The mandate forbids hardcoded policy choices (`HANDOVER-document-lifecycle-dimensions-program.md:3-7`). Consequence: an implementer must invent policy for non-TN/FR countries or retain the vertical fallback. Required change: add a normative country matrix covering every field for every catalogue country, including whether each value is expert-approved, provisional, or refused. Require a complete-row parity test against the country catalogue and fail typed resolution when a row is absent.

**F-152 · High · MAIN · F/I** — owner acknowledgement for the CN VAT delta blocks unrelated lifecycle enforcement and retirement work. The main chain places C-R1b2 and its owner acknowledgement ahead of C-2c and all later work (`PLAN...md:20-22`, `:90`), while R-OQ-9 is expressly an owner decision (`OWNER-DECISIONS...md:17`). Consequence: an unresolved reporting-policy decision stalls status enforcement, legacy retirement, and unrelated procurement/return lanes. Required change: fork C-R1b2 into an owner-gated VAT side chain. Make C-2c depend on C-R1b1 and the status-reader census, not on delta acknowledgement; only the deployment of the changed VAT predicate should wait for R-OQ-9.

**F-153 · High · MAIN · C/F/I** — the purchase-order advance boundary is internally contradictory. The applicability matrix says PO payments are refused “until P-2” (`SPEC...md:59`), but the advance model and P-2 program lane cover confirmed supplier invoices, not purchase orders (`SPEC...md:113-114`; `PLAN...md:149`). N-6 still explicitly misclassifies a PO target as receivable clearing before its fallback refusal (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Payment/Domain/Services/DocumentAllocationClassifier.php:88-113`). Consequence: implementers may lift the PO refusal after P-2 without a defined PO prepayment liability, clearing route, or GL direction. Required change: replace the PO row with “REFUSED throughout this program; P-2 applies only to confirmed supplier invoices.” Add a post-P-2 regression proving PO-targeted payments still return the typed refusal.

**F-154 · High · MAIN · A/D/I** — supplier-invoice fulfilment does not net downstream supplier goods returns. The spec lists supplier returns as fulfilment facts (`SPEC...md:47-50`) but defines the supplier-invoice cache only from receipt slices (`SPEC...md:65`, `:305-320`). The plan recomputes purchase-order fulfilment on returns but does not name supplier-invoice recomputation (`PLAN...md:80`, `:83`). Today supplier credit posting can create and confirm a supplier goods-return note and decrement receipt/PO counters (`SupplierCreditNotePostingService.php:301-324`, `:566-625`), while the return confirmation moves stock (`SupplierGoodsReturnNoteService.php:413-487`). Consequence: a fully received/invoiced supplier invoice can remain `fulfilled` after its goods are returned. Required change: define SI fulfilment as receipt slices minus confirmed, non-reversed supplier returns linked to those slices. Recompute both the SI and PO on return confirmation and reversal/cancellation. Add full, partial, multi-receipt, and reversal tests.

**F-155 · High · MAIN · B/F/I** — P-4 does not define how supplier-invoice cancellation exits VAT reporting. The spec says the original SI leaves the VAT period through a reversal JE dated in the reversal period (`SPEC...md:355-359`), but the VAT repository reads `document_tax_details` by document date and document type, not journal entries (`EloquentVatDataRepository.php:27-65`). P-4 is only a single program-table lane (`PLAN...md:155`) and does not define negative tax rows, their immutable link, or idempotency. Consequence: cancellation can reverse GL while the original tax detail remains indefinitely reportable, or an implementer may mutate immutable tax history. Required change: specify an immutable reversal-tax fact keyed to the original tax-detail rows, its reporting date and sign, and its behavior under retries/replacement invoices. Split P-4 into schema/fact emission, VAT reader, and cancellation orchestration lanes with cross-period tests.

**F-156 · Medium · MAIN · F** — C-P1 depends on an undefined lane. Its dependency is `C-SI0`, while the actual lanes are C-SI0a and C-SI0b (`PLAN...md:78-80`). Consequence: the executable lane graph has no determinate predecessor. Required change: replace `C-SI0` with C-SI0b, or define an explicit C-SI0 join milestone.

**F-157 · Medium · SLICE · F/I** — C-F1h duplicates N-6 and violates the plan’s red-first rule. The plan says every named test must fail on the declared base (`PLAN...md:7`) and assigns chain-head-after-void and legacy-paid tests to C-F1h (`PLAN...md:40`, `:114`). N-6 already selects fiscal heads without lifecycle predicates in posting, delivery, and return services (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:669-674`; `.../DeliveryNoteService.php:124-134`; `.../ReturnNoteService.php:611-619`), and its handback records those tests as delivered (`fix/campaign-n6-payment-advance:docs/superpowers/reviews/2026-08-24-n6-handback.md:243-250`). Consequence: the lane begins green and risks reworking an in-flight branch. Required change: remove the delivered work from C-F1h or redefine it as the residual shared membership/sequence predicate and verifier consolidation, with a newly demonstrated red test.

**F-158 · Medium · SLICE · A/F/I** — the critical slice claims historical two-hop creation without routing the actual historical-opening writer. The spec requires historical openings to traverse Draft→Confirmed→Posted atomically (`SPEC...md:35-36`, `:284`, `:290-292`). C-L0b0 names compatibility shims and `HistoricalOpeningTwoHopTest` (`PLAN...md:36`, `:112`), but the actual opening service directly inserts Posted documents (`ArApOpeningService.php:311-332`) and is not routed until C-2c-c1 (`PLAN...md:91`). Consequence: the critical slice’s stated invariant and named test cannot cover the production writer. Required change: either include `ArApOpeningService` migration to `createHistoricalPosted()` in C-L0b0 or move the claim and test to C-2c-c1 and explicitly state that the slice leaves historical birth writes unenforced.

**F-159 · Medium · MAIN · F/I** — the plan still violates its own one-implementer-day sizing rule. The rule is explicit (`PLAN...md:3-8`), but C-SO0 combines formula definition, triggers, allocation behavior, cancellation, and cache tests (`PLAN...md:72`, `:131`); C-D0b combines delivery-note allocation, partial refusal, reversal, and derived cache behavior (`PLAN...md:73`, `:132`); C-P2b combines supplier advance release, allocation, GL, and reversal (`PLAN...md:87`, `:138`); and P-4 combines cancellation, VAT-period behavior, and reversal (`PLAN...md:155`). Consequence: review checkpoints and failure attribution become unreliable, and several lanes carry multiple independent invariants. Required change: split each into schema/facts, recomputation, orchestration, and reader/reporting lanes, retaining at most one migration per lane.

## r1..r9 findings disposition

“Partial” means the original defect was narrowed but its complete invariant is still not implementable or proven.

| Finding | Status | Finding | Status | Finding | Status | Finding | Status |
|---|---|---|---|---|---|---|---|
| F-1 | RESOLVED | F-2 | PARTIAL | F-3 | RESOLVED | F-4 | RESOLVED |
| F-5 | RESOLVED | F-6 | RESOLVED | F-7 | RESOLVED | F-8 | RESOLVED |
| F-9 | RESOLVED | F-10 | RESOLVED | F-11 | RESOLVED | F-12 | RESOLVED |
| F-13 | RESOLVED | F-14 | RESOLVED | F-15 | RESOLVED | F-16 | RESOLVED |
| F-17 | RESOLVED | F-18 | PARTIAL | F-19 | RESOLVED | F-20 | PARTIAL |
| F-21 | RESOLVED | F-22 | RESOLVED | F-23 | RESOLVED | F-24 | RESOLVED |
| F-25 | PARTIAL | F-26 | RESOLVED | F-27 | RESOLVED | F-28 | RESOLVED |
| F-29 | RESOLVED | F-30 | RESOLVED | F-31 | RESOLVED | F-32 | RESOLVED |
| F-33 | RESOLVED | F-34 | RESOLVED | F-35 | RESOLVED | F-36 | RESOLVED |
| F-37 | RESOLVED | F-38 | PARTIAL | F-39 | RESOLVED | F-40 | PARTIAL |
| F-41 | RESOLVED | F-42 | RESOLVED | F-43 | RESOLVED | F-44 | RESOLVED |
| F-45 | RESOLVED | F-46 | RESOLVED | F-47 | RESOLVED | F-48 | RESOLVED |
| F-49 | RESOLVED | F-50 | RESOLVED | F-51 | RESOLVED | F-52 | RESOLVED |
| F-53 | RESOLVED | F-54 | RESOLVED | F-55 | RESOLVED | F-56 | RESOLVED |
| F-57 | RESOLVED | F-58 | RESOLVED | F-59 | RESOLVED | F-60 | RESOLVED |
| F-61 | RESOLVED | F-62 | RESOLVED | F-63 | RESOLVED | F-64 | RESOLVED |
| F-65 | RESOLVED | F-66 | RESOLVED | F-67 | RESOLVED | F-68 | RESOLVED |
| F-69 | RESOLVED | F-70 | RESOLVED | F-71 | RESOLVED | F-72 | RESOLVED |
| F-73 | RESOLVED | F-74 | PARTIAL | F-75 | PARTIAL | F-76 | RESOLVED |
| F-77 | RESOLVED | F-78 | RESOLVED | F-79 | RESOLVED | F-80 | RESOLVED |
| F-81 | RESOLVED | F-82 | RESOLVED | F-83 | RESOLVED | F-84 | RESOLVED |
| F-85 | RESOLVED | F-86 | PARTIAL | F-87 | RESOLVED | F-88 | RESOLVED |
| F-89 | PARTIAL | F-90 | RESOLVED | F-91 | RESOLVED | F-92 | RESOLVED |
| F-93 | RESOLVED | F-94 | RESOLVED | F-95 | RESOLVED | F-96 | RESOLVED |
| F-97 | RESOLVED | F-98 | RESOLVED | F-99 | RESOLVED | F-100 | RESOLVED |
| F-101 | RESOLVED | F-102 | RESOLVED | F-103 | RESOLVED | F-104 | RESOLVED |
| F-105 | RESOLVED | F-106 | PARTIAL | F-107 | RESOLVED | F-108 | RESOLVED |
| F-109 | RESOLVED | F-110 | RESOLVED | F-111 | RESOLVED | F-112 | RESOLVED |
| F-113 | RESOLVED | F-114 | RESOLVED | F-115 | RESOLVED | F-116 | RESOLVED |
| F-117 | RESOLVED | F-118 | PARTIAL | F-119 | RESOLVED | F-120 | RESOLVED |
| F-121 | RESOLVED | F-122 | RESOLVED | F-123 | RESOLVED | F-124 | RESOLVED |
| F-125 | RESOLVED | F-126 | RESOLVED | F-127 | RESOLVED | F-128 | RESOLVED |
| F-129 | RESOLVED | F-130 | PARTIAL | F-131 | RESOLVED | F-132 | RESOLVED |
| F-133 | RESOLVED | F-134 | RESOLVED | F-135 | PARTIAL | F-136 | RESOLVED |
| F-137 | PARTIAL | F-138 | RESOLVED | F-139 | RESOLVED | F-140 | RESOLVED |
| F-141 | RESOLVED | F-142 | RESOLVED | F-143 | PARTIAL |  |  |

The remaining partial findings are represented by the new concrete defects above: balance ownership/history, lane sizing and graph precision, POS authority representation, VAT cancellation facts, and missing seam tests. No prior finding remains wholly unaddressed at the prose level; the unresolved portions are therefore PARTIAL rather than OPEN.

## OQ dispositions table

### Original OQ-1..11

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Durable SI at confirmation | ACCEPT | Required for later matching, advances, fulfilment, and cancellation. |
| OQ-2 | Census only | REJECT — persist immutable opening basis and side | Census cannot prevent the balance trigger from corrupting imported open balances. |
| OQ-3 | Defer PO correction | REJECT — PO payments remain refused | P-2 defines SI advances, not PO prepayments or their GL direction. |
| OQ-4 | Staged `received` retirement | ACCEPT | Backfill, reader migration, then CHECK tightening is trigger-safe. |
| OQ-5 | Keep SI 422 until P-2 | ACCEPT | Prevents premature supplier-advance accounting. |
| OQ-6 | Minimal SCN | ACCEPT, conditioned | It still needs an explicit lifecycle, fiscal category, stamp, chain, and reversal contract. |
| OQ-7 | Financial-event match remains separate | REJECT — make it a prerequisite | Supplier cancellation and reconciliation cannot be correct while matching semantics are unresolved. |
| OQ-8 | Parallel procurement policy | REJECT — one country/company policy mechanism | A second precedence system recreates hardcoded policy drift. |
| OQ-9 | Widen immutability | ACCEPT | Sealed fiscal and accounting facts must be protected consistently. |
| OQ-10 | Retire `getPaymentStatus()` | ACCEPT | Payment state must come from the recomputation contract. |
| OQ-11 | No `in_payment` enum | NEEDS-OWNER | Product semantics for partially settled documents cannot be inferred safely. |

### R-OQ-1..12

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| R-OQ-1 | Preserve independent dimensions | ACCEPT | Binding program invariant. |
| R-OQ-2 | Cached dimensions derive only from facts | ACCEPT | Prevents lifecycle-to-payment/fulfilment inference. |
| R-OQ-3 | Typed refusal for unsupported applicability | ACCEPT | Safer than silent fallback. |
| R-OQ-4 | Reversal documents are first-class | ACCEPT | Required by immutable event/fiscal history. |
| R-OQ-5 | Recompute only touched leaf | REJECT — recompute the affected root and linked projections | Returns and allocation reversals affect multiple cached documents. |
| R-OQ-6 | Exhaustive applicability matrix | ACCEPT, conditioned | It must cover every persisted type, including historical and reversal types. |
| R-OQ-7 | SEALED owns chain membership | ACCEPT | Lifecycle values must not select fiscal chain members. |
| R-OQ-8 | Leave provisional fiscal documents untouched | ACCEPT | Safest fail-closed behavior during policy rollout. |
| R-OQ-9 | Acknowledge existing-tenant VAT delta | NEEDS-OWNER | It changes statutory figures; it must not block unrelated lanes. |
| R-OQ-10 | Keep gross return-note totals | ACCEPT | Allocation and stock effects should not redefine fiscal face value. |
| R-OQ-11 | Country-row absence is a typed refusal | ACCEPT | No generic policy default is legally defensible. |
| R-OQ-12 | Do not invent country policy | ACCEPT | Expert/owner policy remains required. |

### Owner sheet OQ-11..68

| OQ | Disposition | Reason |
|---|---|---|
| OQ-11 | NEEDS-OWNER | `in_payment` remains a product vocabulary choice. |
| OQ-12 | ACCEPT | Proforma must remain non-fiscal. |
| OQ-13 | ACCEPT | TN pre-delivery fiscal issuance remains refused. |
| OQ-14 | ACCEPT | Country policy precedes company configuration. |
| OQ-15 | ACCEPT | Missing policy must fail closed. |
| OQ-16 | ACCEPT | Existing tenant initialization must be explicit and audited. |
| OQ-17 | ACCEPT | Reporting delta requires acknowledgement. |
| OQ-18 | ACCEPT | Provisional/failed attempts remain unchanged until authoritative completion. |
| OQ-19 | ACCEPT | Return-note face totals remain gross. |
| OQ-20 | ACCEPT | Immutable reversal facts are preferable to mutation. |
| OQ-21 | ACCEPT | Chain membership derives from fiscal facts, not lifecycle. |
| OQ-22 | ACCEPT | `posted_at` is written atomically with the seal. |
| OQ-23 | ACCEPT | `sealed_at` is separate from accounting posting time. |
| OQ-24 | ACCEPT | The verifier must cover every fiscal document family. |
| OQ-25 | ACCEPT | Historical birth requires an explicit API. |
| OQ-26 | ACCEPT | Historical two-hop is an internal atomic operation. |
| OQ-27 | NEEDS-OWNER | Supplier-invoice VAT recognition remains a legal/accounting decision. |
| OQ-28 | ACCEPT | No jurisdiction currently has authorized `allow`. |
| OQ-29 | ACCEPT, conditioned | Owner gate must be a hard jurisdiction capability, not bypassable by company override. |
| OQ-30 | ACCEPT | `allow` requires the deferred-revenue/472 accounting path. |
| OQ-31 | ACCEPT | Country rows must be complete before activation. |
| OQ-32 | ACCEPT | DDL policy defaults must be removed. |
| OQ-33 | ACCEPT | Failed authority attempts remain immutable facts. |
| OQ-34 | ACCEPT | Fiscal completion must be exactly-once. |
| OQ-35 | ACCEPT | Stale attempts cannot overwrite authoritative success. |
| OQ-36 | ACCEPT | Authority failure must not advance lifecycle. |
| OQ-37 | ACCEPT | TN company overrides cannot bypass refusal. |
| OQ-38 | ACCEPT | Fulfilment derives from stock/receipt facts. |
| OQ-39 | ACCEPT | Service-only fulfilment is `not_applicable`. |
| OQ-40 | ACCEPT | Mixed goods/service documents need explicit line applicability. |
| OQ-41 | REJECT — `not_applicable` only after residual stock obligations are detached | An incomplete order is not automatically fulfilment-inapplicable. |
| OQ-42 | ACCEPT | Delivery cancellation must recompute the source document. |
| OQ-43 | ACCEPT | Supplier receipt cancellation must recompute affected PO/SI state. |
| OQ-44 | ACCEPT | Return reversal restores source fulfilment facts. |
| OQ-45 | ACCEPT | POS account-charge projections remain document projections. |
| OQ-46 | ACCEPT, conditioned | POS needs an explicit adopted-event authority representation; copying hashes is forbidden. |
| OQ-47 | ACCEPT | Supplier invoices are durable documents. |
| OQ-48 | REJECT — FIFO plus mandatory final detach/reconciliation | FIFO alone leaves residual allocations ambiguous at cancellation. |
| OQ-49 | ACCEPT | Supplier allocations require explicit AP direction. |
| OQ-50 | ACCEPT | Excess payments stay refused where overpayment is unsupported. |
| OQ-51 | ACCEPT | Write-off remains an explicit fact. |
| OQ-52 | ACCEPT | Closure remains separate from payment. |
| OQ-53 | ACCEPT | Correcting entries are distinct document types. |
| OQ-54 | REJECT — seed the actual ISO catalogue, including GB, with no “generic” country | Runtime policy cannot depend on a fictional generic jurisdiction. |
| OQ-55 | ACCEPT | Missing country configuration is a typed refusal. |
| OQ-56 | ACCEPT | Company overrides cannot widen legal jurisdiction capability. |
| OQ-57 | ACCEPT | Policy rows require provenance and approval state. |
| OQ-58 | ACCEPT | Policy changes need auditability. |
| OQ-59 | ACCEPT | Activation occurs only after complete seeding/backfill. |
| OQ-60 | ACCEPT | Legacy states retire through staged migration. |
| OQ-61 | ACCEPT | Reader removal precedes CHECK tightening. |
| OQ-62 | ACCEPT | Reversals must be explicit documents/facts. |
| OQ-63 | REJECT — distinct reversal types with their own chains; original becomes VOIDED per OQ-66 | “Original stays sealed” conflicts with the later void decision and the fiscal-state model. |
| OQ-64 | ACCEPT | Provisional fiscal attempts block duplicate authority submissions. |
| OQ-65 | ACCEPT | Completed authority facts are immutable. |
| OQ-66 | ACCEPT | Original fiscal state becomes VOIDED while immutable seal evidence remains. |
| OQ-67 | NEEDS-OWNER | VAT entitlement and reversal-period treatment require expert confirmation. |
| OQ-68 | ACCEPT | Replacement documents receive independent fiscal identity and links. |

## Missed owner questions

1. What is the authoritative fiscal representation of a POS-derived invoice: an adopted-event foreign key outside document chains, a separate authority kind, or another expert-approved structure? Default: adopted-event reference outside document-chain membership.
2. What are the exact procurement-policy values and approval states for every supported country and every policy field? No technical default is legitimate.
3. May any company override ever select `allow`, or does OQ-29 prohibit activation until a country capability is explicitly approved? Default: prohibit override elevation.
4. Does a supplier goods return reopen fulfilment on the linked supplier invoice as well as the PO? Recommended default: yes, net the linked receipt slices and recompute both.
5. For cancelled supplier invoices, which immutable tax fact removes entitlement, and which date controls the VAT period? This overlaps OQ-67 but the required representation is not currently posed.

## Lane resequencing

Critical-path slice:

1. N-6 integration baseline.
2. C-0a0.
3. C-F0.
4. C-QR0a: nullable schema only.
5. C-QR0b: complete country rows, initializer/backfill, constraint activation, refusal behavior.
6. C-L0a.
7. C-L0b0, including the actual historical-opening writer—or remove historical openings from the slice claim.
8. C-L0b.
9. C-3a1a.
10. C-L0c.
11. Re-scoped C-F1h residual after subtracting N-6 delivery.
12. C-F1a.
13. C-3a1b.
14. F-2a dark internal implementation.
15. F-2b atomic migration, public activation, and endpoint test.
16. C-F1b.
17. C-F1c.

Main program:

- Insert a historical-opening basis/provenance lane before any allocation or balance migration.
- Insert a POS fiscal-authority representation lane before C-POS0/C-0a1 and before chain tightening.
- Detach C-R1b2 and its owner acknowledgement from the core lifecycle chain.
- Make PO payment refusal permanent for this program; keep P-2 scoped to confirmed supplier invoices.
- Split C-SO0, C-D0b, C-P2b, and P-4 along fact/recomputation/orchestration/reader boundaries.
- Place SI fulfilment-net-of-supplier-returns before supplier-credit automation.
- Implement P-4 only after the immutable reversal-tax fact and expert period ruling are specified.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE:

- Current historical openings directly create Posted invoices and credit notes (`ArApOpeningService.php:311-332`).
- The database trigger recomputes invoice balance from gross total minus payments and credit applications (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-48`).
- Credit-note application triggers currently update the target invoice rather than establishing a complete source-credit balance contract (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:41-56`).
- POS account charges already produce an immutable fiscal event and GL entry before the document projection (`TreasuryAccountChargeBridge.php:59-100`; `GeneralLedgerService.php:4111-4166`).
- Supplier goods-return confirmation moves stock, and supplier credit posting decrements receipt/PO counters (`SupplierGoodsReturnNoteService.php:413-487`; `SupplierCreditNotePostingService.php:566-625`).
- VAT reporting currently reads document tax details by document date/type rather than lifecycle or journal reversal facts (`EloquentVatDataRepository.php:27-65`).
- Existing country defaults are incomplete and currently limited to TN and FR (`CountryDocumentDefaults.php:28-42`).
- Procurement fallback policy is currently hardcoded by vertical (`ProcurementPolicy.php:99-137`; `ProcurementPolicyResolver.php:24-51`).
- The document immutability trigger does not itself prohibit legacy lifecycle-state updates, so staged `paid`/`received` retirement is technically possible (`2025_12_11_054716_add_document_immutability_trigger.php:24-57`).

FALSE or stale:

- The claim that lifecycle-filtered fiscal chain heads remain an unimplemented N-6 defect is stale. N-6 already removed those filters in posting, delivery, and return services (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:669-674`; `.../DeliveryNoteService.php:124-134`; `.../ReturnNoteService.php:611-619`).
- Treating C-F1h’s named chain-head tests as red on the N-6 base is therefore false (`PLAN...md:7`, `:40`, `:114`; `fix/campaign-n6-payment-advance:docs/superpowers/reviews/2026-08-24-n6-handback.md:243-250`).
- The plan’s implication that P-2 eventually enables PO prepayments is false; P-2 only specifies supplier-invoice advances (`SPEC...md:59`, `:113-114`; `PLAN...md:149`).
- The assumption that country backfill can safely precede complete seeding is false under the present catalogue and no-default rule (`CountryDocumentDefaults.php:28-42`; `SPEC...md:117-125`).
- The implication that an SI cancellation reversal JE alone changes the current VAT declaration is false; the repository does not read journal entries (`EloquentVatDataRepository.php:27-65`).

Critical-path slice: ACCEPT-WITH-CONDITIONS

Whole program: CHANGES-REQUESTED