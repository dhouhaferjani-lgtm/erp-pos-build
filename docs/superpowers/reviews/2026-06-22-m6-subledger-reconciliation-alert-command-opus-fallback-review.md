# M-6 Fallback Review — Scheduled Subledger Alerting

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: scheduler reliability, tenant isolation, alert signal quality, and accidental repair behavior.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- Tenant isolation is explicit: the command only reaches companies through a tenant-scoped query inside `forEachTenant()`.
- The command does not repair or mutate balances; it only calls `reconcileSubledger()` and reports/logs discrepancies.
- The reported line includes `entries_without_partner`, satisfying the specific audit concern.
- The scheduler uses `withoutOverlapping()` and `runInBackground()` like neighboring scheduled commands.
- Invalid configured purpose values fail fast with `INVALID`, avoiding silent misconfiguration.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
