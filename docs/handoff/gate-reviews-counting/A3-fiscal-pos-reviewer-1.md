# A3 Fiscal POS Review — Round 1

- **Reviewer:** `fiscal-pos-reviewer`
- **Model:** Opus
- **Scope:** Initial uncommitted A3 diff
- **Mode:** Read-only

## Required Findings

- **Critical:** the health-report endpoint lacked a POS permission and device-to-terminal binding, allowing same-company spoofing of a healthy state.
- **Important:** the residual table rendered a raw scale-4 quantity and would fail the empty-baseline quantity-display audit.
- **Important:** an acknowledged terminal warning left no audit trace on the counting-finalized event.
- **Important:** Inventory-disabled POS tenants would call an Inventory-gated endpoint on every successful tick; suppress the report when Inventory is disabled.
- **Important:** constrain the residual query to the requested counting's grains and explicitly scope movement company.

## Verified Clean

- Every frozen fiscal/projection/canonical/generated surface was untouched.
- No fiscal behavior, queue, scale resolution, or SQLite timestamp comparison changed.
- POS reporting failure was correctly isolated from successful receipt sync.
- Residual arithmetic was read-only and used decimal strings without float coercion.

## Non-Blocking Notes

- Stale threshold/closed-shift signal-to-noise should be monitored in staging.
- POS returns are symmetric in theory but are outside A3's explicit sale-only brief; UI copy must not imply refund coverage.
- Arabic parity remains intentionally deferred to A5.

## Verdict

**VERDICT: CHANGES_REQUIRED**
