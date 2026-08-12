# M2 implementation evidence — ES-07 receipt coverage, reporting, and mirror

## Result

Control commit: `be8751bab`; behavioral commit: `079fb1f66`; projected
population control: `e8f837a3d`.

`pos:verify-chains --type=receipts` now emits three independently
attributable rows per terminal:

| Command row | Source and hash shape | Count |
|---|---|---|
| `Receipts: Fiscal Events` | all terminal `fiscal_events`; re-hash frozen `canonical_bytes`, then verify linkage | fiscal-event rows inspected |
| `Receipts: Projected Mirror` | every `pos_receipts.fiscal_event_id IS NOT NULL`; compare `pos_receipts.fiscal_hash` directly with the referenced event's `current_hash` | projected receipts inspected |
| `Receipts: Legacy` | only `pos_receipts.fiscal_event_id IS NULL`; preserve per-row sealed-algorithm recomputation | legacy receipts inspected |

Each row carries its own valid/failed mark, count, and breakpoint. Any failed
arm still produces the existing aggregate tenant/run exit `1`, and the
per-tenant coverage block is unchanged. Missing receipt mirror hashes and
missing referenced event hashes fail closed with receipt and event UUIDs in
the breakpoint and structured log; canonical bytes are never included.

The mirror and authoritative checks remain deliberately separate. A receipt
hash/event hash disagreement trips only `Projected Mirror`. An event whose
`current_hash` correctly hashes its bytes but whose `previous_hash` is wrong
trips only `Fiscal Events`. Projected rows never enter the legacy query.

## TDD RED

All PostgreSQL commands below used the dedicated disposable database,
serially, with:

```text
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD=''
```

Before production implementation:

```bash
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ReceiptChainRebuildTest.php
```

```text
FAIL ReceiptChainRebuildTest
6 failed, 3 skipped, 6 passed (53 assertions)
Primary failure: ReceiptHashService::verifyTerminalChainArms() was undefined;
the mixed command fixture also could not find the three expected arm rows.
```

M0 T-c was flipped from its pre-M2 characterization to the new honest
expectation before the mirror arm existed:

```bash
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter=test_receipt_mirror_tamper_is_self_asserting
```

```text
FAIL — receipt verifier returned true where T-c now expects false
Tests: 1 failed (2 assertions)
```

## Focused GREEN

```bash
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ReceiptChainRebuildTest.php
```

```text
15 passed, 3 skipped (108 assertions)
```

The passing path includes:

- clean projected receipt with fiscal/mirror counts `1/1`;
- T-c mirror mismatch with fiscal arm clean, mirror arm failed, legacy clean;
- voided and training projected receipts both counted by the mirror arm, with
  a training-row mismatch detected rather than carved out;
- internally hash-valid wrong-link event with fiscal arm failed and two clean
  projected mirrors;
- missing referenced event and missing receipt hash fail-closed controls;
- exact failed command table with the receipt/event coordinate;
- exact mixed command table with counts `fiscal=1`, `mirror=1`, `legacy=1`;
- all-legacy negative control with counts `0/0/1` and the existing legacy hash
  algorithm unchanged.

```bash
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/POS/VerifyPosChainCommandTest.php
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php --filter='test_projection_row_is_excluded_from_legacy_verify_terminal_chain|test_pos_verify_chains_command_does_not_break_on_projection_rows'
```

```text
VerifyPosChainCommandTest: 14 passed (32 assertions)
VerifyEventChainCommandTest: 33 passed (136 assertions)
PosCoreReceiptProjectionTest focused partition controls: 2 passed (2 assertions)
```

The physical database-per-tenant path provisions SQLite tenant databases by
design, so applying the PG environment to it failed before assertions when its
schema copier queried `sqlite_master` through PostgreSQL. It was rerun under
its required configuration:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' php artisan test --no-ansi tests/Feature/POS/VerifyPosChainCommandDbPerTenantTest.php
```

```text
3 passed (7 assertions)
```

## Load-bearing disable proofs

Each mutation was an uncommitted one-line `apply_patch`; each was restored
before the next mutation and before commit.

1. Replacing `projected_mirror => inspectProjectedMirrorArm(...)` with a
   vacuous valid zero result made
   `test_mirror_disagreement_trips_only_the_projected_mirror_arm` fail:
   **1 failed (3 assertions)** because the mirror result became true.
2. Replacing `fiscal_events => inspectFiscalEventsArm(...)` with a vacuous
   valid zero result made
   `test_internally_hash_valid_projected_chain_link_tamper_trips_only_fiscal_events_arm`
   fail: **1 failed (1 assertion)** because the fiscal result became true.
3. Replacing `legacy => verifyLegacyArm(...)` with a vacuous valid zero result
   made `test_command_exits_one_when_receipt_chain_is_broken` fail:
   **1 failed (2 assertions)** because the command exited `0` instead of `1`.

No temporary disable form is present in `079fb1f66`.

## Commit-level revert replay

Tests were committed separately as `be8751bab`, then behavior as `079fb1f66`,
so current covering tests remained present throughout the replay:

```bash
git revert --no-commit 079fb1f66
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ReceiptChainRebuildTest.php
git revert --abort
php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ReceiptChainRebuildTest.php
```

```text
REVERTED with all current controls through `e8f837a3d`: 9 failed, 3 skipped,
6 passed (62 assertions)
RESTORED: 15 passed, 3 skipped (108 assertions)
```

The no-commit revert was aborted after the RED run; no revert commit or
throwaway commit remains in history.

## Formatting, static analysis, and architecture delta

- Pint on all five touched PHP paths: pass.
- PHPStan level 8 on the three touched production paths: `[OK] No errors`.
- Explicit PHPStan on both touched tests reports the existing 23 diagnostics:
  three pre-existing facade/mock typing diagnostics in
  `ReceiptChainRebuildTest` and the same twenty pre-existing diagnostics in
  `VerifyEventChainCommandTest`. No diagnostic points to an M2-added line; no
  ignore or baseline entry was added.
- `ConsoleCommandTenantContextTest` remains at the identical accepted M1
  eleven-command failure list. `VerifyPosChainCommand` is not in it; delta is
  zero.
- Deptrac at M2 base `4d5b0d3f5`: 116 violations. Deptrac at M2 behavior HEAD
  `079fb1f66`: 116 violations with the identical category vector
  `21/41/1/18/29/4/2`; delta is zero. The repository ratchet remains red only
  on the pre-existing 99-to-116 baseline drift.

## Boundaries and outstanding signal

- Z-report production/test diff lines: **0**.
- No migration, event class, projector/writer, signature, permission,
  workflow, controller, or M1 verifier/fleet behavior changed.
- Existing command exits and tenant coverage semantics are preserved.
- V1 `—`; V2 `—`; V3 `—`.
- The registered context-flattening defect in
  `verifyTerminalChainFiscalArm()` remains visible and unchanged. Every M2
  projected fixture is intentionally single-context; M2 does not claim a
  clean two-context aggregate receipt verification.
