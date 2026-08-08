# Round-2 rulings record (SHARED ARTIFACT — plan v4 Phase R)

Contract: a lane blocked on a ruling dispatches ONLY when its row here is ANSWERED with the
verbatim answer + selected branch. Every branch has a named executable consumer.

## R-a — 0%-deductible declared base — ✅ ANSWERED 2026-08-07 (expert-comptable)
Verbatim + consequences: `2026-08-06-q2-gate-minor-followups.md` §I-3. Branch selected:
EXCLUDE ENTIRELY. Consumer executed: R2-G lane, SHIPPED origin/dev `d8efb5df0`. CLOSED.

## R-b — N-9 timbre residue — ✅ ANSWERED 2026-08-08 (expert-comptable)
Verbatim + branch: `2026-08-08-expert-comptable-rulings-rb-c2-c3-stamp.md` §Q1. Branch (2)
dedicated dust account, 2a=PROSPECTIVE-only, 2b=TN-dedicated seeded 6588/7588 (NOT shared
account). Consumer: lane R2-M — dispatchable (seeded-settings directive applies).
Alternatives: (1) keep absorbing ≤tolerance dust in 4375 (status quo, documented) ·
(2) split dust to a rounding-difference account so 4375 carries only true stamp liability.
**Sub-decisions REQUIRED with branch 2 (round-3 finding):** (2a) cut-over: prospective-only
vs historical restatement (historical requires quantification FIRST per ticket §53-76);
(2b) account: shared SalesRoundingDifference vs TN-specific new account (seeder row).
Consumers: (1) → docblock + expert-sign-off line, no code. (2) → lane R2-M (GL/accounting +
release/data + treasury gates; after F2; migration-bearing iff 2a=historical or 2b=new
account).

## R-c — cancellation cluster — c1 + c4 ✅ ANSWERED BY OWNER 2026-08-07; c2 + c3 ⏳ OPEN (expert)

**c1 — ✅ OWNER RULING (verbatim substance):** stock and money are SEPARATE lanes with
guided intersections. In this ERP, invoices touch money, NOT stock — inventory moves only
via exit notes (delivery) and return notes. Therefore: **cancelling an invoice performs NO
automatic stock movement and NO automatic COGS/stock-lane reversal — NEITHER of the two
alternatives originally posed.** Instead the UI must GUIDE the user: on cancelling an
invoice for delivered products, prompt "create a return note?". No return note → no
inventory comes back (COGS stays — the goods are genuinely gone). Return note → its OWN
document lifecycle (opened/draft → confirmed), and its confirmation drives the stock
re-entry and its own GL, linked to the cancelled invoice.
**F2 contract:** (i) invoice-cancel GL reversal mirrors ONLY the invoice's own sealed legs
(revenue/VAT/AR — the L2 mechanism) — never synthesizes stock-lane legs; (ii) the "COGS
unreversed on cancel" ticket is RESOLVED as by-design when no return occurs; the deliverable
becomes the CANCEL-FLOW PROMPT (FE) + return-note creation pre-linked to the cancelled
invoice + the link surfaced on both documents; (iii) PREMISE VERIFICATION required first:
confirm in code WHERE COGS actually posts (exit-note/delivery leg vs invoice leg). If any
path books COGS at invoice-posting, reconcile it to this ruling (COGS belongs to the stock
lane) BEFORE building the prompt — that discrepancy, if found, is its own finding, not
silently absorbed.

**c4 — ✅ OWNER RULING: branch (a), strengthened.** "Everything needs to be documented":
corrections are DOCUMENTS, always. A correction requires creating a correcting document
LINKED to the original document it refers to (source_document_id). No free-floating manual
JEs as the correction mechanism. **F4 contract:** correcting-entry document type w/
mandatory link to the original; schema-bearing → release/data gate + R2-I harness apply.

**c1 PREMISE VERIFICATION (2026-08-07, code research w/ citations):**
- ✅ CONFIRMED for stock UNITS: invoice posting/cancel moves NO stock (DocumentPostingService
  posts/seals/reverses GL only); units move on delivery-note confirm (issueStock/recordSale),
  return-note confirm (receiveStockBack/recordReturn), goods receipt, POS projections.
