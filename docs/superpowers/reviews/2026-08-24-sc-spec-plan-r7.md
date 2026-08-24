# Session C spec+plan adversarial review r7

## Verdict line (whole program)

CHANGES-REQUESTED

## Verdict line (critical-path slice)

CHANGES-REQUESTED

## Findings

**F-107 · Critical · area C/F/I** — C-0a cannot implement its promised `(type,status,provenance)` matrix because two provenance authorities are created much later, and its Invoice rules overlap. The spec first admits every `Invoice+posted` as `ReceivableClearing`, then separately admits `Invoice+posted+opening_side=ap` as `PayableSettlement` without defining precedence (`SPEC-document-lifecycle-dimensions.md:80-92`). Yet C-0a depends only on N-6 (`PLAN-phase2-3-P-R.md:31`), while `opening_side` arrives in C-H0a and `document_provenance` in C-POS0 (`PLAN-phase2-3-P-R.md:39-46`). Today AP openings are ordinary Invoice/CreditNote rows with no side column (`ArApOpeningService.php:159-170,311-328`), and POS provenance lives only in payload/reference (`POSAccountChargeDraftService.php:49-72`). Consequence: the critical-path test cannot construct the promised persisted variants, and an implementation following rule order may send AP openings to Dr bank/Cr 411. Required change: replace §2.1 with a mutually exclusive table whose native-Invoice rule explicitly excludes `opening_side=ap` and `pos_account_charge`; either add a provenance-schema prerequisite before C-0a or make C-0a fail closed for historical/POS documents until C-H0c/C-POS0 admit them. Add a precedence test for every overlapping pair.

**F-108 · Critical · area A/B/I** — The authority protocol is defined only for Invoice/CreditNote `confirmed→posted`, but the authority column and fiscal-completion rule are global across all four fiscal chains. DN/RN seal on `draft→confirmed` (`SPEC-document-lifecycle-dimensions.md:59-60,215-220`; `DeliveryNoteService.php:156-166`; `ReturnNoteService.php:631-660`). The QR protocol says rejection leaves the document `confirmed` and retry later calls `transitionWithFiscalSeal()` (`SPEC-document-lifecycle-dimensions.md:111-120`), while self-loops are forbidden (`SPEC-document-lifecycle-dimensions.md:31-35`). Consequence: if TN authority applies to DN/RN, rejection strands an unsealed Confirmed document with no legal retry edge; if it does not apply, the current global initializer can leave them `pending` and then freeze that incomplete state on sealing. Required change: add a normative `DocumentType × fiscal_authority_mode × sealing edge` matrix. Either mark DN/RN `not_required` by seeded policy or define a draft-preserving authority-attempt protocol for them. Add accepted/rejected/retry tests for Invoice, CreditNote, DeliveryNote and ReturnNote separately.

**F-109 · Critical · area B/E/I** — An open authority request does not freeze the payload submitted to the Ministry. Phase A commits the request, releases the row lock, and Phase C merely detects a changed digest after the external authority may already have accepted it (`SPEC-document-lifecycle-dimensions.md:111-120`). Confirmed documents remain editable and `confirmed→draft` remains legal (`DocumentStatus.php:19-24`; `SPEC-document-lifecycle-dimensions.md:244-245`). Consequence: an accepted legal artifact can exist externally while AutoERP refuses to seal the now-modified row, after which retrying risks issuing a second legal artifact. Required change: state: “An open authority attempt is a downstream blocking fact: it freezes every hash/authority-payload field and refuses `confirmed→draft`, cancellation and line mutation until a response is appended.” Define an accepted-digest-mismatch quarantine/recovery path. Add `OpenAuthorityAttemptFreezesDocumentTest` and `AcceptedPayloadDigestMismatchQuarantinesTest`.

