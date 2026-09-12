# Codex Desktop dispatch — RBAC-W0a-S1: tenant-scoped Spatie permission cache (paste as a NEW thread named `RBAC-W0a-S1 permission cache`)

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. This is the **first "start-now" lane of the roles & permissions programme (RBAC-W0a)**. It was fully specified on 2026-09-03 (Task 1 of the request-hygiene Phase A plan, finding S-1) but never dispatched — this brief refreshes it against today's HEAD and re-validates every file/line citation, none of which drifted materially (one vendor line number correction, noted inline).

## Purpose

Spatie's `PermissionRegistrar` obtains its cache repository through `CacheManager::store()`, a real method that Stancl's `__call`-based tenant tagging never intercepts. Under database-per-tenant, every tenant has its own `permissions` table but they all share the key `spatie.permission.cache`, so tenant A's registry can be served to tenant B and any role edit anywhere flushes everyone. Known since 2026-07-03 (memory `project_spatie_permission_cache_tenant_blind`), never fixed. Audit evidence: `docs/superpowers/audits/2026-09-02-request-hygiene/05-synthesis.md` finding S-1 (confirmed still present, table row unchanged); gate confirmation in `docs/superpowers/reviews/2026-09-03-request-hygiene-phase-a-plan-gate-r4.md` (Task 1 resolved for the production topology).

This task applies **only to database-per-tenant mode**. Production sets `TENANCY_DB_PER_TENANT=true` — re-verified at `apps/api/.env.example:56` at today's HEAD. Staging topology and its tenant-database count remain deployment-preflight facts (not repository facts) and must be confirmed before promotion — do not assume staging is database-per-tenant. In single-schema compatibility mode every tenant intentionally shares the one `permissions` table; the shared base key `spatie.permission.cache` is correct there by design, and the plan includes a test asserting that. `TenancyResolver::initializeIfProvisioned()` returns `false` for an unprovisioned compatibility tenant and must not manufacture a tenant cache context.

## Base and worktree

Base = local `dev`, currently `9d6bc75c1` (full: `9d6bc75c12d7dd9ed8c4226d0b3e7608754a5f28`) — confirm with `git rev-parse --short HEAD` before starting; if it has moved, re-run the citation checks below against the new HEAD before trusting line numbers.

```
git worktree add .worktrees/rbac-w0a-s1 -b lane/rbac-w0a-s1-permission-cache dev
```

Work ONLY inside that worktree. Never edit in the shared `dev` checkout. No `git stash`.

**Vendor copy rule:** a symlinked vendor autoloads the main checkout's classes, not the worktree's — copy it: `cp -R ../../apps/api/vendor apps/api/vendor && cd apps/api && composer dump-autoload`.

## PostgreSQL test database

Per-session private database for the two `ProvisionsTenantDatabases`-based PG tests (`#[Group('pg')]`) in this task: `autoerp_test_r`. Set **both** `DB_DATABASE=autoerp_test_r` and `DB_CENTRAL_DATABASE=autoerp_test_r` for every PG leg (create the database first if it does not exist). Run PG legs serially. Never use the shared default database. Announce the PG leg explicitly in the handback.

## Rules

- PHPUnit **by path only** — never the full suite, on this laptop or in this worktree.
- PHPStan level 8 on every touched file (exact command in Step 7 below).
- Constructor injection only, strict types, no `mixed`.
- Path-scoped commits only. No explicit commit message text exists in the plan for this task (unlike some other Phase A tasks) — use a standard conventional-commit-style message describing the fix (e.g. `fix(identity): tenant-scope the Spatie permission cache (S-1)`), path-scoped, ending with this repo's current attribution trailer convention (check the last few `git log` messages on `dev` for the exact trailer in use).
- Write a failing test first (Step 3 below is the red run); do not skip to green.

## Citation re-verification (2026-09-10, HEAD `9d6bc75c1`)

Every file/line citation Task 1 depends on was re-checked against HEAD. Table:

