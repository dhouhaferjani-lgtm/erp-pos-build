# Handover — Document-per-Action Remediation Track (dedicated session)

**Date:** 2026-08-08 · **For:** a dedicated orchestrator session (owner ruling: this track
is CRITICAL, runs FIRST, in parallel with other fix work, and must be done correctly with
research subagents for the decisions).

## 1. Mission

Fix every violation of the document-per-action governance principle, and cement the
principle so it cannot regrow. The principle (owner, binding): every economic mutation —
stock, GL, cash, fiscal — carries its OWN justifying document with its own lifecycle,
linked to any originating document. Stock and money are separate lanes with guided,
explicit-never-silent, one-go-completing UI intersections.

## 2. Canonical inputs (read in this order)

1. `docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md` — the
   verified violation register (V1-V10, G1-G3) + compliant-idiom catalogue + guard proposal.
2. `docs/superpowers/audits/2026-08-08-dpa-fix-sizing-gl-treasury.md` and
   `…-dpa-fix-sizing-stock-pos.md` — per-item sizing cards with fix shapes, blast radius,
   risks, dependency waves. **The risk notes are load-bearing** (V4 double-AR branch, G3
   double-count, V8 WAC dilution, V10 dual scrap semantics, V9 sunset question).
3. `docs/superpowers/specs/2026-08-08-cancel-flow-guided-return-ux-ruling.md` — owner UX
   contract (binding for the cancel-flow lane).
4. `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md` §R-c (rulings c1/c4 +
   c1-bis open question) and `2026-08-07-cogs-lane-mismatch.md` (D1/D2).
5. Standing directives: `2026-08-08-expert-comptable-rulings-rb-c2-c3-stamp.md` §directive
   (country accounting = SEEDED SETTINGS, never hardcoded — TN now, FR soon).

## 3. The research mandate (do this BEFORE dispatching Wave-3 code)

**D1 IS ANSWERED (expert-comptable via owner, 2026-08-08 — verbatim in
`2026-08-08-expert-comptable-rulings-rb-c2-c3-stamp.md` §c1-bis): PERPETUAL inventory as
the core model (NCT 04 + IFRS), parameterized at COMPANY level (Company Code / Valuation
Area, country-defaulted seed), with a per-category "Intermittent/Expense-based" option for
secondary consumables (office supplies, low-value parts) that bypasses the main stock lane.**

The research mandate is therefore RE-SCOPED from "which model" to implementation
validation + design. Dispatch research subagents on:
- **COGS-at-stock-exit listener design:** trigger on stock-exit movements
  (delivery-note confirm, POS receipt projection, write-off) so G1 + G2 + V10-GL + the
  return-note re-debit collapse into ONE movement-driven GL seam. Validate against the
  existing WAC/cost-lock machinery and the log-never-block posture (add a DETECTOR for
  docs/receipts with physical lines and no COGS entry — COGS is already
  non-deterministically missing today, see sizing).
- **The valuation-mode parameter:** company-level seeded setting (perpetual default for TN
  and FR seeds); decide whether periodic mode is BUILT now or merely not-blocked (schema/
  enum reserved, refusal elsewhere). Recommendation: not-blocked only — no current tenant
  needs periodic.
- **Per-category expense-based override:** model shape (product-category flag), guard that
  it never applies to fiscal/stock-tracked main catalog, TN/FR compliance check.
- **D5 supplement (owner-requested):** short research pass on adjustment-document naming +
  states — survey how major ERPs (SAP material documents, Odoo inventory adjustments,
  Dynamics journals) model lightweight stock-correction documents and their state machines;
  reconcile with the existing `StockAdjustmentService` (a WRITER service, not a document)
  and the house `DocumentStatus` set before freezing the `stock_adjustments` schema.
