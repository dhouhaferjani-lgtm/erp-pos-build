# Codex Review — H-2.4 Supplier Advance Refund Posting

Date: 2026-06-22

Scope:
- `GeneralLedgerService::reverseSupplierAdvanceJournalEntry()`
- `VendorRefundService::refundPrepayment()`
- vendor prepayment refund coverage

Verdict: CLEAN.

Findings:
- No blocker/high issues found.

Acceptance criteria check:
- A vendor prepayment refund with a repository accounting account now creates a `supplier_advance_refund` journal entry and posts it with `posted_by`, `posted_at`, and fiscal hash.
- The actor lookup happens before journal creation, so an invalid actor id does not leak a draft journal entry outside an outer transaction.
- `VendorRefundService` passes the refund actor id and payment currency to the GL helper.
- Actorless legacy calls preserve the previous draft-only behavior.

Residuals:
- Customer-advance clearing remains a separate H-2 draft-producing residual.

Verification reviewed:
- Red observed first: `php artisan test tests/Feature/Treasury/VendorPrepaymentRefundTest.php --filter test_refund_prepayment_posts_supplier_advance_reversal_entry`
- Green: `php artisan test tests/Feature/Treasury/VendorPrepaymentRefundTest.php tests/Feature/Treasury/VendorRefundScalingTest.php`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Domain/Services/VendorRefundService.php`
- Pint and `git diff --check`
