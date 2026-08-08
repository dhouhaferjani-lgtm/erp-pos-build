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

**Centerpiece: perpetual vs periodic inventory accounting (c1-bis).** The codebase is an
incoherent mix (COGS at B2B invoice posting only; POS sales relieve no GL inventory;
counting/shrinkage posts no GL; returns post no GL). Dispatch research subagents on:
- TN norms: NC 01/NC 04 inventory treatment, DGI practice for retail/pharmacy POS
  (inventaire permanent vs intermittent), what certified TN ERPs do.
- FR (coming soon): PCG stock-variation accounts (603x/713x), NF525 interplay.
- The expert-comptable's c1-bis answer (owner is relaying the question — verbatim in the
  rulings record §R-c). Research CHALLENGES or CONFIRMS it; disagreement goes back to the
  owner, not silently resolved.
- Recommendation shape: likely a SEEDED per-country/per-tenant accounting-mode setting
  (perpetual|periodic) per the owner's settings directive — but only if research supports
  operating both; a single well-chosen mode with country seeds is acceptable too.
The answer decides G1/G2/G3-family/V8-GL/return-note-GL in one stroke: perpetual ⇒ one
movement-driven GL listener collapses them; periodic ⇒ period-close variation entries and
most of G2 evaporates.

## 4. Owner decision queue (get these rulings; recommendations attached)

| # | Decision | Blocks | Recommendation |
|---|---|---|---|
| D1 | c1-bis / perpetual-vs-periodic (expert + research §3) | G1, G2, V8-GL, RN-GL | research first; lean perpetual-at-stock-exit (matches ruled lane model; POS covered for free) |
| D2 | F4 manual-JE correction shape: (a) declare-target vs (b) privileged force | V5 | **(a)** — literal embodiment of "corrections are documents, linked" |
| D3 | `voidReceipt` sunset? (already retired on v4-acknowledged terminals, zero FE callers) | V9 | **sunset** → V9 shrinks to endpoint retirement; else fix via server-authored VOID fiscal event |
| D4 | V6 import: (c) deprecate stock_levels type vs (b) cost_price fallback vs (a) new column | V6 | **(c)** — products import already does it right |
| D5 | V7 `adjust`: new lightweight `stock_adjustments` document (delta + observed-before) | V7 | yes — counting doc too heavy for one-shelf corrections |
| D6 | Return-note state naming for the modal ("open"=Draft, "closed"=Confirmed — no Closed state exists) | cancel-flow FE strings | confirm mapping with owner |
| D7 | V6 re-import semantics: refuse-on-second-run + reset affordance (enter-once guard) | V6 | accept; surface `ResetOpeningBalanceService` |

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
Wave 3 (after D1/c1-bis + research):
  G1 (+G2 + V8-GL + RN-GL as one design)         L   inv-costing + fiscal-pos
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
