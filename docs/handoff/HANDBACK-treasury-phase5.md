# Treasury Phase ⑤ — handback

Status: **IN PROGRESS — not the final controller handback**

This file is intentionally started during Gate 2 so the mandatory Fable interpretation below cannot be lost. It must be replaced with the full implementation, gate, verification, deployment, dependency, branch, and known-risk handback after Waves 3–5 and Playwright are complete.

## Gate 2 binding interpretation: void-aware deduplication

> §5.1's file-sha256 uniqueness and line-fingerprint uniqueness range over **active** rows only: statements with `status <> 'voided'`, and lines with `dedupe_active = true`. The invariant's purpose — its own words — is that re-import is "rejected, not silently deduped to zero lines": it prevents duplicate *live* imports; it does not permanently consume a file identity after a §5.3.4 void. Void is a pure status transition: nothing is deleted; a voided statement's lines are retained verbatim for audit but cease participating in dedupe. At most one non-voided statement may exist per (repository, sha256); re-importing the identical file after void is permitted and creates a new active statement.

Authority: `.gates/gate-t5b-gate-2-escalation-verdict.md` (`UPHOLD`, `ESCALATION VERDICT: GATE BLOCKED`) pending the required R3 approval.
