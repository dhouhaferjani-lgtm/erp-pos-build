# RBAC-W0a-S1 permission cache — handback (2026-09-10)

status: review

**Promotion blocker (round 0) — RESOLVED in gate r1 fix round 1, see "Gate r1 conditions" at the end of this document.** As dispatched, broader preflight failed the CI-lane coverage ratchet because this dispatch adds a test class to the parked Identity lane, and the class was named in no live CI filter. Fix round 1 routed it into the live `t6-phase0b-pgsql` selection and raised the two manifest ceilings deliberately; `php tools/feature-lane-manifest-check.php` is green.

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

---

## Gate r1 conditions (fix round 1, 2026-09-10)

Gate register: `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r1.md` (verdict MERGE-WITH-CONDITIONS, copied verbatim from the reviewer's scratchpad). Every condition below was applied in this worktree on top of `3ba7fa0eb`; no push, no rebase, no merge.

### 1 — CI routing [BLOCKER] — CLOSED

`PermissionCacheTenantScopingTest` is now named in the **live** `t6-phase0b-pgsql` `--filter` alternation (`.github/workflows/ci.yml:1271`), the job that runs on PR→dev, PR→main, push→main and `workflow_dispatch` against real PostgreSQL 16 with real per-tenant databases. `backend-test-pgsql` (`ci.yml:1130`) was deliberately NOT used: `lane/w-lot-a-1a` and `lane/t2-receipt-spine` are both editing that filter, and `t6-phase0b-pgsql`'s stated charter ("the row-level → database-per-tenant flip must be proven on real PostgreSQL … before merge to dev OR main", `ci.yml:1179-1184`) is exactly this surface.

`apps/api/tests/feature-lane-manifest.json`: `groups.Identity.classes` 32 → 33 (`raise_note_2026_09_10`) and `gated_ceiling` 1253 → 1254 (`gated_ceiling_raise_note_2026_09_10_rbac_w0a_s1`). Both notes record that the class is **dark in the parked `feature-lane-tenancy` lane** until `vars.SELF_HOSTED_RUNNER_READY` flips, but **live via the `t6-phase0b-pgsql` selection** — i.e. it is not a phantom, and the allowlist entry is to be removed when that gate is flipped, not before.

### 2 — Boot-time cache reset [MAJOR] — CLOSED

`apps/api/docker/entrypoint.sh` now runs, in order:

```sh
DB_HOST="$DIRECT_DB_HOST" php artisan tenants:run permission:cache-reset 2>/dev/null || true
php artisan permission:cache-reset 2>/dev/null || true
```

`tenants:run` is Stancl's registered command (`vendor/stancl/tenancy/src/TenancyServiceProvider.php:86`) and initializes tenancy per tenant, so each reset forgets that tenant's own suffixed key. `DB_HOST="$DIRECT_DB_HOST"` follows the surrounding tenant-wide steps (`tenants:migrate-rolling` :150, `tenants:seed` :165); the `2>/dev/null || true` shape and "never blocks boot" property are preserved, and the comment above the step now explains the per-tenant key.

**Finding on the central reset (the reviewer asked for it explicitly): the central context has no permission use, but the bare reset is still NOT dead code, so it was KEPT rather than replaced.** Verified: `create_permission_tables` is a **tenant** migration (`database/migrations/tenant/2025_11_29_231806_create_permission_tables.php`) and there is no central one; the only `HasRoles` consumer in `app/` is `App\Modules\Identity\Domain\User` (`app/Modules/Identity/Domain/User.php:23`), a tenant model; `App\Models\SuperAdmin` uses `CentralConnection` and no permission trait. So nothing reads permissions in central context. The bare reset survives for two other reasons: (a) in **compatibility mode** (`TENANCY_DB_PER_TENANT=false`) both listeners early-return and the unsuffixed base key IS the live key — replacing the step outright would have silently regressed compat deployments; (b) it evicts the **legacy pre-W0a-S1 shared key** left behind by the flip (the lane plan's step 8 one-off), which otherwise lingers for the 24 h TTL (`config/permission.php:186`).

### 3 — Cross-tenant data-meaning assertion [MAJOR] — CLOSED

New case `test_permission_created_in_one_tenant_is_invisible_to_another_tenant`. It creates `rbac.w0a.s1.tenant-a-only` in **tenant A's database only**, warms A's registry, switches to tenant B and asserts `$registrar->getPermissions(['name' => …])` is **empty**, that B's registry count equals B's own `permissions` row count and equals A's count minus one (unaffected), then returns to A and asserts the permission is present again with A's original count — isolation is not achieved by losing data.

`phpunit-pgsql.xml:55` pins `CACHE_STORE=array`, which is a per-process bag and cannot reproduce a shared-store collision. **What was done:** a per-test `config(['cache.default' => 'file'])` **before** `$this->registrar->initializeCache()` — required ordering, because `PermissionRegistrar::initializeCache()` resolves the store once and caches the `Repository` on the singleton (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:67-99`). `tearDown` forgets exactly the three keys the class can write on that store (base + one per test tenant); no store is ever flushed wholesale.

RED was captured by unregistering the two listeners in `TenancyServiceProvider::boot()` and re-running: `Failed asserting that actual size 1 matches expected size 0` at the tenant-B assertion — tenant B was served tenant A's permission. The listeners were restored immediately and the file committed unmodified in that respect.

### 4 — Debug output [MINOR] — CLOSED

All four `fwrite(STDERR, …)` calls removed from `tests/Feature/Identity/PermissionCacheTenantScopingTest.php`; the green run's log is now clean.

### 5 — Configured cache key [MINOR] — CLOSED

New `App\Modules\Identity\Application\Support\PermissionCacheKey` (`app/Modules/Identity/Application/Support/PermissionCacheKey.php`): a small value object that constructor-injects `Illuminate\Contracts\Config\Repository`, reads `permission.cache.key` **once**, and exposes `base()` / `forTenant(string)`. It is bound as a container **singleton** in `TenancyServiceProvider::register()` and resolved **eagerly** in `boot()`, so the captured value is the one the application booted with — reading the config entry back later is unsafe precisely because the listeners rewrite it on every tenancy transition. Both listeners now inject it and the hardcoded `ScopePermissionCacheToTenant::BASE_KEY` constant is gone. A deployment that customises `permission.cache.key` keeps its value; when the config equals the shipped default (`spatie.permission.cache`) behaviour is byte-identical, which the unchanged key assertions in the two original tests still pin.

### 6 — Residuals recorded [MINOR] — this section

- **Compat-mode guard gap (still open, accepted).** The early return at `ScopePermissionCacheToTenant.php:20-22` / `RestoreCentralPermissionCache.php:16-18` is not exercised by a test. The compat leg at `PermissionCacheTenantScopingTest.php:88-93` drives an **unprovisioned** tenant through `TenancyResolver::initializeIfProvisioned()` (`app/Modules/Tenant/Application/Services/TenancyResolver.php:96-106`), which returns `false` and never fires `TenancyInitialized`, so the shared key is preserved by a path that never reaches the guard. The plan sanctions that shape ("must not manufacture a tenant cache context"). The uncovered case is a **provisioned** tenant reached in compat mode via `$tenant->run()` / `QueueTenancyBootstrapper::initializeTenancyForQueue()` (`vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:93`), which DOES fire the event — there the guard is the only thing keeping the shared key. Closing it is one `$tenant->run()` case; not taken in this round because it was scoped as an alternative to, not a requirement alongside, this record.
- **Shared-trait blast radius: 11 other classes, none in any CI filter.** The `tests/Traits/ProvisionsTenantDatabases.php:37-52,90-94` PostgreSQL branch (added by the implementation commit) changes behaviour for `TenantScopedCommandForEachTenantTest`, `SubledgerReconciliationCommandTest`, `CheckPendingEnrichmentsDriftDbPerTenantTest`, `PreflightFiscalGateCommandTest`, `BatchExpiryDailyCheckCommandTest`, `MarketplaceScheduledCommandsTest`, `DetectFraudPatternsDriftDbPerTenantTest`, `VerifyFiscalChainGenesisDocumentTest`, `ChannelReconcileCommandDbPerTenantTest`, `VerifyPosChainCommandDbPerTenantTest`, `ExpireStockReservationsCommandTest`. None appears in any `.github/workflows/ci.yml` `--filter`, so today's CI blast radius is nil, and the previous PG path was non-functional anyway (it ran `select … from sqlite_master` on a pgsql connection). **Re-run these on PostgreSQL the day a lane gate flips.**
- **Bounded in-memory staleness (accepted).** `PermissionRegistrar::$permissions` is cleared only on tenancy transitions (`ScopePermissionCacheToTenant.php:31`, `RestoreCentralPermissionCache.php:21`). A worker mid-job under tenant A does not observe a role edit made concurrently by an HTTP request in the same tenant: `forgetCachedPermissions()` (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:140-146`) evicts the shared cache entry but cannot reach the worker's in-process collection. It is bounded to the remainder of one job, because `QueueTenancyBootstrapper` ends tenancy after every tenant-aware job (`QueueTenancyBootstrapper.php:96-121`) and `Tenancy::end()` fires `TenancyEnded`.
- **Ceiling recompute, not textual merge.** `lane/w-lot-a-1a` (`Identity` 32 → 39, `gated_ceiling` 1250 → 1264) and `lane/t2-receipt-spine` both edit `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml`. This lane's 1253 → 1254 / 32 → 33 is **union arithmetic against its own base `d418a2656`**. Whoever lands second must RECOMPUTE against dev's current values (dev's ceiling plus this lane's one Identity class), never resolve to a number a lane wrote in an earlier round. The `ci.yml` hunks do not collide: the two in-flight lanes edit the `backend-test-pgsql` filter (`@@ -1130`, `@@ -1138`), this lane edits the `t6-phase0b-pgsql` filter (`:1271`).

### Fix-round verification (all commands from `apps/api`, PHPUnit BY PATH only)

PG leg env on every run: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r`.

```text
# RED (listeners unregistered), new case only
F                                                                   1 / 1 (100%)
1) …::test_permission_created_in_one_tenant_is_invisible_to_another_tenant
Failed asserting that actual size 1 matches expected size 0.
tests/Feature/Identity/PermissionCacheTenantScopingTest.php:215
FAILURES! Tests: 1, Assertions: 6, Failures: 1.

# GREEN (listeners restored), whole class
...                                                                 3 / 3 (100%)
Time: 01:00.414, Memory: 155.00 MB
OK (3 tests, 38 assertions)

# SQLite resolver regressions
.............                                                     13 / 13 (100%)
OK (13 tests, 25 assertions)

# PHPStan level 8, six touched paths
 [OK] No errors

# Pint, same six paths
{"result":"pass"}

# php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1519 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1930 test classes across all suites.
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1254 class(es) …
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) …
```
