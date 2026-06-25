# M-5 Fallback Review — Partner Liability Magnitude Guardrails

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: deploy safety, data normalization, DB bypasses, and convention consistency.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- Direct SQL bypass is covered: PostgreSQL tests update each constrained column directly and expect a `QueryException`.
- Domain bypass is narrowed: Eloquent `saving` rejects negative cached liability magnitudes on create/update, before the database constraint is reached.
- Deployment safety is addressed: stale negative cache values are reset to zero and marked stale via `balance_updated_at = NULL` before constraints are added.
- The sign convention remains coherent: `credit_balance` and `payable_balance` are non-negative magnitudes; `net_balance` still subtracts customer credit from receivables.
- Constraint names are stable and tested: `partners_credit_balance_non_negative` and `partners_payable_balance_non_negative`.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
