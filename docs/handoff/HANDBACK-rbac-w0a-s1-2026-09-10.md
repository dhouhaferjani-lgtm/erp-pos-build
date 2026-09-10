# RBAC-W0a-S1 permission cache — handback (2026-09-10)

status: review

**Promotion blocker:** broader preflight fails the CI-lane coverage ratchet because this dispatch adds a test class to the parked Identity lane. Implementation/path checks are green; overall preflight is **not green**. CI routing needs an orchestrator-owned follow-up before merge.

Branch: `lane/rbac-w0a-s1-permission-cache`  
Implementation commit: `7fc91c61358fd64b0190465c2660ba43294a1582`  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a-s1`  
Base: created from local `dev` at `fe5d56bd2`, rebased onto `d418a2656` before review. The intervening commit added only an unrelated T-2 review document; no executable files changed.

Dispatch source: `../rbac-audit/docs/handoff/CODEX-DISPATCH-RBAC-W0a-S1-permission-cache-2026-09-10.md` (the supplied path was absent from shared `dev`). No merge or push performed. The orchestrator owns the `tenancy-authz-reviewer` gate and promotion.

## Step evidence

- [x] **1 — PostgreSQL provisioning helper.** Added `Bus::dispatchSync(new CreateDatabase(...))`, teardown database deletion, and `MigrateDatabase` for PG; existing SQLite file/schema behavior is unchanged. Vendor was copied from the main checkout and `composer dump-autoload` executed inside this worktree's `apps/api`. Created private database with `createdb autoerp_test_r`. No shared default test database used.
- [x] **2 — Resolver and queue lifecycle tests.** Added two `#[Group('pg')]` tests without `RefreshDatabase`. Compatibility uses an unprovisioned tenant through the real resolver. Two provisioned tenant transitions use the resolver. Queue payloads use real dispatch, central database queue storage, and two real worker runs. No direct `tenancy()->initialize()` in the test.
- [x] **3 — Real red run before production edits.** Both tests failed on incorrect shared keys; compatibility assertions passed. Expected key literals were used so the test could fail on behavior before the listener class existed.

Command from `apps/api` (the local PG role is `houssamr`; both database variables are set on **every PG leg**, run serially):

```sh
DB_USERNAME=houssamr DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/PermissionCacheTenantScopingTest.php
```

```text
FAIL  Tests\Feature\Identity\PermissionCacheTenantScopingTest
  ⨯ resolver keeps compatibility shared and rekeys two provisioned ten… 11.22s  
  ⨯ database queue processes two tenant payloads then restores central…  6.34s  
  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Identity\PermissionCacheTenantScopingTest > resolve…   
  Failed asserting that two strings are identical.
  -'spatie.permission.cache.39956142-4d72-451e-b227-3e32456a6e31'
  +'spatie.permission.cache'
  

  at tests/Feature/Identity/PermissionCacheTenantScopingTest.php:93
     89▕         $tenantA = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-a'));
     90▕         $tenantB = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-b'));
     91▕ 
     92▕         self::assertTrue($resolver->initializeIfProvisioned($tenantA));
  ➜  93▕         self::assertSame('spatie.permission.cache'.'.'.$tenantA->id, $this->registrar->cacheKey);
     94▕         tenancy()->end();
     95▕         self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
     96▕ 
     97▕         self::assertTrue($resolver->initializeIfProvisioned($tenantB));

  1   tests/Feature/Identity/PermissionCacheTenantScopingTest.php:93

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Identity\PermissionCacheTenantScopingTest > databas…   
  Failed asserting that two arrays are identical.
   Array &0 [
  -    'a' => 'spatie.permission.cache.b65281a4-ec04-43e8-8c70-8ed97c77cd04',
  -    'b' => 'spatie.permission.cache.6bb1f0ca-13fd-4593-9396-08c0b26695d5',
  +    'a' => 'spatie.permission.cache',
  +    'b' => 'spatie.permission.cache',
   ]
  

  at tests/Feature/Identity/PermissionCacheTenantScopingTest.php:135
    131▕         // `connection` is positional, while `--once` is the real option.
    132▕         $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertExitCode(0);
    133▕         $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertExitCode(0);
    134▕ 
  ➜ 135▕         self::assertSame([
    136▕             'a' => 'spatie.permission.cache'.'.'.$tenantA->id,
    137▕             'b' => 'spatie.permission.cache'.'.'.$tenantB->id,
    138▕         ], PermissionCacheProbeJob::$observedKeys);
    139▕         self::assertFalse(tenancy()->initialized);

  1   tests/Feature/Identity/PermissionCacheTenantScopingTest.php:135


  Tests:    2 failed (14 assertions)
  Duration: 17.61s
```

- [x] **4 — Listeners.** Constructor-injected registrar; DB-per-tenant guard; tenant key / central base-key restoration; `initializeCache()` then `clearPermissionsCollection()`. No persistent cache flush during switching.
- [x] **5 — Wiring.** Both permission listeners registered after the existing bootstrap/revert registrations. Compatibility remains on the shared base key.
- [x] **6 — Flush caller audit.** `rg -n -- '->forgetCachedPermissions\(' apps/api/app` returns exactly the three existing callers below. No caller edits required.

