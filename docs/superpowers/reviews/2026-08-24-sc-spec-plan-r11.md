# Session C spec+plan adversarial review r11

## Verdict line (whole program)

ACCEPT-WITH-CONDITIONS

No Critical finding remains. Acceptance is conditional on these five changes before the affected lanes dispatch:

1. Split C-RET1 into a fleet data-conversion release, fleet-zero proof, and a later four-value CHECK migration.
2. Make the final status-writer allowlist consistently five methods in SPEC, PLAN, PHPStan fixtures, and the raw-writer census.
3. Repair every undefined lane dependency and restore the missing migration/gate cells in the lane table.
4. Define whether DN/RN reversal atomically moves the original lifecycle to `cancelled`, and align closure semantics and tests.
5. Give P-4a/P-4b/P-4c separate red-first tests covering tax-fact schema, VAT selection, reversal idempotency, period dates, and replacements.

## Verdict line (critical-path slice)

ACCEPT

The four r10 conditions are discharged:

1. **C-QR0a schema-only — discharged.** PLAN:33 makes the new columns nullable and permits an unactivated state; PLAN:34 moves complete country rows, initialization, backfill, and constraint activation to C-QR0b.
2. **F-2a dark deployment — discharged.** PLAN:43 keeps the public route refused; PLAN:44 activates it in F-2b. The tests are split accordingly at PLAN:120.
3. **Historical opening writer in the slice — discharged.** PLAN:36 explicitly routes `ArApOpeningService:311-332` through `createHistoricalPosted()`; PLAN:115 retains the production-path test.
4. **C-F1h residual-only — discharged.** PLAN:40 excludes the chain-head work already delivered by N-6 and targets shared resolver/verifier/backfill consolidation. The proposed red seam is real: the N-6 verifier still filters `status=Posted` at `fix/campaign-n6-payment-advance:apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php:280-285`, and its backfill does likewise at `fix/campaign-n6-payment-advance:apps/api/app/Modules/Compliance/Commands/BackfillFiscalHashesCommand.php:122-133`.

No MAIN change regressed the accepted slice.

## Findings

**F-160 · High · MAIN · E/F** — C-RET1’s fleet proof and CHECK tightening cannot be one tenant migration. SPEC requires C-RET1 to migrate every tenant, prove a zero-residual fleet census, and only then tighten the CHECK (`SPEC-document-lifecycle-dimensions.md:22-25`), while PLAN assigns all of that to one migration (`PLAN-phase2-3-P-R.md:96`). Tenant migrations execute separately per tenant (`vendor/stancl/tenancy/src/Commands/Migrate.php:51-60`), and the rolling runner deliberately invokes that migrator one tenant at a time and records each migration independently (`RollingTenantMigrationCommand.php:25-39,80-100,110-132`). Consequently, the first tenant cannot execute the final CHECK after a fleet-zero proof that is only possible after the last tenant has been converted. Required wording: “C-RET1a converts `paid`/`received` data while both PHP cases and the old CHECK remain; run and archive fleet-zero proof; C-RET1b, in a later migration/release, tightens the CHECK; C-RET2 later removes PHP cases and regenerates TS/Slice-D parity.” Add a mixed-version fleet test.

**F-161 · High · MAIN · F** — The lane graph still contains undefined join names and a malformed lane. PLAN declares `C-D0b1` and `C-D0b2`, but `C-D0c` depends on nonexistent `C-D0b` (`PLAN-phase2-3-P-R.md:74-76`). It declares `C-P2b1` and `C-P2b2`, but P-2, P-3a, and P-4a depend on nonexistent `C-P2b` (`PLAN-phase2-3-P-R.md:89-90,152-153,158`). The `C-P2b1` row also omits its Migration and Gate cells (`PLAN-phase2-3-P-R.md:89`). Consequence: the “executable” graph has no determinate predecessor for purchase fulfilment, supplier advances, SCN authoring, or SI tax work. Required wording: use `C-D0b2`; use `C-P2b2` or declare an explicit `C-P2b` join milestone; restore `C-P2b1 | — | treasury + inventory-costing`; add a machine-checked dependency-closure test that rejects undeclared lane names.