**F-110 · Critical · area B/F/I** — F-2 unblocks posting before lifecycle-independent chain-head selection lands. The critical path includes only C-F1a’s advisory lock/UNIQUE work before F-2 (`PLAN-phase2-3-P-R.md:14-15,35-37`); the corrected membership predicate is deferred to C-F1c after unrelated purchase lanes (`PLAN-phase2-3-P-R.md:71-73`). Today the chain head filters `status=Posted` (`DocumentPostingService.php:462-469`), while VOIDED/cancelled and legacy Paid chain members retain their sequence/hash. Consequence: after UNIQUE installation, a valid paid/voided tail makes the next QR-accepted post reuse an occupied sequence and fail; without the constraint it would fork. Required change: move the lifecycle-independent head predicate `(company,type,hash not null,sequence not null)` into a new critical-path lane before F-2. `ChainHeadAfterVoidTest` and a legacy-Paid-tail test must gate F-2, not wait for C-F1c.

**F-111 · Critical · area A/E/F** — C-RET is compile-ordered but not deployment-safe. It migrates both legacy values, tightens the CHECK and removes both PHP cases in one lane/release (`SPEC-document-lifecycle-dimensions.md:21-27`; `PLAN-phase2-3-P-R.md:81`). `Document.status` is an Eloquent enum cast (`Document.php:171-178`), and existing rows currently contain cases `Paid` and `Received` (`DocumentStatus.php:7-14`). Consequence: during fleet/tenant migration, new application code without those cases can hydrate an unmigrated row and throw before that tenant’s migration finishes. Required change: split retirement into two deployments: C-RET1 retains both cases, migrates every tenant and tightens the CHECK after a zero-residual fleet census; C-RET2 removes the cases and regenerates TypeScript only after fleet completion is proven. Historical event strings remain untouched.

**F-112 · High · area B/G/I** — C-QR0 does not define seeded authority policy for every supported country or missing-row behavior. It names only a TN `required` seed (`SPEC-document-lifecycle-dimensions.md:104-110`; `PLAN-phase2-3-P-R.md:33`). The existing settings family seeds only enumerated countries and currently falls back to code when a row is absent (`CountryDocumentSettingsSeeder.php:38-52`; `PreDeliveryInvoicingPolicyResolver.php:89-95`). Consequence: implementations can give FR/generic/unknown countries a DDL or enum default, violating “policy seeded per country, nothing hardcoded.” Required change: C-QR0 must define explicit rows for every supported country, make an absent row a typed `COUNTRY_DOCUMENT_SETTINGS_NOT_SEEDED` refusal immediately, and prohibit a database default from deciding `fiscal_authority_mode`. Add missing-row and non-TN seed tests.

**F-113 · High · area A/B** — POS account-charge adoption still equates an internal POS seal with Ministry acceptance. The spec says the derived Invoice’s fiscal authority is the POS receipt’s seal (`SPEC-document-lifecycle-dimensions.md:57`), but current POS fiscalization only computes a local hash and writes `fiscalized` (`ReceiptFinalizationService.php:91-119`). Its QR is an optional tenant-key lookup token and may be omitted entirely (`ReceiptQrTokenIssuanceService.php:11-19,27-46`), not evidence of Ministry acceptance. Consequence: a TN derived Invoice can be considered fiscally complete without the authority fact required by the owner mandate. Required change: C-POS0 may adopt the POS event/JE only when the source receipt carries durable external-authority evidence compatible with C-QR0/F-2; otherwise quarantine/block document posting. Add `PosAccountChargeAuthorityEvidenceTest`.

**F-114 · High · area B/I** — The fiscal-chain conversion omits the live backfill command. C-F1c names only head reads and verification (`PLAN-phase2-3-P-R.md:73`), but `BackfillFiscalHashesCommand` remains limited to Invoice/CreditNote and `status=Posted` (`BackfillFiscalHashesCommand.php:55-72,122-133`). Consequence: an operator can run a repository-supported command after rollout and obtain lifecycle-filtered, two-chain behavior inconsistent with the new four-chain model. Required change: C-F1c must adapt or explicitly retire this command, use the shared membership/sealing-status policy, and add `BackfillFiscalHashesAllTypesTest`.

