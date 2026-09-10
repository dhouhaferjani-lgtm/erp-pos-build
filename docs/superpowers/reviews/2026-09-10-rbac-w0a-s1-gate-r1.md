# Gate r1 — lane/rbac-w0a-s1-permission-cache (RBAC W0a-S1, Spatie permission cache under db-per-tenant)

Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a-s1`, HEAD `3ba7fa0eb`, implementation `7fc91c613`, base `d418a2656`. Read-only review; no edits, no git writes.

**PG leg: RAN.** `autoerp_test_r` created on local PG (127.0.0.1:5433, role `autoerp`, superuser). Command and tail:

```
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r TENANCY_DB_PER_TENANT=false \
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Identity/PermissionCacheTenantScopingTest.php

.After dispatch a tenancy end: spatie.permission.cache
After dispatch b tenancy end: spatie.permission.cache
Job a: spatie.permission.cache.3a971c37-0f23-4e12-b94d-606878539e26
After queue worker: spatie.permission.cache
Job b: spatie.permission.cache.4600facd-05c7-4759-8452-7c7b513412ae
After queue worker: spatie.permission.cache
.                                                                  2 / 2 (100%)
Time: 01:18.881, Memory: 155.00 MB
OK (2 tests, 26 assertions)
```

Also run by me: `tests/Feature/Identity/ResolveTenancyMiddlewareTest.php` + `TenancyResolverFailClosedTest.php` on the default SQLite driver — `OK (13 tests, 25 assertions)`. PHPStan on the five touched paths — `[OK] No errors`. Working tree clean (`git status --porcelain` empty).

---

## BLOCKER

**[BLOCKER] CI routing — the lane is RED today, and the test would execute in NO CI job even if the ceiling were raised.**
`apps/api/tools/feature-lane-manifest-check.php:789-795` — reproduced locally from the lane worktree:

```
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Identity" now holds 33 class(es), ceiling is 32. Its lane "feature-lane-tenancy/Identity" is wired but parked behind an unflipped execution gate…
  ✗ GATED-LANE COVERAGE GREW: 1254 class(es) now sit in lanes parked behind an unflipped execution gate, ceiling is 1253.
