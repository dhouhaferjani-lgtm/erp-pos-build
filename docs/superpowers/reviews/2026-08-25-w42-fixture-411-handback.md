# HANDBACK — W4-2 fixture off `411` (consolidation r2, 2026-08-25)

**Lane** `chore/w42-fixture-off-411` · worktree `.worktrees/w42-fixture-411` · base local `dev` `274594ebf` · **no production code, no migration**

**Cause.** `OpeningCashFloatSeedsRepositoryTest::test_a_debit_on_an_account_with_no_repository_is_not_examined` debited `411 Clients` / `SystemAccountPurpose::CustomerReceivable`. W4-3's `PartnerControlAccountResolver` now refuses a GL opening row on a partner control account (`AccountingOpeningService.php:725`), so the fixture tripped a rule the test was never about. Red reproduced verbatim: `RuntimeException: Account '411' is the partner control account for AR open items…`.

**Fix.** One fixture line → `4456 TVA déductible` / `SystemAccountPurpose::VatDeductible` (`:421`): not partner-control, carries a real debit balance at cutover (recoverable input VAT carried forward), and has no repository linked — so the case still exercises "an account with no repository is not examined". The docblock now says why, and warns against moving it back onto `411`/`401` **or any child of them** — the resolver walks the ancestor chain.

**Siblings checked.** Only one other hit repo-wide in the W4-2 classes: `ExpensePaidFromRepositoryTest.php:115` creates `401`/`SupplierPayable`, but never in an opening batch — it is the account `createFromExpense` resolves for an UNPAID expense. Correct as-is, left alone; that class is green.

**Evidence.** sqlite `OpeningCashFloatSeedsRepositoryTest` **15 passed / 73 assertions**; PostgreSQL 16 throwaway `autoerp_test_w42f` @ `127.0.0.1:5433` **15 passed / 73 assertions** (dropped after). Siblings on sqlite (`ExpensePaidFromRepositoryTest`, `ExpensePostTest`, `ExpenseIdempotencyTest`, `OpeningBalancePreviewContractTest`, `UpcomingPaymentsTest`) **22 passed / 1 skipped**. Pint `--dirty` pass. Diff: 1 file, +17/−4, test-only.
