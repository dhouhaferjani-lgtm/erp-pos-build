# H-2.2 Customer-Advance Posting — Codex Review

Diff reviewed: `.superpowers/review-packages/h2-customer-advance-posting.diff`

Acceptance slice:
- Live customer advance/prepayment GL entries should post when a real user/actor exists.
- Partner credit balance refresh should happen only after posting so `PartnerBalanceService` sees posted ledger rows.
- Legacy callers that omit explicit currency should remain safe outside a bound `CompanyContext`.
- Posting and balance events must not leak from rolled-back outer transactions.

Findings:

1. HIGH — Legacy callers omitting `currencyCode` could still fail or leave stale credit balances when no `CompanyContext` was bound. `postEntry()` needs a currency to resolve scale, and the first implementation passed nullable currency through to posting.

Resolution:
- Added `currencyCodeForCompany()` and routed posted customer advance/payment received flows through `postEntryAndRefreshPartnerBalance()`, which uses supplied currency when present and falls back to the company's stored currency.
- Added `GLIntegrationTest::test_customer_advance_omitted_currency_posts_without_company_context`.

Status: resolved.
