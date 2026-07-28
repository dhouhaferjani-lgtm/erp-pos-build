# A3 Fiscal POS Review — Round 2

- **Reviewer:** `fiscal-pos-reviewer`
- **Model:** Opus
- **Scope:** Revised A3 diff after round-one fixes
- **Mode:** Read-only

## Resolved Round-One Findings

The reviewer verified permission/device binding, Inventory-disabled client suppression, POS failure isolation, server acknowledgement verification and audit snapshot, unit-aware quantity formatting with no audit debt, query scoping, and untouched frozen fiscal surfaces.

## Required Findings

- **Important:** prevent an absorbed movement at a newer count from falling through to an older count.
- **Important:** hash only material risk fields so heartbeat timestamps do not flap acknowledgement; refresh reconciliation and show specific copy on a stale-signature 422.
- **Important:** do not drop inactive/training physical terminals that can retain a production-era pending queue.

## Non-Blocking Findings

- Persist the client signature only when server verification succeeds.
- POS returns remain an explicitly out-of-scope follow-up.
- Cache eviction correctly fails closed to unknown.

## Verdict

**VERDICT: CHANGES_REQUIRED**