- **Migration/backfill question:** what happens to already-posted invoices' COGS entries
  when the trigger moves to stock-exit (pre-launch: likely nothing to migrate for tenant
  #1, but the D1 double-COGS window must close atomically with the relocation).
Research CHALLENGES or CONFIRMS the expert's answer against code reality; disagreement
goes back to the owner, not silently resolved.

## 4. Owner decision queue (get these rulings; recommendations attached)

| # | Decision | Blocks | Status (owner responses 2026-08-08) |
|---|---|---|---|
| D1 | inventory accounting model | G1, G2, V8-GL, RN-GL | ✅ **ANSWERED**: perpetual core, company/country-parameterized, per-category expense-based consumables option (see §3) |
| D2 | F4 manual-JE correction shape: (a) declare-target vs (b) privileged force | V5 | ✅ **RATIFIED (a)** — corrections are documents, linked |
| D3 | `voidReceipt` sunset? (already retired on v4-acknowledged terminals, zero FE callers) | V9 | ✅ **RATIFIED: sunset** → V9 shrinks to endpoint retirement + guard; verify no residual consumer before removal |
| D4 | V6 import: (c) deprecate stock_levels type vs (b) cost_price fallback vs (a) new column | V6 | **(c) provisionally** — the UI card literally advertises "Import opening stock quantities", i.e. the compliant path's exact purpose; owner briefed, final confirm owed |
| D5 | V7 `adjust`: new lightweight `stock_adjustments` DOCUMENT (delta + observed-before) | V7 | ✅ **RATIFIED, with owner-requested research supplement** (§3, D5 bullet: document naming + state-machine best practices BEFORE schema freeze). Note: `StockAdjustmentService` is a writer SERVICE, not a document — the document is new |
| D6 | Return-note state naming for the modal ("open"=Draft, "closed"=Confirmed — no Closed state exists) | cancel-flow FE strings | ✅ **RATIFIED** mapping |
| D7 | V6 re-import semantics: refuse-on-second-run + reset affordance (enter-once guard) | V6 | ✅ **RATIFIED** |

## 5. Consolidated dispatch plan

```
Wave 0 (immediately, parallel):
  V1  delete GL-deleting command                 S   fiscal-pos
  S0  recordMovement reference_type/id seam      S   inventory-costing   ← prereq V7/V8/V10 + guard
Wave 1 (parallel, no blockers):
  V2  opening-balance import → batch document    M   fiscal
  V3  repository_adjustments document            M   treasury
  V4  payment reversal document                  L   treasury            ← longest pole, start day 1
  V10 SCRAP write-off → BatchWriteOff pattern    M   fiscal-pos + inv-costing
  CF  cancel-flow UX lane (modal + orchestration
      + CreateReturnNotePage contract repair
      + dated-confirm period guard)              L   frontend-conv + fiscal-pos ← start early
  V9  (after D3 ruling)                          M/S fiscal-pos
Wave 2 (after V3 / S0 / rulings):
  G3  shift-variance GL via V3 document          M   fiscal-pos + treasury
  V7  stock-writer containment (after D5)        L   inv-costing + frontend-conv
  V8  supplier goods-return note (units half)    M   inv-costing
  V5  manual-JE correction linkage (after D2)    M-L fiscal-pos
Wave 3 (after the §3 research validation pass — D1 itself is ANSWERED):
  G1 (+G2 + V8-GL + RN-GL as ONE movement-driven design, per perpetual ruling)
                                                 L   inv-costing + fiscal-pos
  + valuation-mode company setting (seeded, perpetual default) + consumables category flag
Cement (last): architecture guard — forbid JournalEntry::create / StockMovement::create /
  raw StockLevel writes outside document-keyed services with non-self source linkage
  (PHPStan rule or deptrac layer + pinned test). Register §7.
```

**Totals:** ≈25–30 dev-days engineering; wall-clock ≈3–4 weeks at 3–4 parallel lanes
including adversarial-gate rounds (+40% on fiscal lanes historically). No POS device
release needed anywhere (verify V9's server-VOID chain acceptance if D3 = keep).

## 6. Discipline (unchanged house rules)

- One worktree per lane off local `dev`; merge to LOCAL dev; promotion to origin/dev only
  by the main orchestrator in verified fast-forward batches (origin push auto-deploys
  staging incl. tenants:migrate).
- Adversarial gate per lane per the plan table (fiscal-pos / treasury / inventory-costing /
  frontend-conventions reviewers); spec+plan reviewed BEFORE implementation for L-sized
  lanes; every milestone gated. TDD; never run the full PHPUnit suite locally.
- Seeded-settings directive applies to every account/purpose introduced (3 country
  seeders + backfill pattern: `BackfillTolerancePurposesCommand`).
- Findings outside scope → tickets, not scope creep (already flagged: `TestTaxRecoverability`
  same genre as V1; `DepositAllocationSummaryService` semantics under V4; per-source-type
  `MovementSourceType::AcquirerFee` split under V3).

## 7. Interaction with the rest of the program

- Owner priority 2026-08-08: this track FIRST, parallel to other fixes. The launch program
  (first-tenant) continues in the main orchestrator session; surfaces overlap on POS/
  treasury — coordinate merges through the main orchestrator (single promotion authority).
- The expert rulings R-b/c2/c3 lanes (R2-M timbre dust, F2/F3 extourne + net declaration,
  CN-stamp 6654) are SEPARATE lanes owned by the main session — do not absorb them here,
  but V5/F4 and the correcting-document type are shared ground: the F4 correcting-document
  lane should be built ONCE, consumed by both tracks (coordinate).
- Status reporting: hand back per-wave summaries + gate records to the main orchestrator;
  memory updates go through the main session.
