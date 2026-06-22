# M-3 Fiscal Event Coverage Policy — Opus Fallback Review

Date: 2026-06-22
Reviewer: Codex, second independent pass
Lens: Future drift, false confidence, and event immutability.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Adversarial Checks

- Adding a new `FiscalEventType` now fails unless the policy matrix classifies it.
- Marking a case as projected now fails unless a registered projector handles it; adding an unclassified projector also fails.
- Implemented cases now fail if the payload registry resolves them but the validator falls through to the missing per-event clause.
- The patch does not rename, restructure, or delete any fiscal event.

## Residual Notes

- The policy distinguishes projection coverage from canonical reader coverage, which is important because Z/session events project without typed canonical reader methods today.
- True cross-model Opus review remains pending for owner spot-check.