```

Why it matters, in order:
1. `apps/api/tests/feature-lane-manifest.json:9` `gated_ceiling: 1253`, `:817-819` `Identity.classes: 32`, lane `feature-lane-tenancy/Identity`. The lane is unchanged by this diff, so `backend-architecture` (no `if:` guard) fails on every event — the lane cannot merge as-is.
2. `.github/workflows/ci.yml:2031-2037` — job `feature-lane-tenancy` is `if: vars.SELF_HOSTED_RUNNER_READY == 'true' && …` on `runs-on: [self-hosted, …, autoerp-heavy]`. The flag is unset, so the lane runs on no event.
3. `PermissionCacheTenantScopingTest` is named in neither live PG allowlist: `ci.yml:1130` (`backend-test-pgsql`) nor `ci.yml:1271` (`t6-phase0b-pgsql`). Grep for the class name in `ci.yml` returns 0 hits.
4. Consequence: raising the ceiling alone converts a red gate into a **P0 tenant-isolation fix with zero CI coverage**. `apps/api/tests/feature-lane-manifest.json:766-800` shows the checker counts filesystem classes per group and knows nothing about the `--filter` allowlists, so a ceiling raise is required *in addition to* a live selection, not instead of it.

The handback (`docs/handoff/HANDBACK-rbac-w0a-s1-2026-09-10.md:5`, `:185-200`) states this honestly and defers it to the orchestrator. It is still a merge blocker. Resolution below.

---

## MAJOR

**[MAJOR] `apps/api/docker/entrypoint.sh:176` — the boot-time `permission:cache-reset` becomes a no-op for every tenant after this change.**
The step runs in central context (no tenancy initialized), so `Spatie\Permission\Commands\CacheReset` (`vendor/spatie/laravel-permission/src/Commands/CacheReset.php:16-24`) forgets `$registrar->cacheKey`, which post-change is the base key `spatie.permission.cache` (`ScopePermissionCacheToTenant.php:14`) — a key **no tenant uses any more**. The comment at `entrypoint.sh:171-174` states the purpose explicitly: "so role/permission grants applied out of band to existing tenants take effect immediately after deploy instead of serving a stale cached snapshot". After this diff that guarantee is silently gone; the stale window becomes the 24 h TTL (`apps/api/config/permission.php:186`).
Partial mitigation exists and should be stated, not assumed: `tenants:seed RolesAndPermissionsSeeder` (entrypoint.sh:166, opt-in via `SYNC_PERMISSIONS_ON_BOOT`) runs under tenancy, and `Role::syncPermissions()` flushes through `HasPermissions::forgetCachedPermissions()` (`vendor/spatie/laravel-permission/src/Traits/HasPermissions.php:474,553-556`) against the correct tenant key. That covers the seeder path only, and only when the opt-in flag is on.
Fix: replace/accompany line 176 with `php artisan tenants:run permission:cache-reset` (`tenants:run` is registered — verified via `php artisan list`), or record an explicit owner ruling that the boot reset is now central-only and why. The lane's own plan step 8 only calls for a **one-off** reset to evict the former shared key, which is a different thing.

**[MAJOR] The test proves the key STRING, never the data meaning — the actual cross-tenant defect is not asserted.**
`tests/Feature/Identity/PermissionCacheTenantScopingTest.php:93,100,102,105,107,124,148-151` assert only `$registrar->cacheKey`. Nothing anywhere asserts the thing the fix exists for: a permission that exists only in tenant A must not be visible to a principal in tenant B. Worse, `phpunit-pgsql.xml:55` pins `CACHE_STORE=array`, so the cache is process-local for the whole run — the shared-store collision this change targets is never reproduced even incidentally. Under the project's own "data-meaning tests" rule (CLAUDE.md rule 22 / `docs/conventions/09`), a status/string assertion where the requirement is about *what another tenant can see* is insufficient.
Ask: one added case — create permission `X` in tenant A's DB, switch to tenant B, assert `$registrar->getPermissions(['name' => 'X'])` is empty (or `$userB->can('X')` is false) — ideally with a non-`array` shared store so the two keys land in one physical store.

---

## MINOR

**[MINOR] `RestoreCentralPermissionCache.php:19` hardcodes `ScopePermissionCacheToTenant::BASE_KEY` instead of restoring the configured key.** `apps/api/config/permission.php:192` is the source of truth for `permission.cache.key`. Any deployment that customises it loses the custom value permanently at the first `TenancyEnded`, and `ScopePermissionCacheToTenant.php:29` builds tenant keys off the constant rather than the config too. Capture the boot-time `config('permission.cache.key')` once and restore/extend that.

**[MINOR] Debug output left in a committed test.** `tests/Feature/Identity/PermissionCacheTenantScopingTest.php:124,146,153,176` — four `fwrite(STDERR, …)` calls. Visible in my run above; they will pollute the CI log of whichever job ends up running this class. Not in the plan's Step 2 body.

**[MINOR] Compatibility mode (`TENANCY_DB_PER_TENANT=false`) — the listener's early return is never exercised.** The compat leg at `PermissionCacheTenantScopingTest.php:90-93` drives an **unprovisioned** tenant through `TenancyResolver::initializeIfProvisioned()` (`app/Modules/Tenant/Application/Services/TenancyResolver.php:96-106`), which returns `false` and never calls `tenancy()->initialize()` — so `TenancyInitialized` never fires and the guard at `ScopePermissionCacheToTenant.php:20-22` is untouched. The plan sanctions this shape ("must not manufacture a tenant cache context"), so the shared key **is** preserved by design; but the guard itself is unproven. Note also that in compat mode a *provisioned* tenant reached via `$tenant->run()` / `QueueTenancyBootstrapper::initializeTenancyForQueue()` (`vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:93`) DOES fire the event, and the guard is the only thing keeping the shared key there. One line — `$tenant->run(fn () => …)` in compat mode asserting the key stays `spatie.permission.cache` — would close it.

**[MINOR] `tests/Traits/ProvisionsTenantDatabases.php:37-52,90-94` changes shared-trait behaviour for 12 other test classes; only this lane's class was run on PG.** Trait users: `TenantScopedCommandForEachTenantTest`, `SubledgerReconciliationCommandTest`, `CheckPendingEnrichmentsDriftDbPerTenantTest`, `PreflightFiscalGateCommandTest`, `BatchExpiryDailyCheckCommandTest`, `MarketplaceScheduledCommandsTest`, `DetectFraudPatternsDriftDbPerTenantTest`, `VerifyFiscalChainGenesisDocumentTest`, `ChannelReconcileCommandDbPerTenantTest`, `VerifyPosChainCommandDbPerTenantTest`, `ExpireStockReservationsCommandTest`. None of them appears in any `ci.yml` filter (grep: 0 hits each), so today's CI blast radius is nil — and the previous PG path was non-functional anyway (`select … from sqlite_master` on a pgsql connection). Record it; re-run those on PG the day a lane gate flips.

**[MINOR] Residual in-memory staleness is bounded but real.** `PermissionRegistrar::$permissions` is cleared only on tenancy transitions (`ScopePermissionCacheToTenant.php:31`, `RestoreCentralPermissionCache.php:21`). A worker mid-job under tenant A does not observe a role edit made concurrently by an HTTP request in tenant A — `forgetCachedPermissions()` (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:140-146`) evicts the shared cache entry but cannot reach the worker's in-process collection. Bounded to the remainder of one job, because `QueueTenancyBootstrapper` ends tenancy after every tenant-aware job (`QueueTenancyBootstrapper.php:96-121`) and `Tenancy::end()` fires `TenancyEnded` (`vendor/stancl/tenancy/src/Tenancy.php:61-74`). Acceptable; worth one sentence in the handback.

