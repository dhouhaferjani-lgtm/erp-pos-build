## R-1 Opus Adversarial Re-Review — Revert of the supplier-AP recognition pair (H-3.2 `53115efaa` + H-3.1 `407cea426`)

### Verdict: ✅ **APPROVE**

The revert was executed **surgically** (hand-edited, not raw `git revert`), which is exactly what the pre-review flagged as mandatory. Every "highest-risk" failure mode the pre-review predicted was avoided. The executor correctly **deviated from the worklist's literally-unsound acceptance criteria** (kept the pre-existing GL methods rather than deleting them on a "zero references" grep). All five focus areas are clean.

*Verification is static-only:* I did not run the scoped tests / PHPStan / Pint (no run permission + full-suite laptop guard). Findings below are from diff + cross-file inspection.

### Findings by severity

**Blocker / High:** none.

**Medium:** none.

**Low / Observations (non-blocking):**

- **L-1 — Worklist acceptance criterion is wrong, implementation is right (no action on the diff).** The worklist demands *"git grep shows no remaining `createSupplierInvoiceJournalEntry` references."* The executor correctly **kept** both `createSupplierInvoiceJournalEntry` (GLService:459) and `createSupplierPaymentJournalEntry` (:544) — they pre-date the pair and are independently exercised by `GLIntegrationTest`. `GeneralLedgerService.php` is **untouched** by the diff (confirmed empty in `--name-only`), so there is **no silent-Draft regression**: both methods still funnel through `postEntryAndDispatchPostedEventAfterCommit`. The over-revert trap was avoided. Recommend amending the worklist text to target `PurchaseOrderConfirmedListener` + the controller branch, not the method name — so the final remediation pass doesn't "re-open" this.

- **L-2 — Economically-backwards supplier-PO payment is the *accepted* baseline, not an R-1 defect — but flag it so a later sweep doesn't misread the green test.** `PaymentTest::test_purchase_order_payment_uses_document_payment_flow_without_supplier_payable_gl` asserts that paying a supplier PO **increments** repository cash (`1000.000 → 1600.000`) and posts a `customer_payment` entry (`Dr Bank / Cr AR` against the supplier-as-partner). That is cash-direction-backwards for a supplier payment — but it is precisely the pre-Codex state the OWNER DECISION explicitly tolerates and defers to the GR-IR redesign (`payable_balance` stays `0.000`, no `supplier_payment` row, M-5 `>= 0` CHECK untripped). Not a regression introduced by R-1. Worth one line in the reintroduce-later ticket so the passing test isn't later cited as "supplier payments are correct."

### Focus-area checklist (all pass)

| Concern | Result |
|---|---|
| Incomplete revert | ✅ Full `407` controller hunk reverted — `MIXED_PAYMENT_DIRECTIONS` + `SUPPLIER_PAYMENT_REQUIRES_FULL_ALLOCATION` guards, `match`→`SupplierPayment` typing, cash-sign flip (back to `bcadd`), GL ternary (back to `createPaymentReceivedJournalEntry`), and the excess `!== SupplierPayment` guard all removed. Not just the GL ternary (the pre-review's "second risk"). |
| Over-revert | ✅ GL methods kept; `PaymentType::SupplierPayment` case + `Payment::isSupplierPayment()` (Payment.php:322) kept; `PurchaseOrderConfirmed` event class + its dispatch (PurchaseOrderService.php:109) kept (Rule 8). |
| Dangling refs / autoload | ✅ `PurchaseOrderConfirmedListener` deleted; EventServiceProvider mapping + both imports removed; **zero** remaining refs in `app/`. `DocumentType` import drop is safe — only `$document->type->value` (enum-instance access) remains, no static `DocumentType::` reference. No orphaned imports in either trimmed test (`JournalEntry`/`PurchaseOrderConfirmed` still used in POServiceTest; `SystemAccountPurpose`/`DocumentType`/`PaymentType` still used in PaymentTest). |
| Tests assert wrong behavior | ✅ Tests assert the **reverted** (pre-Codex) behavior, not the old buggy behavior, and are internally coherent (balance math, `payable_balance 0`, `assertDatabaseMissing(supplier_payment)`, `customer_payment` Posted). |
| Regression to direct GL helpers | ✅ Untouched. The pre-review's acceptance check #4 was implemented: `GLIntegrationTest` now asserts `status === Posted` **and** non-null `fiscal_hash` on both supplier methods — the exact tripwire for a dropped dispatch. |

### Required fixes
**None blocking.** Optional: amend the R-1 worklist acceptance wording (L-1) and add a one-line note to the GR-IR reintroduce ticket (L-2). Suggest the final remediation pass run the scoped trio (`GLIntegrationTest`, `PurchaseOrderServiceTest`, `PaymentTest`) + PHPStan L8 + Pint to convert my static "plausible-clean" into observed-green.
