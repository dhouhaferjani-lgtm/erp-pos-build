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
  no destructive-loss entry when a frozen seeder is invoked directly. Normal legacy provisioning through
  `ChartOfAccountsService` installs both purposes atomically; the v2 template and unattended tenant
  backfill cover the other creation-time and existing-company populations.
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

## Adversarial round 2 remediation

The round-2 P1 was reproduced before the fix by the existing full-purpose parity guard:
`ChartOfAccountsPurposeParityTest` failed for TN with both `inventory_shrinkage_expense` and
`inventory_gain_income` missing. `InventoryVarianceAccountProvisioner` now owns the single
purpose-first/code-second definition set used by both the command and `ChartOfAccountsService`.
The legacy seeder plus installer run in one transaction, so onboarding and second-company creation cannot
commit a chart without the approved accounts. The raw seeders and their fingerprints remain unchanged.

Owner-checklist G2 now names the three `*.default-v2` bootstraps and requires cloning each to an editable
draft before authenticated HTTP/UI publication and assignment. The parent-pinned fixture/hash consequence
is surfaced in `docs/superpowers/tickets/2026-08-19-m4-country-defaults-v2-certification-repin.md`. The
deploy checklist also records the non-restated COGS-to-shrinkage reporting discontinuity.

Fresh PostgreSQL evidence after the fix:

```text
RED — accounting chart parity/service: 29 tests, 191 assertions, 1 failure
      TN missing inventory_shrinkage_expense and inventory_gain_income
GREEN — command/migration/chart service/creation paths: 66 tests, 359 assertions
GREEN — complete Country Defaults feature + unit dirs: 218 tests, 1831 assertions
```

Round-2 revert-replay removed `c1a067287` while the pre-existing full-purpose parity guard remained.
The focused PostgreSQL test failed with TN missing both `inventory_gain_income` and
`inventory_shrinkage_expense` (1 test, 1 assertion, 1 failure). Aborting the revert restored the exact
committed tree, and the same test passed (1 test, 3 assertions).

Pint and PHPStan level 8 pass on the new provisioner, command, and chart service. Deptrac reports exactly
**174 violations** at both the pinned 3D base and the M4 tip, but this is **not a green gate**: the checked-in
baseline totals 99, the dispatch names 111, and the ratchet returns `RESULT: FAIL` with a Domain-tier
BLOCKER. M4 introduces no violation. The inherited discrepancy is a parent-owned promotion blocker at
`docs/superpowers/tickets/2026-08-19-wave3d-inherited-deptrac-ratchet-blocker.md`. The three frozen seeder
files and `.github/workflows/**` remain untouched.

The complete `tests/Unit/Inventory` directory was executed as required. Its M4 tests pass, while the run
retains two pre-existing `GoodsReceiptDataTest` fixture errors (`warehouse` relation is null): **142 tests,
419 assertions, 2 errors**. Neither `GoodsReceiptData.php` nor that test differs from the pinned 3D base;
the failure is therefore recorded rather than folded into M4.

Static and architectural checks:

```text
Pint (all changed PHP paths): pass
PHPStan level 8 (all changed production paths): [OK] No errors
deptrac checked-in baseline: 99 violations
dispatch-stated baseline:    111 violations
deptrac pinned 3D base:      174 violations, RESULT: FAIL
deptrac M4 tip:              174 violations, RESULT: FAIL (zero M4-introduced edges)
git diff --check: pass
frozen seeder diff: zero files / zero bytes
.github/workflows/** diff: none
```

The historical deptrac sequence is M1 116, M2 127, and the parent-reconciled pinned 3D base 174. The
current baseline file still totals 99, so that sequence is not a passing ratchet reconciliation. M4 stays
at the inherited 174 and adds no architecture violation; the parent ticket above must be discharged before
promotion.

## Adversarial round 3 remediation

The round-3 red-first PostgreSQL run covered chart health, pre-policy assigned templates, template
rollback, preview parity, and missing-parent wording: **49 tests, 204 assertions, 5 failures and 1 setup
error**. After correcting the pre-policy fixture ordering, both template tests remained red for the
intended reasons. The implementation then produced **49 tests, 210 assertions, green**.

`InventoryShrinkageExpense` is now part of the in-product required-purpose detector. Both legacy and
assigned-template chart creation run the same variance provisioner inside their chart transaction, so a
pre-policy published template is completed and a purpose collision rolls back all template writes. The
rollback-owned legacy preview now includes the variance installer and reports the same creation count as
the real legacy path. Account types use `AccountType` values and the missing-parent message is accurate in
both the savepoint backfill and all-or-nothing creation contexts.

