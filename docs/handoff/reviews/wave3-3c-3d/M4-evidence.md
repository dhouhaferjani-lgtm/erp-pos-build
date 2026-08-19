# M4 evidence — Option A inventory variance purposes

**Pinned 3D base:** `48cebf0f2c1b481c592bf35d478302499f9fda5d`

**Treasury authority:** `TREASURY-RULING-2026-08-19-t20-option-a.md`

**Test commit:** `b2d7c9588737504b363783f6883f3dfa52bf61b7`

**Implementation commit:** `ea1280d21d9e573e4b8f43f8f94ba6ecd6580aa5`

**Round-1 guard tests:** `81441329a`

**Round-1 remediation:** `d82670202`

## Delivered map and lifecycle

- TN and FR: `6586` expense under `65` for `inventory_shrinkage_expense`; `7586` revenue under `75`
  for `inventory_gain_income`.
- Generic: the same codes and purposes under `6000` / `7000` with English names.
- New immutable draft bootstrap keys are `coa.tn.default-v2`, `coa.fr.default-v2`, and
  `coa.generic.default-v2`. Each is the exact legacy-v1 payload plus the approved two rows.
- The three legacy chart seeder files and their fingerprints are unchanged. `InventoryShrinkageExpense`
  is now REQUIRED for newly certified country-default templates because destructive-loss writers are live;
  `InventoryGainIncome` remains SOFT until T21.
- The frozen fallback is deliberate absence: a legacy chart missing shrinkage logs a warning and creates
  no destructive-loss entry. The v2 template and unattended tenant backfill make the chart posting-ready.
- The expert-comptable OQ-12/H-5 rider remains a pre-live M5 gate for count-correction posting. M4 does not
  make that listener live.

## Backfill contract

`accounting:backfill-inventory-shrinkage-purposes` is tenant-scoped, purpose-first, code-second, and
run-twice idempotent. TN/FR and Generic parents/names are pinned. Each account definition runs in its
own connection-bound transaction/savepoint, and the PostgreSQL constraint-injection test proves a failed
`6586` insert does not leave the outer transaction in SQLSTATE 25P02: `7586` still succeeds. The final
console line and warning log use the stable token:

```text
INVENTORY-SHRINKAGE-PURPOSE BACKFILL FAILURES: <n>
```

`2026_08_19_130000_backfill_inventory_shrinkage_purposes.php` delegates to that command during
`tenants:migrate`, inside a connection-bound savepoint, and emits the separate warning-level deploy token
`INVENTORY-SHRINKAGE-PURPOSE BACKFILL MIGRATION:` with tenant identity plus `status=ok|FAILED`. The
operator procedure is pinned in `docs/handoff/dpa-inventory-shrinkage-deploy-checklist.md`.

## MovementReason routing

All 17 enum cases are exhaustively pinned through `MovementGlCounterFamily`:

- COGS: `Delivery`, `CustomerReturn`, `POSSale`, `POSReturn`.
- Shrinkage: `Damage`, `Expiry`, `WriteOff`.
- Directional variance: `CountCorrection`.
- Neither: `GoodsReceipt`, `AdjustmentPositive`, `TransferIn`, `ProductionOutput`, `OpeningBalance`,
  `SupplierReturn`, `AdjustmentNegative`, `TransferOut`, `Consumption`.

Generic and batch destructive-loss posting now debit `InventoryShrinkageExpense`; COGS sale/return posting
is unchanged. D-b coverage treats both COGS and shrinkage exits as cost-bearing, so the reroute does not
blind the zero/NULL-cost detector.

## TDD and non-vacuity evidence

### Integrated-base baseline before implementation

The pre-implementation run of `FrozenSeederDocblockTest.php` plus
`ProvisioningRequiredPurposesV1ConformanceTest.php` was green: **14 tests, 432 assertions**. This records
the required green baseline while the frozen seeders still lacked the two purposes.

### Red first

With the new tests present and before their production surfaces existed, the focused run failed with the
expected causes: missing `MovementGlCounterFamily`, missing `glCounterFamily()`, no v2 templates, and no
backfill command (**34 tests; 9 errors; 26 PostgreSQL-only skips**).

Round-1 remediation was also red-first on PostgreSQL: the new command/migration set initially returned
**12 tests, 49 assertions, 2 failures and 2 errors**. The failures proved the repurpose diagnostic and
schema-guard token were absent; the errors proved the unattended tenant migration file did not exist.
Moving shrinkage from SOFT to REQUIRED then produced the expected manifest partition failure before the
publication gate was changed.

