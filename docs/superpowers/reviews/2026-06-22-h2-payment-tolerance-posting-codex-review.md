# Codex Review — H-2.3 Payment Tolerance Posting

Date: 2026-06-22

Scope:
- `GeneralLedgerService::createPaymentToleranceJournalEntry()`
- `CloseInvoiceWithToleranceService`
- `PaymentToleranceService`
- `PaymentAllocationService`
- close-with-tolerance and allocation tolerance tests

Verdict: CLEAN after remediation.

Findings:
- HIGH resolved before commit: initial draft moved actor lookup after journal creation, which could have leaked a draft `payment_tolerance` entry if a caller supplied an invalid actor id outside an outer transaction. Remediation moved `User::findOrFail()` before journal creation, so invalid actor ids fail before any GL row is inserted.

Acceptance criteria check:
- Close-with-tolerance GL entries are posted with `posted_by`, `posted_at`, and fiscal hash when the endpoint/service has a real actor.
- Smart-payment allocation tolerance GL entries are posted when `PaymentAllocationService` resolves a valid command actor.
- Actorless legacy/replay calls still preserve existing draft-only behavior.
- Explicit currency is passed into posting paths, avoiding request-scoped currency resolution during queued/replay contexts.

Residuals:
- Supplier-advance and customer-advance-clearing draft paths remain separate H-2 residual items and are documented in the work-list.

Verification reviewed:
- `php artisan test tests/Feature/Modules/Document/CloseInvoiceWithToleranceEndpointTest.php tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php tests/Unit/Treasury/PaymentAllocationServiceToleranceContractTest.php tests/Unit/Treasury/PaymentToleranceServiceTest.php`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Treasury/Application/Services/PaymentToleranceService.php`
- Pint and `git diff --check`
