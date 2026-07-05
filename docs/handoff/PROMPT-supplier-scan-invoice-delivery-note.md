# Session kickoff — Scan-to-Document: supplier invoices AND supplier delivery notes (BL)

You are continuing the "scan a supplier document" flow in AutoERP (`apps/erp`). Your job this session: produce a **revised spec + plan** covering BOTH document kinds, get them **adversarially reviewed BEFORE any implementation** (standing owner rule), then execute through the factory workflow. Read `docs/factory/WORKFLOW.md` first and follow it (worktree isolation, tests BY PATH on the laptop, domain-reviewer gates, orchestrator-gated merges to dev).

## ⚠️ You also INHERIT procurement Waves 3–9 (owner decision, 2026-07-05)
Beyond the scan/OCR flow, this session now owns the goods-receipt-ledger procurement waves (they share `GoodsReceiptService`, the FIFO matcher, PPV/GL, and the supplier-invoice surface). The procurement worktree `apps/erp.procurement-v2` (branch `feat/procurement-wave3`) has already raced to HEAD `0e0f44ae8 "Wave 9"` — Waves 3–9 are all committed on that branch but **NONE merged to dev**, all pending the orchestrator gate. Open blocker: Wave-4 treasury review is NEEDS-REVISION (W4T-1 = new PPV system accounts lack a backfill migration for existing tenants; see `docs/superpowers/reviews/2026-07-04-procurement-wave4-treasury-review.md` and `docs/sessions/ORCHESTRATOR-NOTE-2026-07-04.md` in that worktree). Per owner resolution A3, Waves 4+5 promote TOGETHER. Finish revisions → treasury-reviewer + inventory-costing-reviewer gates → orchestrator-gated merge of the wave stack to dev. Full context: `HANDOVER-next-session-2026-07-05.md`.

## Business requirement (owner, 2026-07-05)

Suppliers sometimes send **invoices**, but most of the time they send **delivery notes (bons de livraison / BL)**. The BLs accumulate and get **invoiced at the end of the month** — one consolidated supplier invoice covering many BLs. Both must be scannable through the same flow:

- **Scan → OCR extract → review screen → commit**, with the commit target being either:
  - **(a) Supplier invoice** — the original scope, or
  - **(b) Supplier delivery note (BL)** — the NEW and majority case: committing a BL receives the stock (goods receipt + GR-IR accrual), with NO invoice yet.
- **Month-end**: the consolidated supplier invoice (scanned or manual) must reconcile against the accumulated receipt lines from those BLs.
- **First real user**: the customer being onboarded soon — "Bill the Standard" (⚠️ name transcribed from voice, CONFIRM exact spelling/company with the owner before it appears in any seeder/doc). Parapharmacy vertical; assume Tunisia-style BL-heavy supplier workflows.

## Critical coordination — do NOT duplicate in-flight work

1. **Procurement Waves 3–6 (receipt ledger) are IN FLIGHT** in worktree `apps/erp.procurement-v2`, branch `feat/procurement-wave3` (another session runs the implementation; the factory orchestrator gates the merge — see `docs/sessions/ORCHESTRATOR-NOTE-2026-07-04.md` in that worktree). Spec: `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md`; plan: `docs/superpowers/plans/2026-07-03-procurement-completeness-wave3-6-receipt-ledger-plan.md`. Key decisions that BIND your design:
   - **Receipts are first-class NON-document ledger tables** (`goods_receipts` + `goods_receipt_lines`, GRN numbering, written by `GoodsReceiptService` inside the receive transaction). A receipt is an inventory/costing event, NOT a fiscal document.
   - **Wave 5 rebases supplier-invoice matching/clearing to receipt-line grain (FIFO)** — your month-end consolidated invoice reconciliation should LAND ON this matcher, not reinvent it. Multi-PO supplier invoices are the planned final wave; PO-less flows must spawn receipts through the same ledger.
   - **Wave 4 adds received-price capture + PPV GL split** — BL price vs month-end invoice price differences belong to that mechanism.
   - **Waves 7–8 (Gap 3) will build the supplier-invoice creation UI** — your review/commit screen must be designed WITH that surface, not as a parallel competing UI. Coordinate through the factory orchestrator before building any SI-creation page.
