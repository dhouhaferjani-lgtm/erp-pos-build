# Handover to Codex — parapharmacy remediation spec, fix round 1

Date: 2026-09-05. Source snapshot: `b9a5565aa` (local `dev`, 147 ahead of `origin/dev`, unpushed). Owner: admin@otospex.com. Orchestrator: Claude Fable 5.1 (this session).

## Role split (owner ruling 2026-09-05)

- **Fable 5.1 is the orchestrator of record.** It holds the complete view across sessions, sequences lanes, runs the adversarial gates (Opus reviewer agents), and is the **only** party that merges into local `dev` and promotes to `origin/dev`. Codex never merges, rebases, force-pushes or pushes any branch of this repo.
- **Codex owns this spec fix round and, once the spec is accepted, the implementation lanes** dispatched from Fable-written briefs. Each lane lives on its own branch in its own worktree and ends with a `docs/handoff/HANDBACK-<lane>-<date>.md`. Fable gates it, then merges.
- Codex does not commit; it leaves files in the working tree and reports paths. Fable commits path-scoped (`git commit -- <paths>`).
- Owner decisions stay owner decisions. Where the spec needs a ruling, Codex writes the decision row with a recommended default and the consequence of each option; it does not pick.

## Inputs (read all four, in this order)

1. Audit: `docs/sessions/2026-09-05-parapharmacy-readiness-audit.md` (F1–F8).
2. Spec v1: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md`.
3. Fable review r1 (`CHANGES-REQUIRED`): `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-fable51-review.md` — findings F-A..F-F, counterevidence, owner decisions 1–5.
4. Root-cause archaeology: `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-root-cause-archaeology.md` — per-finding origin, five recurring mechanisms, seven proposed guards.

## Owner rulings that change the spec

**R1 — Batch management gets its own A-to-Z lane, finished properly.** Lot management was designed for the warehouse side and never reached the POS device, the sealed receipt, the counting model or batch CRUD (archaeology mechanism 1). The owner wants every remaining small gap closed in one dedicated lane rather than spread across W1/W5/W6. Fold the lot-related parts of W1 (recall/destroy permissions), W5 (lot-grain counts, T22 ticket, lot correction) and W6 into a single package **W-LOT** with its own spec section, ordered gaps list and acceptance matrix. Gaps already known and to be included, each with its source pointer:
- F2 recall/destroy action permissions (`BatchExpiry/Presentation/routes.php:26,29`; `batches.recall` seeded but unconsumed; manager role holds it — owner ruling pending, review decision 2).
- F7 lot-grain counting — supersedes ticket `docs/superpowers/tickets/2026-08-19-counting-lane-c2-default-lot-inflation.md` (T22, OQ-7). Decide what a count does to lots; the DEFAULT top-up invariant in `InventoryCountingDefaultBatchTest:130-131` is a pinned limitation, not a requirement.
- F8 lot identity/expiry frozen after first movement; permissioned correction with history.
- Lot drift census scheduling (W4R2 fiscal gate N-5: detector shipped, nothing schedules it).
- `BatchStockService` float accessor on the issue/transfer guard path (`docs/superpowers/tickets/2026-08-08-f7-fefo-residuals.md` residual (a)).
- DEFAULT-lot inflation on the counting lane (same T22 ticket).
- Provenance labelling: `pos_receipt_line_batch_allocations` and `BatchTraceabilityController` currently present a FEFO estimate as traceability. Label it `system_fefo_estimate` everywhere it is shown.

**R2 — BatchExpiry is a gated module; the POS must honour the gate.** Every lot surface on the device, in the projections and in the web is conditional on `hasModule('BatchExpiry')` / `module:BatchExpiry`, both layers (CLAUDE.md rule 12, `docs/architecture/vertical-module-gating.md`). Existing test `apps/pos/src/stores/__tests__/productStore.hasModule.test.ts` is the device-side anchor. A tenant without the module sees no lot UI and its projections write no lot legs (today's behaviour). Spell this out in W-LOT and W6.

**R3 — POS first iteration: show the lot to sell, no selection.** FEFO stays the default allocation. For the first iteration the register displays the FEFO-suggested lot for a tracked product (batch number and expiry, or whichever identifier the branch cache holds) on the product tile and cart line, read-only. Cashiers cannot choose a lot. The purpose is operational: staff see which batch they should be pulling from the shelf. This is **display of a suggestion**, so the sealed payload is unchanged in iteration 1 and the server projection keeps FEFO, labelled as an estimate. Captured-lot checkout (spec W6 `captured_lot`, decision D3/D7) becomes iteration 2 and stays a decision row. The device needs branch lot eligibility in its product cache (`apps/pos/src/types/product.ts` has no lot fields today; the audit F1 cites `:50–119`), with the D4 freshness policy.

**R4 — Keep the architecture; targeted corrections (spec option 1) stands.**

## What the fix round must produce

Revise the spec **in place** (same path) to v2 with a change log at the top. Address every Fable finding:

- F-A (W2): retire the device-side first-active repository pick (`apps/pos/src/stores/paymentStore.ts:1185`) in favour of one binding; evaluate sealing the resolved `repository_id` on a new SALE_RECEIPT version validated like `TreasuryAccountPaymentBridge.php:406-418`; shrink the enum to what the resolver needs; name the reversal of the H-3 day-one pin (`CleanRegistrationDownstreamAssumptionsTest`, owner decision 3).
- F-B (W6): add decision row **D7** — line-level lot fields in SALE_RECEIPT vN+1 versus a separate evidence stream, with the tradeoff stated. Under R3, D7 only governs iteration 2.
- F-C (W3): remove the prescribed lock order; require a writer census and a PostgreSQL concurrency test; note the unlocked max+1 chain allocation (`AccountingService.php:591-609`) and the missing Document source-uniqueness index.
- F-D (W7): reconcile device totals against the fiscal-event derivation (`ShiftExpectedCashService`); repository-balance comparison is a separate check gated on Treasury booking float and drops (owner decision 4). Register "session reconciliation" in `docs/glossary.md` (convention 11).
- F-E (W1): narrow batch enforcement to recall, destroy and read routes; add the repository adjustments route (`Treasury/Presentation/routes.php:98-100`) to the seam list; manager-recall ruling as a decision row.
- F-F (W4): state the seeding gap precisely (registry fail-closed exclusion only) and keep the scheduled dispatcher for lost enqueues and orphaned `running` rows.

Add a **W0 — guards** package from the archaeology's seven proposals (route action-permission ratchet, location-scope coverage census, full-matrix resolver acceptance, lot-evidence glossary entry, PG concurrency leg, standing recovery acceptance rows, `_pins_limitation_` test marker). Each guard names the mechanism it closes.

Carry the open decisions forward in one table: D1–D7 plus the review's decisions 2–4. Do not resolve them.

## Constraints

- Spec only. No product code, no migrations, no test edits, no commits, no pushes.
- Benchmark-first table (convention 10) stays at the top and must gain rows for R3 (Odoo/ERPNext/Dolibarr lot display at POS).
- Second-of-everything (convention 09) applies to W-LOT: two companies, two locations, two lots, re-run idempotency.
- Cite `path:line` at snapshot `b9a5565aa` for every claim about current code.
- Stop after writing v2 and a short `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-codex-fix-round-1-notes.md` listing what changed, what was rejected from the review and why, and which decisions are still open. Fable runs gate r2.
