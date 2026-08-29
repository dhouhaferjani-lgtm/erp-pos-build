# AutoERP P0-2 registration timeout — adversarial gate r2 (tenancy + ops)

**Lane:** `fix/registration-timeout-p0`  
**Reviewed HEAD:** `92b30042e`  
**Base:** `dev` at `d1f1bb5b4`  
**Scope:** round-1 blockers B1/B2 plus the explicitly retained round-1 PASS items  
**Verdict:** **APPROVED**

## Reviewed history

`git log -3 --oneline --decorate`:

1. `92b30042e` — `fix(tenant): P0-2 — registration provisioning survives load (time-limit seam 240 s, FPM 300 s, nginx 330 s) and never leaves an orphan tenant (Throwable + shutdown compensation with per-request pending slot)`
2. `2baa936a5` — `review: registration-timeout P0-2 — Codex gate r1 (tenancy+ops) CHANGES (B1 shutdown-callback lifecycle, B2 deadline ordering PHP<FPM<nginx)`
3. `d1f1bb5b4` — `docs(session-F): tester checklist — H cleanup (batch 1, 8d629ac89) 6 lines, G-3c line, Phase-2 deferrals in don't-re-report`

## B1 — one lazy process dispatcher and a cleared per-request slot: PASS

`PhpExecutionTimeLimit` now separates the process-lifetime dispatcher from the request-lifetime compensation closure:

- The adapter owns a registration guard and exactly one nullable pending slot (`apps/api/app/Modules/Tenant/Infrastructure/Runtime/PhpExecutionTimeLimit.php:11-13`).
- `registerShutdownHandler()` stores/replaces the current request closure before testing the registration guard (`:20-25`), and calls `register_shutdown_function()` only on the first registration (`:28-29`). It therefore accumulates neither native callbacks nor old tenant/service closures on subsequent attempts through the same adapter.
- `clear()` nulls the pending slot (`:32-35`). The dispatcher snapshots that slot, clears it before invoking compensation, and then invokes only the snapshot (`:37-41`); even a compensation exception cannot leave that request closure armed.
- The container binding is a singleton (`apps/api/app/Modules/Tenant/Infrastructure/Providers/TenantServiceProvider.php:25-28`), so repeated resolutions in a long-lived application process use the guarded adapter.

The provisioning service arms the pending slot before `CreateDatabase`/`MigrateDatabase` (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:122-147`). It marks successful provisioning complete before return (`:238-246`) and unconditionally calls `clear()` in `finally` (`:254-256`). That `finally` runs on the success return, after the ordinary catch compensates and rethrows (`:247-253`), and if `compensate()` itself throws. Thus every method exit after arming disarms the slot.

The real-adapter regression registers two closures but observes one native dispatcher, proves only the current closure is actionable, invokes the dispatcher twice without replay, and proves an explicitly cleared later request is inert (`apps/api/tests/Unit/Modules/Tenant/PhpExecutionTimeLimitTest.php:51-81`). The service regression reuses one service/runtime across two attempts, observes one dispatcher registration and two ordinary compensations, then invokes the retained dispatcher and observes no cross-request third compensation (`apps/api/tests/Unit/Modules/Tenant/TenantProvisioningTimeLimitTest.php:125-155`). Container identity is separately pinned at `TenantProvisioningTimeLimitTest.php:71-77`.

Result: round-1 blocker B1 is resolved. There is one lazy dispatcher, one replaceable pending request slot, no closure accumulation, and no actionable compensation state after either request exits.

## B2 — ordered application, FPM, and nginx deadlines: PASS

The deployed ordering is now strict and leaves 60 seconds between the PHP provisioning deadline and FPM termination, then 30 seconds between FPM and nginx:

| Layer | Deadline | Evidence |
|---|---:|---|
| Provisioning PHP limit | 240 s | `ExecutionTimeLimit::PROVISIONING_SECONDS` at `apps/api/app/Modules/Tenant/Infrastructure/Runtime/ExecutionTimeLimit.php:11`; applied before database dispatch at `TenantProvisioningService.php:142-145` |
| FPM hard termination | 300 s | pool-wide `request_terminate_timeout = 300s` at `apps/api/docker/php/php-fpm.conf:28-30` |
| nginx FastCGI read | 330 s | `fastcgi_read_timeout 330s` at `apps/api/docker/nginx/default.conf:120-124`, inside the generic PHP regex block `location ~ \.php$` opened at `:83-84` |

The ordinary PHP configuration remains unchanged at `max_execution_time = 60` (`apps/api/docker/php/php.ini:5-9`). The provisioning log has the matching literal `time_limit=240` (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:143`). The exact message occurs once in application source and is on the single path between setting the seam and dispatching `CreateDatabase` (`:142-145`).

The nginx and FPM changes remain generic rather than registration-only: the 330-second read timeout applies to the `location ~ \.php$` block and the 300-second kill timeout applies to the whole FPM pool. This is the same explicitly accepted staging-hotfix scope from r1; the unchanged 60-second `php.ini` ceiling continues to constrain ordinary PHP routes that do not override it.

Result: round-1 blocker B2 is resolved. PHP shutdown compensation has operational headroom before FPM termination, and nginx remains alive beyond the FPM deadline.

## Retained round-1 PASS items

### Rule 13 / constructor injection: PASS

`TenantProvisioningService` constructor-injects `ExecutionTimeLimit` as `private readonly` (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:58-63`). The provider uses its injected container property to declare the singleton (`apps/api/app/Modules/Tenant/Infrastructure/Providers/TenantServiceProvider.php:25-28`); it does not call the forbidden `app()` helper. A literal `app(` sweep over the scoped application/test diff from `d1f1bb5b4` found zero helper calls. Test container access via `$this->app` is not the helper.

### Throwable compensation parity with base: PASS

The lane still catches every `\Throwable`, compensates unless shutdown already did so, and rethrows (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:247-253`). The compensation body remains unchanged from base `d1f1bb5b4`: it ends tenancy and revokes tokens (`:272-284`), purges and attempts to delete the physical tenant database when `$databaseCreated` (`:285-291`), then deletes child central records and the tenant directory row last (`:294-297`). The PG regression confirms one revocation and absence of the tenant row after a migration `Error` (`apps/api/tests/Unit/Modules/Tenant/TenantProvisioningTimeLimitTest.php:79-99`); shutdown/catch ordering is covered at `:101-123`.

### Provisioning log cardinality: PASS

The exact `tenant.provisioning time_limit=240` message has one repository application-source occurrence, at `TenantProvisioningService.php:143`. It is emitted once per attempt after the limit is set and before `CreateDatabase`; neither catch, compensation, nor shutdown emits it.

## Required verification

All PHP commands were run from `apps/api`. PostgreSQL was accessed only with the prescribed `autoerp_test_j` environment and `phpunit-pgsql.xml` configuration.

| Check | Result |
|---|---|
| `DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Unit/Modules/Tenant` | exit 0 — **6 passed**, 31 assertions |
| `php artisan test tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php` | exit 0 — **1 passed, 1 skipped**, 2 assertions; PG-only registration case skipped on SQLite |
| `DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php` | exit 0 — **1 passed, 1 skipped**, 8 assertions; SQLite-only migration-output case skipped on PG |
| `php tools/feature-lane-manifest-check.php` | exit 0 — **1474 Feature classes / 74 groups**; filters uniquely matched against 1879 test classes; standing warnings remain 1214 parked classes and 1 coverage-debt class |
| `git diff --check HEAD^ HEAD` | exit 0 |

No scoped blocker remains.

GATEVERDICT: APPROVED
