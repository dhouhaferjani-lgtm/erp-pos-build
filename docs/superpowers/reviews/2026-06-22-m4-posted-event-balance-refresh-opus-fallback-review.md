# M-4 Fallback Review — Transaction/Event Balance Refresh

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: rollback behavior, duplicate refresh risk, event semantics, and remaining synchronous refresh sites.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- Duplicate refresh risk is controlled: `GeneralLedgerService` post helpers no longer call `PartnerBalanceService` directly, so `JournalEntryPosted` listener registration does not double-refresh customer advances, supplier invoices, supplier payments, or customer payment-received entries.
- Draft pollution risk is reduced: invoice, credit-note, generic payment, supplier-advance reversal, tolerance, and customer-advance clearing builders return draft entries without touching cached balances.
- Rollback behavior is covered for both categories:
  - Event-driven post paths retain existing after-commit behavior for outer transactions.
  - Direct posted document paths now roll back invoice/credit-note journal entries and lines if partner balance refresh fails.
- Listener payload stability is preserved: `JournalEntryPosted` did not grow partner IDs, so historical event/audit payload expectations are unchanged.
- The remaining direct `refreshPartnerBalance()` in `createPOSChargeEntry()` is intentional and transactionally equivalent; POS/fiscal bridge tests assert refresh is called and failure prevents persisted journal rows.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
