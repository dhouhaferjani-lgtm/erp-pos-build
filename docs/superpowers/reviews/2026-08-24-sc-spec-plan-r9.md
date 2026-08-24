# Session C spec+plan adversarial review r9

## Verdict line (whole program)

CHANGES-REQUESTED

## Verdict line (critical-path slice)

CHANGES-REQUESTED

## Findings

**F-131 · Critical · SLICE · A/F/I** — C-L0b removes the Phase-1 type-aware `draft→posted` compatibility before its callers are converted to two-hop transitions. SPEC mandates one type-independent map and only `draft→confirmed→posted` for SI, SCN, Expense and Income (`SPEC-document-lifecycle-dimensions.md:32-36`), while C-L0b lands on the critical path (`PLAN-phase2-3-P-R.md:14,35`). N-6 deliberately permits direct posting for those four types (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:72-105`) and routes SI, SCN, Expense and Income through it (`SupplierInvoicePostingService.php:291-298`; `SupplierCreditNotePostingService.php:373-375`; `ExpenseService.php:317-323`; `IncomeService.php:153-157` on that branch). Their permanent two-hop routing is deferred to C-P0/C-2c (`PLAN-phase2-3-P-R.md:75,85-87`) · merging the critical slice after N-6 makes four production posting paths throw an illegal-transition error · add a critical prerequisite C-L0b0: “Convert every N-6 direct-post caller to an atomic two-hop shim before removing its type-aware edge; C-P0 later replaces SI’s shim with durable user confirmation.” Gate it with one posting regression per affected type, including rollback of both transition rows.

**F-132 · Critical · SLICE · A/E/I** — the proposed shared confirmation guard is false for live line-less document families and historical openings. The edge table assigns “≥1 line, partner, totals” to Invoice/CN and “same” to SI/SCN/Expense/Income (`SPEC-document-lifecycle-dimensions.md:261-266`). Expenses create no `document_lines` and permit `partner_id=null` (`ExpenseService.php:122-153`); Income creates neither lines nor a partner (`IncomeService.php:74-100`); AR/AP openings also create no lines (`ArApOpeningService.php:311-332`) · the two-hop repair required by F-131 would still reject valid Expense, Income and opening records · replace “same” with explicit guards: Expense requires valid `ExpenseMetadata`, positive/reconciling totals and its account/repository preflight, with optional partner; Income requires valid `IncomeMetadata` and account/repository preflight, with no partner requirement; historical openings require validated batch, side, partner and open amount but no lines; SI/SCN retain line guards. Add `ExpenseTwoHopPostingTest`, `IncomeTwoHopPostingTest` and `HistoricalOpeningTwoHopNoLinesTest`.

**F-133 · High · SLICE · B/E/I** — an open Ministry attempt is frozen only through an application read port, not at the data boundary. SPEC promises that an open request freezes header and line payload fields even against mutation (`SPEC-document-lifecycle-dimensions.md:125-137`), but C-3a1a adds only attempt schema/read port and C-L0c enforces through that port without a migration (`PLAN-phase2-3-P-R.md:36-37`). The existing trigger returns immediately whenever the old row is not SEALED (`2025_12_11_054716_add_document_immutability_trigger.php:24-27`), exactly the state of a confirmed document with an open attempt · query-builder/raw writers can change the legal payload after Phase A and before external acceptance · add document and line DB triggers in C-3a1a’s migration: when an unresolved attempt exists, reject payload UPDATE/DELETE, cancellation and status rollback. Extend `OpenAuthorityAttemptFreezesDocumentTest` to ORM, builder and raw SQL header/line mutations.

**F-134 · High · SLICE · G/I** — C-QR0’s all-country insert silently assigns a second, unrelated legal policy through an existing DDL default, and “provisional” has no behavior. C-QR0 inserts `country_document_settings` rows for every catalog country (`PLAN-phase2-3-P-R.md:33`), but that table defaults `pre_delivery_invoicing_policy` to `require_delivery_first` (`2026_08_10_120000_create_country_document_settings_table.php:44-48`), while explicit sales defaults currently exist only for TN and FR (`CountryDocumentDefaults.php:28-42`). The resolver still falls back to code when a row is missing (`PreDeliveryInvoicingPolicyResolver.php:81-95`), and the planned authority schema names no provisional flag or blocking rule despite saying other countries are provisional (`SPEC-document-lifecycle-dimensions.md:112-116`) · C-QR0 would hardcode pre-delivery policy for every other country merely as a side effect of adding authority policy · require C-QR0 to insert complete, explicit settings rows, drop the pre-delivery DB default before mass insertion, and persist a `policy_expertise_status`/`provisional` fact whose blocking semantics are stated. Add a test proving C-QR0 cannot alter or synthesize pre-delivery policy.

**F-135 · High · MAIN · A/C/E/I** — `balance_due` has two claimed sole writers. The dimension table and recompute-port rule say the port is the only way payment cache columns, including `balance_due`, change (`SPEC-document-lifecycle-dimensions.md:17,38-47`). PostgreSQL already rewrites `balance_due` directly after payment allocations (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-60`) and credit-note applications (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-70`) · application recomputation and trigger recomputation can use different formulas for CN, SI and SCN and race over the same cache · amend §1.2 to say: “Except atomic posting/import initialization, `balance_due` is owned by the allocation-trigger family; the port locks and reads it/fact rows and owns `payment_status` and closure only.” Extend the trigger family to supplier-credit applications and add PG formula/parity/concurrency tests. Alternatively, explicitly retire every trigger before declaring the port authoritative.

**F-136 · High · MAIN · A/B/E/I** — the program adds three document types without adding them to its exhaustive model or fiscal infrastructure. The applicability matrix is declared exhaustive for 13 cases (`SPEC-document-lifecycle-dimensions.md:49-66`), matching the current enum (`DocumentType.php:7-20,48`), but P-5/R-4 later add `GoodsReceiptReversal`, `DeliveryNoteReversal` and `ReturnNoteReversal` (`SPEC-document-lifecycle-dimensions.md:342-351`). Section 5 still defines only four fiscal chains (`SPEC-document-lifecycle-dimensions.md:235-241`), and tests still assert 13 types (`PLAN-phase2-3-P-R.md:104,137`). “Original stays SEALED (VOIDED flag only)” is also internally impossible when `fiscal_status` itself is the `SEALED/VOIDED` field (`SPEC-document-lifecycle-dimensions.md:18,349-351`) · new documents can bypass payment refusal, closure, authority initialization, CHECK/TS parity and chain verification · add every new `DocumentType` to the complete dimension/guard/classifier matrix, verifier/backfill policy, numbering and contracts. State unambiguously whether the original remains `SEALED` or transitions to `VOIDED`; do not describe `VOIDED` as a separate flag unless one is added.

**F-137 · High · MAIN · B/F/I** — P-4 creates SI tax snapshots but still does not define how supplier invoices enter or leave the VAT declaration. The current query includes only invoice, credit note and expense (`EloquentVatDataRepository.php:30-65`), and the cancellation guard confirms SI input VAT is absent today (`VatPeriodCancellationGuard.php:192-202`). SPEC defers SI entirely to P-4 (`SPEC-document-lifecycle-dimensions.md:335-337`), but P-4 names only snapshot/backfill/delta (`PLAN-phase2-3-P-R.md:149`); its row depends only on C-R1b2 even though the ordering prose says P-4 follows C-P2b (`PLAN-phase2-3-P-R.md:23-25`) · implementations can snapshot VAT yet never declare it, or declare cancelled/replaced SIs incorrectly · make P-4 depend on C-P2b and specify the grouped INPUT predicate, date/period basis, cancellation/replacement treatment and delta gate. Add posted, cancelled, replacement and closed-period SI declaration tests.

**F-138 · High · MAIN · C/F/G/I** — service-line supplier accounting has no JE contract. SPEC says service lines “post directly to expense/payable” (`SPEC-document-lifecycle-dimensions.md:62`), and C-SI0 repeats that promise (`PLAN-phase2-3-P-R.md:76`). Today all SI subtotal is passed to `createSupplierInvoiceGrIrClearingEntry()` (`SupplierInvoicePostingService.php:271-284`), which resolves GR-IR, inventory and PPV accounts (`GeneralLedgerService.php:2052-2058`) and debits 408/inventory (`GeneralLedgerService.php:2076-2087,2139-2161`). A seeded `PurchaseExpenses` purpose exists (`SystemAccountPurpose.php:65-74`) but is not selected here · a service-only or mixed invoice can either clear nonexistent GR-IR or capitalize service cost into inventory · split C-SI0 into classification and GL lanes. Specify: physical net clears 408; service net debits the approved purchase/service expense purpose; service non-recoverable VAT follows the expense leg; mixed invoices aggregate both bases in one balanced JE; neither service HT nor its VAT enters PPV/inventory. Add exact-account and amount assertions, not merely “posts successfully.”

**F-139 · High · SLICE · B/E/I** — legacy TN quarantine has no durable state. SPEC requires existing Posted TN rows lacking proof to be quarantined (`SPEC-document-lifecycle-dimensions.md:121-124`), and F-2b is a data migration for that outcome (`PLAN-phase2-3-P-R.md:42`). The declared authority storage has only four statuses (`SPEC-document-lifecycle-dimensions.md:18`); the only quarantine marker defined is `authority_digest_mismatch=true`, which represents a different accepted-payload corruption case (`SPEC-document-lifecycle-dimensions.md:133-137`) · F-2b cannot durably distinguish “legacy acceptance unprovable” from pending, rejected or digest mismatch, nor support rerunnable listing and resolution · add a durable quarantine record/reason such as `legacy_authority_unproven`, with timestamps, document identity, immutable evidence and explicit exit policy. Extend `LegacyPostedTnQuarantineTest` to prove persistence, rerun idempotency and reporting.

**F-140 · Medium · SLICE · B/E/I** — the chain migration does not reject half-populated membership tuples. Membership requires both hash and sequence (`SPEC-document-lifecycle-dimensions.md:235-237`), while C-F1a adds a unique sequence constraint with only a duplicate census (`PLAN-phase2-3-P-R.md:39`). Existing fiscal constraints allow Draft and NON_FISCAL documents to bypass the paired-field requirement (`2026_03_10_300000_fix_fiscal_constraints_for_drafts.php:23-33`) · a row with sequence but no hash is excluded from head selection yet occupies the unique key, causing the next seal to fail; a hash without sequence disappears from the walk · C-F1a must census and abort on `fiscal_hash IS NULL XOR chain_sequence IS NULL`, then add a pair-consistency CHECK and positive-sequence check. Add `PartialFiscalTuplePreflightTest`.

**F-141 · Medium · SLICE · F** — SPEC and PLAN publish different critical paths. SPEC orders C-L0c before C-3a1a, then repeats C-L0c (`SPEC-document-lifecycle-dimensions.md:140-144`); PLAN correctly orders C-3a1a before C-L0c (`PLAN-phase2-3-P-R.md:14-15`) · implementers have two self-contained normative orders, one of which cannot enforce `hasOpenAttempt()` · replace SPEC’s sequence verbatim with PLAN’s sequence and include the new compatibility/freeze prerequisites required by F-131/F-133.

**F-142 · Medium · MAIN · E/F** — final lifecycle CHECK timing still contradicts the retirement protocol. SPEC says C-RET1 migrates the fleet and only then tightens to four values, with enum removal in C-RET2 (`SPEC-document-lifecycle-dimensions.md:21-28`); PLAN assigns that ownership to C-RET1/C-RET2 (`PLAN-phase2-3-P-R.md:88-89`). Enforcement instead says the final four-value CHECK lands “after C-D0a” (`SPEC-document-lifecycle-dimensions.md:362-372`) · an implementation following §11 can reject still-live `paid`/`received` rows and break enum hydration · replace “after C-D0a” with “in C-RET1 after tenant migration and fleet-zero proof; PHP cases remain until C-RET2.”

**F-143 · Medium · MAIN · F/I** — several lanes still violate the plan’s one-day/one-invariant gate, and the resulting seams lack tests. The plan caps lanes at roughly one implementer-day and one migration (`PLAN-phase2-3-P-R.md:3-7`), but C-QR0 combines shared-table schema, all-country policy, status backfill, initializer, current-mode guard and runtime refusal (`PLAN-phase2-3-P-R.md:33`); C-P2a combines two ledgers, snapshotting, backfill, quarantine, runtime slicing and fulfilment (`PLAN-phase2-3-P-R.md:78`); R-4a combines two new types, two chains, API guards and idempotency (`PLAN-phase2-3-P-R.md:154`) · review gates cannot isolate migration, backfill and runtime failures · split schema/backfill/runtime for C-QR0 and C-P2a; split R-4 type/chain introduction from guard/API work; split C-SI0 classification from service GL. Add the tests required by F-131–F-140 to the owning lanes before dispatch.

## r1..r8 findings disposition

`PARTIAL` means the original defect is narrowed but remains materially present in r8. No prior finding is wholly OPEN.

| Finding | Status | Finding | Status | Finding | Status | Finding | Status | Finding | Status |
|---|---|---|---|---|---|---|---|---|---|
| F-1 | RESOLVED | F-2 | RESOLVED | F-3 | RESOLVED | F-4 | RESOLVED | F-5 | RESOLVED |
| F-6 | RESOLVED | F-7 | RESOLVED | F-8 | PARTIAL | F-9 | RESOLVED | F-10 | RESOLVED |
| F-11 | RESOLVED | F-12 | RESOLVED | F-13 | RESOLVED | F-14 | RESOLVED | F-15 | RESOLVED |
| F-16 | RESOLVED | F-17 | RESOLVED | F-18 | RESOLVED | F-19 | RESOLVED | F-20 | PARTIAL |
| F-21 | RESOLVED | F-22 | RESOLVED | F-23 | RESOLVED | F-24 | RESOLVED | F-25 | RESOLVED |
| F-26 | RESOLVED | F-27 | RESOLVED | F-28 | RESOLVED | F-29 | RESOLVED | F-30 | RESOLVED |
| F-31 | RESOLVED | F-32 | RESOLVED | F-33 | RESOLVED | F-34 | RESOLVED | F-35 | RESOLVED |
| F-36 | RESOLVED | F-37 | RESOLVED | F-38 | RESOLVED | F-39 | RESOLVED | F-40 | RESOLVED |
| F-41 | RESOLVED | F-42 | RESOLVED | F-43 | RESOLVED | F-44 | RESOLVED | F-45 | RESOLVED |
| F-46 | RESOLVED | F-47 | RESOLVED | F-48 | RESOLVED | F-49 | RESOLVED | F-50 | RESOLVED |
| F-51 | RESOLVED | F-52 | RESOLVED | F-53 | RESOLVED | F-54 | RESOLVED | F-55 | RESOLVED |
| F-56 | RESOLVED | F-57 | RESOLVED | F-58 | RESOLVED | F-59 | RESOLVED | F-60 | RESOLVED |
| F-61 | RESOLVED | F-62 | RESOLVED | F-63 | RESOLVED | F-64 | RESOLVED | F-65 | PARTIAL |
| F-66 | RESOLVED | F-67 | RESOLVED | F-68 | RESOLVED | F-69 | RESOLVED | F-70 | RESOLVED |
| F-71 | RESOLVED | F-72 | RESOLVED | F-73 | RESOLVED | F-74 | RESOLVED | F-75 | RESOLVED |
| F-76 | RESOLVED | F-77 | RESOLVED | F-78 | RESOLVED | F-79 | RESOLVED | F-80 | RESOLVED |
| F-81 | RESOLVED | F-82 | RESOLVED | F-83 | RESOLVED | F-84 | RESOLVED | F-85 | RESOLVED |
| F-86 | RESOLVED | F-87 | RESOLVED | F-88 | RESOLVED | F-89 | RESOLVED | F-90 | RESOLVED |
| F-91 | RESOLVED | F-92 | RESOLVED | F-93 | RESOLVED | F-94 | RESOLVED | F-95 | RESOLVED |
| F-96 | RESOLVED | F-97 | RESOLVED | F-98 | RESOLVED | F-99 | RESOLVED | F-100 | RESOLVED |
| F-101 | RESOLVED | F-102 | RESOLVED | F-103 | RESOLVED | F-104 | RESOLVED | F-105 | RESOLVED |
| F-106 | RESOLVED | F-107 | RESOLVED | F-108 | RESOLVED | F-109 | PARTIAL | F-110 | RESOLVED |
| F-111 | RESOLVED | F-112 | RESOLVED | F-113 | RESOLVED | F-114 | RESOLVED | F-115 | RESOLVED |
| F-116 | RESOLVED | F-117 | RESOLVED | F-118 | RESOLVED | F-119 | RESOLVED | F-120 | RESOLVED |
| F-121 | RESOLVED | F-122 | RESOLVED | F-123 | PARTIAL | F-124 | RESOLVED | F-125 | RESOLVED |
| F-126 | RESOLVED | F-127 | RESOLVED | F-128 | RESOLVED | F-129 | PARTIAL | F-130 | RESOLVED |

Remaining partials map directly to this review:

- F-8 → F-132/F-136.
- F-20 → F-133/F-135/F-137/F-138.
- F-65 → F-134.
- F-109 → F-133.
- F-123 → F-138.
- F-129 → F-136.

## OQ dispositions table

### Legacy OQ-1..11

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Durable SI confirmation | ACCEPT | Required for supplier advances and durable adjacency. |
| OQ-2 | Census-only opening classification | REJECT — persist `opening_side` and provenance | Type/status inference is not independent. |
| OQ-3 | Defer PO prepayment correction | REJECT — immediate typed 422 until 409 exists | Otherwise the live writer can use customer-receipt GL direction. |
| OQ-4 | Staged `received` retirement | ACCEPT | Readers and tenant rows must migrate before enum removal. |
| OQ-5 | Keep supplier excess-payment 422 | ACCEPT | Prevents accidental supplier-advance accounting. |
| OQ-6 | Minimal SCN lifecycle | ACCEPT | r8 now separates authoring, posting, policy, application and reversal. |
| OQ-7 | Fiscal authority as a future program | REJECT — authority acceptance remains prerequisite | TN posting cannot precede Ministry acceptance. |
| OQ-8 | Parallel procurement fallback | REJECT — one country-row/company-override authority | Multiple fallbacks recreate hidden policy. |
| OQ-9 | Widen immutability | ACCEPT | Seal and authority facts must freeze together. |
| OQ-10 | Retire direct posting helpers | ACCEPT | Only after all writers are routed and compatibility is preserved. |
| OQ-11 | Add `in_payment` status | NEEDS-OWNER | This is a visible product meaning, not a safe migration default. |

### Legacy R-OQ-1..12

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| R-OQ-1 | Remove CN lifecycle-paid conflation | ACCEPT | Settlement must not alter lifecycle. |
| R-OQ-2 | Separate cash-refund lane | ACCEPT | Refund and application have different treasury directions. |
| R-OQ-3 | Standalone CN settlement service | ACCEPT | One cap and lock authority is required. |
| R-OQ-4 | Refuse cancelling applied CN | ACCEPT | Child application must be reversed explicitly. |
| R-OQ-5 | Direct returned cache writes | REJECT — root-fact recomputation | Direct caches drift under cross-source returns and reversal. |
| R-OQ-6 | RN reversal documents | ACCEPT | Subject to exhaustive type/chain integration in F-136. |
| R-OQ-7 | Seal RN at confirmation | ACCEPT | Confirmation is its fiscal issuance edge. |
| R-OQ-8 | Leave refund metadata untouched | ACCEPT | Deferral is safer than deletion or speculative revival. |
| R-OQ-9 | VAT correction after delta acknowledgement | NEEDS-OWNER | Existing-tenant declared figures change. |
| R-OQ-10 | Leave dashboard gross | ACCEPT | Preserves an existing watched metric until separately authorized. |
| R-OQ-11 | Internal confirmed hop for openings | ACCEPT | Preserves adjacency without emitting a false external event. |
| R-OQ-12 | No invented B2B-return restriction | ACCEPT | Any restriction must come from jurisdiction policy. |

### Owner sheet OQ-11..63

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-11 | DTO boolean, no `in_payment` value | NEEDS-OWNER | Visible reconciliation semantics need product approval. |
| OQ-12 | Reserved identifier | ACCEPT | No behavior. |
| OQ-13 | Supplier return reduces net PO fulfilment | ACCEPT | Correct stock/fulfilment separation. |
| OQ-14 | VAT-free confirmed proforma | ACCEPT | Consistent with adopted Art. 18 treatment. |
| OQ-15 | Refuse until authority acceptance | ACCEPT | Required fail-closed behavior. |
| OQ-16 | Reuse immutable `DocumentFullyPaid` event | ACCEPT | Schema need not change. |
| OQ-17 | FR purchase policy provisional | ACCEPT | Missing expertise must block posting. |
| OQ-18 | Leave refund metadata untouched | ACCEPT | Safe deferral. |
| R-OQ-9 | Merge VAT fix only after delta acknowledgement | NEEDS-OWNER | Financial figures change. |
| OQ-19 | Keep dashboard gross | ACCEPT | Avoids an unauthorized metric change. |
| OQ-20 | Per-type closure table | ACCEPT | OQ-28 governs RN. |
| OQ-21 | Returned invoice can satisfy fulfilment | ACCEPT | Settlement remains independently required. |
| OQ-22 | Cancellation closes permanently | ACCEPT | Stable terminal rule. |
| OQ-23 | TN CN settlement excludes stamp | ACCEPT | Matches production application basis. |
| OQ-24 | Equal-to-default rows become inherited | ACCEPT | Preserves genuine overrides. |
| OQ-25 | Expense/Income use metadata basis | ACCEPT | They have no allocation ledger. |
| OQ-26 | TN 472 only | ACCEPT | FR `allow` remains unavailable. |
| OQ-27 | SI VAT periods/delta | NEEDS-OWNER | Historical period scope remains an owner decision. |
| OQ-28 | RN closes at confirmation | ACCEPT | Terminal return-document rule. |
| OQ-29 | No country activates `allow` | ACCEPT | Correct safe default. |
| OQ-30 | Keep types; persist `opening_side` | ACCEPT | Avoids destructive type migration. |
| OQ-31 | DN returned only by quantity exhaustion | ACCEPT | Link existence alone is insufficient. |
| OQ-32 | Scheduled expiry command | ACCEPT | Needed for durable closure timestamps. |
| OQ-33 | POS receipt proves fulfilment | ACCEPT | Only fulfilment; authority and GL remain independent. |
| OQ-34 | Retain SCN residue | ACCEPT | Future supplier offset remains possible. |
| OQ-35 | VAT and GL periods both open | ACCEPT | Prevents asymmetric reversals. |
| OQ-36 | Quarantine backwards paid-CN accounting | ACCEPT | Cash history must not be rewritten blindly. |
| OQ-37 | Historical CN openings non-settleable | ACCEPT | Avoids inferred refund semantics. |
| OQ-38 | No temporary authority override | ACCEPT | Binding fail-closed stance. |
| OQ-39 | Quarantine affected chain only | ACCEPT | Other chains remain independently verifiable. |
| OQ-40 | New attempt after rejection | ACCEPT | Unsealed payload may be retried immutably. |
| OQ-41 | SO becomes n/a when invoiced | REJECT — only after residue detachment | Otherwise attached advances disappear. |
| OQ-42 | Delivered before returned per tuple | ACCEPT | A CN cannot manufacture fulfilment. |
| OQ-43 | Reverse children before SI cancellation | ACCEPT | No implicit cascade. |
| OQ-44 | Supplier advance company override | ACCEPT | Valid country/default/override hierarchy. |
| OQ-45 | Quarantine blocks posting and closure | ACCEPT | Ambiguous facts must fail closed. |
| OQ-46 | POS invoice adopts existing event/JE | ACCEPT | Prevents duplicate seal and GL. |
| OQ-47 | Durable SI confirmed state | ACCEPT | Needed for P-2 and lifecycle clarity. |
| OQ-48 | FIFO capped order transfers | REJECT — add mandatory final-residue detachment | FIFO alone leaves money on a closed order. |
| OQ-49 | Quarantine legacy TN rows | ACCEPT | Do not fabricate Ministry acceptance. |
| OQ-50 | Product-wide unlocated capacity | ACCEPT | Correct until durable location provenance exists. |
| OQ-51 | Replacement SI linkage | ACCEPT | Required audit trail. |
| OQ-52 | Provisional gate precedes override | ACCEPT | A company cannot waive missing expertise. |
| OQ-53 | Authority applies to Invoice/CN only | ACCEPT | DN/RN need a different attempt protocol. |
| OQ-54 | “UK/generic” non-TN seeds | REJECT — actual catalog ISO codes, including GB; no pseudo-country | The FK and catalog do not support the stated examples. |
| OQ-55 | Block TN POS charge without Ministry proof | ACCEPT | Tenant QR is not authority acceptance. |
| OQ-56 | Detach final SO residue | ACCEPT | Required before payment becomes n/a. |
| OQ-57 | Corrective document after digest mismatch | ACCEPT | Never reseal accepted history. |
| OQ-58 | Expense/Income corrected by reversal | ACCEPT | Avoids unsafe cancellation. |
| OQ-59 | Service lines bypass receipts | ACCEPT | Subject to the explicit GL matrix in F-138. |
| OQ-60 | Every provisionable ISO country | ACCEPT | Catalog parity and missing-row refusal are correct. |
| OQ-61 | TN SCN unstamped | ACCEPT | Must remain explicit seeded policy. |
| OQ-62 | Reverse downstream before GR reversal | ACCEPT | Avoids cascading inventory/accounting rewrites. |
| OQ-63 | Distinct DN/RN reversal types and chains | ACCEPT | Subject to full matrix integration and an unambiguous original fiscal state. |

## Missed owner questions

1. For every catalog country beyond TN/FR, what is the explicit pre-delivery policy, and does a provisional jurisdiction row block sales posting before company overrides? C-QR0 otherwise inherits the table’s DDL default (`2026_08_10_120000_create_country_document_settings_table.php:44-48`).

2. For service SI lines, is the expense leg always the country-seeded `PurchaseExpenses` purpose, or may PO/category/account provenance select a more specific expense account? Current GL has no such rule (`GeneralLedgerService.php:2052-2058`; `SystemAccountPurpose.php:65-74`).

3. After a DN/RN reversal, does the original remain `fiscal_status=SEALED`, become `VOIDED`, or carry a new independent reversal marker? The current wording asserts incompatible states (`SPEC-document-lifecycle-dimensions.md:349-351`).

4. For P-4, which date creates input-VAT entitlement, and how should cancellation/replacement be represented in a filed/open period? OQ-27 asks which periods to backfill but not the declaration predicate itself (`PLAN-phase2-3-P-R.md:149`).

5. What resolves a `legacy_authority_unproven` quarantine: permanent reporting, owner-certified grandfathering, or a corrective document? OQ-49 selects quarantine but not its exit.

## Lane resequencing

Recommended critical path:

1. N-6 merge.
2. C-0a0 → C-F0.
3. Split C-QR0 into:

   - C-QR0a: authority/status schema, existing-row backfill and initializer.
   - C-QR0b: explicit complete country rows, provisional gate, missing-row refusal.

4. C-L0a.
5. New C-L0b0: Phase-1 direct-post compatibility and explicit line-less-family guards.
6. C-L0b.
7. C-3a1a, including DB-enforced open-attempt freeze.
8. C-L0c.
9. C-F1h → C-F1a, including partial-tuple census/CHECK.
10. C-3a1b → F-2a → F-2b with durable legacy quarantine.
11. C-F1b → C-F1c.

Main-chain adjustments:

- Split C-SI0 into `line_kind`/fulfilment and service-GL lanes.
- Split C-P2a into schema, backfill/quarantine and runtime writer/recompute lanes.
- Make P-4 depend explicitly on both C-R1b2 and C-P2b.
- Define reversal types in the exhaustive model before P-5/R-4 migrations.
- Split R-4 type/chain creation from APIs/guards and inverse stock accounting.

## Claims verified TRUE in the research sweeps that the spec relies on, and claims found FALSE/stale

### Verified TRUE

- SI currently posts directly Draft→Posted; N-6 routes that write through the type-aware status service (`fix/campaign-n6-payment-advance:SupplierInvoicePostingService.php:291-298`).
- Credit-note applications reduce invoice `balance_due` through a dedicated PG trigger (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-70`).
- Return quantities must union an invoice with its backing DNs; both source directions are live (`ReturnNoteService.php:296-310`; `DeliveredQuantityResolver.php:463-479`).
- The current verifier supports only Invoice/CreditNote and filters lifecycle `Posted` (`VerifyFiscalChainsCommand.php:252-285`).
- VAT aggregation is status-blind on its document arm and excludes SupplierInvoice (`EloquentVatDataRepository.php:30-65`).
- Expense/Income settlement lives in metadata and their documents are line-less (`ExpenseService.php:122-153`; `IncomeService.php:74-100`).
- The existing immutability trigger permits lifecycle migration of old `paid` rows because it freezes fiscal fields, not `status`, and arms only from an old SEALED row (`2025_12_11_054716_add_document_immutability_trigger.php:24-57`).
- `DocumentFullyPaid` contains no lifecycle-status field, so preserving its schema does not rename stored events (`DocumentFullyPaid.php:9-34`).

### FALSE or stale

- RESEARCH-P’s claim that SI Draft→Posted violates the Phase-1 adjacency is stale for the N-6 branch: Phase 1 explicitly adds type-aware direct-post edges for SI, SCN, Expense and Income (`fix/campaign-n6-payment-advance:DocumentStatusMachine.php:72-105`).
- Any claim that `balance_due` ignores sales credit-note applications is false after the dedicated trigger migration (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:38-70`).
- RESEARCH-P’s “no documents.status CHECK on dev” is true only of the inspected base tree, not the Phase-1 target branch; N-6 adds a six-value CHECK retaining `paid` and `received` (`fix/campaign-n6-payment-advance:2026_08_24_100100_add_status_check_constraint_to_documents.php:34-43,86-89`).
- Treating `fiscal_status=SEALED` or lifecycle `status=posted` as chain membership is false: current cancelled/paid tails retain hash/sequence, which is why the r8 lifecycle-independent membership correction is necessary (`VerifyFiscalChainsCommand.php:280-285`; `SPEC-document-lifecycle-dimensions.md:235-241`).

Critical-path slice: CHANGES-REQUESTED

Whole program: CHANGES-REQUESTED