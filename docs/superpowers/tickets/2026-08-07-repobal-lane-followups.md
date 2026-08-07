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
the same pattern, and consider a model-level guard (throw on balance in create attributes in
non-production) so the trap is loud.

**2026-08-07 R2-G-gate escalation — the sweep predicate is TOO NARROW and dev is RED:**
`grep "balance' =>"` misses fixtures that never pass balance at all.
`ExpenseVatPostingTest` has **3 reds ON origin/dev today** (`InsufficientRepositoryBalance`
from `cashRepository()` which creates an unfunded till, then drives outflows). Correct
predicate: **any test driving an OUTFLOW through the movement port from a fixture-created
repository** must fund it via the port first. ⚠️ Repair warning from the gate: funding those
fixtures re-pins four values in a treasury-reconcile golden (ExpenseVatPostingTest:381-390) —
the fix belongs to a treasury-gated change, not whatever lane happens to trip over the reds.
Fix PROMPTLY: every lane touching these suites currently has to hand-wave "3 pre-existing
failures".

## 5. Already-negative production repositories (deploy checklist lines — gate-corrected timing)

After deploy, repos with `balance < 0 AND allow_negative = false` (blocked types) will refuse
further outflows until topped up or explicitly allowed. Gate round-2 corrections:
- **Pre-deploy** (column doesn't exist yet): `SELECT ... WHERE balance < 0 AND type <> 'bank_account'`.
- **Post-`tenants:migrate`**: the lane's commit-body query (`balance < 0 AND allow_negative = false`).
- ⚠️ The natural remedy (an `in` adjustment) can ITSELF 422 on a tenant whose chart lacks the
  payment-tolerance account (pinned by
  `RepositoryAdjustmentTest::test_in_adjustment_returns_422_when_chart_lacks_payment_tolerance_account`)
  — check the account exists before prescribing that remedy in the runbook.
Run per tenant alongside the discount lane's negative-line query.
