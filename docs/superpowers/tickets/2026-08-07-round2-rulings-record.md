# Round-2 rulings record (SHARED ARTIFACT — plan v4 Phase R)

Contract: a lane blocked on a ruling dispatches ONLY when its row here is ANSWERED with the
verbatim answer + selected branch. Every branch has a named executable consumer.

## R-a — 0%-deductible declared base — ✅ ANSWERED 2026-08-07 (expert-comptable)
Verbatim + consequences: `2026-08-06-q2-gate-minor-followups.md` §I-3. Branch selected:
EXCLUDE ENTIRELY. Consumer executed: R2-G lane, SHIPPED origin/dev `d8efb5df0`. CLOSED.

## R-b — N-9 timbre residue — ⏳ OPEN (expert-comptable)
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

c2 purchase-doc cancel when period CLOSED/FILED: reverse-in-current vs refuse-cancel →
consumer F1 (F1 ships default-refusal FIRST, explicitly reversible). ⏳ expert.
c3 declaration reconciliation: reversal-aware aggregation vs correction-row emission →
consumer F3. ⏳ expert.

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
