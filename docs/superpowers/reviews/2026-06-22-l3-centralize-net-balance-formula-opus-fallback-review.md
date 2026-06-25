# L-3 Centralize Net-Balance Formula — Opus Fallback Review

opus-review: PENDING

## Fallback Review Lens

Because a true Opus reviewer is not reachable from this runtime, this independent pass focuses on API compatibility, sorting semantics, formula divergence risk, and frontend precision behavior.

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW findings.

The list endpoint still sorts by the SQL-selected `net_balance` alias, so existing sort behavior is preserved. The added response assertion confirms the API includes the computed value. Frontend rendering now uses the API-provided decimal string when present, which meets the "consume DTO value where possible" requirement and reduces duplicated frontend formula dependency. Fallback calculation remains for compatibility with older fixtures or partial payloads and now uses `bcsub` rather than JS floating-point arithmetic.

## Residual Notes

The SQL expression and PHP calculation are both housed on `Partner`, but they remain separate implementations because one targets SQL sorting/selection and the other targets PHP accessors. The adjacent tests cover the important parity cases for customer and both-type partners. A future broader cleanup could introduce a dedicated query-builder helper if more SQL consumers appear.

