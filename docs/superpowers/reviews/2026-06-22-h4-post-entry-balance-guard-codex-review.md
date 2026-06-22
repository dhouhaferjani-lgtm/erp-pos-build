# H-4 Post Entry Balance Guard — Codex Adversarial Review

Date: 2026-06-22  
Scope reviewed: `GeneralLedgerService::postEntry()` and `GLIntegrationTest` unbalanced-post coverage.  
Review mode: refute the patch against mutation ordering, event correctness, valid posting regressions, and currency precision.

## Verdict

PASS.

## Findings

### No BLOCKER/HIGH findings

The balance comparison now happens after the draft-status check and before fetching the previous hash, calculating a fiscal hash, updating status fields, or emitting `JournalEntryPosted`. The new regression test asserts the unbalanced entry remains `Draft`, hash/posting metadata remains null, and no posting event is dispatched.

The event payload now reuses the same debit/credit totals that were validated before mutation, avoiding divergent calculations between the guard and the event.

### LOW — The guard accepts empty zero-total entries

Status: DOCUMENTED, not remediated in H-4.

An entry with no lines, or only zero lines, has equal debit and credit totals and would pass this balance-specific guard. H-4's acceptance criteria only required rejecting unbalanced entries; line-count/non-zero policy should be handled as a separate journal-validity rule if the domain wants to forbid empty postings.

### LOW — Existing fixture exposed an inconsistent draft invoice setup

Status: REMEDIATED for the posting path.

`test_posting_journal_entry_adds_hash()` previously created an invoice with `total = 120.00` while the generated document line remained `100.00` with no tax. That produced an unbalanced journal entry once posting started enforcing debit/credit equality. The fixture now uses `total = 100.00` so the test continues to verify the successful posting/hash path.

## Verification Reviewed

- `php artisan test --filter test_posting_unbalanced_journal_entry_is_rejected_without_mutation`
- `php artisan test --filter test_posting_journal_entry_adds_hash`
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Accounting/DocumentGLIntegrationTest.php tests/Feature/Accounting/GeneralLedgerHashServiceTest.php`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Accounting/GLIntegrationTest.php`
- `git diff --check`

## Residual Risk

The guard uses the explicit `currencyCode` scale when provided, otherwise it falls back to `CompanyContext` currency scale. That matches existing posting behavior and the H-2/H-3 production callers now pass explicit currency for queued/no-context posting paths. Legacy direct callers without a bound company context still rely on the default scale path.
