# Procurement Wave 5 — Fix-Round Closure Verification

Scope: closure verification (NOT a fresh review) of the Wave 5 FIX ROUND in the
uncommitted working tree at `apps/erp.procurement-v2`. Each source-review finding is
checked for: (a) fix real in code (file:line), (b) a test that exercises the TRUE
failure mode, (c) no regression. Nothing committed.

## Verdict: CLOSED

All five treasury findings (W5T-1..W5T-5) and four inventory findings (W5I-1..W5I-4)
are resolved in code with genuine failure-mode tests. Binding resolution for
W5T-2/W5I-2 (spec §2.5 Rev 3.2 paid/free split + `free_quantity_invoiced` column) is
implemented exactly. Targeted suites pass on this tree:
- `SupplierInvoiceMatcherReceiptBasisTest` + `SupplierInvoiceReceiptClearingTest` +
  `SupplierInvoiceSnapshotTest` + `SupplierCreditNoteGlTest` → 39 passed, 1 skipped.
- `SupplierInvoiceGlTest` + `RematchDraftsCommandTest` + `SupplierInvoiceMatcherTest` +
  `PurchaseBonusGoodsReceiptTest` → 41 passed (regression clean).

---

## W5T-1 [was CRITICAL] Credit-note ↔ receipt-line ledger desync — CLOSED
- Fix real: `SupplierCreditNotePostingService.php:151-158` locks receipt lines
  (`orderBy('id')`, `lockForUpdate`); `:181-184` runs GoodsReturn decrement;
  `:411-452` `guardAndDecrementGoodsReturn` decrements receipt-line `quantity_invoiced`
  via `decrementReceiptLineInvoiced` (`:457-509`, LIFO `created_at desc, id desc`) then
  re-derives the PO counter from `receiptLedgerSum` = `SUM(quantity_invoiced)`
  (`:432,514-519`). Bonus mirror at `:529-583` on `free_quantity_invoiced`.
- Posting re-derives PO counters from the same receipt-line sums
  (`SupplierInvoicePostingService.php:156-158,204-206`), so the return decrement is no
  longer silently overwritten.
- True failure mode: `SupplierCreditNoteGlTest::test_goods_return_reopens_receipt_line_window_and_reinvoice_posts_cleanly`
  (:507-536) asserts ledger==counters at EACH step — after CN (PO 3.0000 == RL 3.0000,
  :522-523), after re-invoice (5.0000 == 5.0000, :528-529), after idempotent CN re-post
  (JE count 1, 5.0000 == 5.0000, :533-535). Without the receipt-line decrement the
  re-invoice (:526) would throw "insufficient receipt-line quantity" or the RL assert
  (:523) would read 5.0000 — so the test genuinely fails on the old code path.
- Posting can no longer silently reverse a credit note: the idempotent re-post is a
  no-op (JE count == 1, counters unchanged).

## W5T-2 / W5I-2 [was Important] free_qty paid-vs-free split — CLOSED
- Fix real (Rev 3.2): planner `matchableQty` is paid-only `received_qty - quantity_invoiced`
  (`ReceiptLineConsumptionPlanner.php:62-68`); `freeMatchableQty` is separate
  (`:75-81`). Matcher paid ceiling sums the paid window (`SupplierInvoiceMatcher.php:148-171`);
  bonus window sums `freeMatchableQty` (`:413-431`). Posting paid guard caps at
  `received_qty` (`SupplierInvoicePostingService.php:315-326`); free guard caps at
  `free_qty` (`:376-387`). Matcher window == posting guard window — the matcher-OK /
  posting-throw split is GONE.
- True failure mode:
  - `SupplierInvoiceMatcherReceiptBasisTest::test_paid_matchable_excludes_free_receipt_window_and_bonus_window_is_separate`
    (:107-135) — paid matchable == 1.0000 (free excluded), over-paid qty 2 → QuantityVariance,
    bonus qty 2 → Matched.
  - `SupplierInvoiceReceiptClearingTest::test_paid_invoice_cannot_consume_free_receipt_window`
    (:176-215) — matcher QuantityVariance AND posting throws (they agree); 0 JE, both
    receipt-line and PO paid/free counters stay 0 → 408 never over-clears.
  - `..::test_bonus_line_consumes_free_receipt_window_without_overclearing_408` (:217-268)
    — 408 nets 0.000, free consumption lands on `free_quantity_invoiced` (RL 2.0000, PO
    2.0000) only, paid `quantity_invoiced` 10.0000.
