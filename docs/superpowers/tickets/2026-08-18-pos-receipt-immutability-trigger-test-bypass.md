# POS return tests disable the sealed-receipt immutability trigger without a necessity proof

**Severity:** LOW-MEDIUM (test integrity and least-privilege CI portability).
**Raised by:** wave3-3c M2 adversarial review round 7, P3-6 (PLAUSIBLE).
**Disposition:** ship-with-ticket under
`ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md` ruling 5.

## Observation

`ReceiptReturnFlowTest` temporarily disables PostgreSQL trigger
`enforce_receipt_immutability` around two controlled fixture mutations and restores it in
`finally`. The round-7 reviewer observed that the file had previously passed without this
workaround and could not prove the bypass necessary without changing the tree.

The bypass weakens the fixture: it can no longer demonstrate that its mutation is legal under the
same append-only rule production enforces. `ALTER TABLE ... DISABLE TRIGGER` also requires table
ownership, so the tests may fail under a least-privilege CI role even when product behavior is sound.

## Completion criteria

1. Reproduce both tests on PostgreSQL with the trigger continuously enabled.
2. If both remain green, delete the disable/enable blocks.
3. If either fails, replace the illegal mutation with a fixture created in the required initial
   state, or document why a trigger-bypass fixture is the only faithful test mechanism.
4. Run the complete `ReceiptReturnFlowTest` under the least-privilege CI database role.

This ticket does not authorize weakening the production trigger.
