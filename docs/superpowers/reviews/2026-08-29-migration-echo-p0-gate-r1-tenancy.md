# migration-echo-p0 — adversarial gate r1 (tenancy/authz + integration)

Date: 2026-08-29  
Reviewed range: `cdce47570..93fc6fc3a`  
Lens: tenancy/authz, registration integration, migration output, regression strength  

## Repository state

The prompt described uncommitted changes, but `git status --short --branch` reported a clean `fix/migration-echo-p0` worktree and the lane is already committed at `93fc6fc3a`. Current local `dev` is `50d71e4fa`; the stated base and actual merge-base are both `cdce47570`. I therefore reviewed the 17-file committed diff from `cdce47570` to `93fc6fc3a`. There were no untracked files.

## Blocking findings

### B1 — The HTTP regression arm does not execute tenant migrations in-request

The test posts to the real route (`tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:92`; route binding at `app/Modules/Identity/routes.php:26`), so the controller is not mocked. However, both test configurations force `TENANCY_DB_PER_TENANT=false` (`phpunit.xml:49`, `phpunit-pgsql.xml:64`), and this test never overrides that setting. `AuthController::register()` therefore takes its shared-database branch at `app/Modules/Identity/Presentation/Controllers/AuthController.php:355-362`; it does not call `TenantProvisioningService`, whose real in-request `dispatchSync(new MigrateDatabase($tenant))` is at `app/Modules/Tenant/Application/Services/TenantProvisioningService.php:117-120`.

The class is still RED against the bare-echo base semantics, but only through its second, out-of-request arm: it deletes the eight migration rows at `tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:69-72`, starts an output buffer at `:74`, and dispatches the real Stancl job at `:76`. On the base, the first bare census `echo` enters that buffer (for example `[W4-1] invented DEFAULT-lot expiries found: 0\n`), so the empty-output assertion at `:82-86` fails. That proves synchronous migration output is captured; it does not prove the `201` response arm traverses the production provisioning branch. The manifest note's in-request claim is therefore stronger than the test.

### B2 — The stdout regression guard omits explicitly scoped writer forms

`tests/Unit/Migrations/NoBareEchoInMigrationsTest.php:32` detects line-leading `echo`/`print`/`printf` and `fwrite(STDOUT, ...)`, but does not detect `$this->getOutput()`, `dump()`, or an inline `if (...) echo`. A direct probe against the test's regex returned `0` for all three and `1` only for line-leading `echo` and `fwrite(STDOUT, ...)`. Thus the named guard can stay green after reintroducing two writer families explicitly listed in this gate.

The current tree itself is clean under a broader audit: recursive search found no executable `echo`, `print`, `printf`, `fwrite(STDOUT, ...)`, `$this->getOutput()`, or `dump()` in `database/migrations`. The only `echo` matches are shell snippets inside comments in the 2026-08-23 held-order/voucher migrations. Stancl's job calls `Artisan::call('tenants:migrate', ...)` at `vendor/stancl/tenancy/src/Jobs/MigrateDatabase.php:32-36`; neither that job nor `vendor/stancl/tenancy/src/Commands/Migrate.php` contains a prohibited raw writer. The command's pre-existing `$this->line(...)` at `Migrate.php:52` writes to Artisan's buffered console output, not PHP stdout.

### B3 — UOM failure logging lost the exception and tenant context

The base migration logged `units.seed_failed` with the exception object and tenant id, and logged the outer `units.visibility_migration_failed` with the same diagnostic context. The replacement at `database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php:78-89` logs only the grep-facing census/error text through `MigrationOutput`; the throwable (and, on the inner failure, tenant id) is discarded. This is an operational observability regression, not merely deduplication. It also means the adjusted test no longer verifies the failure's exception/tenant association (`tests/Feature/Uom/UnitsInvariantTest.php:108-120`). Preserve one structured failure log or extend the helper to retain context without duplicating the operator line.

### B4 — One of the four adjusted files weakens negative assertions

The Company, Inventory, and Treasury adjustments move the same message checks from captured stdout to logged messages without dropping their data assertions. `UnitsInvariantTest`, however, falls from 34 assertion/expectation call sites on the base to 32 now. In the already-correct, second-up, and half-state cases (`tests/Feature/Uom/UnitsInvariantTest.php:56-105`), the base explicitly asserted that `units-seeded` was absent; the new version only checks the expected census log plus empty stdout. Because `Log::spy()` accepts additional calls, a false `units-seeded` log can now pass. The mid-seed failure case also replaces the exact structured exception/tenant expectation with a generic string at `:119`. This violates the requested no-weakened-assertions comparison.

