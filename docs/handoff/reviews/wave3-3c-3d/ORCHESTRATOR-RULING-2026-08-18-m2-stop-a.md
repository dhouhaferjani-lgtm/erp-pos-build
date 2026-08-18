# ORCHESTRATOR RULING — wave3-3c-3d M2 STOP-A resolution (2026-08-18)

Authority: SELF-REVIEW-HARNESS.md:52-64 (STOP A escalates to the human/orchestrator; no
constraint on the resolution) + the amending-authority precedent of
ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md. Verdict of record being resolved:
`docs/handoff/reviews/wave3-3c-3d/M2-round7.md` (CHANGES-REQUIRED, fix budget exhausted).

This ruling grants **exactly one further substantive fix round (round 8), scoped as below.**
`max_fix_rounds: 5` remains immutable; the wave adds a second `review_resume:` block to the
progress YAML citing this file, `scope: stop_a_scoped_fix_round`, consumed by the round-8
verdict. If round 8 is CHANGES-REQUIRED on anything inside this scope, STOP A restores
immediately with no further budget.

## Rulings

**1. R-1 is DECIDED at this gate: option (a) CONFIRMED** (per brief :126-140, :602-603 — the
orchestrator recommendation becomes the ruling). The interactive refund path in
`ReceiptReturnService` must resolve refund `unit_cost` from the **original sale's
`stock_movements.unit_cost`** at the same receipt + product (+variant) grain — the same
lookup shape as `PosCoreReceiptProjection::originalPosSaleBasis()` — closing **P2-1**.
`receiptLineUnitCost()` survives only as the explicit fallback when the sale movement is
absent. Required by R-1's own deliverable: a test fixture at a non-representable cost
(e.g. `1.234568`) that goes RED under option (b). Collateral in the same round: correct the
now-stale docblock rationale in `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php`
(it claims `pos_receipt_lines.unit_cost` is not a WAC snapshot; M2's projection writes make
it one).

**2. P2-2: adopt the round-7 reviewer's remedy verbatim.** Replace the
`isTestBoundaryGuardEnabled()` short-circuit in `InventoryGlPostingBoundaryGuard` with a
`DB::transactionLevel() === 0` gate; delete `enableTestBoundaryGuard()` /
`isTestBoundaryGuardEnabled()` and all 8 opt-in call sites across the 6 test files. R-2's
"Guard 4 is NOT optional / the only guard that does not depend on the register being right"
is verbatim binding; the opt-in defeats exactly that property. Regression set: the 6
formerly-opt-in test files + `StandaloneInvoiceGuidedDeliveryTest`,
`InvoiceDeliveryNoteConfirmationTest`, `ReceiptReturnFlowTest`.

**3. Close in the same round (round-7 findings 3, 4, 5 — none is design work):**
- P3-3: the T16/D-13 ADDITION-4 ratchet test (no `POSSale`/`POSReturn` movement above the
  watermark with `unit_cost IS NULL`).
- P3-4: the missing M2 report section (T14–T17 with actual output) + a deptrac number
  reconciled against the baseline drift recorded at M1 (116 vs 99).
- P3-5: squash the cutover (`f848dab39` + `28a2d854b` + `55e03c025` + this round's fixes)
  into ONE commit — D-13 atomicity must be history, not intention.

**4. P3-7 RULED: the compositional reading is ACCEPTED.** The 2026-08-11 hardened gate
("all ten T11c pairs GREEN post-cutover") is satisfied by per-writer terminal trace proofs
(`CogsRelocationCharacterisationTest`, `PosReturnScrapWriteOffTest`,
`InventoryGlVoucherLockOrderTraceTest`, `GoodsReceiptGlPostingOrderTest`), NOT by the
pair-salted harness, which runs byte-identical SQL per pair and proves nothing pair-wise.
Condition: the round-8 register must cite, for every writer participating in the ten pairs,
the trace proof covering it. The pair harness is retained as scaffold, not evidence.

**5. Ship-with-ticket (harness :78-79 standing rule) — P3-6, P3-8, P3-9, P3-10.** The wave
records one ticket each in the brief's tree before round 8: trigger-disable necessity
(P3-6, PLAUSIBLE only), missing-movement `is_historical` conflation at
`PosCoreReceiptProjection.php:2415` (P3-8 — if the P2-1 port makes a shared helper the
natural fix, closing it in-round is permitted but not required), hard-deleted-product
refund refusal (P3-9, pre-dates the wave), and the per-tenant duplicate-count cutover
precondition (P3-10 — joins the deploy notes as a hard pre-promotion step; M0's local R-11
probe was vacuous and must not be cited as satisfying it).

**6. 3D remains not started** (`branch_3d: null`); nothing here authorizes or blocks it.

— Parent orchestrator, 2026-08-18
