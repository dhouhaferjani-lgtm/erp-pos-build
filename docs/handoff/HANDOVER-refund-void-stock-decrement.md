# HANDOVER — POS refund/void receipts decrement stock the wrong direction

**Date:** 2026-06-27
**Author:** stock-decrement session (branch `fix/pos-variant-stock-decrement`, worktree `../erp.pos-stock`)
**Status:** OPEN — surfaced by the adversarial review of the variant-stock fix; **deliberately left out of scope** of that branch (rule 4 — one task at a time). This doc is the starting point for the follow-up task.
**Related:** [`docs/superpowers/audits/2026-06-27-pos-variant-stock-decrement-review.md`](../superpowers/audits/2026-06-27-pos-variant-stock-decrement-review.md) (review that flagged it), the just-shipped variant fix on this branch.

---

## TL;DR

`PosCoreReceiptProjection::apply()` calls `decrementStockForLines()` **unconditionally** for every `SALE_RECEIPT` fiscal event — *including* events whose `invoice_type_code` is `REFUND` or `VOID` (which the same method maps to `ReceiptType::Return`). A refund/void returns goods to the shelf, so its stock effect should be a **restock (increment)**, not a decrement. Today a refund **double-removes** stock: the original sale decremented it, and the refund decrements it again.

The just-merged variant fix makes this *more precise but not less wrong* — a variant refund now decrements the **variant** row twice instead of the product row. Direction is still inverted.

---

## Evidence (file:line)

All paths in `apps/api`.

1. **Unconditional decrement** — `app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`
   - `apply()` calls `decrementStockForLines($receiptId, $event, $terminal, $view)` at the end of the transaction with **no receipt-type guard** (~line 319).
   - `resolveReceiptType()` (~line 433) maps `invoice_type_code ∈ {REFUND, VOID}` + resolvable original → `ReceiptType::Return`. So by the time `decrementStockForLines` runs we *know* it can be a Return, and still decrement.
   - `decrementStock()` writes `MovementType::Issue` + `MovementReason::POSSale` (~line 966-967) — i.e. it records every refund line as a **sale issue**.

2. **Refunds DO flow through this projection.** `handlesEventType()` is `SALE_RECEIPT` only, but a `SALE_RECEIPT` payload legitimately carries `invoice_type_code = REFUND|VOID` (the validator requires `original_receipt_reference` for those). Pinned by `tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php::test_refund_projection_throws_when_original_receipt_unresolvable` and the VOID twin — both build `SALE_RECEIPT` events with `invoiceTypeCode: 'REFUND'|'VOID'`.

3. **The correct shape already exists** in the legacy path — `app/Modules/POS/Application/Services/ReceiptReturnService.php`:
   - `restoreStock(...)` (called ~line 414) **adds** stock back, targeting the same grain the sale decremented, using the inventory `Receipt`/return semantics.
   - Inventory enums available: `MovementType::Receipt`, `MovementReason::POSReturn = 'pos_return'` (and `CustomerReturn`). A projection-side restock should mirror these.

4. **Inventory enums** — `app/Modules/Inventory/Domain/Enums/`: `MovementType::{Receipt, Issue, …}`, `MovementReason::{POSSale, POSReturn, CustomerReturn, …}`.

---

## Open questions to resolve in design (do these FIRST)

1. **Is refund/void via `SALE_RECEIPT` a live Phase-1 path, or deferred to a Phase-2 `REFUND_RECEIPT` event type?**
   `FiscalEventType::REFUND_RECEIPT` exists but is "Phase 2+ reserved" (see the chokepoint manifest note on `ReceiptReturnService`, disposition (c)). Confirm whether the **device** actually emits `SALE_RECEIPT` events with `invoice_type_code=REFUND|VOID` today. If it does **not**, this is latent (fix-before-Phase-2); if it does, it is a **live go-live data-correctness bug**. This determines urgency.

2. **Canonical sign convention for refund line quantities.** Does `line_items[].quantity` on a REFUND/VOID payload arrive **positive** (magnitude, direction implied by `invoice_type_code`) or **negative** (signed)?
   - If **positive**: current code decrements (doubly wrong) → fix must *invert to a restock*.
   - If **negative**: `bcsub` would actually *add* stock, but `MovementType::Issue`/`reason POSSale` are still wrong, and the insufficient-stock `bccomp` guard and movement audit are nonsensical with negatives.
   Verify against `apps/pos` device authoring + the canonical spec (`docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`) and the fiscal payload validator. **Do not assume** — this is the crux.