- Storage: migration `2026_07_04_120000_add_free_quantity_invoiced_to_goods_receipt_lines.php`
  adds `decimal(15,4)` default 0; model fillable/cast present (`GoodsReceiptLine.php:75,95`).

## W5T-3 [was Important] receipt-grain over-clear untested — CLOSED
- Fix real: guard at `SupplierInvoicePostingService.php:315-326` (per receipt line) and
  `:334-341` (insufficient remaining).
- True failure mode: `SupplierInvoiceReceiptClearingTest::test_receipt_grain_overclear_rejects_and_rolls_back`
  (:270-313) — invoice 1 bills 4 of a 5-received receipt line via the receipt-grain path
  (a receipt line EXISTS, so it is not the legacy branch); invoice 2 billing 2 pushes the
  RL past 5 → throws + full rollback (0 JE for #2, RL 4.0000, PO 4.0000, still Draft).

## W5T-4 / W5I-1 [was Important/Minor] snapshot-vs-live never distinguished — CLOSED
- Fix real: clearing reads live `accrual_unit_cost` (`SupplierInvoicePostingService.php:300-311`),
  match status reads the frozen `price_match_basis` snapshot
  (`SupplierInvoiceMatcher.php:538-566`).
- True DIVERGENCE (numbers really differ):
  - `SupplierInvoiceSnapshotTest::test_snapshot_prevents_reclassification_when_later_receipt_changes_live_basis`
    (:161-190) — stamp snapshot 5.200000, then mutate the consumed RL `accrual_unit_cost`
    to 6.000000; matcher stays Matched on the snapshot (live 6.000 at qty 100 vs invoice
    5.200 would exceed both the 2% and 10.000 tolerance → PriceVariance), and `rematch-drafts`
    refreshes to 6.000000 → PriceVariance.
  - `SupplierInvoiceReceiptClearingTest::test_clearing_uses_live_receipt_accruals_not_match_snapshot`
    (:315-343) — snapshot 5.200 vs live accrual 6.000; 408 clears at LIVE 600.000 (not
    snapshot×qty 520.000), PPV income 80.000, net-408 0.000, status Matched (snapshot).

## W5I-3 [was Important] lock/concurrency test missing — CLOSED (documented limitation)
- Deterministic lock ordering is in code (`SupplierInvoicePostingService.php:76,85-87`
  PO lines then receipt lines, both `orderBy('id')` + `lockForUpdate`). The suite runs on
  sqlite where `FOR UPDATE` is a no-op; `SupplierInvoiceReceiptClearingTest::test_receipt_line_lock_contention_requires_postgresql`
  (:345-352) is pgsql-gated and skips on sqlite with an explicit message — this matches the
  reviewer's own accepted alternative ("or document the limitation explicitly"). Residual:
  real row-lock contention remains unexercised in the default (sqlite) CI run.

## W5T-5 [was Minor] rematch "fleet-wide" docblock overstated — CLOSED
- `RematchDraftSupplierInvoicesCommand.php:18-23` now reads "Single tenant connection per
  invocation; fleet-wide operation is an external operator loop." `--dry-run` still writes
  nothing; scale resolution is currency-explicit (rule 19 clean).

## W5I-4 [was Minor] snapshot stamped on bonus lines — CLOSED
- `CreateSupplierInvoiceService.php:150-156` skips `matchSnapshotAttributes` for bonus
  lines (`price_match_basis`/`matched_receipt_line_id` forced null). Proven by
  `SupplierInvoiceSnapshotTest::test_creation_skips_price_snapshot_for_bonus_lines` (:125-159).

---

## What to fix before merge
Nothing blocking. Optional follow-up: add a Postgres-only receipt-line contention test so
the FOR UPDATE serialization is exercised in CI rather than only documented (W5I-3).
