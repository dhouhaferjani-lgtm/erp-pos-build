# Procurement Wave 5 — Treasury/AP-GL Adversarial Review

Scope: uncommitted working tree in `apps/erp.procurement-v2` (branch `feat/procurement-wave3`),
Wave 5 = supplier-invoice matcher/posting rebased to receipt-line grain + PPV interaction.
Reviewer focus: AP/GL integrity of the new 408 clearing path and its interaction with the
Wave-4 PPV split in `GeneralLedgerService`.

## Verdict: NEEDS-REVISION

The core multi-price 408→PPV clearing engine is correct and well-tested (exact-leg asserts,
408 nets 0, Inventory untouched, rule-19 clean, deterministic lock order, correct idempotency
ordering, sound snapshot/clearing separation). Three integrity/coverage gaps must be resolved
or explicitly guarded before ship, led by a credit-note coherence regression (W5T-1).

---

## W5T-1 [Important — Critical for return→re-invoice flow] Credit-note reopen desynced from receipt-line ledger
`SupplierCreditNotePostingService.php:413,426` decrements only `poLine->quantity_invoiced` to
"reopen" a PO line after a GoodsReturn; it never decrements the corresponding
`goods_receipt_lines.quantity_invoiced`. Wave 5 makes the PO counter a DERIVED aggregate:
`SupplierInvoicePostingService.php:156-158,182` OVERWRITES `poLine->quantity_invoiced` with
`Σ goods_receipt_lines.quantity_invoiced`. After a return:
  - `ReceiptLineConsumptionPlanner::matchableQty` (`ReceiptLineConsumptionPlanner.php:62-71`)
    still reads the un-decremented receipt-line `quantity_invoiced` → matchable stays 0 → the
    reopened quantity is NOT re-invoiceable (`consumeReceiptLines` throws "insufficient
    receipt-line quantity", `SupplierInvoicePostingService.php:322-329`).
  - If re-invoiced anyway, line 182 overwrites the PO counter back to the full receipt-line sum,
    silently reversing the credit note's decrement.
Untested (credit-note file untouched by this diff; no return-then-reinvoice test at receipt grain).
Fix: on GoodsReturn, decrement `goods_receipt_lines.quantity_invoiced` (FIFO/LIFO reverse) under
the same lock so the derived PO counter reconciles, or block re-invoice with a clear error and a
test asserting the new invariant.

## W5T-2 [Important] `free_qty` double-counted / inconsistently modeled across matcher vs posting
The priced matchable window includes free_qty: `ReceiptLineConsumptionPlanner.php:65`
(`received = received_qty + free_qty`), so `SupplierInvoiceMatcher::matchableQty`
(`SupplierInvoiceMatcher.php:150-163`) grants a PRICED invoice line capacity over the free units.
But (a) free units accrue 408 at cost 0 (`GoodsReceiptService.php:280,287-297` fire GoodsReceived
with `unitCost:'0'`), and (b) the same free units are ALSO offered to bonus lines via the
separate PO-line counter `free_quantity_received − free_quantity_invoiced`
(`SupplierInvoiceMatcher.php:413-418`). Consequences:
  - `assertPostable` classifies a priced invoice of `received_qty + free_qty` as qty-OK (matcher
    window includes free), yet posting's PO-line guard uses paid-only `quantity_received`
    (`GoodsReceiptService.php:356`; free tracked separately at :310) and HARD-throws at
    `SupplierInvoicePostingService.php:171-180` — matcher says postable, posting rejects.
  - The only thing preventing silent 408 over-clear is that PO `quantity_received` is paid-only.
    Spec §2.3.1 says `quantity_received = Σ received_qty + free_qty`; if any path/backfill honors
    that, `consumeReceiptLines` accrues free slices at `accrual_unit_cost`
    (`SupplierInvoicePostingService.php:298-299`) while 408 was credited 0 → 408 left with a
    debit residue. No test uses `free_qty > 0`.
Fix: exclude free_qty from the PRICED planner window (free consumable only by bonus lines, and
bonus consumption must decrement the receipt-line free portion), or clear free slices at 0 basis;
reconcile matcher matchable with the posting guard so they agree.

## W5T-3 [Important — test quality] R3-1 over-clear untested on the NEW receipt-line path
The receipt-line over-clear guards (`consumeReceiptLines` per-line `SupplierInvoicePostingService.php:305-314`
and "insufficient receipt-line quantity" :322-329) have ZERO reject-case coverage. The one
write-boundary over-clear test, `SupplierInvoiceGlTest::test_write_boundary_overclear_rejected_and_rolls_back`
(:848-884), runs the LEGACY branch — its `confirmedPoWithReceipt` helper (:149-196) creates NO
`goods_receipt_lines`, so it exercises the `else` compatibility path (:159-169), not
`consumeReceiptLines`. Every receipt-grain clearing test bills exactly the available qty; none
bills beyond received to trigger the new throw; no concurrent/stale test. R3-1 ("invoiced can
NEVER exceed received+free per receipt line") is enforced in code but unproven on the shipped path.
Fix: add a serial test — post invoice 1 committing part of a receipt line, then post invoice 2 that
would push a receipt line past `received_qty+free_qty`, assert the receipt-line throw + full rollback
(receipt-line and PO counters unchanged, no orphan JE).

## W5T-4 [Minor — test quality] Clearing-uses-live-receipts not proven
`consumeReceiptLines` correctly reads live `accrual_unit_cost` and never touches
`price_match_basis` (separation is SOUND — surface 4 code is correct). But no test distinguishes
live vs snapshot clearing: in all clearing tests the weighted snapshot × qty equals the live FIFO
sum by construction (e.g. `SupplierInvoiceReceiptClearingTest.php:106-138`, snapshot 5.280×100 =
live 60×5.200+40×5.400 = 528). Add a test where receipts change between create and post and assert
408 clears at the LIVE receipt-line sum, not snapshot×qty.

## W5T-5 [Minor — runbook/doc] Rematch "fleet-wide" is single-tenant-connection
`RematchDraftSupplierInvoicesCommand.php:41-57` queries `Document` on the currently bootstrapped
tenant DB; it does not iterate tenants. Under database-per-tenant one invocation covers only the
active tenant's companies — matching the established `BackfillGoodsReceiptsCommand` pattern
(:45-77), so consistent, but the `@cross-tenant-by-design ... fleet-wide` docblock (:19) overstates
scope. `--dry-run` correctly writes nothing (no `save()` in the dry-run branch, :88-92 —
verified). Explicit currency to scale resolvers everywhere (getScale($currency) /
getScaleSafe($currency,3)) — rule-19 clean. Confirm the ops runbook wraps the command per-tenant.

---

## Verified-correct (no action)
- Multi-price 408→PPV math: accruedHt = Σ(slice qty × receipt-line accrual); `GeneralLedgerService.php:1227-1233`
  computes plug, priceDelta = billedHt − accruedHt, inventoryPlug = plug − priceDelta. Case
  receipts 50@5.200+50@5.400 / bill: Dr408 = 530.000, 408 nets 0, delta → PPV with correct sign,
  Inventory untouched — asserted in `SupplierInvoiceGlTest::test_wave5_multi_receipt_divergent_accrual_bases_clear_fifo_without_interim_guard`
  and `SupplierInvoiceReceiptClearingTest.php:106-199` (exact legs).
- PPV sign: billed>accrued → PPV expense debit (:1299-1308); billed<accrued → PPV income credit (:1309-1319).
- Idempotency ordering: both locks acquired (:73-88) then exists-check (:91-98) BEFORE consumption
  → genuine re-post is a no-op, receipt-line qty consumed exactly once
  (`test_idempotent_repost_is_noop_after_receipt_line_quantities_are_consumed`).
- Lock ordering: PO lines then receipt lines, both `orderBy('id')` (:76,85); receiving locks PO
  line (UPDATE) before inserting receipt lines — consistent global order, no new deadlock.
- Snapshot vs clearing separation: `price_match_basis` used ONLY for match STATUS
  (`SupplierInvoiceMatcher.php:522-554`), never for clearing.