| Citation | Status | Note |
|---|---|---|
| `apps/api/app/Providers/TenancyServiceProvider.php` — `TenancyInitialized`/`TenancyEnded` listener wiring | VALID | `Event::listen(TenancyInitialized::class, …)` at line 49 (wraps `BootstrapTenancy`), `Event::listen(TenancyEnded::class, …)` at line 55 (wraps `RevertToCentralContext`), both gated on `config('tenancy_resolver.db_per_tenant', false)`. `boot()` closes at line 60. Insert the two new `Event::listen()` calls from Step 5 **after line 59** (after the `TenancyEnded` registration, before the closing `}` of `boot()`). |
| `apps/api/config/permission.php` cache key | VALID | Line 192: `'key' => 'spatie.permission.cache',` (inside the `'cache' => [...]` block starting line 179; `'store' => 'default'` at line 200). |
| `apps/api/config/tenancy.php` bootstrappers incl. `CacheTenancyBootstrapper` | VALID | `'bootstrappers' => [...]` array at lines 38–44; `CacheTenancyBootstrapper::class` is the second entry, line 40. |
| `vendor/spatie/laravel-permission/src/PermissionRegistrar.php` cache store/key mechanism | VALID | Installed version confirmed **6.25.0** (`composer show spatie/laravel-permission`), matching the plan's stated "Spatie Permission 6.25". Public `string $cacheKey` (line 42), `initializeCache()` sets `$this->cacheKey = config('permission.cache.key')` (line 67/74), `getCacheStoreFromConfig()` calls `$this->cacheManager->store()` / `->store($cacheDriver)` (lines 90/98 — the real `CacheManager::store()` method the plan's rationale describes), `clearPermissionsCollection()` at line 173. |
| `apps/api/docker/entrypoint.sh` — `permission:cache-reset` at boot + `SYNC_PERMISSIONS_ON_BOOT` block | VALID | `SYNC_PERMISSIONS_ON_BOOT` opt-in block at lines 156–168; unconditional `php artisan permission:cache-reset 2>/dev/null \|\| true` at **line 176**. **Operational note for the deploy step below:** this call already runs unconditionally on every container boot (not gated behind any flag), so the Step 8 deploy note ("run `permission:cache-reset` once") is in practice already satisfied automatically by the next deploy — call it out in the handback rather than treating it as a manual step someone must remember. The comment above line 176 ("Flush the shared Spatie permission cache (key `spatie.permission.cache`, default/redis store...)") will become stale prose once this lane ships (the key becomes per-tenant); no code behavior change needed, optional comment touch-up only if convenient, not required scope. |
| Queue-lifecycle test: `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php` | VALID (confirmed absent) | Does not exist yet — matches the plan's "Create" designation in Task 1's Files list. Step 3 below is its red run. |
| `apps/api/app/Modules/Identity/Application/Listeners/` still lacks the two listeners | VALID (confirmed absent) | Directory currently contains only `SendEnrichmentNotificationListener.php`. `ScopePermissionCacheToTenant.php` and `RestoreCentralPermissionCache.php` do not exist yet. |
| `apps/api/tests/Traits/ProvisionsTenantDatabases.php` — SQLite `touch()`/`sqlite_master` logic to branch before | VALID | Current file (118 lines) has exactly the shape Step 1 describes: `provisionTenantDatabase()` (line 32, SQLite `touch()`), `provisionTenantDatabaseWithSchema()` (line 66, `sqlite_master` clone), `withinTenantDatabase()` (line 104). Step 1's replacement code branches before the existing SQLite logic in both methods — no structural drift. |
| Pattern reference "the existing `TenantStanclFlipTest`/`TreasuryAlertRecipientsTest` pattern" (Step 1 prose) | DRIFTED (path only) | `TreasuryAlertRecipientsTest` is correctly at `apps/api/tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`. `TenantStanclFlipTest` is **not** under `tests/Feature/Identity/` — its real path is `apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php`. The plan names it without a path so this isn't a broken citation, just worth pointing Codex at the right directory to avoid a search detour. |
| `vendor/stancl/tenancy/.../QueueTenancyBootstrapper.php:82` (Step 2 intro prose: "calls `tenancy()->initialize()` directly") | DRIFTED | `stancl/tenancy` is pinned at `v3.10.0` (composer.lock). Line 82 is the method **signature**, `protected static function initializeTenancyForQueue($tenantId)`. The actual `tenancy()->initialize(tenancy()->find($tenantId));` call the sentence is describing is **line 93**. Use `QueueTenancyBootstrapper.php:82–94` (whole method) or `:93` (the call) when citing this in the handback/PR description — `:82` alone points at the signature, not the call. |
| Step 6 "the only three flush callers": `GenerateRecurringExpensesCommand`, `BatchExpiryDailyCheckCommand`, `TreasuryAlertRecipients` | VALID | Repo-wide grep for `->forgetCachedPermissions()` under `app/` returns exactly these three: `app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:192`, `app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:213`, `app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:32`. (Other `PermissionRegistrar` consumers exist — `SupportAccess/.../ImpersonationContext.php`, `TenantSubjectTokenAdapter.php`, `TenantDatabaseSupportAccessNotifier.php`, several `Fiscal` commands — but none of them call `forgetCachedPermissions()`; the `SupportAccess` ones only call `getPermissionsTeamId()`/`setPermissionsTeamId()`, Spatie's separate *teams* feature, which this task does not touch. All predate the 2026-09-03 plan (2026-08-07), so this is not new drift.) |
| `apps/api/tests/Feature/Identity/ResolveTenancyMiddlewareTest.php`, `TenancyResolverFailClosedTest.php` (Step 7) | VALID | Both exist at the cited path. |
| `.env.example:56` `TENANCY_DB_PER_TENANT=true` (Phase 0 preflight fact) | VALID | Confirmed unchanged. |

**Net:** zero blocking drift. Two citations get a corrected line number/path (both noted above, both cosmetic — do not change the plan's actual code). Proceed with the steps below verbatim.

## In-flight lane overlap check

`git log --oneline dev -20` on `apps/api/app/Providers/TenancyServiceProvider.php`, `apps/api/config/permission.php`, `apps/api/docker/entrypoint.sh`, `apps/api/app/Modules/Identity/` shows no commits touching `TenancyServiceProvider.php`, `config/permission.php`, or `docker/entrypoint.sh` in recent history (most recent relevant work: the T9 cache-store fail-closed lane `489d9fbc6`/`9381de4d5`, which touches `docker/entrypoint.sh`'s WebSocket/cache-store block only, and the `SYNC_PERMISSIONS_ON_BOOT` feature `478b7e106`/`5ba514ab3`, also `entrypoint.sh` but a different block — neither overlaps the `permission:cache-reset` line or the listener-wiring file).

Branch diff check (`git diff dev...<branch> --stat` scoped to `apps/api/app/Providers apps/api/config apps/api/docker apps/api/app/Modules/Identity`):
- `lane/t1-transfers-edge`, `lane/t2-receipt-spine`: **no commits ahead of dev** — both branch pointers currently sit at `dev` HEAD, zero diff, zero overlap risk.
- `lane/w-lot-a-1a`: **4 commits ahead**, touches `app/Modules/Identity/` — but only `Application/DTOs/` (`LotActionPermissionDeltaResult.php`, `RoleData.php`), `Application/Services/` (`GeneralManagerAssignmentGuard.php`, `LotActionPermissionDelta.php`), `Domain/Enums/` (`LotActionPermissionDeltaOutcome.php`, `RoleProvisioningSource.php`, `SystemRoleName.php`), and `Presentation/Controllers/` (`RoleController.php`, `UserController.php`), plus a new `apps/api/config/lot_action_permissions.php`. It does **not** touch `Application/Listeners/`, `TenancyServiceProvider.php`, `config/permission.php`, or `docker/entrypoint.sh` — no file-level conflict with this task's file list. Same top-level module directory (`app/Modules/Identity/`) is being actively developed by that lane, so rebase this lane onto `dev` before opening for review in case `w-lot-a-1a` merges first, but no content collision is expected.

## Task 1 — reproduced verbatim from `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` (rev 13, "## Task 1: Tenant-scoped Spatie permission cache (S-1)", line ~60)

**Files**

- Create: apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php
- Create: apps/api/app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php
- Modify: apps/api/app/Providers/TenancyServiceProvider.php
- Modify: apps/api/tests/Traits/ProvisionsTenantDatabases.php
- Create: apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php

**Scope and rationale:** this task applies only to database-per-tenant mode. Production uses `TENANCY_DB_PER_TENANT=true` (`apps/api/.env.example:56`); staging topology and its tenant-database count are deployment-preflight facts, not repository facts, and must pass Phase 0 before promotion. Each physical tenant database owns a separate `permissions` table, so its Spatie cache key must be `spatie.permission.cache.<tenant-id>`. In single-schema compatibility mode every tenant intentionally shares the one `permissions` table; the shared base key `spatie.permission.cache` is therefore correct by design. `TenancyResolver::initializeIfProvisioned()` returns `false` for an unprovisioned compatibility tenant and must not manufacture a tenant cache context.

**Exact lifecycle:** `PermissionRegistrar` exposes public `cacheKey`, `initializeCache()`, and `clearPermissionsCollection()`. Resolver initialization in database-per-tenant mode emits `TenancyInitialized`; the existing first listener runs `BootstrapTenancy`, which resolves `QueueTenancyBootstrapper`, installs its payload hook, and switches the database. The permission listener runs afterward and reinitializes the registrar under the tenant key. On `TenancyEnded`, the existing reverter restores central context before the permission listener restores the base key. The queue test uses the central `database` queue, real `dispatch()`, and two real `queue:work --once` executions; no synthetic queue events or direct `tenancy()->initialize()` calls are allowed in the assertions.

- [ ] **Step 1: Make the shared provisioning helper real on PostgreSQL without changing its SQLite behavior.** Add imports for `Illuminate\Support\Facades\Bus`, `Stancl\Tenancy\Jobs\CreateDatabase`, and `Stancl\Tenancy\Jobs\MigrateDatabase`. Branch before the current SQLite `touch()`/`sqlite_master` logic:

```php
protected function provisionTenantDatabase(Tenant $tenant): Tenant
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        Bus::dispatchSync(new CreateDatabase($tenant));
        $this->beforeApplicationDestroyed(static function () use ($tenant): void {
            try {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
                DB::purge('tenant');
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (Throwable) {
                // Best-effort cleanup must not hide the original assertion failure.
            }
        });

        return $tenant;
    }

    $path = database_path($tenant->getDatabaseName());
    touch($path);
    $this->beforeApplicationDestroyed(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });

    return $tenant;
}

protected function provisionTenantDatabaseWithSchema(Tenant $tenant): Tenant
{
    $this->provisionTenantDatabase($tenant);

    if (DB::connection()->getDriverName() === 'pgsql') {
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    /** @var list<object{sql: string}> $objects */
    $objects = DB::connection()->select(
        "select sql from sqlite_master where sql is not null and name not like 'sqlite_%' order by case type when 'table' then 0 else 1 end",
    );

    $this->withinTenantDatabase($tenant, static function () use ($objects): void {
        DB::statement('PRAGMA foreign_keys = OFF');

        foreach ($objects as $object) {
            try {
                DB::statement($object->sql);
            } catch (Throwable) {
                // Objects SQLite created implicitly may already exist. A
                // missing table still fails loudly when the test uses it.
            }
        }
    });

    return $tenant;
}
```

The Task 1 test must not use `RefreshDatabase`: PostgreSQL forbids `CREATE DATABASE` inside the transaction it opens. Follow the existing `TenantStanclFlipTest` (`apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php` — DRIFTED note above: not under `tests/Feature/Identity/`) / `TreasuryAlertRecipientsTest` (`apps/api/tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`) pattern: ensure central migrations exist in `setUp()`, create unique tenant slugs, track central tenant rows, and let the helper drop physical databases during application teardown.

- [ ] **Step 2: Add the two real resolver/worker tests.** Use the installed PHPUnit PG marker, `#[Group('pg')]`, on both methods. The compatibility assertion and the two provisioned HTTP-context transitions go through `TenancyResolver::initializeIfProvisioned()`; the two queue jobs are initialized by Stancl's `QueueTenancyBootstrapper` (which calls `tenancy()->initialize()` directly — DRIFTED citation corrected: `QueueTenancyBootstrapper.php:93`, not `:82` as originally cited; `:82` is the enclosing method's signature line, `protected static function initializeTenancyForQueue($tenantId)` — and fires the same `TenancyInitialized`/`TenancyEnded` events); never call `tenancy()->initialize()` in this test.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Listeners\ScopePermissionCacheToTenant;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

final class PermissionCacheTenantScopingTest extends TestCase
{
    use ProvisionsTenantDatabases;

    /** @var list<Tenant> */
    private array $tenants = [];

    private PermissionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Permission cache lifecycle requires the PostgreSQL PG lane.');
        }
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        config([
            'permission.cache.key' => ScopePermissionCacheToTenant::BASE_KEY,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'central',
            'queue.connections.database.central' => false,
        ]);
        PermissionCacheProbeJob::$observedKeys = [];
        $this->registrar = app(PermissionRegistrar::class);
        $this->registrar->initializeCache();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::connection('central')->table('jobs')
            ->where('payload', 'like', '%PermissionCacheProbeJob%')
            ->delete();
        foreach ($this->tenants as $tenant) {
            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    private function tenant(string $prefix): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => $prefix.'-'.Str::lower(Str::random(10)),
        ]);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    #[Group('pg')]
    public function test_resolver_keeps_compatibility_shared_and_rekeys_two_provisioned_tenants(): void
    {
        $resolver = app(TenancyResolver::class);
        $compatibilityTenant = $this->tenant('permission-compat');

        config(['tenancy_resolver.db_per_tenant' => false]);
        self::assertFalse($resolver->initializeIfProvisioned($compatibilityTenant));
        self::assertFalse(tenancy()->initialized);
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY, $this->registrar->cacheKey);

        config(['tenancy_resolver.db_per_tenant' => true]);
        $tenantA = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-a'));
        $tenantB = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-b'));

        self::assertTrue($resolver->initializeIfProvisioned($tenantA));
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY.'.'.$tenantA->id, $this->registrar->cacheKey);
        tenancy()->end();
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY, $this->registrar->cacheKey);

        self::assertTrue($resolver->initializeIfProvisioned($tenantB));
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY.'.'.$tenantB->id, $this->registrar->cacheKey);
        tenancy()->end();
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY, $this->registrar->cacheKey);
    }

    #[Group('pg')]
    public function test_database_queue_processes_two_tenant_payloads_then_restores_central_key(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);
        $resolver = app(TenancyResolver::class);
        $tenantA = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-queue-a'));
        $tenantB = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-queue-b'));

        foreach ([['a', $tenantA], ['b', $tenantB]] as [$label, $tenant]) {
            self::assertTrue($resolver->initializeIfProvisioned($tenant));
            $pending = dispatch(new PermissionCacheProbeJob($label));
            unset($pending); // deterministically run PendingDispatch::__destruct() before ending tenancy
            tenancy()->end();
            self::assertSame(ScopePermissionCacheToTenant::BASE_KEY, $this->registrar->cacheKey);
        }

        $payloads = DB::connection('central')->table('jobs')->orderBy('id')->pluck('payload');
        self::assertCount(2, $payloads);
        self::assertSame(
            [$tenantA->id, $tenantB->id],
            $payloads->map(static function (string $payload): string {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return (string) $decoded['tenant_id'];
            })->all(),
        );

        // WorkCommand's installed signature is `queue:work {connection?}`;
        // `connection` is positional, while `--once` is the real option.
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertExitCode(0);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertExitCode(0);

        self::assertSame([
            'a' => ScopePermissionCacheToTenant::BASE_KEY.'.'.$tenantA->id,
            'b' => ScopePermissionCacheToTenant::BASE_KEY.'.'.$tenantB->id,
        ], PermissionCacheProbeJob::$observedKeys);
        self::assertFalse(tenancy()->initialized);
        self::assertSame(ScopePermissionCacheToTenant::BASE_KEY, $this->registrar->cacheKey);
    }
}