**F-115 · High · area A/C/F/I** — Expense and Income advertise `posted→cancelled` without a specified reversal. The applicability matrix gives both a cancelled state (`SPEC-document-lifecycle-dimensions.md:63`), and the edge table promises “full reversal (§7)” (`SPEC-document-lifecycle-dimensions.md:240-247`), but §7 defines only SupplierInvoice receipt/GR-IR reversal (`SPEC-document-lifecycle-dimensions.md:263-277`). Current Expense reversal is linked-cost-only (`ExpenseService.php:818-826`); ordinary Expense and Income posting can move GL and repository cash (`ExpenseService.php:309-359`; `IncomeService.php:145-188`) with no general cancellation path. Consequence: implementers can flip lifecycle to Cancelled while leaving GL and cash movements live. Required change: add explicit Expense and Income cancellation rows defining reversal document, JE, repository movement, metadata, idempotency and period behavior—or remove their `posted→cancelled` edge. Add paid/unpaid Expense and received/unreceived Income cancellation tests.

**F-116 · High · area A/C/I** — SalesOrder can become `payment_status=not_applicable` and close while a prepayment residue is still attached. The spec says partial transfers leave residue on the order, but all-lines-invoiced forces payment to `not_applicable` (`SPEC-document-lifecycle-dimensions.md:154-162`). The current converter demonstrates why residue is real: it moves every allocation wholesale to the first invoice and clears 419 immediately (`SalesOrderToInvoiceConverter.php:529-588`), which C-SO0 must replace. Consequence: after a discounted/final partial invoice, remaining customer money can disappear from the order’s payment and closure views without becoming an explicit partner advance. Required change: “On final conversion, any untransferred residue is detached from the order into a partner-level advance; the order becomes `not_applicable` only when no live allocation remains attached.” Add a final-invoice-under-order-total test.

**F-117 · Medium · area E/I** — Enforcement still overclaims what CHECK plus census proves. The spec says raw SQL and unresolved enum writes are “covered” by the DB CHECK and `DocumentStatusRawWriterCensusTest` (`SPEC-document-lifecycle-dimensions.md:250-256,327-334`). The Phase-1 rule correctly documents that a valid-domain raw `status='paid'` satisfies the CHECK and bypasses adjacency, and that dynamic arrays/`setAttribute`/`DB::table` remain outside the rule (`fix/campaign-n6-payment-advance:apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:48-66`). Consequence: a green CHECK/census can be misread as database enforcement of the single path. Required change: replace “covered” with “bounded but not enforced”; state that CHECK proves values only. Give the census an explicit syntax fixture matrix for assignment, update/fill/forceFill, setAttribute, builder, raw query and DB facade forms.

**F-118 · Medium · area F/I** — The critical-path lanes still violate their own one-day/one-invariant limit, and the new dangerous seams lack named tests. The plan mandates ≤1 implementer-day and serial hotspot work (`PLAN-phase2-3-P-R.md:3-7`), but C-L0 combines schema, transaction ownership, audit logging, typed fiscal post-images and four sealing paths (`PLAN-phase2-3-P-R.md:34`); F-2 combines external orchestration, runtime state transitions, two backfills and quarantine (`PLAN-phase2-3-P-R.md:37`). Missing named claims include authority applicability per chain type, open-attempt mutation freeze, AP/native classifier precedence, lifecycle-independent head before F-2, zero-downtime C-RET, POS Ministry evidence, backfill-command parity, Expense/Income cancellation and final SalesOrder residue. Required change: split C-L0 and F-2 as resequenced below and add those exact tests before dispatch.

## r1..r6 findings disposition