**F-162 · High · MAIN · A/E/I** — The final status-writer allowlist remains contradictory. SPEC names five methods—`transition`, `transitionWithFiscalSeal`, `createDraft`, `createHistoricalPosted`, and `createPostedReversal` (`SPEC-document-lifecycle-dimensions.md:390-400`)—but PLAN still specifies a four-method allowlist (`PLAN-phase2-3-P-R.md:93-95`). N-6 currently exempts the whole class (`fix/campaign-n6-payment-advance:apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:72,189-219`) and retains a direct repair write (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:226-245`). Consequence: an implementer must either reject an intended fiscal writer or preserve a class-wide bypass. Required wording in C-2c-c2: “five exact method identities; `transitionWithFiscalSeal()` delegates its mutation to the shared private writer; `repairNeverPostedToConfirmed()` is removed/folded into `transition(reason=repair)` before enforcement.” Require one negative PHPStan fixture for every other method in the service.

**F-163 · High · MAIN · A/B/D/I** — DN/RN reversal does not define the original document’s lifecycle or closure result. The applicability matrix says both types have `confirmed→cancelled` (`SPEC-document-lifecycle-dimensions.md:63-64`), and the guard table assigns reversal documents to that cancellation edge (`SPEC-document-lifecycle-dimensions.md:281-289`). The reversal section and PLAN, however, specify only `fiscal_status→VOIDED`, stock inversion, and root recompute (`SPEC-document-lifecycle-dimensions.md:377-380`; `PLAN-phase2-3-P-R.md:166-168`). Today confirmation writes lifecycle `Confirmed` together with the seal (`ReturnNoteService.php:631-660`). Consequence: implementations may leave the original `confirmed + VOIDED + closed_reason=completed`, or move it to `cancelled + VOIDED + closed_reason=cancelled`; both conform to different passages and produce different API behavior. Required wording: either “R-4 atomically transitions the original `confirmed→cancelled`, voids the fiscal tuple without removing chain membership, and recomputes closure as cancelled,” or explicitly remove the lifecycle cancellation edge and define reversal as an orthogonal corrective fact. Add assertions for status, fiscal status, `closed_reason`, chain membership, and retry.

**F-164 · High · MAIN · B/I** — P-4’s statutory VAT behavior still lacks red-first proof in its owning lanes. SPEC now requires immutable negative tax-detail rows, reversal-date reporting, replacement-date independence, and a delta gate (`SPEC-document-lifecycle-dimensions.md:360-365`). The current repository is document-date/type based and excludes SupplierInvoice entirely (`EloquentVatDataRepository.php:27-65`). Yet P-4a and P-4b have empty Tests cells, while P-4c carries only a snapshot test and an unnamed delta test (`PLAN-phase2-3-P-R.md:158-160`). Consequence: schema or reader work can begin green, and cancellation may leave original input VAT reportable, duplicate a negative row on retry, or use the original period. Required change: P-4a owns `SupplierInvoiceTaxReversalFactSchemaTest`; P-4b owns `SupplierInvoiceVatPredicateTest` covering posted/cancelled/replacement and original-vs-reversal dates; P-4c owns `SupplierInvoiceTaxReversalIdempotencyTest`, `SupplierInvoiceVatCrossPeriodTest`, and a named delta-command test. P-4 remains owner-gated by OQ-27/OQ-67.

**F-165 · Medium · MAIN · A/F** — Generic `posted_at` has two owners separated by most of the program. C-L0a creates it and C-L0b says every transition writes it (`PLAN-phase2-3-P-R.md:35-37`); SPEC likewise requires it for all lifecycle-posted types (`SPEC-document-lifecycle-dimensions.md:18,265-267`). C-8a later claims “generic `posted_at` writers” again (`PLAN-phase2-3-P-R.md:98`). Consequence: C-L0b either leaves non-fiscal rows without the required instant until C-8a, or C-8a duplicates already-landed writers and tests. Required wording: C-L0b owns all new `posted_at` writes; C-8a owns only `posting_journal_entry_id`, legacy `posted_at` backfill, quarantine, and immutability preparation.

**F-166 · Low · MAIN · F** — PLAN r10 identifies its normative dependency as “Spec r9” (`PLAN-phase2-3-P-R.md:1-3`) although the reviewed object is SPEC r10 (`SPEC-document-lifecycle-dimensions.md:1-4`). Consequence: a literal handoff points implementers at a nonexistent/stale revision. Replace with “SPEC r10.”

**F-167 · Low · MAIN · A** — The normative enum value is `not_applicable`, but the native Invoice closure row uses `n/a` (`SPEC-document-lifecycle-dimensions.md:16,60`). Consequence: generated contracts or tests can encode a second spelling. Replace every normative `n/a` token with `not_applicable`; reserve “n/a” for prose only.

## r1..r10 findings disposition

`R = RESOLVED`, `P = PARTIAL`, `O = OPEN`.

| Finding | State | Finding | State | Finding | State | Finding | State |
|---|---:|---|---:|---|---:|---|---:|
| F-1 | R | F-2 | R | F-3 | R | F-4 | R |
| F-5 | R | F-6 | R | F-7 | R | F-8 | R |
| F-9 | R | F-10 | R | F-11 | R | F-12 | R |
| F-13 | R | F-14 | R | F-15 | R | F-16 | R |
| F-17 | R | F-18 | R | F-19 | R | F-20 | P |
| F-21 | R | F-22 | R | F-23 | R | F-24 | R |
| F-25 | R | F-26 | R | F-27 | R | F-28 | R |
| F-29 | R | F-30 | R | F-31 | R | F-32 | R |
| F-33 | R | F-34 | R | F-35 | R | F-36 | R |
| F-37 | R | F-38 | P | F-39 | R | F-40 | P |
| F-41 | R | F-42 | R | F-43 | R | F-44 | R |
| F-45 | R | F-46 | R | F-47 | R | F-48 | R |
| F-49 | R | F-50 | R | F-51 | R | F-52 | R |
| F-53 | R | F-54 | R | F-55 | R | F-56 | R |
| F-57 | R | F-58 | R | F-59 | R | F-60 | R |
| F-61 | R | F-62 | R | F-63 | R | F-64 | R |
| F-65 | R | F-66 | R | F-67 | R | F-68 | R |
| F-69 | R | F-70 | R | F-71 | R | F-72 | R |
| F-73 | R | F-74 | R | F-75 | P | F-76 | R |
| F-77 | R | F-78 | R | F-79 | R | F-80 | R |
| F-81 | R | F-82 | R | F-83 | R | F-84 | R |
| F-85 | R | F-86 | R | F-87 | R | F-88 | R |
| F-89 | R | F-90 | R | F-91 | R | F-92 | R |
| F-93 | R | F-94 | R | F-95 | R | F-96 | R |
| F-97 | R | F-98 | R | F-99 | R | F-100 | R |
| F-101 | R | F-102 | R | F-103 | R | F-104 | R |
| F-105 | R | F-106 | R | F-107 | R | F-108 | R |
| F-109 | R | F-110 | R | F-111 | R | F-112 | R |
| F-113 | R | F-114 | R | F-115 | R | F-116 | R |
| F-117 | R | F-118 | R | F-119 | R | F-120 | R |
| F-121 | R | F-122 | R | F-123 | R | F-124 | R |
| F-125 | R | F-126 | R | F-127 | R | F-128 | R |
| F-129 | R | F-130 | R | F-131 | R | F-132 | R |
| F-133 | R | F-134 | R | F-135 | R | F-136 | R |
| F-137 | P | F-138 | R | F-139 | R | F-140 | R |
| F-141 | R | F-142 | R | F-143 | R | F-144 | R |
| F-145 | R | F-146 | R | F-147 | R | F-148 | R |
| F-149 | P | F-150 | R | F-151 | R | F-152 | R |
| F-153 | R | F-154 | R | F-155 | P | F-156 | R |
| F-157 | R | F-158 | R | F-159 | R |  |  |

The remaining partials are represented by F-162/F-164 or by the general test-completeness condition; none remains wholly unaddressed in r10 prose.

## OQ dispositions table

### Original OQ-1..11

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Durable user-visible SI `confirmed` state | ACCEPT | Preserves one adjacency map and gives P-2 a legitimate advance target. |
| OQ-2 | Census supplier-payment typing only | REJECT — persist side and operation | A census does not repair customer-shaped `DocumentPayment` semantics. |
| OQ-3 | Defer PO-prepayment correction | REJECT — refuse PO payments throughout this program | P-2 covers SI advances, not PO liabilities or clearing. |
| OQ-4 | Retire lifecycle `received` | ACCEPT, conditioned | Use net GR/SGRN recomputation and the split C-RET rollout in F-160. |
| OQ-5 | Keep unposted-SI payment 422 until P-2 | ACCEPT | This is the safe behavior while 409 create/clear accounting is absent. |
| OQ-6 | Build a minimal SCN path | ACCEPT, conditioned | “Minimal” includes author, confirm, post, apply, residue, exact reversal, and applied-cancel refusal. |
| OQ-7 | Leave FE match vocabulary separate | REJECT — prerequisite | The durable SI-confirm step must not ship with an impossible FE match state. |
| OQ-8 | Add country defaults to purchase policy | ACCEPT, conditioned | Country defaults must feed the existing procurement vocabulary through inherited/override provenance. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT | Persistent posting facts must protect headers and lines after cancellation. |
| OQ-10 | Retire `Document::getPaymentStatus()` | ACCEPT | One cached recompute authority avoids divergent type lists. |
| OQ-11 | Omit `in_payment` from the enum | NEEDS-OWNER | Reconciliation exists; presentation semantics are a product decision. |

### R-OQ-1..12

| OQ | Recommended default | Disposition | Reason |
|---|---|---|---|
| R-OQ-1 | Treat lifecycle `paid` on CN as a defect | ACCEPT | CN settlement derives from outward applications/refunds. |
| R-OQ-2 | Build CN cash refund in R-3 | ACCEPT, conditioned | It must share the ex-stamp cap, lock order, payment identity, and idempotency contract. |
| R-OQ-3 | Permit standalone CN application | ACCEPT | The common settlement service provides the necessary shared cap. |
| R-OQ-4 | Refuse cancellation of an applied CN | ACCEPT | Correct until compensating allocation facts exist. |
| R-OQ-5 | Cache return quantity on the directly sourced line | REJECT — recompute the canonical root | Invoice- and DN-sourced returns affect sibling/root projections. |
| R-OQ-6 | Add RN reversal documents | ACCEPT, conditioned | F-163 must settle original lifecycle and closure semantics. |
| R-OQ-7 | Keep RN sealing at `confirmed` | ACCEPT | Fiscal chain membership is independent of lifecycle spelling. |
| R-OQ-8 | Delete or revive dead refund metadata now | NEEDS-OWNER | Keep it untouched until R-3 defines disposition. |
| R-OQ-9 | Correct CN VAT declaration | NEEDS-OWNER | Merge only after tenant/period delta acknowledgement. |
| R-OQ-10 | Net dashboard revenue by CN | NEEDS-OWNER | This changes an owner-watched business figure. |
| R-OQ-11 | Historical opening takes an internal two-hop | ACCEPT | Preserve adjacency without emitting a false confirmation event. |
| R-OQ-12 | Add no B2B return policy | ACCEPT | Do not invent legal/product restrictions; future policy must be country-seeded. |

### Owner-sheet OQ-11..73 audit

The r10 dispositions for OQ-11..68 remain valid, with two qualifications:

- **OQ-63: REJECT the stale “original stays sealed” clause.** Keep the recommended distinct reversal types and own chains, but the original becomes `VOIDED` under the later OQ-66 ruling (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:62,65`).
- **OQ-27, OQ-67 and R-OQ-9: NEEDS-OWNER.** They change VAT entitlement or filed-period figures (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:17,26,66`).
- **OQ-11: NEEDS-OWNER.** `in_payment` remains a presentation decision (`OWNER-DECISIONS-lifecycle-dimensions-2026-08-24.md:9`).

New rows:

| OQ | Default | Disposition | Reason |
|---|---|---|---|
| OQ-69 | Adopted-event FK outside document chains | ACCEPT | Avoids duplicate fiscal identity and hash copying. |
| OQ-70 | Company cannot elevate `allow` capability | ACCEPT | Jurisdiction capability must precede tenant preference. |
| OQ-71 | TN approved; all other procurement rows provisional | ACCEPT | Fail-closed until country expertise is supplied. |
| OQ-72 | Supplier return recomputes both SI and PO | ACCEPT | Both caches depend on the affected receipt slices. |
| OQ-73 | Immutable negative SI tax-reversal rows | ACCEPT, conditioned | P-4 requires the red-first coverage in F-164. |

## Missed owner questions

1. After a DeliveryNote/ReturnNote reversal, must the original lifecycle transition to `cancelled` with `closed_reason=cancelled`, or remain `confirmed` while only its fiscal state becomes `VOIDED`? The current spec supports both readings. Recommended default: atomically cancel the original while retaining its immutable chain tuple.
2. If the owner instead wants reversal to remain orthogonal to lifecycle, should a reversed RN remain permanently `closed_reason=completed`? This cannot be inferred from OQ-66, which rules only on fiscal state.

## Lane resequencing

The accepted critical path remains unchanged.

Main-chain corrections:

1. Replace `C-D0c → C-D0b` with `C-D0c → C-D0b2`.
2. Declare a `C-P2b` join after C-P2b2, or replace all P-2/P-3/P-4 dependencies with C-P2b2.
3. Complete C-P2b1’s migration and gate cells.
4. Replace C-RET1 with C-RET1a data conversion → fleet-zero proof → C-RET1b four-value CHECK → later C-RET2 enum/TS removal.
5. Make C-2c-c2 depend on the final five-method writer contract.
6. Add the lifecycle/closure decision and its tests to R-4a before R-4b stock inversion.
7. Assign tax-schema tests to P-4a, VAT-reader tests to P-4b, and cancellation/idempotency/delta tests to P-4c.
8. Remove `posted_at` writer work from C-8a; it already belongs to C-L0b.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE:

- SupplierInvoice currently posts directly from Draft, seeds `balance_due=total`, and writes Posted (`SupplierInvoicePostingService.php:286-292`).
- PO `Received` is still a gross fulfilment verdict, while partial receipt exists only as an on-demand string result (`GoodsReceiptService.php:723-734,876-914`).
- The payment-allocation trigger computes `total − payments − credit applications`, so historical opening basis must be persisted (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-48`).
- The credit-note allocation trigger currently updates only the target invoice (`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-69`).
- The current immutability trigger protects only rows whose old fiscal status is SEALED and permits lifecycle/cache updates (`2025_12_11_054716_add_document_immutability_trigger.php:24-57`).
- Expense and Income still post directly from Draft (`ExpenseService.php:309-319`; `IncomeService.php:145-154`), and linked-cost reversal directly creates a Posted Expense (`ExpenseService.php:841-853`).
- Historical openings directly create Posted Invoice/CreditNote rows with an authoritative imported open amount (`ArApOpeningService.php:306-332`).
- The VAT repository remains status-blind and SI-blind (`EloquentVatDataRepository.php:27-65`).
- Return-note confirmation writes lifecycle `Confirmed` and fiscal `SEALED` in the same update (`ReturnNoteService.php:631-660`).