final class PermissionCacheProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var array<string, string> */
    public static array $observedKeys = [];

    public function __construct(private readonly string $label) {}

    public function handle(PermissionRegistrar $registrar): void
    {
        self::$observedKeys[$this->label] = $registrar->cacheKey;
    }
}
```

- [ ] **Step 3: Run the real PG test red, serially, on the session database.**

Run: `cd apps/api && DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/PermissionCacheTenantScopingTest.php`

(Plan text used the placeholder `autoerp_test_<letter>`; this dispatch's reserved letter is `r` — always pass `autoerp_test_r`, not the placeholder.)

Expected before production changes: the compatibility assertions pass by design; provisioned tenant A retains `spatie.permission.cache`, and the queue observations retain the base key. No SQLite run counts as evidence for this task.

- [ ] **Step 4: Implement the database-per-tenant-only listeners with these exact imports and bodies.**

```php
// ScopePermissionCacheToTenant.php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyInitialized;

final class ScopePermissionCacheToTenant
{
    public const BASE_KEY = 'spatie.permission.cache';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(TenancyInitialized $event): void
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return;
        }
        $tenantId = (string) $event->tenancy->tenant?->getTenantKey();
        config(['permission.cache.key' => self::BASE_KEY.'.'.$tenantId]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
```

```php
// RestoreCentralPermissionCache.php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyEnded;

final class RestoreCentralPermissionCache
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(TenancyEnded $event): void
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return;
        }
        config(['permission.cache.key' => ScopePermissionCacheToTenant::BASE_KEY]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
```

- [ ] **Step 5: Wire both permission listeners after the existing bootstrap/revert registrations.** Their own guards keep compatibility mode unchanged; registration order ensures the database/cache bootstrap completes before registrar reinitialization and central reversion completes before base-key restoration. (Re-verified insertion point at HEAD `9d6bc75c1`: insert after `TenancyServiceProvider.php` line 59, before the closing `}` of `boot()` at line 60.)

```php
use App\Modules\Identity\Application\Listeners\RestoreCentralPermissionCache;
use App\Modules\Identity\Application\Listeners\ScopePermissionCacheToTenant;

