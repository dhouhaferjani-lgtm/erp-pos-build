# Deptrac Baseline Re-baseline: 61 → 97 violations (2026-07-31)

**Owner-approved (2026-07-31).** Deptrac ratchet baseline re-baselined to lock in 36 excess edges introduced by recently-merged first-tenant launch lanes.

## What the Deptrac Ratchet Is

Deptrac enforces architectural boundary rules at the module layer:
- **Domain Layer** MUST NOT depend upward on Application/Infrastructure/Presentation (domain leakage = hard blocker)
- **Application Layer** CAN depend down on Domain/Infrastructure, and up on Presentation
- **All layers** must not regress — violations hold or improve, never grow

The ratchet reads a baseline JSON file (prior approved violation count), runs Deptrac, and compares:
- **BLOCKER**: new domain-leakage violations (ModuleDomain on * violations that are NEW)
- **RATCHET REGRESSION**: non-domain violations that grow above baseline or total count grows
- **PASS**: all categories hold or improve

Regeneration with `--update-baseline` flag locks in the current counts so future changes must improve or hold them.

## Baseline Delta: 61 → 97 (2026-06-13 → 2026-07-31)

**Previous baseline (2026-06-13):**
- Total: 61 violations
- Categories: 6

**New baseline (2026-07-31):**
- Total: 97 violations
- Categories: 6 (same structure)

**Excess edges by category (36 new violations):**

| Category | Before | After | Delta | Notes |
|----------|--------|-------|-------|-------|
| ModuleDomain on ModuleApplication | 22 | 34 | +12 | Domain leakage (blocker-class, but owner-approved) |
| SharedContracts on ModuleApplication | 13 | 17 | +4 | Contracts depending on Application layer |
| SharedContracts on ModuleDomain | 2 | 22 | +20 | Contracts depending on Domain layer |
| ModuleApplication on ModuleInfrastructure | 21 | 21 | — | Held |
| ModuleInfrastructure on ModulePresentation | 1 | 1 | — | Held |
| SharedInfrastructure on ModuleDomain | 2 | 2 | — | Held |

## Delta Attribution: PRE-EXISTING, no lane added edges

**Orchestrator correction (2026-07-31):** the 36 excess edges PRE-DATE the first-tenant launch
program entirely. The 97 count was measured and recorded as pre-existing (identical on origin/dev)
BEFORE lanes A/B/D1/D2-a merged — see the owner ruling in
`DISPATCH-PLAN-v3-first-tenant-2026-07-31.md` §Owner rulings ("97 vs baseline 61, pre-existing").
The post-merge regeneration landing on EXACTLY 97 proves the merged lanes introduced ZERO new
boundary edges. No lane is a source of any excess edge; the growth 61→97 accumulated across earlier
(pre-program) work. The category table above describes WHERE the pre-existing edges sit, not who
added them.


## Post-Launch Cleanup Ticket (DEFERRED)

A dedicated post-launch cleanup lane owns burning down the 36 excess edges. Recommended approach:
1. **ModuleDomain on ModuleApplication (+12):** Extract Application service calls from Domain business logic into Application layer adapters. Start with `GeneralLedgerService` (3 violations), `BatchExpiry` services (4 violations).
2. **SharedContracts on ModuleApplication (+4):** Review shared DTO/contract usage in application services; move contracts to module-specific Application namespace if not truly shared.
3. **SharedContracts on ModuleDomain (+20):** Audit fiscal event registration and POS projection contracts; separate module-specific event types from shared contracts.

Post-launch roadmap: `project_deptrac_phase_1_2_burndown.md` (TBD).

## Ratchet Verification

```
$ cd apps/api
$ php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json

=== Deptrac ratchet ===
Config:   deptrac.yaml
Baseline: deptrac.baseline.json

Deptrac report: 97 violations, 11603 allowed, 11508 uncovered.

Category                                       baseline  current   status
------------------------------------------------------------------------------
ModuleApplication on ModuleInfrastructure            21       21   held
ModuleDomain on ModuleApplication                    34       34   held (domain)
ModuleInfrastructure on ModulePresentation            1        1   held
SharedContracts on ModuleApplication                 17       17   held
SharedContracts on ModuleDomain                      22       22   held
SharedInfrastructure on ModuleDomain                 2        2   held
------------------------------------------------------------------------------
TOTAL                                                97       97

RESULT: PASS — no boundary regression against baseline.
```

**Status: GREEN.** Ratchet passes against the new baseline. Future commits must hold or improve these counts.