### r1 — F-1..F-20

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-1 | RESOLVED | F-11 | PARTIAL |
| F-2 | PARTIAL | F-12 | RESOLVED |
| F-3 | RESOLVED | F-13 | RESOLVED |
| F-4 | PARTIAL | F-14 | PARTIAL |
| F-5 | RESOLVED | F-15 | RESOLVED |
| F-6 | RESOLVED | F-16 | RESOLVED |
| F-7 | RESOLVED | F-17 | PARTIAL |
| F-8 | PARTIAL | F-18 | PARTIAL |
| F-9 | RESOLVED | F-19 | RESOLVED |
| F-10 | RESOLVED | F-20 | PARTIAL |

### r2 — F-21..F-40

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-21 | RESOLVED | F-31 | RESOLVED |
| F-22 | RESOLVED | F-32 | RESOLVED |
| F-23 | PARTIAL | F-33 | RESOLVED |
| F-24 | RESOLVED | F-34 | RESOLVED |
| F-25 | RESOLVED | F-35 | PARTIAL |
| F-26 | RESOLVED | F-36 | RESOLVED |
| F-27 | RESOLVED | F-37 | RESOLVED |
| F-28 | RESOLVED | F-38 | PARTIAL |
| F-29 | RESOLVED | F-39 | RESOLVED |
| F-30 | RESOLVED | F-40 | PARTIAL |

### r3 — F-41..F-58

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-41 | PARTIAL | F-50 | PARTIAL |
| F-42 | PARTIAL | F-51 | RESOLVED |
| F-43 | RESOLVED | F-52 | RESOLVED |
| F-44 | RESOLVED | F-53 | RESOLVED |
| F-45 | RESOLVED | F-54 | RESOLVED |
| F-46 | RESOLVED | F-55 | PARTIAL |
| F-47 | RESOLVED | F-56 | RESOLVED |
| F-48 | RESOLVED | F-57 | RESOLVED |
| F-49 | PARTIAL | F-58 | RESOLVED |

### r4 — F-59..F-75

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-59 | PARTIAL | F-68 | RESOLVED |
| F-60 | RESOLVED | F-69 | RESOLVED |
| F-61 | PARTIAL | F-70 | RESOLVED |
| F-62 | PARTIAL | F-71 | RESOLVED |
| F-63 | RESOLVED | F-72 | RESOLVED |
| F-64 | RESOLVED | F-73 | RESOLVED |
| F-65 | RESOLVED | F-74 | PARTIAL |
| F-66 | RESOLVED | F-75 | PARTIAL |
| F-67 | RESOLVED |  |  |

### r5 — F-76..F-87

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-76 | RESOLVED | F-82 | RESOLVED |
| F-77 | PARTIAL | F-83 | RESOLVED |
| F-78 | RESOLVED | F-84 | RESOLVED |
| F-79 | PARTIAL | F-85 | PARTIAL |
| F-80 | RESOLVED | F-86 | PARTIAL |
| F-81 | RESOLVED | F-87 | RESOLVED |

### r6 — F-88..F-106

| Finding | Status | Finding | Status |
|---|---|---|---|
| F-88 | PARTIAL | F-98 | RESOLVED |
| F-89 | PARTIAL | F-99 | RESOLVED |
| F-90 | PARTIAL | F-100 | RESOLVED |
| F-91 | RESOLVED | F-101 | RESOLVED |
| F-92 | PARTIAL | F-102 | RESOLVED |
| F-93 | RESOLVED | F-103 | RESOLVED |
| F-94 | PARTIAL | F-104 | RESOLVED |
| F-95 | RESOLVED | F-105 | RESOLVED |
| F-96 | PARTIAL | F-106 | RESOLVED |
| F-97 | RESOLVED |  |  |

## OQ dispositions table

### Legacy OQ-1..11 and R-OQ-1..12

