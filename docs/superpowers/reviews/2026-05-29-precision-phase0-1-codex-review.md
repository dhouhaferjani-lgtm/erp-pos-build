# Codex Adversarial Review — Precision Phase 0 + Phase 1

**Date:** 2026-05-29
**Branch:** `feat/precision-drift-remediation`
**Diff reviewed:** `83ed1f15d` (origin/dev) .. `17313dad4` (branch tip)
**Reviewer:** Codex (adversarial), with controller verification of every finding.

---

## Codex verdict: REQUEST-CHANGES (2 BLOCKER, 1 P1, 2 P2)

> ⚠️ Codex ran in a read-only sandbox and made only **one** tool call — it could not actually read the diff or grep the codebase, and it could not save this file. Its findings are therefore *speculative from the prompt description*, not code inspection. Several cite **non-existent filenames** and **incorrect facts**. Each finding was independently verified by the controller against the real code; verdicts below.

---

## Finding-by-finding verification

### BLOCKER 1 — "POS stock sale path truncates 4-decimal quantities to scale 2"
**Claim:** `ReceiptCreationService` / `PosCoreReceiptProjection` write stock movements using a currency/`bcformat` scale (2–3) instead of quantity scale 4.

**Verified: REAL, but PRE-EXISTING and already scheduled — NOT a Phase-1 regression.**
`ReceiptCreationService.php:888` does `bcsub($stockQty, $quantity, 2)` while the availability check at `:870` uses `bccomp($available, $quantity, 4)`. This scale-4-compare-vs-scale-2-write asymmetry is *exactly* the site the plan documents under **Task 3.10** (`ReceiptCreationService.php:869,887`, `PosCoreReceiptProjection.php:875`). The `bcsub(..., 2)` predates this branch; Phase 1 only widened the storage column. It cannot be made worse by Phase 1 because no quantity with a non-zero 3rd/4th decimal can be *created* until Phase 4 ingress regex permits it. **Resolution: tracked by Task 3.10 (now unblocked — inventory PR #151 merged). Out of scope for the Phase-1 PR.**

### BLOCKER 2 — "Return validation allows over-returns after quantity → scale 4"
**Claim:** `ReceiptReturnService` over-return guard compares at a hardcoded lower scale, leaving a 0.0001 gap.

**Verified: forward-looking, scheduled — NOT a Phase-1 regression.** This is the precise concern the plan's **Task 3.11** (ReceiptReturnService symmetric bcmath) and the **Task 3.10 dependency note** address ("after Phase 1.3 widens `pos_receipt_lines.quantity` to scale 4 … the bccomp and bcsub should both use the quantity scale (4)"). It cannot manifest in the Phase-1-only state: scale-4 return quantities require Phase 4 ingress. **Resolution: tracked by Task 3.10/3.11.**

### P1 — "Price tier matching compares 4-decimal thresholds at scale 2"
**Verified: forward-looking, same category.** A quantity-comparison-scale concern that only mis-fires once sub-3-decimal quantities exist (Phase 4). Tracked by the Phase 3 service sweep / Phase 4 ingress. Not a Phase-1 regression.

### P2.1 — "`Payment.exchange_rate_at_payment` cast `decimal:4` truncates a `decimal(18,6)` column"
**Verified: FALSE (hallucination).** The cast is `'exchange_rate_at_payment' => 'decimal:6'` (`Payment.php:120`) and the column is `decimal(15,6)` (migration `2025_12_10_100003:17`). They match exactly. `fx_gain_loss_amount`/`discount_taken` are `decimal:4` matching their `decimal(15,4)` columns.

### P2.2 — "pos_orders narrowing pre-check omits `discount_amount`"
**Verified: FALSE (hallucination).** Codex cited a non-existent file (`..._align_pos_orders_tax_and_discount.php`). The real migration `2026_05_29_100001_align_pos_orders_to_scale_3.php` lists `discount_amount` in the `COLUMNS` map for **both** tables, and the pre-check loops over every column (lines 44–88). `discount_amount` IS guarded.

---

## Controller verdict: APPROVE for the Phase 0+1 PR

- No valid blocker for this PR. The two "BLOCKER" items and the P1 are **pre-existing precision bugs the plan explicitly sequences into Phase 3.10/3.11 + Phase 4**, and the storage-first ordering is intentional and safe (widen the column before any wider data can be written). Both P2s are factually wrong.
- Phase 1 introduces no regression: the narrowing migration's per-column pre-check (incl. `discount_amount`) prevents fiscal-data truncation; all added casts match their column scales; the full PHPUnit suite is green except one pre-existing, untouched tenancy failure.
- **Carried forward into Phase 3/4** (documented so the dependency is explicit): Task 3.10 (`ReceiptCreationService` stock decrement scale-2→quantity-scale-4), Task 3.11 (ReceiptReturn symmetric scale), and the Phase 3/4 price-tier/ingress quantity-scale alignment.
