# PR B — Codex Round 1 Review

**Subject:** fix(pos): cash payment amount = tendered + void over-refund fix
**PR:** https://github.com/otospexsolutions/erp/pull/121
**Base:** `dev`
**Branch:** `fix/pos-cash-payment-amount-is-tendered` (head `26e9586b`)
**Date:** 2026-05-11

## Findings

### [P2] Preserve refunds for pre-fix over-tendered receipts

`apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:226-227`

For receipts already created by the previous POS client, `change_due` was persisted while `pos_receipt_payments.amount` still held the cart total. In that legacy over-tender scenario, e.g. a €10 sale paid with €20, this subtraction computes `10 - 10 = 0` and skips the cash refund entirely, even though voiding should remove €10 from the drawer. The legacy guard only handles `change_due = NULL`, but existing pre-fix offline receipts can have positive `change_due`.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 2 prep)

Replaced the formula `cash_sum − change_due` with the universal `receipt.total − Σ(non_cash_payments.amount)`. This is the net cash that physically entered the drawer at sale time regardless of payment.amount shape:

- Pre-fix cash-only over-tender: total=10, cash_sum=10, change_due=10 → net = 10−0 = 10 ✓
- Post-fix cash-only over-tender: total=10, cash_sum=20, change_due=10 → net = 10−0 = 10 ✓
- Cash-only exact tender (either shape): total=10, cash_sum=10 → net = 10 ✓
- Split cash+card: total=30, cash=20, card=10, change=0 → net = 30−10 = 20 ✓
- Split with cash over-tender (post-fix): total=30, cash_sum=25, card=10, change=5 → net = 30−10 = 20 ✓
- Pure non-cash: total=30, non_cash=30, no cash row → no refund recorded (`hasCashPayment=false`) ✓

Added a regression test seeding the pre-fix legacy shape (cash.amount=total, change_due>0) and asserting the refund equals the net cash.

## Raw codex output

> The patch fixes new over-tender refund math but breaks void refunds for existing pre-fix over-tendered receipts that already persisted change_due alongside cart-total payment amounts.
>
> Review comment:
>
> - [P2] Preserve refunds for pre-fix over-tendered receipts — apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:226-227
>   For receipts already created by the previous POS client, `change_due` was persisted while `pos_receipt_payments.amount` still held the cart total. In that legacy over-tender scenario, e.g. a €10 sale paid with €20, this subtraction computes `10 - 10 = 0` and skips the cash refund entirely, even though voiding should remove €10 from the drawer. The legacy guard only handles `change_due = NULL`, but existing pre-fix offline receipts can have positive `change_due`.
