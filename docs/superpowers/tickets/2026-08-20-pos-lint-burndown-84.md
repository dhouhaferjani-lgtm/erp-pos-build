# @autoerp/pos lint burn-down from the 2026-08-20 owner-acked re-baseline (40 → 84)

Reproduce: `pnpm -w run lint:ratchet`. Owner ack 2026-08-20 (parent session de182ed3): re-baselined
so S-14 promotion dispatches stop failing on the stale number; the +44 warnings are tracked here,
not waived. Enumerate with `pnpm --filter @autoerp/pos lint` — burn as-you-go alongside
first-tenant stabilization (same sequencing ruling as the deptrac burn-down). Contrast: @autoerp/web
fell 11739 → 6449 in the same window, so the mechanism works when lanes run it locally.