2. **The old branch `feat/supplier-invoice-ocr` (5 ahead of dev, worktree `apps/erp.ocr-docs`) is STALE INPUT, not a base.** It contains `GoodsReceiptDocumentService` + a `DocumentType::GoodsReceipt` enum case + 3 design/handover docs — built BEFORE the receipt-ledger decision, on the assumption that receipts are documents. That assumption is now WRONG. Read the docs for requirements/UX thinking, salvage what fits, and explicitly list in your spec what from that branch is superseded. Do not merge or rebase that branch as-is.
3. **Media/storage**: memory `project_media_unification_strategy` — unify on `MediaAsset` (Catalog/Domain/Media) with a new `MediaOwnerType`; do NOT extend legacy `DocumentAttachment` for scanned pages. Decide the scanned-page storage in the spec accordingly.
4. Memory to read: `project_supplier_invoice_ocr_capture` (original scope + deps), `project_procurement_completeness_v1` (wave state — may be a session behind; git is truth). MEMORY.md rules apply (never full test suites on the laptop; Codex reviews to file; adversarial review before dispatch).

## Design questions your spec must answer (bring to the owner where product-level)

- **BL commit semantics**: PO-backed BL (receive against the PO) vs PO-less BL (spawn a receipt with no PO — check what Wave 3's ledger requires; `purchase_order_id` is currently NOT NULL in the receipt schema — a PO-less path may need an auto-created PO, a schema relaxation coordinated with the procurement session, or an owner decision).
- **Month-end reconciliation UX**: how the consolidated invoice's lines map to receipt lines (supplier item codes ↔ our products — consider reusing unified-imports' matching/normalization: `apps/api/app/Modules/Import/Services/` number/party/product normalizers); partial invoicing; BLs missing at month-end; price deltas → PPV (Wave 4), quantity deltas → dispute flow?
- **OCR engine + pipeline**: what extracts the fields (platform ML? the enrichment photo pipeline? third-party?) — the old design docs may have decided this; re-validate against what exists TODAY (`erp-ml` FastAPI service, enrichment H-B capture pipeline).
- **Fiscal/compliance**: supplier invoices join the fiscal posting path; BLs do not post GL beyond GR-IR accrual (via the existing movement-driven listener). Confirm in spec; treasury-reviewer + inventory-costing-reviewer + imports-reviewer agents (`.claude/agents/`) will gate the merge.
- **Precision contract (CLAUDE.md rule 19)**: every extracted money/qty lands as strings with regex ceilings; OCR confidence never rounds through floats.

## Execution model — Codex is the workhorse (owner directive, 2026-07-05)

You (Claude) are the session coordinator; **Codex CLI does the implementation work**. This planning started earlier — RESUME from the uncommitted design + 2 handover docs in worktree `apps/erp.ocr-docs` (memory `project_supplier_invoice_ocr_capture` marks them ▶RESUME); update them for the BL requirement rather than starting from scratch.

Division of labor (proven on the procurement waves — copy that protocol):
- **You**: spec/plan revision, wave slicing, writing `docs/sessions/CODEX-TASK-<wave>.md` INTO the worktree (wave section + spec §refs inlined + global constraints), reviewing Codex diffs, running scoped verification, committing, coordinating with the factory orchestrator.
- **Codex**: all implementation waves. Dispatch pattern (from the procurement plan's execution protocol): `cd <worktree> && node ~/.claude/plugins/cache/openai-codex/codex/<ver>/scripts/codex-companion.mjs task --write --background --fresh "Read docs/sessions/CODEX-TASK-<wave>.md and execute it fully."` — flags as separate argv tokens BEFORE the prompt (apostrophe trap). See memory `reference_codex_companion_worktree_sandbox` for the full worktree recipe.
- **Codex CANNOT git-commit in sandboxed worktrees** → task-log protocol: Codex maintains `docs/sessions/TASK-LOG-<wave>.md`; you review the diff and commit (memory `feedback_codex_worktree_commit_sandbox`). Codex sandbox also blocks PHPStan parallel workers → run with `--debug`.
- **Codex does NOT review documents** — it confabulates on doc review (proven 3× on the margin spec). Spec/plan adversarial reviews = Claude agents; Codex reviews are fine for CODE it can grep-verify, saved to a file not inline (memory `feedback_codex_review_to_file`).

## Process requirements

- Brainstorm → revised spec (`docs/superpowers/specs/`) → plan (`docs/superpowers/plans/`) → **adversarial review of both BEFORE dispatching Codex implementation** (Claude agent review — standing owner rule).
- Implementation waves TDD-first in an isolated worktree off current dev; verify the worktree base commit before working (known harness bug: stale bases). Tests BY PATH only on the laptop — never full suites.
- The factory orchestrator session gates all merges to dev; coordinate wave sequencing with it, especially anything touching `GoodsReceiptService`, the matcher, or SI surfaces (procurement session territory).
