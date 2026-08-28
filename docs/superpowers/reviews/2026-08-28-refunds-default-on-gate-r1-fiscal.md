# Refunds default-on gate r1 — fiscal/POS

**Lane:** `feat/refunds-default-on`  
**Range:** `e63ccccb3..ddccb6119` (one commit, 14 files)  
**Review posture:** adversarial, read-only source review; PostgreSQL work used only throwaway `autoerp_gate_refunds_r1` on `127.0.0.1:5433`, dropped after verification (`pg_database` census: 0 remaining).

## Finding

### F-1 — BLOCKING — the provider is shared, but it is not a drift-proof single source

The intended wiring exists:

- `RefundCompensationAccountProvider` owns the FR/TN and generic definitions (`apps/api/app/Modules/Accounting/Application/Services/RefundCompensationAccountProvider.php:22-44`).
- Both chart-provisioning branches invoke it (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:44-80`).
- The brownfield command obtains its country definitions from it (`apps/api/app/Modules/Accounting/Infrastructure/Commands/BackfillRefundCompensationAccountsCommand.php:86-100`).

However, chart provisioning still has independent definitions in every legacy country seeder: Tunisia `6590`/`709` at `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:285-286,316-317`, France at `apps/api/database/seeders/FranceChartOfAccountsSeeder.php:342-343,385-386`, and generic `6590`/`7090` at `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:201-202,210-211`. Templates can independently supply purpose-bearing rows too.

That duplication is observable because `provisionDefinition()` returns as soon as it finds a purpose holder (`RefundCompensationAccountProvider.php:93-101`), while `assertUsable()` checks only `type` and `is_active` (`:157-178`). It does **not** enforce the provider's country-correct code or name. Therefore a seeder/template may drift in code/name and the purported canonical provider silently accepts it.

Reviewer probe on the throwaway PG database:

```text
6590-X | Drifted write-off | refund_write_off
709-X  | Drifted sales return | sales_return
```

Those rows were created for a fresh TN company, then passed through the real `RefundCompensationAccountProvider::provisionNewCompany()`; no exception or normalization occurred. This directly disproves “no drift possible.” The provisioning test deletes both purpose rows before calling the service (`apps/api/tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php:109-121`), so it proves the missing-account overlay but cannot catch a pre-existing purpose row whose code/name has drifted.

Required correction: make the provider genuinely canonical for provisioning, or at minimum make the purpose-holder path validate the country-correct code and name and add a regression case for a conflicting purpose holder. The backfill's deliberate patch-only brownfield semantics may remain distinct.

## 1. Country definitions and fresh provisioning

Current values are correct and match the shipped charts:

| Plan | Sales return | Refund write-off |
|---|---|---|
| FR/TN | `709`, `Rabais, remises et ristournes accordés` (`RefundCompensationAccountProvider.php:22-26`) | `6590`, `Perte sur remboursement (write-off)` (`:28-32`) |
| Generic | `7090`, `Sales Returns` (`:34-38`) | `6590`, `Refund Write-Off` (`:40-44`) |

The new data-provider test names both fresh-company cases and asserts both purposes, codes, and names (`ProvisioningFlagMatrixTest.php:90-131`). Re-run on PostgreSQL with TestDox:

```text
✔ Pre policy templates still provision both refund compensation purposes with data set "Tunisia"
✔ Pre policy templates still provision both refund compensation purposes with data set "generic fallback"
OK (2 tests, 12 assertions)
```

This passes, but it does not close F-1 because it exercises only definitions absent from the selected template.

## 2. Terminal creation census and client control

Exhaustive production/seeder search found six terminal-creation writers (test factories and test-only raw inserts excluded):

| Writer | Type/result | Evidence |
|---|---|---|
| Admin `store()` | physical, explicitly true before first save | `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:126-155` |
| Device `requestTerminal()` | physical, explicitly true before first save | `TerminalController.php:712-729` |
| `getOrCreateWebTerminal()` | web, flag omitted and DB default remains false | `TerminalController.php:777-825`; assertion at `apps/api/tests/Feature/POS/TerminalCreationFiscalSchemaVersionTest.php:214-226` |
| `VirtualAdminTerminalResolver` | virtual-admin, flag omitted and DB default remains false | `apps/api/app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php:15-55`; column default at `apps/api/database/migrations/tenant/2026_07_31_930000_add_v4_refund_authoring_capability_to_pos_terminals.php:35-37` |
| `CoffeeShopSeeder` | physical, explicitly true before first save | `apps/api/database/seeders/CoffeeShopSeeder.php:1217-1239`; assertion at `apps/api/tests/Feature/Seeders/CoffeeShopSeederTerminalTest.php:29-42` |
| `DemoPharmacySeeder` | physical; only `wasRecentlyCreated` rows are stamped true | `apps/api/database/seeders/DemoPharmacySeeder.php:752-787`; assertion at `apps/api/tests/Feature/Seeders/DemoPharmacySeederTest.php:210-223` |

The PostgreSQL writer probes passed: the main focused bundle includes both controller physical paths, the web path, the virtual resolver, and CoffeeShop; the isolated pharmacy writer passed `1 test, 29 assertions`. A direct real-resolver probe printed `virtual_admin enabled=false`.

The rollout flag is not client mass-assignable:

- It is absent from `Terminal::$fillable` (`apps/api/app/Modules/POS/Domain/Terminal.php:87-113`); a runtime `isFillable()` probe printed `v4_refund_authoring_enabled fillable=no`.
- It is absent from create, device-request, and update validation (`apps/api/app/Modules/POS/Presentation/Requests/CreateTerminalRequest.php:27-41`, `RequestTerminalRequest.php:27-44`, `UpdateTerminalRequest.php:27-45`).
- The two physical HTTP writers assign the server-owned value directly after constructing from validated fields.

Result: only the four physical production/seeder paths produce enabled rows; web and virtual-admin remain false.

## 3. Staging-shaped PostgreSQL migration rehearsal

The real migration file was run through `artisan migrate --path=...` against a fully migrated throwaway PostgreSQL 16 database. Fixture:

- company `CLEAN`: two clean physical tills plus one physical till with a legacy-sealed fiscalized receipt; both refund purposes present;
- company `MISSING`: one clean physical till but only one refund purpose.

Before:

```text
company  terminal  enabled  legacy  refund_purposes
CLEAN    POS01     false    false   2
CLEAN    POS02     false    false   2
CLEAN    POS03     false    true    2
MISSING  POS04     false    false   1
```

Run 1 emitted the structured census:

```json
{"status":"ok","enabled":2,"skipped":2,"reasons":{"legacy-history":1,"missing-accounts":1}}
```

After run 1, both clean tills in the multi-till company were true; the legacy terminal and missing-accounts terminal stayed false. That matches the terminal-by-terminal owner override implemented at `apps/api/database/migrations/tenant/2026_08_28_110000_enable_v4_refund_authoring_by_default.php:65-112`; there is no single-till restriction.

For run 2, the migration record was removed to force the same `up()` to execute again, and every terminal's `(enabled, updated_at)` was snapshotted. It emitted:

```json
{"status":"ok","enabled":0,"skipped":2,"reasons":{"legacy-history":1,"missing-accounts":1}}
```

The post-run SQL census was unchanged and `terminal_rows_changed_on_rerun = 0`. Thus the migration is data-idempotent; already-enabled rows are excluded at `:65-70,105-112`, while still-ineligible rows are re-censused without writes.

## 4. Enable/disable, events, hashes, and device acknowledgement

- The Enable command's executable code is byte-unchanged in the range; only its docblock and `$description` changed. Its three preflights remain single active physical terminal (`apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnableV4RefundAuthoringCommand.php:82-97`), no legacy-sealed history (`:102-126`), and both account purposes (`:128-144`).
- `DisableV4RefundAuthoringCommand.php` has no diff. It still selects either half of the capability state (`:118-134`) and atomically clears enabled plus acknowledged-at (`:185-195`). Its PostgreSQL tests were included in the 63-test green bundle, including end-to-end enable/ack/disable and the S6 half-state repair.
- No Event class is among the 14 changed files. No production payload registry, canonical serializer, hash service, fiscal event engine, projector, or receipt authoring service changed. The only hash-looking additions are fixture hashes inside the new migration test (`apps/api/tests/Feature/Fiscal/Migrations/EnableV4RefundAuthoringByDefaultMigrationTest.php:190-218`). Rules 8 and 19 are unaffected; the new migration uses the existing enums (`migration:5-7,67,76,87-90`) and constructor injection is used for the new provider (`RefundCompensationAccountProvider.php:46`) and its consumers, satisfying rules 9 and 13.
- Born-enabled is compatible with Phase 2. The device reads the server flag (`apps/pos/src/lib/sync/syncService.ts:1444-1447`), persists it and immediately POSTs the acknowledgement whenever it is true (`:1462-1469`); the fiscal-regression fallback does the same (`:1483-1494`). The server handler requires the already-true flag and stamps the first acknowledgement idempotently (`apps/api/app/Modules/POS/Application/Services/V4RefundAuthoringAcknowledgementService.php:31-41`). Nothing requires observing a prior false state, so a terminal true at claim time follows the normal acknowledgement path. The server acknowledgement/guard suite passed on PostgreSQL in the focused bundle. Device Vitest could not be launched from this worktree because its `node_modules` is absent; no install or source mutation was made.

## 5. Manifest

The numerical change is legitimate:

- base: 82 `tests/Feature/Fiscal/**/*Test.php` classes;
- target: 83;
- sole new class: `EnableV4RefundAuthoringByDefaultMigrationTest.php`;
- `Fiscal.classes` moves 82 → 83 and global `gated_ceiling` moves 1196 → 1197 (`apps/api/tests/feature-lane-manifest.json:9,802-805`).

`php apps/api/tools/feature-lane-manifest-check.php` passes and reports 1,197 parked classes. Non-blocking documentation debt: the Fiscal note at line 805 still begins `DELIBERATE RAISE 81 -> 82` and does not record this lane's 82 → 83 raise, unlike recent precedent. The enforced ceiling itself is correct.

## Verification record

| Check | Result |
|---|---|
| PostgreSQL focused backend bundle (provisioning, backfill, terminal writers/types, enable, disable, ack, migration, CoffeeShop) | **OK — 63 tests, 218 assertions** |
| PostgreSQL named TN + generic provisioning cases | **OK — 2 tests, 12 assertions** |
| PostgreSQL DemoPharmacy physical writer | **OK — 1 test, 29 assertions** |
| SQLite broad bundle | 82 passed, 1 skipped, 1 inherited failure out of 83; the failure is the untouched supplier payable sign assertion at `DemoPharmacySeederTest.php:274-298`, reproduced twice in isolation and present at base by blame. The range changes only terminal creation in that seeder and one flag assertion in its test. |
| Real PG migration rehearsal, including forced second run | **PASS — exact census above; 0 terminal rows changed on rerun** |
| PHPStan on all eight changed production/migration/seeder PHP files | **No errors** |
| Pint `--test` on all changed PHP files | `{"result":"pass"}` |
| PHP syntax on all 13 changed PHP files | **No syntax errors** |
| Manifest checker | **PASS** |
| `git diff --check` | **PASS** |

GATEVERDICT: CHANGES
BLOCKING-1: `RefundCompensationAccountProvider` silently accepts purpose-bearing accounts with drifted country code/name, so chart provisioning is not the required drift-proof single source.