FALSE or stale:

- The research claim that the N-6 adjacency forbids all direct `Draft→Posted` purchase/expense flows is stale: the branch now has explicit type-aware compatibility for SupplierInvoice, SupplierCreditNote, Expense, and Income (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:70-111`).
- The research implication that N-6 still leaves a default-open classifier is stale. Its default is refusal, although PurchaseOrder remains an explicit wrong-direction exception that C-0a0 must remove (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php:87-112`).
- The original claim that N-6 still uses lifecycle-filtered chain heads in all sealing writers is stale: its posting/DN/RN writers use hash membership. The verifier and backfill commands remain lifecycle-filtered, which is the genuine C-F1h residual (`fix/campaign-n6-payment-advance:apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:664-674`; verifier `:280-285`; backfill `:122-133`).
- “C-RET1 can migrate the whole fleet, prove fleet zero, and tighten each tenant CHECK in one migration” is false under the repository’s per-tenant migration runner (`RollingTenantMigrationCommand.php:25-39,80-100`).
- “All PLAN dependencies resolve to declared lanes” is false: `C-D0b` and `C-P2b` are undeclared aliases (`PLAN-phase2-3-P-R.md:74-76,89-90,152-158`).

Critical-path slice: ACCEPT

Whole program: ACCEPT-WITH-CONDITIONS