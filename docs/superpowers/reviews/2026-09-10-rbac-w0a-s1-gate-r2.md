# Gate r2 (delta) — lane/rbac-w0a-s1-permission-cache

Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a-s1`, HEAD `97b59dbe9` (fix round 1) on top of `3ba7fa0eb`, base `d418a2656`. Read-only: no edits, no git writes, PHPUnit by path only. Round-1 register: `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r1.md` (now committed in the lane).

Delta reviewed: `git diff 3ba7fa0eb..97b59dbe9` — 10 files, +395/-18 (1 new prod class, 2 listeners, 1 provider, entrypoint, 1 test, ci.yml, manifest, 2 docs).

Independent runs (all from `apps/api`, this worktree):

```
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r \
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Identity/PermissionCacheTenantScopingTest.php

PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: .../apps/api/phpunit-pgsql.xml
...                                                                 3 / 3 (100%)
Time: 01:18.189, Memory: 155.00 MB
OK (3 tests, 38 assertions)
EXIT=0
```

```
php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1519 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1930 test classes across all suites.
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1254 class(es) …
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) …
(exit 0)
```

```
./vendor/bin/phpstan analyse --no-progress <5 touched PHP paths>   [OK] No errors
./vendor/bin/pint --test <same paths>                              {"result":"pass"}
python3 yaml.safe_load(.github/workflows/ci.yml)                   YAML OK, 26 jobs, t6-phase0b-pgsql present
python3 json.load(apps/api/tests/feature-lane-manifest.json)       JSON OK, gated_ceiling 1254, Identity 33
git status --porcelain                                             (empty)
```

The three green dots carry NO stderr noise — the four `fwrite` calls are gone (r1 MINOR 4 visible-in-log confirmation).

---

## Closure table

| # | Round-1 condition | Status | Verified at |
|---|---|---|---|
| 1 | **[BLOCKER]** CI routing: name the class in a live filter + raise both ceilings with notes, checker green | **CLOSED** | `.github/workflows/ci.yml:1271` — `\|PermissionCacheTenantScopingTest` appended to the `t6-phase0b-pgsql` alternation, inside the anchored `'/\\(…)::/'` form; exactly one occurrence in the file (grep). Job condition `ci.yml:1185` = PR→dev, PR→main, push→main/dev, dispatch — no `SELF_HOSTED_RUNNER_READY` guard, real PG16 + Redis services (`ci.yml:1188-1210`), env `DB_DATABASE/DB_CENTRAL_DATABASE=autoerp_test` (`ci.yml:1265-1270`) = the shape I ran green. `feature-lane-manifest.json:9` `gated_ceiling` 1253→1254, `:819-820` `Identity.classes` 32→33 + `raise_note_2026_09_10`, `:1061` `gated_ceiling_raise_note_2026_09_10_rbac_w0a_s1`. Checker green, and it independently asserts every `--filter` entry is anchored and **uniquely matched**. |
| 2 | **[MAJOR]** entrypoint per-tenant reset (or an owner ruling) | **CLOSED** | `apps/api/docker/entrypoint.sh:190` `DB_HOST="$DIRECT_DB_HOST" php artisan tenants:run permission:cache-reset 2>/dev/null \|\| true`, kept above the bare reset at `:191`. Option shape correct: Stancl `Run` signature is `tenants:run {commandname} {--tenants=*} {--argument=*} {--option=*}` (`vendor/stancl/tenancy/src/Commands/Run.php:24-27`) and `permission:cache-reset` takes no arguments/options, so the bare form is right — `--argument=`/`--option=` (the `SeedChartsCommand`-style pass-through) would be wrong here. `DIRECT_DB_HOST` matches the neighbours (`:132` migrate, `:150` tenants:migrate-rolling, `:165` tenants:seed). `2>/dev/null \|\| true` preserves never-blocks-boot. Retained central reset justified in the rewritten comment `:172-189` and in the handback (compat mode = base key is the LIVE key; plus one-off legacy shared-key eviction) — independently confirmed: `create_permission_tables` is a **tenant** migration only, `HasRoles` is used solely by `app/Modules/Identity/Domain/User.php:23`, `SuperAdmin` has no permission trait, so the bare reset is not dead but is also harmless. |
| 3 | **[MAJOR]** cross-tenant data-meaning assertion | **CLOSED** | `tests/Feature/Identity/PermissionCacheTenantScopingTest.php:191-229`. The tenant-B visibility assertion is `:215` `self::assertCount(0, $this->registrar->getPermissions(['name' => 'rbac.w0a.s1.tenant-a-only']))` — exactly the line the fix-round agent reports red (`Failed asserting that actual size 1 matches expected size 0`, handback "Fix-round verification"), i.e. tenant B was served tenant A's permission. Confirmed red-capable by construction: with the listeners unregistered, `RestoreCentralPermissionCache` never clears `PermissionRegistrar::$permissions`, so `loadPermissions()` short-circuits on A's collection (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:221-223`) and `:215` cannot pass. Not just a negative: `:219-220` pin B's count against B's own `permissions` rows and against `$countInA - 1`, and `:224-226` prove isolation was not achieved by losing A's data. |
| 4 | **[MINOR]** strip `fwrite` debug | **CLOSED** | Zero `fwrite` in the file (grep) — removed at former `:124,146,153,176` and in `PermissionCacheProbeJob::handle` (`:241-244`). My green run's log is clean. |
| 5 | **[MINOR]** restore the *configured* key, not a hardcoded constant | **CLOSED** | New `app/Modules/Identity/Application/Support/PermissionCacheKey.php:36-62` — constructor-injects `Illuminate\Contracts\Config\Repository`, reads `permission.cache.key` once, falls back to `DEFAULT_BASE_KEY` only when absent/non-string/empty. Both listeners now inject it (`RestoreCentralPermissionCache.php:13-16,23`, `ScopePermissionCacheToTenant.php:15-18,31`); the old `ScopePermissionCacheToTenant::BASE_KEY` constant is gone and has **no** remaining references anywhere in `app/`, `tests/`, `database/` (grep). |
| 6 | **[MINOR]** record residuals (compat guard gap, shared-trait blast radius, in-memory staleness) | **CLOSED** | `docs/handoff/HANDBACK-rbac-w0a-s1-2026-09-10.md` §"Gate r1 conditions" → 6: all three recorded with file:line, the compat-mode guard gap explicitly left OPEN+accepted (one `$tenant->run()` case named as the closure), the 11 trait consumers enumerated with "re-run on PG the day a lane gate flips". |
| 7 | **Sequencing note** (recompute, don't textually merge, vs `w-lot-a-1a` / `t2-receipt-spine`) | **CLOSED** | Recorded twice — handback §6 last bullet and inside both manifest notes (`feature-lane-manifest.json:819`, `:1061`). Verified against reality below (Merge readiness): dev is still at 1253/32, so this lane's arithmetic is currently exact. |

7/7 closed. No condition PARTIAL or NOT CLOSED.

---

## New findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**[MINOR] `apps/api/docker/entrypoint.sh:190` — `tenants:run` has NO per-tenant failure isolation, and `2>/dev/null` hides the abort.**
`Tenancy::runForMultiple()` (`vendor/stancl/tenancy/src/Tenancy.php:154-161`) is a bare `foreach { initialize($tenant); $callback($tenant); }` with no try/catch. Under `db_per_tenant=true` a single directory row whose database is missing/unreachable throws on `initialize()` and aborts the loop; every tenant after it keeps its stale snapshot for the 24 h TTL (`config/permission.php:186`). `|| true` correctly stops that from blocking boot, but `2>/dev/null` also stops it from appearing in the deploy log, so a partial reset is indistinguishable from a full one. This is not a regression (today's central-only reset reaches no tenant at all) — it is an incomplete guarantee, and the entrypoint's own comment at `:145-147` shows the house pattern: `tenants:migrate-rolling` exists precisely because "isolates per-tenant failures" was needed over plain `tenants:migrate`. Suggested fix (post-merge is acceptable): drop `2>/dev/null` on this one line and/or add the surrounding `echo`/if-then-else reporting the neighbours all have (`:148-153`, `:162-170`), so a partial pass is visible.

**[MINOR] `tests/Feature/Identity/PermissionCacheTenantScopingTest.php:50-51` is now decorative and can mislead.**
`setUp()` sets `config(['permission.cache.key' => 'spatie.permission.cache'])` **after** the application booted, but the listeners no longer read that entry — they read `PermissionCacheKey`, captured at `TenancyServiceProvider::boot()` (`app/Providers/TenancyServiceProvider.php:62`). The two values are identical today (`config/permission.php:192`), so the test is correct; but an editor who changes that literal to steer the listeners will get a confusing failure. Suggested fix: delete the line or add a one-line comment saying it only resets the *config* entry, not the captured base key.

**[MINOR] `app/Modules/Identity/Application/Support/PermissionCacheKey.php:7` imports `App\Providers\TenancyServiceProvider` for a docblock `{@see}` only.**
A module Application-layer class now carries a `use` statement pointing at an app-level provider (direction smell vs. rule 6 / hexagonal layering). Pint and PHPStan are green (PHP-CS-Fixer keeps docblock-referenced imports), so this is cosmetic: prefer the fully-qualified name in the docblock text.

**[MINOR] 7 empty `storage/framework/cache/data/<xx>/<yyyy>` directories survive the file-store test.**
`find apps/api/storage/framework/cache/data -type f` → only `.gitignore`; `-type d -empty` → 7. Key/file cleanup in `tearDown` (`:75-78`) is therefore **complete** — no cache entry leaks, no store is flushed wholesale — but Laravel's file store leaves its two-level hash directories behind. Gitignored, `git status --porcelain` empty after my run. Informational.

---

## Verified OK (this round's specific asks)

- **1. Capture-before-first-`TenancyInitialized` is guaranteed in every entry point — and doubly so.**
  (a) Eager: `TenancyServiceProvider::register():50-57` binds the singleton; `boot():62` resolves it. `bootstrap/providers.php:64` lists `TenancyServiceProvider` among the app providers, all of which are registered and booted by `Illuminate\Foundation\Bootstrap\{RegisterProviders,BootProviders}` before any HTTP request, console command or queue job is dispatched. (b) Even without the eager `make()`, the capture would still be correct: both listeners are registered as **class-string** listeners (`TenancyServiceProvider.php:76-77`), so they are container-resolved at dispatch time and their constructors run *before* `handle()` mutates the config — on the first transition the repository still holds the boot value. There is no window in which a listener can read an already-rewritten key.
  **No writer of `permission.cache.key` exists outside these two listeners** — repo-wide grep over `app/`, `config/`, `database/`, `bootstrap/` returns only the listeners, `config/permission.php:192`, and a *read* in `database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:115-116` (which correctly reads the live, tenant-scoped value inside a tenant migration).
  **No provider-order dependency on Spatie.** `PermissionCacheKey` injects `Illuminate\Contracts\Config\Repository`, never `PermissionRegistrar`, so it is indifferent to whether `Spatie\Permission\PermissionServiceProvider` (package-discovered, hence registered/booted *before* the app providers) has run. Grep of that provider for `cache` returns nothing — it never rewrites the key. The registrar's own `initializeCache()` reading the base key at construction is harmless because each listener calls `initializeCache()` again after mutating the config.
  Entry points individually: HTTP (`ResolveTenancy` middleware — post-boot), queue worker (`QueueTenancyBootstrapper` per job — post-boot; the long-running worker never rebuilds the container, so the singleton persists across jobs and every `TenancyEnded` restores the same captured base), `tenants:run` (`Tenancy::runForMultiple` — post-boot), `tenants:migrate-rolling` (`RollingTenantMigrationCommand` — post-boot), PHPUnit (fresh app per test → fresh capture; the class green on PG above).
  **Constructor injection only, no `app()` in production code:** `PermissionCacheKey.php:38`, `ScopePermissionCacheToTenant.php:15-18`, `RestoreCentralPermissionCache.php:13-16` are all `private readonly`. `TenancyServiceProvider::boot():62` uses `$this->app->make(...)` — the correct provider-level form, and stricter than the pre-existing `app(BootstrapTenancy::class)` calls beside it at `:65,:71`.
- **2. Entrypoint step:** see closure row 2 — option shape, `DIRECT_DB_HOST`, never-blocks-boot and the justified retention of the central reset are all confirmed against the vendor signature and the neighbouring lines. The step sits inside the `DB_CONNECTED != false` branch, so it is not attempted when the database is unavailable.
- **3. `tearDown` cleanup is complete.** `:75-78` forgets exactly `spatie.permission.cache` plus one `spatie.permission.cache.<id>` per tenant registered by `tenant()` (`:91-99` appends every tenant to `$this->tenants`, including the two provisioned in the new case), never flushes a store, and runs on the same `file` store the registrar wrote to (`permission.cache.store = 'default'` → `cache.default`, which the test set to `file` *before* `initializeCache()` at `:193-195`; `PermissionRegistrar::getCacheStoreFromConfig()` resolves the store once and caches the Repository, so that ordering is load-bearing and correct). Empirically: no cache files remain after my run (see MINOR 4). Central `jobs`/`domains`/`tenants` rows are deleted at `:80-86`; per-tenant databases are dropped by `ProvisionsTenantDatabases::provisionTenantDatabase():39-49`.
  One nuance, not a finding: pre-fix, `:215` goes red via the registrar's *in-memory* collection before the shared *store* is ever consulted, so the `file` store proves the physical-store leg less sharply than the comment at `:182-188` implies. The defect asserted is the right one either way, and post-fix green does exercise the real store (B misses on its own key and loads from its own DB).
- **4. Manifest + CI:** both notes follow the file's established idiom (`DELIBERATE RAISE x -> y (date, lane, reason) … gated_ceiling n -> n+1 is exactly this one class …`), matching the existing `note` on the same group and the `gated_ceiling_raise_note_*` family at `:1053-1062`. `ci.yml` parses (26 jobs), the filter entry is unique and anchored, and the checker's own "anchored and uniquely matched" assertion passes. Manifest JSON parses.
- **5. PG run + static analysis:** pasted above — `OK (3 tests, 38 assertions)`, PHPStan level 8 `[OK] No errors`, Pint pass.
- **6. Commit hygiene:** `97b59dbe9` touches exactly 10 files, all lane-scoped (5 code/infra + 1 test + 2 CI/manifest + 2 docs). No stray session files, no build artifacts, working tree clean. Author `otospexsolutions <admin@otospex.com>`; trailer present: `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` + `Claude-Session: …` — the fix-round agent's co-author line; noted and acceptable. Committing the gate register `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r1.md` inside the fix commit is consistent with the house pattern for gate records (not a rule-15 session file at repo root).

---

## Merge readiness

- `git -C /Users/houssamr/Projects/syneriva/apps/erp rev-parse --short dev` → **`5dbb7e1ee`**
- `git merge-base dev 97b59dbe9` → **`d418a2656`** (the lane's base; dev has advanced since)
- `git merge-tree --write-tree dev 97b59dbe9` → **exit 0, no conflicts**
- `git diff dev...97b59dbe9 --stat` → 11 files, +860/-8 (11th file = `tests/Traits/ProvisionsTenantDatabases.php` from the implementation commit)
- **Files `dev` changed since the lane's base, among the lane's files: NONE.** `git diff d418a2656..dev -- .github/workflows/ci.yml apps/api/tests/feature-lane-manifest.json apps/api/app/Providers/TenancyServiceProvider.php apps/api/docker/entrypoint.sh apps/api/tests/Traits/ProvisionsTenantDatabases.php apps/api/app/Modules/Identity` → empty.
- **Ceiling arithmetic re-checked against dev's tip, not against the lane's base:** `git show dev:apps/api/tests/feature-lane-manifest.json` → `gated_ceiling 1253`, `Identity.classes 32`; `git show dev:.github/workflows/ci.yml` line 1271 is byte-identical to the lane's pre-image. So 1253→1254 and 32→33 are **exact** as of `5dbb7e1ee`. The sequencing condition stands for `lane/w-lot-a-1a` (Identity 32→39, ceiling 1250→1264) and `lane/t2-receipt-spine`: whichever lands after this one must RECOMPUTE, not textually merge — and if one of them lands first, re-derive this lane's number before merging it.
- Merge into LOCAL `dev` only, per rule 21; promote to `origin/dev` later as a fast-forward batch.

## Deploy note

- **Existing tenants need no seeder re-sync for this change** (no permission was added), but the first deploy after this lands changes what the boot reset does: `entrypoint.sh:190` now iterates every tenant. Expect one `Tenant: <uuid>` line per tenant in the boot log, and — per MINOR 1 — a tenant with a missing database will silently truncate that loop (stderr suppressed). If the staging census shows directory rows without databases, run the reset manually per tenant after deploy, or watch for stale-permission reports.
- The plan's step 8 one-off eviction of the **legacy shared** `spatie.permission.cache` key is satisfied by the retained bare reset at `entrypoint.sh:191` on the first post-flip boot; nothing further is owed.
- `t6-phase0b-pgsql` gains one class (~80 s locally) on PR→dev / PR→main / push→main / dispatch. The allowlist entry at `ci.yml:1271` must be REMOVED when `vars.SELF_HOSTED_RUNNER_READY` flips and `feature-lane-tenancy` starts running the Identity group, or the class runs twice — recorded in the manifest note, worth carrying into the runner-flip checklist.

VERDICT: MERGE