**[MINOR] Merge-order conflict risk on the two shared CI files.** `lane/w-lot-a-1a` and `lane/t2-receipt-spine` both edit `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml`; `w-lot-a-1a` moves `gated_ceiling` 1250 → 1264 and `Identity.classes` 32 → 39 while this worktree's base already reads 1253/32. Whoever lands second must recompute, not textually merge, the ceilings.

---

## Verified OK

- **Key becomes `spatie.permission.cache.<tenant-id>` on every path that reads the registry.** `PermissionRegistrar::initializeCache()` re-reads `config('permission.cache.key')` into the public `$cacheKey` (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:67-80`, `:42`), and `loadPermissions()` reads that property (`:221-223`), so the config mutation at `ScopePermissionCacheToTenant.php:29-30` genuinely re-keys the registry. The registrar is a container **singleton** (`vendor/spatie/laravel-permission/src/PermissionServiceProvider.php:43`), so the listener's constructor-injected instance is the same one `HasRoles`/`HasPermissions` resolve.
  - HTTP: `app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:78` → `TenancyResolver::initializeIfProvisioned()` → `tenancy()->initialize()` → `TenancyInitialized` (`vendor/stancl/tenancy/src/Tenancy.php:54-58`).
  - Queue: `vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:62-66,93` fires the same event — **empirically proven** by the green run above (`Job a: …3a971c37…`, `Job b: …4600facd…`).
  - `tenants:run` / `tenants:migrate`: Stancl's `runForMultiple` → `tenancy()->initialize()`.
  - `tenants:migrate-rolling`: `app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:95-98,121` delegates to `tenants:migrate` per tenant and ends tenancy between iterations.
- **In-memory registry IS cleared on both edges.** `clearPermissionsCollection()` (`PermissionRegistrar.php:173-178`, nulls `$permissions`, `$wildcardPermissionsIndex`, `$isLoadingPermissions`) is called at `ScopePermissionCacheToTenant.php:31` and `RestoreCentralPermissionCache.php:21`. An A→B switch is safe on both routes: `Tenancy::initialize()` calls `end()` first when a different tenant is already initialized (`vendor/stancl/tenancy/src/Tenancy.php:48-51`), and the same-tenant early return at `:43-45` is harmless. **No path serves tenant A's collection to tenant B.**
- **Listener registration order is correct.** `app/Providers/TenancyServiceProvider.php:51-61` registers the `BootstrapTenancy`/`RevertToCentralContext` closures first, `:63-64` registers the permission listeners after — so `DatabaseTenancyBootstrapper` has swapped the connection before `initializeCache()` re-resolves the cache store, and central context is restored before the base key is.
- **Why the old code was tenant-blind, confirmed in vendor:** `CacheTenancyBootstrapper` swaps the *container's* `cache` binding to a tagging manager (`vendor/stancl/tenancy/src/Bootstrappers/CacheTenancyBootstrapper.php:28-35`), but `PermissionRegistrar` resolves its store from its own injected `$this->cacheManager` (`PermissionRegistrar.php:57-65,82-99`), so tenant tags never applied to the permission entry. Re-keying is the right fix and is independent of tagging.
- **No central-context permission read exists to break.** `create_permission_tables` and `create_users_table` are **tenant** migrations (`apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php`, `…/2025_11_30_000003_create_users_table.php`); only `app/Modules/Identity/Domain/User.php:23,56` uses `HasRoles`, and `app/Models/SuperAdmin.php` does not. So restoring the base key in central context cannot trigger a `relation "permissions" does not exist` 500.
- **Tenant migration that flushes the cache still hits the right key.** `apps/api/database/migrations/tenant/2026_07_16_100100_register_manage_location_access_permission.php:15,49` calls `Artisan::call('permission:cache-reset')` inside a tenant migration, i.e. under initialized tenancy → tenant key.
- **Constructor injection, strict types, no `mixed`.** `ScopePermissionCacheToTenant.php:3,16`, `RestoreCentralPermissionCache.php:3,12` — `private readonly PermissionRegistrar`, `declare(strict_types=1)`, no `app()` in production code, no `mixed`. PHPStan level 8 on all five touched paths: `[OK] No errors`.
- **Red-capability of the test is credible.** Without the listeners the key stays `spatie.permission.cache` and the assertions at `:100,105,148-151` cannot pass; the handback records the actual red output (`HANDBACK-rbac-w0a-s1-2026-09-10.md:34-77`).
- **No overlap with in-flight lanes on the five implementation files.** `git diff --name-only dev...lane/w-lot-a-1a` and `…lane/t2-receipt-spine` contain neither listener, nor `TenancyServiceProvider.php`, nor `tests/Traits/ProvisionsTenantDatabases.php`. Their only intersection with this lane's *needed* follow-up is `ci.yml` + the manifest (see MINOR above); their `ci.yml` hunks are at `@@ -1130` and `@@ -1138` (the `backend-test-pgsql` filter), not at the `t6-phase0b-pgsql` filter.

---

## CI routing recommendation

**Do this (smallest correct resolution, three edits, no new job, no conflict with either in-flight lane):**

1. **Name the class in the `t6-phase0b-pgsql` filter** — `.github/workflows/ci.yml:1271`, append `|PermissionCacheTenantScopingTest` to the existing `--filter` alternation. Rationale, each point checked:
   - The job is real PostgreSQL 16 + Redis (`ci.yml:1190-1210`) and already provisions real per-tenant databases (`TenantStanclFlipTest`, `TenantDatabaseIsolationTest`, `TenantProvisioningServiceTest` are in the same filter), which is exactly what `ProvisionsTenantDatabases`' new PG branch needs.
   - It runs on **PR→dev**, PR→main, push→main and dispatch (`ci.yml:1185`) — the day-to-day merge gate.
   - Its stated charter is literally "the row-level → database-per-tenant flip must be proven on real PostgreSQL … before merge to dev OR main" (`ci.yml:1179-1184`). A tenant-scoped permission cache is squarely that surface.
   - Its env shape (`DB_DATABASE=autoerp_test`, `DB_CENTRAL_DATABASE=autoerp_test`, `ci.yml:1265-1270`) matches the shape I ran green locally.
   - Cost: ~79 s of PHPUnit on my laptop, added to a job that already runs 16 classes. No new runner, no new job, no new services.
   - **Neither in-flight lane touches this line** (their `ci.yml` hunks are `@@ -1130` and `@@ -1138`), so the edit conflicts with nothing.
2. **Raise `Identity.classes` 32 → 33** (`apps/api/tests/feature-lane-manifest.json:817-819`) with a `raise_note` following the established form, and **`gated_ceiling` 1253 → 1254** (`:9`) with a matching top-level note. This is required *in addition to* step 1 because the checker counts filesystem classes per group and is blind to `--filter` allowlists (`tools/feature-lane-manifest-check.php:765-800`). The note must say, in the manifest's own idiom, that the class is dark in `feature-lane-tenancy` until `SELF_HOSTED_RUNNER_READY` flips but is **live** via the `t6-phase0b-pgsql` selection — i.e. it is *not* dark in CI.

**Rejected alternatives, with reasons:**
- *Flip the `feature-lane-tenancy` execution gate.* `SELF_HOSTED_RUNNER_READY` is a single repository variable shared by **all eight** self-hosted lane jobs (`ci.yml:1439,1561,1675,1795,1913,2031,2157,2275`). Flipping it starts ~1 254 parked classes across eight jobs on a runner that is not registered (`ci.yml:1422-1426`) — an unbounded, un-baselined change dressed up as a routing fix. Not this lane's call.
- *Move the class to `tests/Feature/Security/`.* That job has no `if:` guard and a whole-directory selector (`ci.yml:503-506,576`) — tempting, and it is the precedent the manifest itself records for `UserOffboardingCascadeTest` (`feature-lane-manifest.json` `Security` note, 2026-08-23). **But it is SQLite-only by design** (`ci.yml:521-522`: "SQLite in-memory by design (phpunit.xml pins it) … this job runs no database service"), and this test `markTestSkipped`s on any non-pgsql driver (`PermissionCacheTenantScopingTest.php:41-43`). It would be green-and-dark — the exact failure mode the manifest exists to prevent.
- *Add to the `backend-test-pgsql` filter (`ci.yml:1130`).* Functionally fine (same PR→dev PG job family), and it is where `lane/w-lot-a-1a` is adding its twelve Identity classes — which is precisely why it will conflict. Use `t6-phase0b-pgsql` instead.
- *Raise the ceiling only, ship dark.* Rejected outright: this is a P0 cross-tenant-authz fix. `lane/w-lot-a-1a` sets the correct precedent — it raises `Identity` 32 → 39 and `gated_ceiling` 1250 → 1264 **and** names twelve of the new classes in the live `backend-test-pgsql` filter, explicitly recording which two are deliberately *not* selected. Copy that discipline, not just its ceiling arithmetic.

---

## Merge conditions

1. **[BLOCKER]** Apply the CI routing above: `PermissionCacheTenantScopingTest` added to `ci.yml:1271`, `Identity.classes` 32 → 33 and `gated_ceiling` 1253 → 1254 with notes. Re-run `php tools/feature-lane-manifest-check.php` green from the lane worktree before merge.
2. **[MAJOR]** Either add `php artisan tenants:run permission:cache-reset` alongside `docker/entrypoint.sh:176`, or record an explicit owner ruling + amend the comment at `entrypoint.sh:171-174` so the dead guarantee is not left implied. One-off eviction of the legacy shared key (plan Step 8) is a separate, still-needed action.
3. **[MAJOR]** Add one data-meaning assertion (permission created only in tenant A is invisible under tenant B), ideally on a non-`array` cache store, so the fix is proven by behaviour and not by string equality.
4. **[MINOR]** Strip the four `fwrite(STDERR, …)` debug lines (`:124,146,153,176`).
5. **[MINOR]** Restore the *configured* `permission.cache.key` rather than the hardcoded constant (`RestoreCentralPermissionCache.php:19`, `ScopePermissionCacheToTenant.php:29`).
6. **[MINOR]** Record the compat-mode guard gap, the shared-trait blast radius, and the bounded in-memory staleness in the handback, or close the compat gap with the one-line `$tenant->run()` case.
7. **Sequencing:** recompute (do not textually merge) `gated_ceiling` / `Identity.classes` against whichever of `lane/w-lot-a-1a` / `lane/t2-receipt-spine` lands first.

**What to fix before merge:** route the test into `t6-phase0b-pgsql` (ci.yml:1271) + raise the two manifest ceilings with notes, then close the `entrypoint.sh:176` tenant-flush regression and add the one cross-tenant data-meaning assertion.

VERDICT: MERGE-WITH-CONDITIONS
