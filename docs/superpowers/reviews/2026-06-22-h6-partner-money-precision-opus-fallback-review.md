# H-6 Partner Money Precision — Opus Fallback Review

Date: 2026-06-22
Reviewer: Codex, second independent pass
Lens: Migration safety, API compatibility, and data-contract drift.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Adversarial Checks

- Migration ordering is safe for fresh tenants: the original balance/B2B migrations still create 4-decimal columns, and the later H-6 tenant migration narrows all four partner money fields to scale 3.
- PostgreSQL-specific schema behavior is covered by `PartnerMoneyPrecisionTest`, which asserts `information_schema.columns.numeric_precision = 15` and `numeric_scale = 3` for all four columns.
- API compatibility impact is intentional: partner create/update requests now reject 4-decimal credit limits, and tests cover both create and update rejection.
- POS and partner-list contract drift is covered by updated feature tests expecting 3-decimal partner-money strings.
- The change stays inside partner money fields; Treasury tolerance writeoffs and existing ledger reconciliation statement outputs remain outside this item.

## Residual Notes

- True cross-model Opus review remains pending for owner spot-check.
