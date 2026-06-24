I now have a complete, evidence-backed picture. Here is my adversarial review.

---

# R-1 Adversarial Review — Revert the supplier-AP recognition pair (H-3.2 `53115efaa` + H-3.1 `407cea426`)

## Verdict

**Reverting both commits is the correct DECISION, but the worklist's prescription for HOW is technically unsound and will cause an over-revert plus a silent regression if executed literally.** Revert the *wiring and tests the pair introduced* — do **not** `git revert` the commits wholesale, and do **not** delete the GL methods or their pre-existing tests.

Three facts the worklist gets wrong, all verified against current HEAD:

1. **`createSupplierInvoiceJournalEntry` and `createSupplierPaymentJournalEntry` PRE-DATE the pair.** Both exist at `407cea426~1` (GLService:440/525). The pair only added a `?string $currencyCode` param and swapped the post-commit call. The acceptance criterion *"git grep shows no remaining `createSupplierInvoiceJournalEntry` references"* would force deleting a pre-existing method → **over-revert**.

2. **`GLIntegrationTest::test_supplier_invoice_creates_correct_journal_entry` / `_payment_` also pre-date the pair** (present at `407cea426~1`:452). They directly exercise the two methods and are **not** in either commit. R-1's file list omits `GLIntegrationTest.php` entirely. A literal "zero references" revert breaks them; the correct action is to **keep them untouched**.

3. **The GLService post-commit line has drifted since the pair.** All ~12 GL creators now funnel through `postEntryAndDispatchPostedEventAfterCommit` (lines 371/446/533/599/670/780/879/1134/1613/1691). `53115efaa` introduced the older `postEntryAndRefreshPartnerBalanceAfterCommit`; before that it was `partnerBalanceService->refreshPartnerBalance`. A raw `git revert` will **conflict on this line and, if resolved to the commit's "old" side, regress supplier-invoice/payment entries to Draft-only with no `JournalEntryPosted` dispatch** — and **no existing test catches it** (the GLIntegration supplier tests assert lines/amounts only, never `status === Posted` or `fiscal_hash`).

## Required changes (exact)

**Remove (the wiring/behavior the pair introduced):**
- `apps/api/app/Modules/Accounting/Listeners/PurchaseOrderConfirmedListener.php` — delete (new file in `53115efaa`).
- `EventServiceProvider.php` — remove the `PurchaseOrderConfirmed => [PurchaseOrderConfirmedListener]` mapping (lines 68–70) and the two now-unused imports (lines 9, 15). **Keep** the `PurchaseOrderConfirmed` event class and its dispatch in PO `confirm()` (Rule 8 — events immutable; an unheard event is harmless).
- `PaymentController::store()` — revert the **entire** `407cea426` controller hunk, not just the GL ternary: the `DocumentType::PurchaseOrder` allocation detection, the `MIXED_PAYMENT_DIRECTIONS` + `SUPPLIER_PAYMENT_REQUIRES_FULL_ALLOCATION` 422 guards, the `match`→`PaymentType::SupplierPayment` typing (line 236), the repository cash-sign flip (line 389), the GL ternary (line 423), and the excess-advance `!== SupplierPayment` guard (line 457). Target = the pre-`407` customer-only flow (unconditional `createPaymentReceivedJournalEntry`, `bcadd` increment, `DocumentPayment`/`Advance` typing).
- `PurchaseOrderServiceTest.php` — remove `test_confirmation_posts_supplier_invoice_gl_and_refreshes_payable_balance` and `test_purchase_order_confirmed_listener_is_idempotent` + their added imports (`JournalEntryStatus`, `JournalEntry`, `PurchaseOrderConfirmedListener`). **Keep** `test_dispatches_purchase_order_confirmed_event`.
- `PaymentTest.php` — remove `test_purchase_order_payment_posts_supplier_payment_gl` and `test_purchase_order_payment_rejects_unallocated_excess`.

