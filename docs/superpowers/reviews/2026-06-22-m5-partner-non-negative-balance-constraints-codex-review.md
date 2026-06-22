# M-5 Codex Review — Partner Non-Negative Balance Constraints

Date: 2026-06-22
Scope:
- `Partner` cached liability guard
- tenant migration `2026_06_22_140000_add_partner_non_negative_balance_constraints.php`
- `PartnerMoneyPrecisionTest` constraint/domain coverage

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- The model rejects negative `credit_balance` and `payable_balance` writes before persistence.
- PostgreSQL migration adds named CHECK constraints for both cached liability magnitude columns.
- Migration normalizes any pre-existing negative cached values to zero and clears `balance_updated_at` before adding constraints, which avoids deploy failure on stale cache rows while preserving GL as source of truth.
- PostgreSQL tests verify constraint metadata and direct DB rejection for both `credit_balance` and `payable_balance`.
- `receivable_balance` is intentionally not constrained here; the M-5 claim is about non-negative cached liability magnitudes.

## Residual Risk

- The migration does not recalculate normalized cache rows from GL. It clears `balance_updated_at`; the normal partner-balance refresh path remains responsible for recalculation.
