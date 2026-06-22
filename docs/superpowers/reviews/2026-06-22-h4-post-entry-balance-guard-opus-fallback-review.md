# H-4 Post Entry Balance Guard — Opus Fallback Review

Date: 2026-06-22  
True Opus review: PENDING; not reachable from this runtime.  
Fallback mode: independent second adversarial pass focused on ordering, event immutability, hash-chain safety, and precision boundaries.

## Verdict

PASS for H-4.

## Findings

### No BLOCKER/HIGH findings

The patch fails closed before any irreversible posting metadata is written. Because the balance check precedes hash-chain lookup and status mutation, a rejected unbalanced entry does not consume a fiscal hash position or emit a misleading `JournalEntryPosted` event.

The test covers the important failure semantics: exception message, unchanged draft status, unchanged fiscal metadata, unchanged actor/timestamp, and no posting event dispatch. The existing valid posting regression confirms the hash path still succeeds when the generated journal entry is balanced.

### LOW — Balance equality is necessary but not a complete journal validity contract

Status: DOCUMENTED.

This slice hardens the specific missing debit/credit comparison. It does not add broader journal validation such as requiring at least two non-zero lines, forbidding same-side zero-only entries, or checking account activity. Those are separate posting-policy questions and should not be mixed into this H-4 fix without a wider audit.

### LOW — Precision behavior depends on caller-supplied currency context

Status: ACCEPTED.

The comparison uses the same scale selection as the event total calculation: explicit currency when passed, otherwise company-context currency scale. Recent production posting paths pass currency explicitly where queued/no-context execution mattered. Remaining legacy direct callers retain the service's previous context/default behavior.

## Verification Reviewed

- Red first: the unbalanced-post test failed because the entry posted instead of throwing.
- Green: `php artisan test --filter test_posting_unbalanced_journal_entry_is_rejected_without_mutation` passed 1 test, 7 assertions.
- `php artisan test --filter test_posting_journal_entry_adds_hash` passed 1 test, 4 assertions.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Accounting/DocumentGLIntegrationTest.php tests/Feature/Accounting/GeneralLedgerHashServiceTest.php` passed 40 tests, 160 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- PHPStan L8, Pint `--test`, and `git diff --check` passed.