**Keep (do NOT remove — these predate the pair or are unrelated drift):**
- Both GL methods and `GLIntegrationTest` supplier tests (optionally drop the now-unused `currencyCode` param symmetry, but simplest/safest is to leave the methods exactly as they are at HEAD).
- The current `postEntryAndDispatchPostedEventAfterCommit` body of both methods — **must not** revert to `refreshPartnerBalance`.
- `PaymentType::SupplierPayment` enum case + `Payment::isSupplierPayment()` (predate the pair; referenced by the domain model at Payment.php:299/322).
- M-5, M-7, M-6, M-3.

## Acceptance checks that would catch an incomplete/incorrect revert

1. **No incoherent controller state:** `git grep -n 'PaymentType::SupplierPayment' PaymentController.php` → **0 hits**; but `git grep` in `PaymentType.php` + `Payment.php` → **still hits** (proves no over-delete of the enum/helper).
2. **Wrong-direction guard:** a feature test paying against a `PurchaseOrder` allocation must post the **pre-Codex** result (a `createPaymentReceivedJournalEntry`-shaped entry / `DocumentPayment` type, repository **incremented**) and must **not** drive `partners.payable_balance` negative or trip the M-5 `>= 0` CHECK. Assert no `source_type = 'supplier_payment'` row is created.
3. **No PO-confirm GL:** confirming a PO creates **zero** `journal_entries` with `source_type = 'supplier_invoice'`, and still dispatches `PurchaseOrderConfirmed` (existing test stays green).
4. **GL methods still POST (regression tripwire the current tests lack):** assert `createSupplierInvoiceJournalEntry`/`_payment_` return `status === Posted` **and** non-null `fiscal_hash` — this is the only check that catches a bad conflict-resolution dropping the dispatch helper.
5. **Dangling refs:** `git grep PurchaseOrderConfirmedListener` → only the (kept) event class file, nothing in `app/`/`Providers`; `composer dump-autoload` clean.
6. PHPStan L8 + Pint clean on the 4 touched code files; scoped run of `GLIntegrationTest`, `PurchaseOrderServiceTest`, `PaymentTest` green (no full suite — laptop guard).

## Risks / skeptical flags

- **Highest risk — silent Draft regression** via raw `git revert` conflict resolution on the GLService post-commit line (see Verdict #3). Existing supplier GL tests will stay green while broken. Mitigated only by acceptance check #4.
- **Second risk — partial controller revert** (reverting only the GL ternary, as R-1's prose literally says "supplier-payment branch"): leaves `PaymentType::SupplierPayment` typing + cash **decrement** in place while GL posts a customer-style `Dr Bank / Cr AR`. Result: repository cash down, GL says cash up + AR credited — **worse than both pre- and post-Codex**. Must revert the *whole* `407` controller hunk.
- **Over-revert risk** from the "zero `createSupplierInvoiceJournalEntry` references" acceptance: would delete a pre-existing, independently-tested method and capability the worklist itself wants to "re-introduce later." Correct the acceptance wording to target `PurchaseOrderConfirmedListener` + the controller branch, not the method name.
- **Do NOT touch:** `PurchaseOrderConfirmed` event class, `PaymentType::SupplierPayment`, `Payment::isSupplierPayment()`, the unified `postEntryAndDispatchPostedEventAfterCommit` helper, or any M-3/5/6/7 code.
- **Coupling claim is sound:** reverting H-3.2 alone would indeed leave H-3.1 debiting an uncreated payable → negative balance → M-5 rejects supplier payments. Pairing is correct. The post-revert state (PO payment behaves as an ordinary customer-style document payment, `payable_balance` stays 0) is self-consistent and tolerated by the kept M-5 constraint.
- **Minor:** confirm `PaymentType::SupplierPayment` isn't surfaced to the frontend as a now-unreachable option (out of R-1 scope, but worth a one-line note so it isn't mistaken for dead code in a later sweep).
