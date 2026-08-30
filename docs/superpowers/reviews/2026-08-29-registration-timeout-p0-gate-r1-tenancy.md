# AutoERP P0-2 registration timeout — adversarial gate r1 (tenancy + ops)

**Lane:** `fix/registration-timeout-p0`  
**Base:** `dev` at `d1f1bb5b4`  
**Review surface:** the complete uncommitted tracked diff plus the untracked runtime seam and Tenant unit-test directory  
**Verdict:** **CHANGES**

## Blocking findings

### B1 — The shutdown callback is permanently accumulated by long-lived workers

`PhpExecutionTimeLimit::registerShutdownHandler()` is a direct call to
`register_shutdown_function()` (`apps/api/app/Modules/Tenant/Infrastructure/Runtime/PhpExecutionTimeLimit.php:16-18`).
Each provisioning call registers a new closure which captures the `Tenant`, the provisioning service through `$this`,
and three flags by reference (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:123-140`).
There is no unregister/clear operation on the seam (`apps/api/app/Modules/Tenant/Infrastructure/Runtime/ExecutionTimeLimit.php:9-16`)
and no success/finally cleanup in the service (`TenantProvisioningService.php:238-255`). PHP permits multiple shutdown
registrations and runs all of them at process shutdown; it provides no unregister API. Consequently, an Octane or queue
worker that reaches this service retains one callback and its captured object graph per attempt until worker exit. This
violates the requested “not left registered across requests” property and creates cumulative memory/work-at-exit risk.

The flags themselves are correct: `$provisioningCompleted = true` is set before the successful return
(`TenantProvisioningService.php:238-246`), so a retained callback cannot compensate a successful provisioning; and
`$compensationPerformed` is set before either cleanup entry (`TenantProvisioningService.php:138-139,247-251`), so the
shutdown/catch sequence cannot double-compensate. The PG test exercises the latter ordering
(`apps/api/tests/Unit/Modules/Tenant/TenantProvisioningTimeLimitTest.php:93-113`), but there is no test that models two
requests in one process or invokes the retained callback after a successful request.

Required before approval: make registration process-lifetime-safe—for example, one process-level delegating callback
owned by a singleton runtime adapter with explicit per-attempt arm/disarm state, cleared on both success and handled
failure—and add a worker-reuse regression proving the first request's tenant/service state is not retained or actionable.

### B2 — The hard 300-second FPM deadline can bypass the compensation callback

The application calls `set_time_limit(300)` only after Laravel has booted and the central tenant/domain rows already
exist (`TenantProvisioningService.php:86-120,142-145`), while the FPM pool kills the worker at 300 seconds from the start
of the request (`apps/api/docker/php/php-fpm.conf:28-29`). Therefore the FPM wall-clock deadline can arrive before the
application's later-started PHP execution deadline. PHP's official documentation says `request_terminate_timeout` kills
the worker and that shutdown functions are not executed when the process is killed by SIGTERM/SIGKILL:
[FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php),
[`register_shutdown_function`](https://www.php.net/manual/en/function.register-shutdown-function.php).

Thus the handler is useful for an ordinary PHP fatal/max-execution shutdown, but it does **not** guarantee compensation
for the operational timeout this lane is intended to survive. The equal nginx read deadline
(`apps/api/docker/nginx/default.conf:120-123`) also leaves no response/cleanup headroom. Required before approval: keep
the application provisioning deadline at 300 seconds but put the upstream FPM/nginx hard deadlines above it with
explicit cleanup/response headroom, or use a recovery mechanism that does not depend on an orderly PHP shutdown.

## Required checks

### 1. Rule 13 / seam shape — PASS

- `TenantProvisioningService` receives `ExecutionTimeLimit` through its constructor as `private readonly`
  (`TenantProvisioningService.php:58-63`).
- The provider binds interface to adapter through the provider container property
  (`apps/api/app/Modules/Tenant/Infrastructure/Providers/TenantServiceProvider.php:25-28`). This is not an `app()`
  helper/service-locator call.
- A literal `app(` sweep over the full diff returned zero matches. The feature test's `$this->app->instance(...)` is
  test-container setup, not the forbidden helper (`apps/api/tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:109-110`).
- The seam is narrow: only time-limit setting and shutdown registration are exposed
  (`ExecutionTimeLimit.php:9-16`). Its missing lifecycle operation is the B1 defect, not scope excess.

### 2. Shutdown flag semantics — PARTIAL / BLOCKED by B1 and B2

- No double compensation: PASS by flag ordering at `TenantProvisioningService.php:138-139,247-251` and PG test
  `TenantProvisioningTimeLimitTest.php:93-113`.
- No compensation after successful provisioning: PASS by the success flag at
  `TenantProvisioningService.php:238-246` and predicate at `:257-263`.
- No handler retained across Octane/queue requests: FAIL; see B1.
- Compensation on the real hard timeout: not guaranteed; see B2.

### 3. Throwable compensation parity with base — PASS, with a test-coverage caveat

The base catch at `d1f1bb5b4` called `compensate($tenant, $databaseCreated)` and rethrew
(`TenantProvisioningService.php@d1f1bb5b4:219-223`). The lane still does so on every ordinary `Throwable`, guarded only
against cleanup already performed by the shutdown path (`TenantProvisioningService.php:247-254`). The body of
`compensate()` is otherwise unchanged from base: end tenancy, revoke tokens, purge/drop the tenant database when
`$databaseCreated`, then delete subscription, identity, domain, and tenant central rows
(`TenantProvisioningService.php:270-295`; base `:231-257`). Therefore the tenant DB drop and tenant-row deletion retain
the prior behavior and ordering.

The new PG test proves the tenant row is absent and the revoker runs once
(`TenantProvisioningTimeLimitTest.php:71-91`), but its fake does not create a physical tenant database and it does not
assert PostgreSQL catalogue absence. That is a non-blocking coverage gap because the drop implementation is byte-for-byte
unchanged from base.

### 4. Timeout scope / staging acceptance — PASS only as a stated staging exception

- `fastcgi_read_timeout 300s` is in the generic regex `location ~ \.php$` block
  (`apps/api/docker/nginx/default.conf:83-129`), not a registration-only location. Every Laravel route internally
  rewritten to `/index.php` receives it.
- `request_terminate_timeout = 300s` is likewise pool-wide (`apps/api/docker/php/php-fpm.conf:28-29`).
- `php.ini` remains `max_execution_time = 60` (`apps/api/docker/php/php.ini:5-9`), so ordinary routes still have the
  60-second PHP ceiling unless they override it.
- A concrete non-registration route now gets the full 300-second stack: `POST /api/v1/imports`
  (`apps/api/app/Modules/Import/Providers/ImportServiceProvider.php:67-73`) calls `set_time_limit(300)` in
  `ImportController::store()` (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:81-84`).

Plain staging decision: **yes, the change allows at least the import-upload route—not only registration—to occupy an
FPM worker and nginx request for up to 300 seconds. That broader exposure is acceptable for tonight's staging hotfix,
but it is not registration-scoped and should not be promoted as a durable production policy.** B2 still blocks the
claimed timeout-compensation guarantee.

### 5. Provisioning log cardinality — PASS (static proof)

There is exactly one repository occurrence of the exact message, and it is executed once per provisioning attempt after
the seam is configured and before `CreateDatabase` is dispatched
(`TenantProvisioningService.php:142-145`). Neither catch nor shutdown logs it. The supplied tests do not fake/assert the
logger, so this is source-path proof rather than behavioral log capture.

### 6. Requested verification — PASS

Run from `apps/api` exactly as scoped:

| Check | Result |
|---|---|
| `DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Unit/Modules/Tenant` | exit 0 — **3 passed**, 15 assertions |
| `php artisan test tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php` | exit 0 — **1 passed, 1 skipped**, 2 assertions; PG-only registration case skipped on SQLite |
| `DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php` | exit 0 — **1 passed, 1 skipped**, 8 assertions; SQLite-only migration-output case skipped on PG |
| `php tools/feature-lane-manifest-check.php` | exit 0 — **1474 Feature classes / 74 groups**, filters uniquely matched against 1878 classes; standing warnings: 1214 parked classes and 1 coverage-debt class |
| `git diff --check d1f1bb5b4` | exit 0 |
| PHP syntax checks for the seam, service, and new unit test | all exit 0 |

GATEVERDICT: CHANGES
BLOCKER: B1 — shutdown callbacks and captured tenant/service state accumulate across long-lived worker requests because there is no unregister/clear lifecycle.
BLOCKER: B2 — equal 300-second PHP/FPM/nginx deadlines let FPM kill the worker before the later-started PHP deadline, bypassing shutdown compensation.
