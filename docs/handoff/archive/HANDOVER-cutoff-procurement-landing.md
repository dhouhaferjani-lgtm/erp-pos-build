# HANDOVER — Cutoff B1: Land procurement-to-pay on dev (Stage G)

> **The long pole / freeze trigger.** The feature is built; this is an INTEGRATION + REVIEW + MERGE
> task, not new feature work. Landing it closes the supplier side of the GL and partner-balance B4.

## Branch state (verified 2026-06-27)

- Branch **`feat/procurement-to-pay`**, dedicated worktree **`apps/erp.procurement`**.
- **22 ahead / 62 behind `dev`.** Feature + web UI are COMPLETE on the branch:
  - GR-IR on goods receipt — Dr Inventory / Cr 408 (`396d4a9c8`, `50ee63c0f`, `3b5607a39`).
  - `supplier_invoice` DocumentType + `match_status` + `quantity_invoiced` col (`616d16e26`).
  - 3-way matcher with hard 408 quantity invariant + advisory price variance (`7a08685cd`,
    `d170a0bec` — Codex BLOCKER-1/2 fixed).
  - Locked + idempotent invoice GL clearing 408 → 401 + VAT (4456) + non-recoverable timbre
    (`50ee63c0f`, `3b5607a39`).
  - Supplier payment clears 401 + reduces `payable_balance`, concurrency + cross-partner guards
    (`fd4501794`, `d92f849ac`, `b593ee70b`).
  - Supplier credit-note GL matrix + `quantity_invoiced` reversal (`f145fb243`, +D1 reviews
    `25ac742c9`, `80be437e0`).
  - Supplier-invoice HTTP API (`8072b3d4a`) + Codex DO-NOT-SHIP review closed (`63ea54b0a`,
    `26b98616b`).
  - Web UI (`65a3e3fe9`, `f29bf7ebd`, `56006ff0a`, `b0d474cbc`).
  - **Stage E media port DONE** — `MediaRole::SourceDocument` + optional role on document upload
    (`bb3c5158b`), re-homed onto `App\Modules\Media` (`MediaOwnerType::Document` + `owner_id` =
    supplier_invoice doc id). The earlier dropped Codex commit is preserved at tag
    `stage-e-media-port-orig` — do NOT resurrect the old `Catalog\…` namespace version.

## Remaining work = Stage G (integrate + verify + merge)

1. **Reconcile with current dev (62 behind).** In the existing worktree (do NOT create a fresh
   worktree off dev — that orphans the 13+ branch commits), rebase the branch over / merge current
   `dev`. Watch for collisions with the parallel batch-write-off / inventory + media-unification
   workstreams that advanced dev.
2. **Verify scoped (NEVER the full suite / `--parallel` — crashes the laptop, rule + memory).** Run
   by path/filter: GL integration (`GLIntegrationTest` was 77 green post a prior rebase), the
   procurement matcher/invoice/payment/credit-note tests, and accounting GR-IR tests.
   PHPStan L8 + Pint clean on touched files.
3. **Final review** — Codex adversarial pass + Opus cross-model review on the integrated diff
   (money/GL/hash-chain changes warrant it). Save reviews to files (rule), adjudicate every finding
   vs code before applying.
4. **Merge to LOCAL dev first, then FF-push origin/dev** in a clean batch (rule 21).

## What landing this unlocks

- Accounting audit gaps **#1 (supplier invoice AP/VAT), #2 (supplier payment 401), #7 (GR inventory
  GL)** — closed. The B2C side was already wired; this completes the supplier cycle.
- Partner-balance hardening **B4** (no supplier-invoice → GL caller; `payable_balance` never
  populated) — closed.
- The E2E campaign's Phase 3 (procure-to-stock) becomes fully testable.

## Still deferred AFTER this lands (→ integration branch, not dev)

- Supplier **advance** / PO prepayment GL (`createSupplierAdvanceJournalEntry` missing) — import
  procurement **Phase 2** (4091), explicitly out of scope.
- Landed cost + customs/import VAT — Phase 2.
- Media-system full unification (kill disk, default S3/R2) — separate session; procurement only
  consumes the unified `MediaAsset` it needs.

**Reference:** spec/plan + reviews under `docs/superpowers/{specs,plans,reviews}/2026-06-24-domestic-procurement-to-pay-*`.
