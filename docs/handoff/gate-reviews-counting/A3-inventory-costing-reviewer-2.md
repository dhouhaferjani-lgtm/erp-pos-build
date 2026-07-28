# A3 Inventory Costing Review — Round 2

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** Revised A3 diff after round-one fixes
- **Mode:** Read-only

## Resolved Round-One Findings

The reviewer verified the successful-apply boundary, endpoint authorization/device binding, server acknowledgement signature and audit snapshot, exact event boundary, shared POS contract, unit-aware quantity formatting, and company/current-grain query scoping.

## Required Findings

- **Critical:** `is_flagged` is not equivalent to “nothing posted”; normal posted variance/manual-override lines may be flagged. Exclude only apply-time blocking `flag_reasons` and add posted-flagged coverage.
- **Important:** consume a movement at its newest successfully applied count even when that count absorbed it, preventing fall-through and false attribution to an older count.

## Non-Blocking Findings

- Document replay-era/legacy analysis limits.
- Remove the dead residual field from the pre-finalize reconciliation payload.
- Persist only a verified acknowledgement signature.
- Hash material risk state rather than heartbeat timestamps.
- Align stale copy with server `reported_at` semantics.

## Verdict

**VERDICT: CHANGES_REQUIRED**
