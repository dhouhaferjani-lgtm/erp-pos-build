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

## Delta Attribution: Recently-Merged Launch Lanes

Per the launch plan:
- **Lane A (Accounting / Reports):** touched `Accounting/*` module tree (likely source of Accounting domain-leakage violations in GeneralLedgerService, PartnerBalanceService dependencies)
- **Lane D1 (POS Cash Rounding Phase 1):** touched POS TerminalController, Tenant seeders, fiscal projections (cross-module contract dependencies)
- **Other lanes (D, E, F):** treasury, multi-location, deptrac re-baseline (this lane — read-only changes)

The 36-edge bump across all categories suggests that lanes A and D1 introduced:
1. Domain→Application dependencies (12 edges) — mostly Accounting domain reaching into application services
2. Shared contracts reaching down into Application (4 edges) — likely from POS or new shared DTOs
3. Shared contracts reaching down into Domain (20 edges) — likely from refactored PosCoreReceiptProjection or fiscal event registration

No regressions in other categories (ModuleApplication↔ModuleInfrastructure, ModuleInfrastructure→ModulePresentation held).

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