| Caller | Context / outcome |
|---|---|
| `GenerateRecurringExpensesCommand.php:192` | `TenantScopedCommand::forEachTenant()` initializes tenancy only for provisioned DB-per-tenant iterations. Flush uses that tenant's key; compatibility uses the shared table/key. |
| `BatchExpiryDailyCheckCommand.php:213` | Same `forEachTenant()` lifecycle and key behavior. |
| `TreasuryAlertRecipients.php:32` | Runs within caller-selected tenant database context; registrar already has that tenant's key. Team-ID handling is unchanged. |

Citation check: `git diff 9d6bc75c1 fe5d56bd2 --` the provider, permission/tenancy config, provisioning trait and entrypoint produced no changes. Installed registrar still exposes `cacheKey:42`, `initializeCache():67`, `CacheManager::store():90/98`, and `clearPermissionsCollection():173`; queue initialization remains `QueueTenancyBootstrapper.php:93`. Listener files and new test were absent. `CacheTenancyBootstrapper` remains configured; default permission cache key remains `spatie.permission.cache`.

- [x] **7 — Path-scoped verification.** No full PHPUnit suite was run. PG runs use **private `autoerp_test_r`** for both default and central databases, serially.

```sh
# apps/api; final clean PG runs
DB_USERNAME=houssamr DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r php artisan test -c phpunit-pgsql.xml --display-warnings tests/Feature/Identity/PermissionCacheTenantScopingTest.php
DB_USERNAME=houssamr DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r php artisan test -c phpunit-pgsql.xml --display-warnings tests/Feature/Treasury/TreasuryAlertRecipientsTest.php
```

Actual two-tenant queue observations and final green output:

```text
After dispatch a tenancy end: spatie.permission.cache
After dispatch b tenancy end: spatie.permission.cache
Job a: spatie.permission.cache.e2d53efe-389f-4997-b8d8-8a3ecec5d500
After queue worker: spatie.permission.cache
Job b: spatie.permission.cache.6bdfdf7f-6582-40d4-b3ee-1d70665fc877
After queue worker: spatie.permission.cache

   PASS  Tests\Feature\Identity\PermissionCacheTenantScopingTest
  ✓ resolver keeps compatibility shared and rekeys two provisioned tena… 7.32s  
  ✓ database queue processes two tenant payloads then restores central…  7.10s  

  Tests:    2 passed (26 assertions)
  Duration: 14.49s
```

```text
PASS  Tests\Feature\Treasury\TreasuryAlertRecipientsTest
  ✓ recipients are company scoped deny direction                         5.30s  
  ✓ resolution survives multi tenant iteration                           9.53s  
  ✓ membership must be active                                            3.96s  
  ✓ notification uses only database channel and a stable type            0.10s  

  Tests:    4 passed (11 assertions)
  Duration: 18.92s
```

Default-driver regressions executed by the path-scoped preflight:

```sh
php artisan test tests/Feature/Identity/ResolveTenancyMiddlewareTest.php tests/Feature/Identity/TenancyResolverFailClosedTest.php
```

```text
PASS  Tests\Feature\Identity\ResolveTenancyMiddlewareTest
  ✓ bearer token resolves tenant with no tenant header                   3.64s  
  ✓ session tenant id is resolved on the cookie branch                   0.07s  
  ✓ no token and no session resolves nothing                             0.06s  
  ✓ resolver does not break the request in the current single schema se… 0.06s  
  ✓ middleware class exists                                              0.06s  
  ✓ suspended tenant is blocked at request time for token auth           0.06s  
  ✓ archived tenant is blocked at request time                           0.06s  
  ✓ suspended tenant is blocked on the cookie session branch             0.06s  
  ✓ active tenant is not blocked                                         0.06s  

   PASS  Tests\Feature\Identity\TenancyResolverFailClosedTest
  ✓ single schema mode skips silently and does not fail closed           0.07s  
  ✓ resolver returns false in single schema mode                         0.06s  
  ✓ db mode fails closed when present tenant cannot initialize           0.06s  
  ✓ db mode present uninitializable tenant never reaches downstream      0.06s  

  Tests:    13 passed (25 assertions)
  Duration: 4.42s
```

PHPStan level 8 (all five touched PHP files, no suppressions/baseline changes):

```sh
./vendor/bin/phpstan analyse --level=8 --memory-limit=2G app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php app/Providers/TenancyServiceProvider.php tests/Traits/ProvisionsTenantDatabases.php tests/Feature/Identity/PermissionCacheTenantScopingTest.php
```

```text
[OK] No errors
```

Pint with the same five paths: `{"result":"pass"}`. `git diff --check`: clean. Private DB cleanup check: zero remaining `permission-%` tenants and zero probe jobs. Total requested test evidence: **19 passed, 62 assertions**.

