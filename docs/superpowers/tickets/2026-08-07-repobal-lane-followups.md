# Repo-balance lane (W-5b) — follow-ups from implementation + gate (2026-08-07)

Source: docs/superpowers/reviews/2026-08-07-l5-repobal-gate.md on `fix/l5-negative-repo-balance`
(travels with the branch). In-lane items are in the fix round; these are parked.

## 1. FE toggle for `allow_negative` (P2 — completes the owner ruling's surface)

The backend write path (store/update validation, explicit value wins over type derivation)
ships in-lane per orchestrator ruling. The FE repositories settings surface needs the toggle
(boolean switch, admin-gated like the sibling repository settings), plus the generated-types
regen. Until then the field is API-settable only.

## 2. Treasury alert upgrade for negative-balance events (P3)

In-lane mechanism is structured `Log::warning` in the movement port's afterCommit. The gate
corrected the lane's claim: `TreasuryAlertNotification` IS live (6 call sites —
ReconcileTreasuryCommand ×3, InstrumentMaturityAlertsCommand ×2,
GenerateRecurringExpensesCommand ×1). Consider emitting it (or a digest) when an
allowNegative-flagged movement lands a repo negative, so ops sees it without log-grepping.
Hot-path concern: send from a queued listener, not inside record().

## 3. `BankStatementAggregateSchemaTest` hardcodes `migrate:rollback --step 6` (P2, pre-existing trap)

Assumes no migration ever lands after the bank-statement ones — ANY new tenant migration
shifts the window and fails it (the W-5b migration trips it today; the next lane will too).
Fix: target the rollback by migration name/batch, not a fixed step count.

## 4. `PaymentRepository::create(['balance' => …])` silently drops balance (P2, test-fixture trap sweep)

`balance` is port-managed and not `$fillable`; two suites (VendorPrepaymentRefundTest,
PaymentGlPostingTest) had never actually funded their repositories — invisible until the new
guard. Fixed in-lane by funding through the movement port. Sweep remaining tests/seeders for
the same pattern (`grep "balance' =>" tests/ database/`), and consider a model-level guard
(throw on balance in create attributes in non-production) so the trap is loud.

## 5. Already-negative production repositories (pre-deploy checklist line)

After deploy, repos with `balance < 0 AND allow_negative = false` (blocked types) will refuse
further outflows until topped up or explicitly allowed. Detection SQL lands in the lane's
commit body; run per tenant pre-deploy alongside the discount lane's negative-line query.