### T20b mutation and restore

Temporary mutation: remove the `7586` gain row from the v2 importer, then run
`ChartOfAccountsParityTest::test_v2_is_v1_plus_the_approved_option_a_variance_rows`.

```text
RED: 3 tests, 3 failures
TN expected 141 rows, actual 140
FR expected 146 rows, actual 145
Generic expected 63 rows, actual 62
```

After restoring the row, the identical command returned **3 tests, 21 assertions, green**.

## Adversarial round 1 remediation

- P1: the new tenant migration makes the existing-company repair automatic under `tenants:migrate`,
  contains PostgreSQL failures in a savepoint, and emits a tenant-attributed deploy token.
- P2 publication gate: `InventoryShrinkageExpense` moved from SOFT to REQUIRED for newly certified
  country-default templates. The frozen-seeder fallback remains the narrower F-1 exception expressly
  allowed by `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md`; the migration repairs companies that exist at
  deploy and the guarded warning/no-entry path remains pinned for later fallback provisioning.
- P2 tests: promotion, repurpose refusal, missing parent, dry-run, schema guard, automatic migration,
  failure containment, and irreversible no-op rollback are all covered.
- P3 records: the stale session handback and GL docblock are corrected; refusal diagnosis now checks a
  claimed purpose before secondary type/active validation; the schema guard emits the stable token.
  Importer comparisons use `(sort_order, code)` for deterministic tie ordering. Custom non-TN/FR
  PCG-shaped charts remain intentionally fail-loud and are called out in the deploy checklist.

Round-1 revert-replay removed `d82670202` while `81441329a` kept the covering tests present. The focused
PostgreSQL run returned **4 tests: 3 failures and 1 error**—repurpose ordering, schema token, REQUIRED
classification, and the missing automatic migration all went red for their intended reason. Aborting the
revert restored the committed tip and the green results below.

### Commit revert-replay

`git revert --no-commit ea1280d21` left the test commit present and removed the implementation. The focused
classification/template/backfill run returned **12 errors** for the missing enum, v2 importer/templates,
and command. `git revert --abort` restored the exact clean implementation tree.

## Final verification

All behavioral acceptance runs below used real PostgreSQL 5432 / `autoerp_test` through
`phpunit-pgsql.xml`:

```text
core map/backfill/catalog/detector/inventory seam: 101 tests, 748 assertions
legacy bootstrap importer regression:              9 tests,  45 assertions
batch-expiry destructive-loss regression:         45 tests, 173 assertions
POS scrap regression (isolated process):          11 tests,  42 assertions
fiscal projection regression (isolated process):  16 tests,  77 assertions
TOTAL:                                            182 tests, 1085 assertions
```

The complete touched Country Defaults directories also pass on the compatibility runner:
**218 tests, 1,731 assertions, 8 deliberate environment skips**.

Round-1 fresh PostgreSQL verification:

```text
backfill command + automatic migration: 13 tests,   60 assertions
Country Defaults feature + unit dirs:   218 tests, 1827 assertions
inventory seam + POS/fiscal/unit paths:  91 tests,  385 assertions
BatchExpiry owners, isolated by class:   45 tests,  173 assertions
```

Running the seven real-root BatchExpiry classes in one PHPUnit process reproduced two cross-class source
collisions; each owning class passes in isolation. This is the previously recorded F-7 defect—movement
entry idempotency omits `company_id`—and is not folded into M4 code. Its future-slice ticket remains
`docs/superpowers/tickets/2026-08-19-inventory-movement-entry-idempotency-company-scope.md`.

The complete `tests/Unit/Inventory` directory was executed as required. Its M4 tests pass, while the run
retains two pre-existing `GoodsReceiptDataTest` fixture errors (`warehouse` relation is null): **142 tests,
419 assertions, 2 errors**. Neither `GoodsReceiptData.php` nor that test differs from the pinned 3D base;
the failure is therefore recorded rather than folded into M4.

Static and architectural checks:

```text
Pint (all changed PHP paths): pass
PHPStan level 8 (all changed production paths): [OK] No errors
deptrac pinned 3D base: 174 violations
deptrac M4 tip:         174 violations (zero regression; no M4-introduced file reported)
git diff --check: pass
frozen seeder diff: zero files / zero bytes
.github/workflows/** diff: none
```

The deptrac sequence is reconciled as: M1 recorded 116, M2 recorded 127, the parent-reconciled pinned 3D
base now measures 174, and M4 remains exactly 174. M4 adds no architecture violation.