| Question | Recommended default | Disposition | Reason |
|---|---|---|---|
| OQ-1 | Durable SupplierInvoice Confirmed state | ACCEPT | Required for a real SI-advance target and one type-independent adjacency map. |
| OQ-2 | Census supplier-payment typing only | REJECT — persist side + operation | A census does not govern cash direction or reversal. |
| OQ-3 | Defer wrong PO-prepayment GL | REJECT — immediate 422 | Keep PO allocation refused until a correct 409 path exists. |
| OQ-4 | Retire lifecycle Received | ACCEPT | Route all writers/readers first and retire in a staged fleet migration. |
| OQ-5 | Keep unposted SI payment 422 until P-2 | ACCEPT | Correct fail-closed behavior. |
| OQ-6 | Build minimal SCN creation path | ACCEPT | Minimum includes author, confirm, post, apply, cancel-unapplied, exact reversal and residue. |
| OQ-7 | Leave FE match vocabulary separate | REJECT — prerequisite | It must land before SI Confirmed UI exposure. |
| OQ-8 | Add a parallel country policy layer | REJECT — one inherited authority | Country defaults must feed the existing procurement-policy vocabulary. |
| OQ-9 | Widen non-fiscal immutability | ACCEPT | Requires backfill/quarantine, lines/deletes and protection after cancellation. |
| OQ-10 | Retire `getPaymentStatus()` | ACCEPT | Only after all callers use the cache authority. |
| OQ-11 | Remove `in_payment` | NEEDS-OWNER | Retain `has_unreconciled_payments`; presentation is product policy. |
| R-OQ-1 | Treat CN lifecycle Paid as defect | ACCEPT | CN settlement is outward payment state. |
| R-OQ-2 | CN cash refund in R-3 | ACCEPT | Must share the ex-stamp lock/cap and customer-refund direction. |
| R-OQ-3 | Standalone CN application in R-2 | ACCEPT | Must use the same settlement service. |
| R-OQ-4 | Refuse applied-CN cancellation | ACCEPT | Safest without immutable compensating allocation events. |
| R-OQ-5 | Cache return on direct source line | REJECT — canonical root aggregate | Invoice/DN unions require root-level recomputation. |
| R-OQ-6 | RN reversal document | ACCEPT | Preserves the original fiscal and stock events. |
| R-OQ-7 | RN seals at Confirmed | ACCEPT | Subject to the missing authority-applicability matrix in F-108. |
| R-OQ-8 | Delete/revive ReturnNote metadata | NEEDS-OWNER | Refund disposition and live FE fields are product scope. |
| R-OQ-9 | Correct CN VAT declaration | NEEDS-OWNER | Merge only after tenant/period deltas are acknowledged. |
| R-OQ-10 | Net dashboard revenue | NEEDS-OWNER | It changes an owner-visible metric. |
| R-OQ-11 | Historical rows take internal Confirmed hop | ACCEPT | No false event; classify from `opening_side`. |
| R-OQ-12 | Add no B2B return policy | ACCEPT | Do not invent policy; future restrictions must be country-seeded. |

### Current owner sheet OQ-11..52

