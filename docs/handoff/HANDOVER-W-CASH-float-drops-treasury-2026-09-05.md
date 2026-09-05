# Lane brief — W-CASH: opening float, drops and drawer→safe→bank in Treasury (RD4, ruled CRITICAL)

Date: 2026-09-05. Orchestrator: Claude Fable 5.1 (gates, merges, promotes). Implementer: Codex (owner dispatches via Codex Desktop). Base: local `dev` at dispatch time (≥ `e3ca1ba67`). Worktree: `apps/erp/.worktrees/w-cash`, branch `lane/w-cash-float-drops`. PG: private container ≥ 5453, test DB `autoerp_test_wcash`. Never `git stash`. No merges, no pushes; end with `docs/handoff/HANDBACK-W-CASH-<date>.md`. Independent of W-LOT; can run in parallel.

**Authority:** owner ruling RD4 (2026-09-05): Treasury must book opening float, cash in/out (drops) and drawer→safe→bank transfers so repository balances are real and the drawer reconciles to the Z report. Spec v4 §W7 "repository comparison" and §3.2 global requirements apply. Rule 20 (POS cross-layer contracts) and the document-per-action principle apply.

## 0. Benchmark (convention 10)

| Guarantee | Odoo | ERPNext | AutoERP today (`b9a5565aa`) | Decision |
|---|---|---|---|---|
| Opening float is a booked fact | Cash control: opening balance recorded at session open in the cash journal | POS Opening Entry records opening amount per mode of payment | Device + fiscal projection only (`ZSessionLifecycleProjection`); Treasury unaware | **Book** float into the drawer repository from the shift-open fact |
| Cash in/out (drops) are booked | Cash In/Out creates a journal entry (credit cash, debit bank suspense / chosen account) | Recorded in closing reconciliation per mode | Device + fiscal only (`pos_z_session_events` v3 / `pos_cash_drawer_operations` v2); no Treasury movement | **Book** each drop as a repository movement drawer→safe (or drawer→bank) with counter-custody |
| Transfer to bank | Two liquidity entries (cash journal, bank journal) | Payment Entry / Journal Entry | `RepositoryTransferService::transfer` exists, manual back-office only | Reuse; add safe→bank as the same service |
| Close difference | Cash difference gain/loss account | Difference per mode in POS Closing Entry | `PostShiftCashVarianceAdjustment` shipped **disabled** (SV-3/SV-4) | Enable behind flag once float/drops are booked; keep flag as kill switch |
| Expected cash derivation | Session opening + sales + in/out | Opening + expected per mode | `ShiftExpectedCashService:27-59` (fiscal-derived, correct) | Unchanged; becomes comparable to repository balance |

Sources: Odoo 14–18 POS cash control docs; Odoo forum "POS cash out and transfer cash to the bank"; ERPNext POS Opening/Closing Entry docs.

## 1. Design constraints (from the spec and gates)

- **Source identity:** every booked float/drop carries a stable source (fiscal event id for v3 shifts, `pos_cash_drawer_operations.id` for v2) so replay and re-ingestion are idempotent; conflicting content under the same identity is a visible conflict.
- **Not new company money:** an opening float is a **transfer** from the safe (or the drawer's prior closing balance) into the drawer, never a credit from nowhere. If the drawer repository already holds the previous shift's closing cash, the float reconciles to it; a mismatch is an exception, not a silent adjustment. Model after Odoo's cash-journal semantics.
- **Counter-custody explicit:** every drop names its destination repository (safe/bank) from **configuration per location**, never from the cashier (same doctrine as D8). Missing configuration → blocked work with reason, not a guessed destination.
- **Worker context:** projections run with no CompanyContext; pass currency explicitly (`getScaleSafe`), derive location from the terminal record.
- **Shared drawers / two terminals:** attribute movements by terminal session; do not sum two floats into company cash.
- **Frozen repository policy** (`TreasuryMovementService:91-96`) and per-leg idempotency preserved.
- **No fiscal change:** the Z report and `ShiftExpectedCashService` are untouched; this lane adds the money side only.

## 2. Slices

| Slice | Deliverable | Reviewer gate |
|---|---|---|
| C1 | Census: every float/drop/count fact source (v3 `pos_z_session_events` kinds, v2 `pos_cash_drawer_operations` kinds, X/Z payloads), every repository writer, current drawer balances on staging tenants; write `docs/superpowers/audits/2026-09-xx-w-cash-census.md` before code | treasury |
| C2 | Per-location cash custody configuration: drawer → default safe (drops) and safe → bank (deposits) on the existing repository surface (a setting, not a UI filter); validation at activation; census command for unconfigured locations | treasury + tenancy-authz |
| C3 | Treasury shift bridge (new projector, module-gated, mandatory when Treasury enabled): on shift open → float movement safe→drawer (or reconcile to prior closing); on cash-in/out → movement drawer↔safe with reason; on shift close → nothing new (variance handled by C5). One document + one movement (+ journal where GL-relevant) per fact, cross-linked, idempotent on source id. Registered in `FiscalEventProjectionRegistry`, queue covered by `HorizonQueueCoverageTest`, dependency on receipt projection where ordering matters | treasury + fiscal-pos + stock-gl-interaction |
| C4 | Back-office: safe→bank deposit via `RepositoryTransferService` with location scope (W1 rules), bank settlement kept separate; cash-position and repository detail show float/drops/deposits as movements | treasury + frontend-conventions |
| C5 | Enable `treasury.shift_variance_gl_enabled` path: variance leg posts only when the drawer's covered interval equals the fiscal expected-cash interval (opening float + covered movements); keep the flag; add W7 "repository comparison" result state (`unavailable` until C2/C3 complete for that location) | treasury + fiscal-pos |
| C6 | Backfill policy: historical shifts are **not** back-booked automatically; a read-only census lists uncovered intervals; operator-led opening-balance alignment per drawer with evidence | treasury |

## 3. Acceptance

Two companies, two locations, two terminals, one shared drawer case. (a) Open shift with 200.000 TND float: drawer +200.000, safe −200.000, one movement, one document; replay → no second movement. (b) Drop 1,000.000 mid-shift: drawer −1,000.000, safe +1,000.000, reason recorded. (c) Cash sales 3,500.000: drawer +3,500.000 via existing receipt bridge, unchanged. (d) Close with count 2,690.000 vs expected 2,700.000: variance −10.000 posted once via the flagged leg; repository balance equals expected before variance. (e) Two terminals on one drawer: single drawer balance, movements attributed per session. (f) Unconfigured safe at a location: float/drop projections `blocked` with reason; fiscal Z unaffected. (g) Worker dies after movement commit before status update → converges to one movement. (h) Frozen safe → recorded with alert per existing policy. (i) v2 shift (drawer operations rows) and v3 shift (fiscal events) both book identically. (j) Module-off (no Treasury) tenant: nothing booked, no error.

## 4. Deliverables

Code + PG-lane tests; additive self-guarding migrations; new queue in `config/horizon.php`; census command; `HANDBACK-W-CASH-<date>.md` with the census, per-slice evidence, staging deploy steps (repository configuration per location before enabling the projector; flag stays off until C5 evidence), and the list of tenants needing opening-balance alignment.
