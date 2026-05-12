# PR B — Codex Round 2 Review

**Subject:** fix(pos): cash payment amount = tendered + void over-refund fix
**PR:** https://github.com/otospexsolutions/erp/pull/121
**Base:** `dev`
**Branch:** `fix/pos-cash-payment-amount-is-tendered` (head `80735e8e` — Codex r1 P2 closure)
**Date:** 2026-05-11

## Findings

### [P2] Subtract tolerance write-offs from cash void refunds

`apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:232`

For receipts paid short within the configured cash tolerance, `pos_receipt_payments.amount` is the cash actually tendered while `receipt.total` remains the full sale total and `tolerance_writeoff` stores the difference. This new `total - non_cash_sum` formula therefore over-refunds the drawer by the tolerance amount (e.g. a 100.000 sale with 99.700 cash and 0.300 tolerance records a 100.000 refund instead of 99.700), making the shift cash short after voiding these receipts.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 3 prep)

Replaced the r1 formula `total − non_cash_sum` with the universal:

    refund = max(0, min(Σ(cash.amount), total − Σ(non_cash) − tolerance_writeoff))

This handles every payment.amount shape and every cash edge case:

| Scenario | cash.amount | non_cash | change_due | tolerance | total | refund | branch |
|---|---|---|---|---|---|---|---|
| Post-Bug-2 over-tender | 20 | 0 | 10 | 0 | 10 | 10 | min → cash_owed |
| Pre-Bug-2 over-tender (Codex r1) | 10 | 0 | 10 | 0 | 10 | 10 | both equal |
| Tolerance short-pay (Codex r2) | 99.7 | 0 | 0 | 0.3 | 100 | 99.7 | min → cash_sum |
| Exact tender (either era) | 10 | 0 | 0 | 0 | 10 | 10 | both equal |
| Split exact | 20 | 10 | 0 | 0 | 30 | 20 | both equal |
| Split over-tender cash (post-fix) | 25 | 10 | 5 | 0 | 30 | 20 | min → cash_owed |
| Pure non-cash | 0 (no cash row) | 30 | 0 | 0 | 30 | 0 | hasCashPayment=false |

Added a regression test for the tolerance short-pay case asserting refund=99.700 on a 100.000 receipt with 99.700 cash + 0.300 tolerance write-off.

## Raw codex output

> The patch fixes over-tender cash refund math but introduces an over-refund for valid short-pay-within-tolerance receipts by ignoring `tolerance_writeoff`. This affects drawer reconciliation when such receipts are voided.
>
> Review comment:
>
> - [P2] Subtract tolerance write-offs from cash void refunds — apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:232-232
>   For receipts paid short within the configured cash tolerance, `pos_receipt_payments.amount` is the cash actually tendered while `receipt.total` remains the full sale total and `tolerance_writeoff` stores the difference. This new `total - non_cash_sum` formula therefore over-refunds the drawer by the tolerance amount (e.g. a 100.000 sale with 99.700 cash and 0.300 tolerance records a 100.000 refund instead of 99.700), making the shift cash short after voiding these receipts.