| Question | Disposition | Reason |
|---|---|---|
| OQ-11 | NEEDS-OWNER | DTO reconciliation fact is safe; status presentation remains product policy. |
| OQ-12 | ACCEPT | Reserved identifier is harmless. |
| OQ-13 | ACCEPT | Supplier returns should reduce net PO fulfilment. |
| OQ-14 | ACCEPT | VAT-free proforma follows the adopted Art. 18 ruling. |
| OQ-15 | ACCEPT | Refusal until authority acceptance is binding. |
| OQ-16 | ACCEPT | Existing immutable event may retain “fully settled” semantics. |
| OQ-17 | ACCEPT | Provisional jurisdiction must block before overrides. |
| OQ-18 | NEEDS-OWNER | Leave metadata untouched pending refund-disposition scope. |
| R-OQ-9 | NEEDS-OWNER | Delta acknowledgement is mandatory. |
| OQ-19 | NEEDS-OWNER | Net revenue changes a daily metric. |
| OQ-20 | ACCEPT | Current table is aligned to OQ-28. |
| OQ-21 | ACCEPT | Returned may satisfy fulfilment only under the per-tuple rule. |
| OQ-22 | ACCEPT | Cancellation permanently closes with reason `cancelled`. |
| OQ-23 | ACCEPT | Ex-stamp settlement matches the production application basis. |
| OQ-24 | ACCEPT | Default-equivalent rows may become inherited. |
| OQ-25 | ACCEPT | Preserve Expense/Income metadata settlement. |
| OQ-26 | ACCEPT | FR `allow` remains unavailable without an approved mapping. |
| OQ-27 | NEEDS-OWNER | P-4 periods and deltas require explicit acknowledgement. |
| OQ-28 | ACCEPT | RN closes at confirmation independently of refund disposition. |
| OQ-29 | ACCEPT | No country activates `allow` by default. |
| OQ-30 | ACCEPT | `opening_side` is safer than rewriting historical types. |
| OQ-31 | ACCEPT | DN closure requires every physical tuple returned. |
| OQ-32 | ACCEPT | Scheduled expiry supplies a durable fact. |
| OQ-33 | ACCEPT | Receipt-line projection can prove fulfilment; it does not prove Ministry acceptance. |
| OQ-34 | ACCEPT | Retained supplier credit is the safest residue disposition. |
| OQ-35 | ACCEPT | Original VAT, every original JE period and reversal period must be open. |
| OQ-36 | ACCEPT | Quarantine plus bank-preserving 411→419 reclassification is correct. |
| OQ-37 | ACCEPT | Historical credits remain non-settleable. |
| OQ-38 | ACCEPT | Refusal stands; no seal-only TN window. |
| OQ-39 | ACCEPT | Quarantine only the affected chain. |
| OQ-40 | ACCEPT | New attempt against the unchained payload, but an open attempt must freeze it. |
| OQ-41 | REJECT — base remains live until residue is detached | `not_applicable` is unsafe while money remains attached to the order. |
| OQ-42 | ACCEPT | Every tuple must first be delivered, then returned. |
| OQ-43 | ACCEPT | Refuse SI cancellation until children are explicitly reversed. |
| OQ-44 | ACCEPT | Nullable company override is acceptable outside provisional jurisdictions. |
| OQ-45 | ACCEPT | Quarantine must block posting and closure. |
| OQ-46 | ACCEPT | Adopt the existing POS event/JE, but only with durable authority evidence where required. |
| OQ-47 | ACCEPT | Durable SI confirmation is required for SI-targeted advances. |
| OQ-48 | REJECT — FIFO capped plus final residue re-homing | FIFO is sound, but the final residue must become a partner advance before the order becomes n/a. |
| OQ-49 | ACCEPT | Quarantine unproven legacy TN Posted rows without changing lifecycle. |
| OQ-50 | ACCEPT | Product-wide capacity is correct absent durable location allocation. |
| OQ-51 | ACCEPT | Replacement linkage and copied supplier reference are required audit facts. |
| OQ-52 | ACCEPT | Provisional is an absolute jurisdiction gate. |

## Missed owner questions

- Does Ministry authority apply to all four document chains, or only Invoice/CreditNote? If it applies to DN/RN, what is the rejection/retry lifecycle before `draft→confirmed` sealing?
- Which supported countries explicitly seed `fiscal_authority_mode=not_required`, and must a missing country row always refuse?
- What durable evidence on a POS receipt proves Ministry acceptance? The current optional tenant-signed QR is not that evidence.
- After the final partial invoice, must untransferred SalesOrder prepayment residue become a partner-level advance before the order closes?
- If an authority accepts a payload but the local digest no longer matches, is the document quarantined permanently or replaced by a corrective document?
- May ordinary Expense and Income be cancelled, and if so must their GL and repository movements be reversed by new documents?