## Non-blocking verification

### Census strings, logging, and operator behavior

The actual census stdout expressions are byte-preserved across all eight migrations: the diff changes only their sink from `echo $message.PHP_EOL` (or the identical inline expression) to `MigrationOutput`, whose write is still `$message.PHP_EOL` at `app/Shared/Database/MigrationOutput.php:25-29`. Sink points are:

- W4-1 expiry census: `2026_08_26_100100_null_invented_default_lot_expiries.php:91,102,133,136,197,225`.
- I-1 cash-tender census: `2026_08_27_100000_census_cash_tender_invariant_violations.php:65,71,99,119,134,143,157,218`.
- Payment-method scope/collision census: `2026_08_28_100000_enforce_company_scoped_payment_method_codes.php:44,50,57,72,98,179`.
- Default-location collision: `2026_08_29_100000_backfill_default_location_code_f1.php:49-51`.
- Product SKU scope/collision census: `2026_08_30_100000_enforce_company_scoped_product_skus.php:32,38,45,60,84,165`.
- Variant SKU scope/collision census: `2026_08_30_100100_enforce_company_scoped_variant_skus.php:30,36,42,49,65,90,136`.
- Partner VAT scope/collision census: `2026_08_30_100200_enforce_company_scoped_partner_vat_numbers.php:31,37,44,59,84,165`.
- Units census/seed/error lines: `2026_08_30_100300_ensure_units_visible_per_company.php:64,79,85-89`.

Former `echo` + same-message `Log::info` sites now make one helper call, so the grep-facing line is not double-logged; the four uniqueness migrations retain their distinct structured collision `Log::error` calls (payment `:119`, product `:105`, variant `:111`, partner `:105`). The UOM structured failure-context loss is B3 above.

The helper uses the `App` and `Log` facades correctly and never calls `app()` (`app/Shared/Database/MigrationOutput.php:7-28`). In FPM HTTP, `runningInConsole()` is false; in PHPUnit CLI, `runningUnitTests()` is true; both suppress stdout while retaining the log. In an Artisan process or queue worker, `runningInConsole()` is true and `runningUnitTests()` is false, so behavior for `tenants:migrate` remains console-visible. A staging operator watching the `tenants:migrate` worker/process log will see the same byte-identical `[W4-1]`, `[I-1]`, uniqueness-tag, `default-location-code-collision`, and `units.*`/`units-*` census lines, one newline-terminated line per emission; each line is also sent once to the Laravel log by the helper.

### Manifest

`php tools/feature-lane-manifest-check.php` exited 0: 1472 Feature classes across 74 groups, with 1213 classes parked behind the execution gate and the debt ceiling intact. `Identity` 31 -> 32 and `gated_ceiling` 1212 -> 1213 exactly account for the one new Feature class. The chained `DELIBERATE RAISE ... === prior note ===` format follows existing manifest precedent and is acceptable. The note should be corrected when B1 is fixed because its current real-register/in-request wording is inaccurate.

### Requested test runs

SQLite, by path, from `apps/api`:

```text
php artisan test \
  tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php \
  tests/Unit/Shared/Database/MigrationOutputTest.php \
  tests/Unit/Migrations/NoBareEchoInMigrationsTest.php \
  tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php \
  tests/Feature/Inventory/NullInventedDefaultLotExpiryMigrationTest.php \
  tests/Feature/Treasury/PaymentMethodCashTenderTest.php \
  tests/Feature/Uom/UnitsInvariantTest.php
```

Exit 0: **49 passed, 1 skipped, 797 assertions**. The skip is the pre-existing PostgreSQL-only NOT NULL re-tightening case in `NullInventedDefaultLotExpiryMigrationTest`.

PostgreSQL, using the mandated leg exactly:

```text
DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php
```

Exit 0: **2 passed, 8 assertions**.

GATEVERDICT: CHANGES
BLOCKER: Make the HTTP response arm execute the real DB-per-tenant registration/provisioning path with `MigrateDatabase` inside the request.
BLOCKER: Expand `NoBareEchoInMigrationsTest` to reject every scoped stdout writer, including `$this->getOutput()`, `dump()`, and non-line-leading echo/print/printf forms.
BLOCKER: Preserve structured UOM failure diagnostics (throwable and tenant context) while avoiding duplicate grep-facing lines.
BLOCKER: Restore the UOM tests' negative `units-seeded` assertions and exact failure-context assertion so none of the four adjusted files is weaker than the base.