3. **Does VOID differ from REFUND for stock?** A same-day VOID (cancel) vs a REFUND (goods physically returned) may have different inventory intent (e.g. VOID of an unfulfilled line should not restock if goods never left). Decide per `invoice_type_code`.

4. **Partial refunds.** A REFUND may cover a subset of original lines/quantities. Confirm the canonical payload represents only the refunded lines/quantities (so restock = payload line quantities), not the full original receipt.

---

## Recommended fix (shape, pending the answers above)

In `PosCoreReceiptProjection`, branch the stock effect on the resolved receipt type:

- **`ReceiptType::Sale`** → existing `decrementStock()` (variant-aware, just shipped). Unchanged.
- **`ReceiptType::Return`** (REFUND/VOID) → a new `restockForLines()` / `restoreStock()` that **adds** the line quantity back to the **same grain** (`variant_id` honoured exactly as the decrement does), writing `MovementType::Receipt` + `MovementReason::POSReturn`, with `reference_type='pos_receipt'`, `reference_id=$receiptId`.

Keep it idempotent the same way: the whole `apply()` is guarded by the `fiscal_event_id` fast-path probe + `INSERT … ON CONFLICT DO NOTHING`, so a replayed refund won't double-restock. Preserve the precision contract (bcmath at scale 4, no float — rule 19) and the no-`CompanyContext` rule (rule 20): read tenant/company/location from the event/terminal, never `app()`.

**Reuse, don't reinvent:** the grain-matching + batch-allocation restore logic in `ReceiptReturnService::restoreStock` / `restoreBatchAllocations` is the reference implementation. Consider extracting a shared `Inventory` service so the projection and the legacy return path can't drift.

---

## Directly-coupled stale comment (small, do it with this task)

The variant fix changed the **grain** a projection-path sale decrements (product-level → variant), which invalidates a now-stale premise in the **return** path:

`app/Modules/POS/Application/Services/ReceiptReturnService.php` (~line 410, inside the restock loop):

> *"Variant symmetry rule: … Draft-path lines carry variant_id (variant-scoped decrement); **projection-path lines carry NULL (variant_id IS NULL decrement)**. Never re-derive the variant for NULL lines."*

After the variant fix, **projection-path variant sales decrement the variant row** (and `pos_receipt_lines.variant_id` is the resolved variant). `restoreStock` already targets `$originalLine->variant_id`, so behaviour is now **correctly symmetric** — but the comment's parenthetical is false and will mislead. Update it to: projection-path *variant* lines decrement the variant grain (symmetric with the receipt line's `variant_id`); only *non-variant* lines carry NULL. The "never re-derive for NULL lines" rule still holds. (Comment-only; no behaviour change.)

---

## TDD test checklist for the fix

Mirror `tests/Feature/Fiscal/PosCoreReceiptProjectionVariantStockTest.php` conventions (build a verified `SALE_RECEIPT` `FiscalEvent` directly; `app(CompanyContext::class)->clear()` before `apply()` per rule 20):

- REFUND of a **non-variant** line **restocks** the product-level row (`+qty`); movement is `receipt`/`pos_return`.
- REFUND of a **variant** line restocks the **variant** row (`+qty`), product-level untouched; movement carries `variant_id`.
- VOID behaves per the decision in open-question #3.
- Replay of the same REFUND event is a **no-op** (no double-restock).
- A REFUND followed by reading stock equals the pre-sale level (sale −q then refund +q == net 0) — the end-to-end "no phantom stock" assertion.
- Precision: fractional refund qty stays decimal(4) bcmath, no float drift.
- Partial refund restocks only the refunded quantity.

Run **by path** (never the full suite — it crashes the laptop): `vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjection*Test.php`. Preflight scoped to changed files (PHPStan `app/` only — tests aren't analysed; Pint).

---

## Branch / process

- Fresh worktree off `origin/dev` (e.g. `git worktree add -b fix/pos-refund-void-restock ../erp.pos-refund origin/dev`).
- `composer install` in the worktree first (vendor is not shared across worktrees; do **not** symlink it — the autoloader resolves to the main repo and runs stale code).
- Merge to LOCAL `dev` first; promote to `origin/dev` only as a clean fast-forward; never force-push shared `dev`.
- This is the same Inventory/POS T2 family as the variant fix and is likewise a prerequisite for the variant-WAC COGS accounting work — keep GL/accounting out of scope; this is an inventory-direction fix only.
