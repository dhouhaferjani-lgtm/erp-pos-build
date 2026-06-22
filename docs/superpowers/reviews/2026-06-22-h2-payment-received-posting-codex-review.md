# H-2.1 Payment-Received Posting — Codex Review

Diff reviewed: `.superpowers/review-packages/h2-payment-received-posting.diff`

Acceptance slice:
- Customer payment-received GL entries in production should be posted when a real user/actor is supplied.
- Partner balance refresh should happen only after posting so `PartnerBalanceService` sees posted ledger rows.
- Backward-compatible draft-only callers should remain supported until their flow is handled separately.

Findings:

1. P1 — `applyAllocationFromCommand()` could fail in non-HTTP production paths because `createPaymentReceivedJournalEntry()` posted without an explicit currency. Projection callers can supply a real actor while running without bound `CompanyContext`, and `postEntry()` requires a currency in that context.

2. P2 — The first implementation inserted `?User $user` before the existing `?string $description` parameter, breaking any positional caller that passed a description as argument 7.

Resolution:
- Kept the original positional description slot and added optional `user` and `currencyCode` after it.
- Passed payment currency into payment-received posting from `PaymentAllocationService` and `PaymentController`.
- Added verification through `GLIntegrationTest` and the scoped treasury payment tests.

Status: resolved.
