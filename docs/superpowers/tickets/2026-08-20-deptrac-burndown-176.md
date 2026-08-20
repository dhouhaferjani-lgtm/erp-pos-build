# Deptrac burn-down from the 2026-08-20 owner-acked re-baseline (99 → 176)

Reproduce: `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` (apps/api).
Owner ack: 2026-08-20, parent orchestration session de182ed3 — re-baseline so the S-14 promotion
dispatches stop failing on a stale number; the debt is tracked here, not waived.

| Category | baseline (08-03) | now | delta | class |
|---|---|---|---|---|
| ModuleDomain on ModuleApplication | 35 | 54 | +19 | **BLOCKER family** — Domain must not depend on Application. 17 of the 19 are enumerated in the enforcement-P2 decision doc (`.worktrees/enforcement-p2/docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md`); the P1 census's WAC `?string` cluster overlaps. Burn these FIRST. |
| ModuleApplication on ModuleInfrastructure | 21 | 68 | +47 | Ratchet growth across the merged waves; largest single bucket. |
| SharedContracts on ModuleDomain | 22 | 29 | +7 | Pre-dates 08-08 (see the G3 waiver's not_absorbed note); owned by the erp.deptrac-rebaseline lane. |
| SharedDomain on ModuleDomain | 0 | 4 | +4 | New family — triage first (may be one refactor). |
| others (held) | 21 | 21 | 0 | — |

Sequencing: as-you-go alongside first-tenant stabilization (owner ruling 2026-08-20). Every new lane
inherits the ratchet at 176 — no growth passes CI, so the number only moves down.
