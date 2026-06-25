# M-8 Fallback Review — Customer Advance Clearing Cap

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: over-application correctness, draft lifecycle, precision, and concurrency.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- The original bug is covered by a red/green test: a posted `40.000` customer advance can no longer be cleared for `60.000`.
- Draft clearings are counted as already-reserved advance, matching the method's current lifecycle where clearing entries are created as `Draft` rather than immediately posted.
- The helper uses bcmath and the injected currency scale; no float money math is introduced.
- The query scopes pending clearings by company, partner, customer-advance account, draft status, and `prepayment_application` source type.
- The exact-available path remains valid and still creates balanced draft GL lines.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