- ✅ CONFIRMED return-note lifecycle: Draft → Confirmed only (confirm refuses non-draft; no
  cancel endpoint); linkage EXISTS: documents.source_document_id + return_note_metadata
  (source_delivery_note_id / source_invoice_id / linked_credit_note_id).
- ❌ CONTRADICTED at the GL layer: **COGS books at INVOICE posting** (InventoryServiceProvider:77
  → PostCOGSOnInvoice → GeneralLedgerService::createCOGSEntry, Dr COGS/Cr Inventory at
  current WAC; failures logged-never-blocking). Two defects vs the ruled lane model:
  **D1 — re-invoice double-COGS:** cancel books no COGS reversal (reverseDocumentGl mirrors
  source_type=DOCUMENT only; the COGS entry is separately sourced) → cancel-then-re-invoice
  books COGS TWICE for one delivery. Pre-existing, reachable via the normal fix-and-reissue
  flow. **D2 — return-note confirm writes NO GL:** units + WAC come back but GL inventory
  asset is never re-debited / COGS never credited → GL-vs-physical inventory divergence and
  overstated COGS on every confirmed sales return. Ticket:
  `2026-08-07-cogs-lane-mismatch.md`.
- **NEW EXPERT QUESTION c1-bis (blocks F2's GL half; the prompt/UX half is unblocked):** où
  doit être constaté le coût des ventes — à la sortie de stock (confirmation du BL, lane
  stock, cohérent avec le modèle voulu) ou à la facturation (état actuel) ? Et quelle
  écriture comptable à la confirmation d'un bon de retour (re-débit stock / crédit 607) ?
  La réponse détermine si on déplace le COGS vers le BL (migration comptable) ou si on le
  garde à la facture avec extourne à l'annulation + écriture au retour.
- ✅ c4 infrastructure CONFIRMED BETTER than feared: source_document_id already exists on
  documents (+ CN/refund/conversion precedents, DOCUMENT_CANCELLATION journal keying,
  reverses_*_id idempotency idiom) — the correcting-document type may need little or no new
  schema.

c2 purchase-doc cancel when period CLOSED/FILED — ✅ ANSWERED 2026-08-08 (expert):
REVERSE-IN-CURRENT-PERIOD (extourne, DMI régularisation box). Verbatim:
`2026-08-08-expert-comptable-rulings-rb-c2-c3-stamp.md` §Q3. Adoption GATED on F2's AP
mirror (reverseDocumentGl null for supplier docs today); F1's shipped refusal stays interim.
c3 declaration reconciliation — ✅ ANSWERED 2026-08-08 (expert): NET aggregation on the
declaration + DISTINCT correction lines via linked correcting document (never
delete/overwrite). Verbatim: same ticket §Q4. Consumer F3 (composes with c4/F4).
⏳ NEW OPEN: c1-bis (COGS at BL-confirm vs at invoicing; return-note GL entry) — blocks
F2's GL half; relayed to expert. See same ticket §Still-OPEN.

### Gate-derived relay notes, 2026-08-07 (R2-F1 taxation gate — inputs for the expert, NOT rulings)

Three findings from reading the shipped code, offered so the expert rules on facts
rather than on the lane's original framing. F1 has SHIPPED the default-refusal; none of
this changes what is merged.

- **(i) Recommend keeping the refusal UNIFORM across CLOSED and FILED.** A tempting
  middle branch — "permit cancel when CLOSED, refuse only when FILED, since CLOSED is
  reopenable" — is more expensive than it looks. `VatPeriodManagementService::closePeriod()`
  FREEZES the declaration into the period row (`total_output_vat`, `total_input_vat`,
  `net_vat`, `declaration_data`, and the `vat_period_breakdowns` rows). Cancelling a
  document inside a CLOSED period would leave that frozen snapshot stale with no
  invalidation anywhere. The sanctioned path already exists and un-freezes correctly:
  `reopenPeriod()` deletes the breakdowns and nulls the totals. So CLOSED-permits would
  require auto-invalidating the frozen snapshot on cancel — that is F3 work, not a flag
  flip. Recommend: keep both statuses refusing; direct users to reopen (CLOSED) or to a
  credit note (FILED).
- **(ii) c2's purchase arm has NO live workflow today.** There is no caller and no route
  that can cancel a supplier invoice, supplier credit note, expense or purchase order:
  the only HTTP cancel endpoints are `invoices.cancel` and `credit-notes.cancel` (both
  `RefundController`), and the only in-process callers of
  `DocumentPostingService::cancel()` are `RefundService` (invoice/credit-note only) and
  `SalesOrderService`. c2 is therefore FORWARD-LOOKING, not a bleeding workflow — it can
  be ruled unhurried, and F2 is the lane that will make it reachable.
- **(iii) Supplier-invoice input VAT is not in the declaration at all.**
  `EloquentVatDataRepository::aggregateByRateAndDirection()` (`:42`) restricts to
  `invoice`, `credit_note` and `expense`; `supplier_invoice` appears nowhere in the
  Taxation module. F1's original justification for refusing purchase docs ("the same
  filed declaration as the output VAT") was FACTUALLY WRONG and has been corrected
  in-code: the refusal now rests on AP / trial-balance integrity (a supplier invoice does
  carry a GL entry dated `document_date`, via
  `GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry()`) plus forward
  compatibility. If the expert's instinct was "input VAT symmetry", note that the symmetry
  does not exist yet.

**Constraint on any c2 = "reverse-in-current-period" answer:** it cannot be adopted until
F2's AP mirror is merged. `AccountingService::reverseDocumentGl()` returns `null` for any
type other than Invoice/CreditNote (`:833`) and supplier invoices take the non-fiscal
branch of `cancel()`, so permitting the cancel TODAY yields a withdrawal with no GL
reversal at all — the AP/GR-IR legs would stand forever. The refusal is currently the only
thing preventing that.

## R-d — multi-company launch posture (owner) — ⏳ OPEN
Disable-and-defer (API refusal + pinned test; A2/A3 deferred) vs keep-enabled (A2+A3
pre-launch). Sub-decisions iff keep-enabled: d1 identifier contract (company-inclusive
uniqueness vs company discriminator) → consumer A2; d2 mixed-currency contract
(refuse-mixed-aggregate vs per-row currency, family-wide) → consumer A3.
Disable branch consumer: small lane R2-Q0 (API-level refusal + test + UI hide).

## R-e — W-7 F-8 company-vs-user discount cap on web documents (owner) — ⏳ OPEN
Accept → 0.6 launch-sheet correction + rationale line here. Reject → **lane R2-Q**
(Wave 2; per-user cap resolution in document validation; treasury + tenancy-authz gates).

## R-f — remittance-without-draft UX (owner) — ⏳ OPEN
Accept-for-launch → risk-accepted line here + post-launch backlog entry. Fix-now →
**lane R2-R** (Wave 2; reviewable remit draft + mid-loop failure recovery; treasury + FE
gates).

## R-g — sealed-deposit-receipt recoverability (owner/product) — ⏳ OPEN
Context: preflights only NARROW the TOCTOU races (ticket §48-63) — orphans remain possible
and existing ones exist. Alternatives, each with consumer = R2-K's recoverability sublane
K-rec: (1) void-annotation register (fiscal event annotating the orphan, no chain rewrite) ·
(2) compensating fiscal event (device-visible reversal) · (3) manual-disposition register +
accountant list only. Mapping: (1)/(2) = K-rec implements the chosen event/register shape
(fiscal-pos + treasury gates; (2) touches device contract → device-release dependency
flagged); (3) = K-rec reduces to a detection query + runbook section.

## R-h — `fiscal:backfill` register-or-delete (owner) — ⏳ OPEN (pre-E-9)
Register → small lane wiring the command into the production CLI (fiscal-pos gate).
Delete → owner-sheet edit via 0.6 (Phase-E corrections) + line here. Either way BEFORE any
E-9 sheet executes.

## R-i — `credit-notes.confirm` permission policy (owner) — ⏳ OPEN (NEW, was implicit in
R2-L; round-3 finding)
Question: which roles may confirm credit notes, and is it a DISTINCT permission
(`credit-notes.confirm`) or does `documents.confirm` govern? Recommendation on file: distinct
permission, granted admin+accountant+manager (mirrors invoice-confirm precedent) — CONFIRM
OR OVERRIDE. Consumer: R2-L's R5 subtask aligns seeder+route+FE to the answer
(tenancy-authz gate). R2-L's OTHER subtasks do not wait.