## Lane resequencing

1. Merge N-6.
2. Split C-0a:

   - C-0a0: exhaustive default-refusal for currently provable native shapes; historical/POS explicitly refused.
   - C-PROV0: add/backfill `opening_side` and `document_provenance`.
   - C-0a1: admit AR/AP/POS provenance with mutually exclusive rules.

3. Run C-F0.
4. Rewrite C-QR0 to include the complete country/type authority matrix, typed missing-row refusal and explicit seed coverage.
5. Split C-L0:

   - C-L0a: one migration for `posted_at`, `sealed_at` and transition log.
   - C-L0b: locked transition/post-image core.
   - C-L0c: adapt Invoice/CN/DN/RN sealers and freeze open authority attempts.

6. Run C-F1a, then add C-F1h for lifecycle-independent chain-head selection before F-2.
7. Run C-3a1, then F-2. F-2 must test open-attempt freezing and digest-mismatch recovery.
8. Keep verifier/seal-time backfill work independent, but include `BackfillFiscalHashesCommand` parity.
9. Split C-RET into C-RET1 fleet data/CHECK migration and C-RET2 enum/TypeScript removal after fleet proof.
10. Add explicit Expense/Income cancellation or remove their cancellation edges.
11. Extend C-SO0 with final residue re-homing before `not_applicable`/closure.

## Claims verified TRUE in the research sweeps that the spec relies on and claims found FALSE/stale

Verified TRUE against the current tree:

- SupplierInvoice still posts directly to Posted and persists `balance_due=total` plus match status in one save (`SupplierInvoicePostingService.php:286-292`).
- Full gross PO receipt still writes lifecycle Received; partial receipt preserves the prior status (`GoodsReceiptService.php:723-733`).
- Both PG balance functions subtract payment allocations and sales credit-note applications (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-48`; `2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:37-70`).
- Current document chain head and verifier remain lifecycle-Posted filters; the verifier still covers only Invoice/CreditNote and substitutes `document_date` (`DocumentPostingService.php:462-504`; `VerifyFiscalChainsCommand.php:252-315`).
- Return quantities are netted across an Invoice and its backing DNs per actual product/location (`DeliveredQuantityResolver.php:479-540`).
- POS account charge already creates 411, revenue and VAT before its derived Invoice posts (`GeneralLedgerService.php:4077-4166`).
- Current SalesOrder conversion transfers every prepayment allocation wholesale and clears 419 at conversion (`SalesOrderToInvoiceConverter.php:529-588`).
- Ordinary Expense/Income posting moves lifecycle, GL and potentially repository cash directly (`ExpenseService.php:309-359`; `IncomeService.php:145-188`).

FALSE or stale:

- The purchase sweep’s statement that Phase-1 categorically forbids SupplierInvoice Draft→Posted is stale against the inspected N-6 branch: it now has a type-aware direct-post guard (`fix/campaign-n6-payment-advance:DocumentStatusMachine.php:72-105`).
- “C-0a can test AR/AP/POS provenance immediately after N-6” is false; those persisted provenance columns are scheduled later.
- “F-2 has the corrected chain head before it unblocks posting” is false; that predicate is assigned to later C-F1c.
- “POS receipt seal/QR proves Ministry acceptance” is false; current QR is an optional tenant-key lookup token (`ReceiptQrTokenIssuanceService.php:11-46`).
- “One C-RET deployment is a safe versioned retirement” is false because the live Eloquent enum cast must read unmigrated tenant rows during rollout.
- “All status bypasses are covered by the DB CHECK” is false; the CHECK enforces values, not adjacency or service ownership.
- “Expense/Income full reversal is defined in §7” is false; §7 is SupplierInvoice-specific.
- “Every fully invoiced SalesOrder can become payment n/a” is incomplete while an attached prepayment residue can remain.

Critical-path slice: CHANGES-REQUESTED

Whole program: CHANGES-REQUESTED