- [ ] **8 — Reviewer gate (orchestrator-owned).** Ready for `tenancy-authz-reviewer`; no independent reviewer was run by this lane. No merge, push, deployment, or promotion performed.

## Deviations and environment repairs

1. PHPStan rejects the supplied nullable `Model|Tenant` method call. The tenant listener now checks the `Stancl\Tenancy\Contracts\Tenant` interface and throws `LogicException` if initialized tenancy lacks a valid tenant. Valid lifecycle behavior matches the dispatch; malformed context cannot generate an empty suffix.
2. The test uses literal expected keys, independent of the production constant, allowing the mandatory behavioral red run before production classes exist.
3. Laravel's `artisan()` returns `PendingCommand|int`. The test checks `PendingCommand` explicitly and calls `run()` after setting the exit-code expectation. Keeping the command in a variable otherwise defers execution until destruction; the intermediate rerun exposed this, and explicit execution fixed it. The test also asserts central restoration after **each** worker and prints actual keys to stderr.
4. Added a non-PG teardown guard so a SQLite skip cannot query an unmigrated `jobs` table. SQLite is not counted as lifecycle evidence.
5. The first environment attempt hit nonexistent PG role `root`; reran using local role `houssamr`. Early green/Treasury assertions emitted missing-`.env` warnings; added an ignored test-only `.env` with a generated local key and array cache, then reran both PG paths cleanly. No secrets committed.
6. Worktree frontend dependencies were linked to existing installed `node_modules` for the required broader preflight. PHP vendor is a real copy with regenerated worktree autoloads.
7. Commit title follows the user-provided `Phase <major.minor.patch>` convention; attribution follows the existing `Co-Authored-By: Codex <noreply@openai.com>` trailer. No fabricated Claude session attribution.

## Deployment

`apps/api/docker/entrypoint.sh:176` already runs `php artisan permission:cache-reset` unconditionally at container boot. The next normal deployment clears the former shared key automatically; no additional manual reset is required. **`SYNC_PERMISSIONS_ON_BOOT` was not touched**, and neither was any other entrypoint code. Compatibility-mode deployment behavior is unchanged.

Staging topology and its tenant-database count remain deployment-preflight facts to verify before promotion. Do not promote on a manual test day.

## Broader preflight — not green

Executed from worktree root (PHPUnit remains path-scoped):

```sh
PREFLIGHT_SCOPE=paths \
PREFLIGHT_TEST_PATHS='tests/Feature/Identity/ResolveTenancyMiddlewareTest.php tests/Feature/Identity/TenancyResolverFailClosedTest.php' \
PREFLIGHT_PINT_PATHS='app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php app/Providers/TenancyServiceProvider.php tests/Traits/ProvisionsTenantDatabases.php tests/Feature/Identity/PermissionCacheTenantScopingTest.php' \
PREFLIGHT_PHPSTAN_PATHS='app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php app/Providers/TenancyServiceProvider.php tests/Traits/ProvisionsTenantDatabases.php tests/Feature/Identity/PermissionCacheTenantScopingTest.php' \
./scripts/preflight.sh
```

Pint, PHPStan level 8, both resolver test paths (13 tests/25 assertions), generated DTO types drift, permission-map drift, TypeScript, ESLint (0 errors; 6410 existing warnings), TanStack-key audit, design-system audit, and quantity-display audit passed. The manifest check then stopped the script:

```text
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Identity" now holds 33 class(es), ceiling is 32. Its lane "feature-lane-tenancy/Identity" is wired but parked behind an unflipped execution gate, so a new class here still runs nowhere — the ceiling stays enforced until the gate is flipped.
  ✗ GATED-LANE COVERAGE GREW: 1254 class(es) now sit in lanes parked behind an unflipped execution gate, ceiling is 1253. Lower the ceiling when a gate is flipped or a class leaves; raising it is a deliberate edit.
```

This is **caused by the required new test**, not an unrelated baseline failure. Inspection of `.github/workflows/ci.yml` confirms the new `PermissionCacheTenantScopingTest` is absent from the existing live PG filters (including the tenancy filter at line 1271). `apps/api/tests/feature-lane-manifest.json` keeps Identity parked. The orchestrator must arrange real PG execution/coverage accounting before promotion; merely accepting local PASS output leaves CI coverage missing. No ceiling, manifest, workflow, or execution gate was changed because the dispatch explicitly limits source changes to its five-file list. Checks after the manifest stop did not execute.

A separate SQLite guard check (`php artisan test --group=pg --display-warnings tests/Feature/Identity/PermissionCacheTenantScopingTest.php`) cleanly skipped both methods (0 assertions) with the intended PostgreSQL-only reason, confirming skip teardown is safe. It is not PG lifecycle evidence.

Full local logs: `docs/sessions/rbac-red.log`, `rbac-green.log`, `rbac-treasury.log`, `rbac-phpstan.log`, `rbac-preflight.log`, and `rbac-sqlite-skip.log` (ignored session artifacts).