Event::listen(TenancyInitialized::class, ScopePermissionCacheToTenant::class);
Event::listen(TenancyEnded::class, RestoreCentralPermissionCache::class);
```

- [ ] **Step 6: Audit the only three flush callers.** `GenerateRecurringExpensesCommand` and `BatchExpiryDailyCheckCommand` receive tenant keys only in database-per-tenant runs because compatibility `forEachTenant` does not initialize tenancy. `TreasuryAlertRecipients` executes under its caller-provided tenant database context. There is no Fiscal flush caller and no Fiscal edit. This task does not attempt to give compatibility-mode commands separate keys: their shared table requires the shared key. (Re-verified at HEAD: exactly these three call `->forgetCachedPermissions()` repo-wide — `apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:192`, `apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:213`, `apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:32`. No fourth caller has appeared since the plan was written.)

- [ ] **Step 7: Verify by path.** Run the new class and `tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` on the PG lane with the same private `autoerp_test_r` variables, one path at a time. Run the existing resolver tests on the default driver: `tests/Feature/Identity/ResolveTenancyMiddlewareTest.php` and `tests/Feature/Identity/TenancyResolverFailClosedTest.php`. Run PHPStan on both listeners, `app/Providers/TenancyServiceProvider.php`, the provisioning trait, and the probe test:

```
./vendor/bin/phpstan analyse app/Modules/Identity/Application/Listeners app/Providers/TenancyServiceProvider.php apps/api/tests/Traits/ProvisionsTenantDatabases.php apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php
```

(Adjust the trait/test paths to be relative to `apps/api` when actually invoking from inside that directory — the handover brief's original PHPStan command only named the two listener files and the provider; include the trait and the new test file too since both are touched/created by this task, and PHPStan level 8 zero-errors is a hard gate per repo rule 6.)

- [ ] **Step 8: Gate.** `tenancy-authz-reviewer`. Deployment note: run `php artisan permission:cache-reset` once to remove the former shared key; no compatibility-mode deployment action changes. **Operational context re-verified at HEAD:** `apps/api/docker/entrypoint.sh:176` already runs `permission:cache-reset` unconditionally on every boot, so the next normal deploy performs this automatically — record that in the handback instead of treating it as a separate manual step. **This lane does NOT change `SYNC_PERMISSIONS_ON_BOOT`** (`apps/api/docker/entrypoint.sh:156-168`) — that flag's role/permission catalog sync across tenant databases is untouched; a later RBAC-programme wave is expected to revisit it. Do not add, remove, or alter the `SYNC_PERMISSIONS_ON_BOOT` block in this lane.

## Two-tenant key assertion (what "done" looks like)

The queue-lifecycle test (`test_database_queue_processes_two_tenant_payloads_then_restores_central_key`) must show, in its handback output:
- tenant A's job observed `spatie.permission.cache.<tenantA-id>`
- tenant B's job observed `spatie.permission.cache.<tenantB-id>`
- the central key `spatie.permission.cache` restored after both `tenancy()->end()` calls and after both `queue:work --once` runs complete
Paste the actual observed keys (not just "PASS") into the handback so the reviewer can see the two distinct tenant-suffixed keys and the restored central key.

## Deliverable (handback)

Write `docs/handoff/HANDBACK-rbac-w0a-s1-2026-09-10.md` with: branch + commit hash; each plan step ticked with the command run and its output tail (red run, green run, PHPStan); the queue-lifecycle test output showing both tenants' keys and the central key afterwards (see assertion above); anything deviated from and why; the deploy note (`php artisan permission:cache-reset` already runs on every boot via `entrypoint.sh:176` — no extra manual action, just confirm this in the note); explicit confirmation that `SYNC_PERMISSIONS_ON_BOOT` was not touched. Then stop with `status: review`. The orchestrator runs the reviewer gate and merges — do not merge into `dev`, do not push to origin.

## Reviewer gate

`tenancy-authz-reviewer` (orchestrator runs it). Do not promote on a manual test day.

## Out of scope

- Anything in compatibility mode beyond the by-design shared-key test.
- Rate limiting (B-1) and any other task in the Phase A plan (Tasks 2–14).
- The `SYNC_PERMISSIONS_ON_BOOT` opt-in sync block in `entrypoint.sh` — untouched, later wave.
- `RoleController.php`, `UserController.php`, or any other file currently in flight on `lane/w-lot-a-1a` — rebase to pick up its changes if it merges first, but do not resolve conflicts by rewriting its DTOs/Services/Enums; this lane's diff is limited to the Files list above.
- Spatie *teams* scoping (`getPermissionsTeamId()`/`setPermissionsTeamId()` in `SupportAccess`) — a separate mechanism, not touched by this fix.