The earlier accounting-directory green wording was incorrect: the full-purpose parity test was already
red at the pinned 3D base and M4 is the change that makes it green. It must not be cited as a passing base
or pre-M4 regression result.

Fresh round-3 verification:

```text
focused red set after implementation:       49 tests,  210 assertions, OK
Country Defaults feature + unit directories: 220 tests, 1837 assertions, OK
accounting chart/command/migration set:       56 tests,  343 assertions, OK
Pint on round-3 production + test paths:      pass
PHPStan level 8 on round-3 production paths: [OK] No errors
deptrac tip:                                  174 violations, RESULT: FAIL (inherited)
```

Round-3 revert-replay removed `ad9909716` while test commit `266d37bc1` remained. The six focused
PostgreSQL guards all failed for their intended reasons: required-purpose membership, detector output,
pre-policy template completion, template rollback, preview count parity, and missing-parent wording.
Aborting the revert restored the committed tip and the same six tests passed with 17 assertions.

## Adversarial round 4 remediation

Round 4 identified a legal cross-country chart assignment: a French-plan template certified for Morocco
can omit the SOFT gain purpose while still satisfying Morocco's protected-code registry. Before the fix,
the overlay derived `7000` from company country and aborted because the assigned chart contains `75`.
The focused PostgreSQL test failed with exactly that missing-parent exception before implementation.

The template-only overlay now prefers the country-derived parent but falls back to the matching parent
family that actually exists in the freshly seeded chart. Strict legacy/backfill provisioning is unchanged,
including the deliberately fail-loud custom-chart contract. The overlay is explicitly documented as the
two-account post-template layer validated by per-company chart health; `country-defaults:verify` remains
the template/assignment verifier. The previewer declares its refusal exceptions, and the manifest test
name now states the 43-case partition it asserts.

Fresh round-4 verification:

```text
RED — French-plan template assigned to MA without gain: 1 test, 1 error (missing parent 7000)
GREEN — same focused test:                            1 test, 1 assertion
GREEN — provisioning matrix + manifest conformance: 26 tests, 457 assertions
GREEN — Country Defaults feature + unit directories: 221 tests, 1838 assertions
GREEN — accounting chart/command/migration set:      56 tests, 343 assertions
Pint on round-4 production + test paths:             pass
PHPStan level 8 on round-4 production paths:         [OK] No errors
```

Round-4 revert-replay removed `e28562448` while test commit `bbe846284` remained. The cross-plan
PostgreSQL guard errored on missing parent `7000`; aborting the revert restored the exact committed tip
and the same test passed, resolving the new gain account beneath `75`.

## Adversarial round 5 remediation

The final fix round covers a legally published third-plan template whose revenue root is `9000` and which
omits the SOFT gain purpose. Before implementation, the PostgreSQL guard errored on missing parent `7000`.
Template provisioning now keeps REQUIRED shrinkage fail-closed but warns and skips only the optional gain
when no same-type `75`/`7000` parent exists. The stable warning includes tenant, company, and country.
Known plan parents are required to match the variance account type, preventing a revenue gain from being
grafted beneath an expense-typed lookalike code.

The deploy checklist now distinguishes template/assignment verification from required per-company chart
health and the optional-gain warning, and it reserves the strict country-derived repair command for the
pre-G2 legacy path. The stale `Consumption` docblock now describes the exhaustive `Neither` classification
rather than a removed default arm.

Fresh round-5 verification:

```text
RED — third-plan template without optional gain parent: 1 test, 1 error (missing parent 7000)
GREEN — both template-overlay edge cases:              2 tests, 5 assertions
GREEN — Country Defaults feature + unit directories: 222 tests, 1842 assertions
GREEN — accounting provisioning + movement routing:   75 tests, 420 assertions
Pint on round-5 production + test paths:              pass
PHPStan level 8 on round-5 production paths:          [OK] No errors
deptrac tip:                                           174 violations, RESULT: FAIL (inherited)
```

Round-5 revert-replay removed `7179bb643` while test commit `336559830` remained. The third-plan
PostgreSQL guard errored on missing parent `7000`; aborting the revert restored the exact committed tip,
and the same test passed with 4 assertions, including the tenant-attributed warning contract.
