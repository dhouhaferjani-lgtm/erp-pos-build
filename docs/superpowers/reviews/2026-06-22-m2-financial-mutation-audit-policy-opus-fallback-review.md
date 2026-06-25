# M-2 Financial Mutation Audit Policy — Opus Fallback Review

Date: 2026-06-22
Reviewer: Codex, second independent pass
Lens: Scope control, event immutability, and subscriber durability.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Adversarial Checks

- The patch reuses existing immutable domain events and does not rename or reshape event payloads.
- The missing audit coverage is closed by subscribing `PaymentAllocated` and `ReconciliationCompleted`; refund/reversal coverage remains intact.
- The policy test fails if a scoped financial mutation event is removed from the audit subscriber map.
- The change does not claim fiscal projection support for these mutations; it explicitly classifies them as audit-only.

## Residual Notes

- Account refund fiscal-event semantics are still deferred to the full fiscal matrix/policy item; this patch avoids adding a partial fiscal event implementation.
- True cross-model Opus review remains pending for owner spot-check.
