# Request Hygiene Phase A Implementation Plan

Revision 6 (2026-09-03) — addresses plan gates r1..r5

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development or superpowers:executing-plans. Execute each checkbox in order; every task starts red, ends with its named focused checks, and goes through its reviewer gate.

**Goal:** Land the low-blast-radius request-hygiene fixes while Tenant #1 onboarding continues: tenant-scoped permission caching, bounded reads, frontend request coalescing, cache-store hardening, log-only guards, and the first idempotency/autosave protections.

**Architecture:** Each task is an isolated lane off dev. Backend list endpoints remain company/tenant scoped and become bounded. Frontend cache keys keep their established roots so existing prefix invalidation still works. Client-generated idempotency keys survive failures and rotate only after an awaited success. Phase A adds no migrations.

**Tech stack:** Laravel 12, PHP 8.2 strict, Spatie Permission 6.25, Stancl Tenancy, PHPUnit, React 19, TanStack Query 5, Vitest, Testing Library.

**Specs:** docs/superpowers/audits/2026-09-02-request-hygiene/05-synthesis.md and docs/superpowers/audits/2026-09-02-request-hygiene/08-idempotency-synthesis.md.

## Global Constraints

- [ ] Use constructor injection in production PHP; tests may resolve fixtures with app().
- [ ] Keep strict types, avoid mixed, retain tenant/company predicates, and keep tenant/location query-key helpers.
- [ ] Write a failing test first. Run PHPUnit by path only. Use the per-session PostgreSQL database variables for PG-only legs. The full backend suite is **NEVER** run locally (laptop rule); any whole-suite gate named below runs only on CI for the lane branch.
- [ ] Do not add hardcoded user-facing copy. Task 2 reuses the existing common:pagination keys; Task 4 adds its backend validation key in en/fr/ar.
- [ ] Do not touch DocumentForm.tsx, ProductController.php, or any Inventory Counting file. Task 6 remains blocked until the wave-2 PO lane merges.
- [ ] Rebase a lane before review, path-scope its commit, merge only after the named reviewer returns MERGE, and never promote on a manual test day.
- [ ] Phase A adds no migrations. Task 13 relies on the existing stock_transfers_idempotency_unique constraint.

### Onboarding safety after gate r1..r5 mitigations

| Task | Gate r2 verdict | Revision 3 verdict and carried mitigation |
|---|---|---|
| 1 | Conditional | Yes after this task, for the production topology only. Production sets `TENANCY_DB_PER_TENANT=true`; staging topology and its tenant-database count remain deployment-preflight facts that must be confirmed before promotion. Resolver-driven PG tests use two real provisioned tenant databases for HTTP and queued-worker lifecycles. Single-schema compatibility deliberately retains the shared base key because all tenants share one permissions table there. |
| 2 | No as written | Yes after this task. Dedicated request validation, portable `ESCAPE '!'` search, deterministic ordering, a PHP-computed `created_at DESC, id DESC` expected sequence asserted exactly across pages on both drivers, one parse-valid `mockReturn` declaration retaining both movement rows and all six meta fields, current query-key fixtures, and an executable W4 last-page assertion remove the gate gaps. |
| 3 | No as written | Yes after this task. Dedicated request validation, deterministic ordering and tied-page coverage accompany the required list pagination, exact keys, six-field fixtures, and complete page iteration. |
| 4 | Conditional | Conditional. Coordinate with document lanes; update every payload contract, reject each half-specified aggregate/date pair under the global `VALIDATION_ERROR` envelope, retain aggregate/range ascending order for complete validated pairs, localize the max-span error in en/fr/ar, prove the default-50 audit cap and page-2 traversal, reject `per_page=101`, and prove the legacy limit clamps 0/-5/5000 into 1..100. |
| 5 | Conditional | Conditional. Run the LineItemEntryBar suite and browser-check counting, transfer, replenishment, and document consumers before promotion. |
| 6 | No alongside PO lane | **WAIT.** Start only after the wave-2 PO lane merges; rebase, run the shared DocumentLineEditor suite, and browser-check PO plus credit-note pricing. |
| 7 | Conditional | Conditional. Phase A only partially closes S-6 by deduplicating the two component consumers for the same product/variant. The shared hook subscribes to and gates on tenant/company stores itself, the key remains rooted at stock-levels, and feature tests count only stock-level URLs; one request per distinct product remains until B-8. |
| 8 | No | Yes after this task. Reconnect invalidates all active queries with only a tested 30-second cooldown; CompanySelector global invalidation is intentionally unchanged. |
| 9 | Conditional | Conditional. An isolated config require proves the unset fallback is redis; all three runtime entrypoints fail closed after `config:cache`, their check-only branches prove `database` exits non-zero, and the infrastructure-free type-drift job pins `CACHE_STORE=array`. Promotion is still forbidden until each web, worker, scheduler, and CLI environment independently proves a reachable taggable Redis store because the command proves capability, not connectivity. |
| 10 | Conditional | Conditional. Production remains unchanged; a red handler test proves log-and-continue, distinct movement timestamps plus proportional linked-document fixtures prove flat queries, all four identified exact-log tests run locally, and the lane cannot merge until the CI-only full backend suite is green. |
| 11 | Yes | Yes. It is an isolated hook with no pre-existing consumer. |
| 12 | No as written | Yes after this task. PaymentForm, SplitPaymentForm, and active RecordPaymentModal use the same key/ref-lock contract; `SplitPaymentFormProps.totalAmount` and deprecated `SplitPaymentModalProps.totalAmount` are decimal strings at every local caller, SplitPaymentForm passes the required-total string directly to `bcsub`/`bccomp` while `bcadd` sums split strings, and the real harness proves exact `"0.100" + "0.200" = "0.300"` acceptance plus a one-millime rejection. Consumed `@ts-expect-error` directives lock out numeric props; tests submit twice inside one act boundary, and PaymentForm exposes pending in both disabled and loading state. |
| 13 | Conditional | Conditional. A real PostgreSQL two-connection collision test proves the pre-check miss, one colliding insert, clean post-rollback reread at transaction level 0 and after savepoint rollback at level 1, and winner replay; both non-idempotency rethrow cases remain. |
| 14 | No | Yes after this task. The promise tail strictly serializes N callers, always chains from a swallowed predecessor rejection, survives a throwing `onError`, covers StrictMode replay, and still coalesces rapid data changes into one debounced request. |

---

## Phase 0: Pre-flight (orchestrator, 10 minutes)

- [ ] Record this revision as the response to docs/superpowers/reviews/2026-09-03-request-hygiene-phase-a-plan-gate-r1.md, r2.md, r3.md, r4.md, and r5.md.
- [ ] Confirm no manual test day overlaps the promotion window.
- [ ] Run git worktree list and git status in every live lane; record ownership of the protected files.
- [ ] Confirm Task 6 remains WAIT until the wave-2 PO lane is merged.
- [ ] Confirm the production fact used by Task 1: `.env.example` line 56 sets `TENANCY_DB_PER_TENANT=true`. The staging topology and staging tenant-DB count are **to be confirmed in the deployment preflight**: enumerate every active staging tenant, prove each expected physical database exists and is migrated, and block Task 1 promotion if staging is not database-per-tenant or any tenant database is absent. Task 1 does not change compatibility-mode cache semantics.
- [ ] Reserve a private PostgreSQL database matching `autoerp_test_<letter>` for Tasks 1, 2, 3, and 13; set both `DB_DATABASE` and `DB_CENTRAL_DATABASE`, run PG legs serially, and never use a shared default database.
- [ ] Confirm each web, worker, scheduler, and CLI environment explicitly sets `CACHE_STORE=redis` and reaches Redis before Task 9 can promote.
- [ ] Reserve the Task 10 lane’s CI-only full-backend-suite leg; never substitute a laptop run, and do not merge that lane without the green CI result.
- [ ] Inventory external POS/mobile consumers before promoting Tasks 2 or 3. None are present in this checkout, so obtain each external owner’s contract-test evidence for mandatory pagination and stable traversal, or block that consumer’s rollout until it is upgraded; repository tests cannot substitute for absent client code.

---

## Task 1: Tenant-scoped Spatie permission cache (S-1)

**Files**

- Create: apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php
- Create: apps/api/app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php
- Modify: apps/api/app/Providers/TenancyServiceProvider.php
- Modify: apps/api/tests/Traits/ProvisionsTenantDatabases.php
- Create: apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php

**Scope and rationale:** this task applies only to database-per-tenant mode. Production uses `TENANCY_DB_PER_TENANT=true` (`apps/api/.env.example:56`); staging topology and its tenant-database count are deployment-preflight facts, not repository facts, and must pass Phase 0 before promotion. Each physical tenant database owns a separate `permissions` table, so its Spatie cache key must be `spatie.permission.cache.<tenant-id>`. In single-schema compatibility mode every tenant intentionally shares the one `permissions` table; the shared base key `spatie.permission.cache` is therefore correct by design. `TenancyResolver::initializeIfProvisioned()` returns `false` for an unprovisioned compatibility tenant and must not manufacture a tenant cache context.

**Exact lifecycle:** `PermissionRegistrar` exposes public `cacheKey`, `initializeCache()`, and `clearPermissionsCollection()`. Resolver initialization in database-per-tenant mode emits `TenancyInitialized`; the existing first listener runs `BootstrapTenancy`, which resolves `QueueTenancyBootstrapper`, installs its payload hook, and switches the database. The permission listener runs afterward and reinitializes the registrar under the tenant key. On `TenancyEnded`, the existing reverter restores central context before the permission listener restores the base key. The queue test uses the central `database` queue, real `dispatch()`, and two real `queue:work --once` executions; no synthetic queue events or direct `tenancy()->initialize()` calls are allowed in the assertions.

- [ ] **Step 1: Make the shared provisioning helper real on PostgreSQL without changing its SQLite behavior.** Add imports for `Illuminate\Support\Facades\Bus`, `Stancl\Tenancy\Jobs\CreateDatabase`, and `Stancl\Tenancy\Jobs\MigrateDatabase`. Branch before the current SQLite `touch()`/`sqlite_master` logic:

~~~php
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
~~~

The Task 1 test must not use `RefreshDatabase`: PostgreSQL forbids `CREATE DATABASE` inside the transaction it opens. Follow the existing `TenantStanclFlipTest`/`TreasuryAlertRecipientsTest` pattern: ensure central migrations exist in `setUp()`, create unique tenant slugs, track central tenant rows, and let the helper drop physical databases during application teardown.

- [ ] **Step 2: Add the two real resolver/worker tests.** Use the installed PHPUnit PG marker, `#[Group('pg')]`, on both methods. The compatibility assertion, the two provisioned HTTP-context transitions, and both queue jobs all go through `TenancyResolver::initializeIfProvisioned()`; never call `tenancy()->initialize()` in this test.

~~~php
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
~~~

- [ ] **Step 3: Run the real PG test red, serially, on the session database.**

Run: `cd apps/api && DB_DATABASE=autoerp_test_<letter> DB_CENTRAL_DATABASE=autoerp_test_<letter> php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/PermissionCacheTenantScopingTest.php`

Expected before production changes: the compatibility assertions pass by design; provisioned tenant A retains `spatie.permission.cache`, and the queue observations retain the base key. No SQLite run counts as evidence for this task.

- [ ] **Step 4: Implement the database-per-tenant-only listeners with these exact imports and bodies.**

~~~php
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
~~~

~~~php
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
~~~

- [ ] **Step 5: Wire both permission listeners after the existing bootstrap/revert registrations.** Their own guards keep compatibility mode unchanged; registration order ensures the database/cache bootstrap completes before registrar reinitialization and central reversion completes before base-key restoration.

~~~php
use App\Modules\Identity\Application\Listeners\RestoreCentralPermissionCache;
use App\Modules\Identity\Application\Listeners\ScopePermissionCacheToTenant;

Event::listen(TenancyInitialized::class, ScopePermissionCacheToTenant::class);
Event::listen(TenancyEnded::class, RestoreCentralPermissionCache::class);
~~~

- [ ] **Step 6: Audit the only three flush callers.** `GenerateRecurringExpensesCommand` and `BatchExpiryDailyCheckCommand` receive tenant keys only in database-per-tenant runs because compatibility `forEachTenant` does not initialize tenancy. `TreasuryAlertRecipients` executes under its caller-provided tenant database context. There is no Fiscal flush caller and no Fiscal edit. This task does not attempt to give compatibility-mode commands separate keys: their shared table requires the shared key.
- [ ] **Step 7: Verify by path.** Run the new class and `tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` on the PG lane with the same private `autoerp_test_<letter>` variables, one path at a time. Run the existing resolver tests on the default driver: `tests/Feature/Identity/ResolveTenancyMiddlewareTest.php` and `tests/Feature/Identity/TenancyResolverFailClosedTest.php`. Run PHPStan on both listeners, `app/Providers/TenancyServiceProvider.php`, the provisioning trait, and the probe test.
- [ ] **Step 8: Gate.** tenancy-authz-reviewer. Deployment note: run `php artisan permission:cache-reset` once to remove the former shared key; no compatibility-mode deployment action changes.

---

## Task 2: Bounded, server-filtered stock-movement reads (S-2 inventory half)

**Files**

- Create: apps/api/app/Modules/Inventory/Presentation/Requests/ListStockMovementsRequest.php
- Modify: apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php
- Modify: apps/api/tests/Feature/Inventory/StockMovementTest.php
- Modify: apps/web/src/features/inventory/StockMovementsPage.tsx
- Modify: apps/web/src/features/inventory/StockMovementsPage.test.tsx
- Modify: apps/web/src/features/inventory/__tests__/tenantScope.test.tsx
- Modify: apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx
- Modify: apps/web/e2e/money-campaign/w4-support.ts
- Verify existing unpaged consumers: apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php:713,811

**Contract:** every call returns data plus `OffsetPaginationMeta`, default 25, maximum 100. `ListStockMovementsRequest` validates every accepted parameter: `location_id`, `location_ids[]`, `product_id`, `movement_type`, `reason`, `search`, `page`, and `per_page`; the controller consumes only its `validated()` array. Search lowercases a bound term and uses the portable predicate `LOWER(column) LIKE ? ESCAPE '!'`, escaping `!`, `%`, and `_`, across product name, SKU, and `stock_movements.reference` on both PostgreSQL and SQLite. `movement_type=transfer` maps to `TransferIn` and `TransferOut`; `reason=write_off` maps to `WriteOff`, `Expiry`, and `Damage`. Results are ordered by `created_at DESC, id DESC`. The browser never filters a received page, and source documents are fetched once per page.

**Search-helper disposition:** `app/Support/Traits/FiltersAndSorts.php:164` contains controller-local escaping coupled to its trait query flow and no reusable public LIKE builder; `ReceiptController.php:117` hardcodes a backslash escape. Neither provides the explicit cross-driver escape contract required here, so this task uses the bound `ESCAPE '!'` predicate above.

- [ ] **Step 1: Extend StockMovementTest using its existing tenant/company/user/location/product setUp.** Add these imports and the concrete helper/tests.

~~~php
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use Carbon\CarbonImmutable;

private function ledgerRow(
    int $index,
    MovementType $type = MovementType::Receipt,
    ?MovementReason $reason = null,
): StockMovement {
    return StockMovement::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'product_id' => $this->product->id,
        'location_id' => $this->warehouse->id,
        'movement_type' => $type,
        'reason' => $reason,
        'quantity' => '1.0000',
        'quantity_before' => (string) $index.'.0000',
        'quantity_after' => (string) ($index + 1).'.0000',
        'reference' => 'CAP-'.$index,
        'user_id' => $this->user->id,
        'occurred_at' => now()->addSeconds($index),
    ]);
}

public function test_index_without_page_is_bounded_to_25(): void
{
    foreach (range(1, 30) as $index) {
        $this->ledgerRow($index);
    }

    $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');
    $response->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.total', 30);
}

public function test_transfer_alias_is_server_side_across_pages(): void
{
    foreach (range(1, 26) as $index) {
        $this->ledgerRow($index, $index % 2 === 0 ? MovementType::TransferIn : MovementType::TransferOut);
    }
    $this->ledgerRow(99, MovementType::Receipt);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?movement_type=transfer&page=1&per_page=25');

    $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
}

public function test_write_off_alias_is_server_side_across_pages(): void
{
    $reasons = [MovementReason::WriteOff, MovementReason::Expiry, MovementReason::Damage];
    foreach (range(1, 26) as $index) {
        $this->ledgerRow($index, MovementType::Issue, $reasons[$index % 3]);
    }
    $this->ledgerRow(99, MovementType::Issue, MovementReason::Delivery);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?reason=write_off&page=1&per_page=25');

    $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
}

public function test_search_matches_product_name_sku_and_movement_reference(): void
{
    $match = Product::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'sku' => 'SKU-BETA',
        'name' => 'Alpha Needle',
        'type' => ProductType::Part,
        'is_active' => true,
    ]);
    $service = app(StockAdjustmentService::class);
    $service->receive($match->id, $this->warehouse->id, '1.0000', 'REF-GAMMA', $this->user->id);
    $service->receive($this->product->id, $this->warehouse->id, '1.0000', 'NO-MATCH', $this->user->id);

    foreach (['alpha', 'sku-beta', 'ref-gamma'] as $search) {
        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/stock-movements?search='.rawurlencode($search),
        );
        $response->assertOk()->assertJsonCount(1, 'data');
        self::assertSame($match->id, $response->json('data.0.product_id'));
    }
}

public function test_search_treats_percent_and_underscore_as_literals(): void
{
    $percent = Product::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'sku' => 'LITERAL-PERCENT',
        'name' => 'Percent%Product',
        'type' => ProductType::Part,
        'is_active' => true,
    ]);
    $underscore = Product::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'sku' => 'LITERAL_UNDERSCORE',
        'name' => 'Underscore_Product',
        'type' => ProductType::Part,
        'is_active' => true,
    ]);
    $service = app(StockAdjustmentService::class);
    $service->receive($percent->id, $this->warehouse->id, '1.0000', 'LITERAL-PERCENT', $this->user->id);
    $service->receive($underscore->id, $this->warehouse->id, '1.0000', 'LITERAL-UNDERSCORE', $this->user->id);
    $service->receive($this->product->id, $this->warehouse->id, '1.0000', 'ORDINARY', $this->user->id);

    $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?search=%25')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_id', $percent->id);
    $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?search=_')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_id', $underscore->id);
}

public function test_search_rejects_121_characters(): void
{
    $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?search='.str_repeat('x', 121))
        ->assertUnprocessable();
}

public function test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions(): void
{
    $createdAt = CarbonImmutable::parse('2026-09-03 12:00:00');
    /** @var list<array{id: string, created_at: string}> $seededRows */
    $seededRows = [];
    foreach (range(1, 30) as $index) {
        $movement = $this->ledgerRow($index);
        $movement->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $seededRows[] = [
            'id' => $movement->id,
            'created_at' => (string) $movement->getRawOriginal('created_at'),
        ];
    }

    usort($seededRows, static function (array $left, array $right): int {
        $createdAtOrder = strcmp($right['created_at'], $left['created_at']);

        return $createdAtOrder !== 0
            ? $createdAtOrder
            : strcmp($right['id'], $left['id']);
    });
    $expectedIds = array_column($seededRows, 'id');

    $pageOne = $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?page=1&per_page=15')->assertOk()->json('data');
    $pageTwo = $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?page=2&per_page=15')->assertOk()->json('data');
    $actualIds = array_column([...$pageOne, ...$pageTwo], 'id');

    self::assertCount(30, $actualIds);
    self::assertCount(30, array_unique($actualIds));
    self::assertSame($expectedIds, $actualIds);
}
~~~

- [ ] **Step 2: Run red on both database drivers.** Default SQLite: `cd apps/api && php artisan test tests/Feature/Inventory/StockMovementTest.php`. PostgreSQL, serially: `DB_DATABASE=autoerp_test_<letter> DB_CENTRAL_DATABASE=autoerp_test_<letter> php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php`. The cap/aliases fail before controller work; literal `%` and `_` must pass only after the explicit escape clause exists.
- [ ] **Step 3: Create the dedicated list request with all accepted inputs.**

~~~php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListStockMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<object|string>> */
    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'],
            'location_ids.*' => ['uuid'],
            'product_id' => ['sometimes', 'nullable', 'uuid'],
            'movement_type' => [
                'sometimes',
                'string',
                Rule::in([
                    ...array_map(static fn (MovementType $type): string => $type->value, MovementType::cases()),
                    'transfer',
                ]),
            ],
            'reason' => [
                'sometimes',
                'string',
                Rule::in([
                    ...array_map(static fn (MovementReason $reason): string => $reason->value, MovementReason::cases()),
                    'write_off',
                ]),
            ],
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
~~~

- [ ] **Step 4: Replace inline input reads with the validated array, portable bound search, and stable pagination.** Change `index(Request $request)` to `index(ListStockMovementsRequest $request)`, import that request, and remove the raw `Request`, `DB`, and driver-specific operator imports. No `$request->has()`, `$request->input()`, `$request->query()`, or `$request->integer()` remains in `index()`.

~~~php
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Presentation\Requests\ListStockMovementsRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

$validated = $request->validated();
$requestedLocationIds = array_key_exists('location_ids', $validated)
    ? array_values($validated['location_ids'])
    : (($validated['location_id'] ?? null) !== null ? [(string) $validated['location_id']] : []);
$locationIds = $this->locationScopeResolver->resolve($user, $requestedLocationIds);

$query = StockMovement::query()
    ->where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)
    ->with(['product.unitOfMeasure', 'location', 'user', 'reversalOf']);
if (is_string($validated['product_id'] ?? null)) {
    $query->where('product_id', $validated['product_id']);
}
$query->whereIn('location_id', $locationIds);

$search = $validated['search'] ?? null;
if (is_string($search) && $search !== '') {
    $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
    $pattern = '%'.$escapedSearch.'%';
    $query->where(static function (Builder $searchQuery) use ($pattern): void {
        $searchQuery->whereRaw("LOWER(stock_movements.reference) LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereHas('product', static function (Builder $productQuery) use ($pattern): void {
                $productQuery->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$pattern]);
            });
    });
}

$movementType = $validated['movement_type'] ?? null;
if ($movementType === 'transfer') {
    $query->whereIn('movement_type', [
        MovementType::TransferIn->value,
        MovementType::TransferOut->value,
    ]);
} elseif (is_string($movementType)) {
    $query->where('movement_type', $movementType);
}

if (($validated['reason'] ?? null) === 'write_off') {
    $query->whereIn('reason', [
        MovementReason::WriteOff->value,
        MovementReason::Expiry->value,
        MovementReason::Damage->value,
    ]);
} elseif (is_string($validated['reason'] ?? null)) {
    $query->where('reason', $validated['reason']);
}

$perPage = (int) ($validated['per_page'] ?? 25);
$page = (int) ($validated['page'] ?? 1);
$movements = $query
    ->orderByDesc('created_at')
    ->orderByDesc('id')
    ->paginate($perPage, ['*'], 'page', $page);

$documentIds = $movements->getCollection()
    ->filter(static fn (StockMovement $movement): bool =>
        $movement->reference_id !== null
        && in_array($movement->reference_type, ['Document', Document::class], true))
    ->pluck('reference_id')
    ->filter(static fn ($id): bool => is_string($id))
    ->unique()
    ->values();
/** @var Collection<string, Document> $sourceDocumentsById */
$sourceDocumentsById = Document::query()
    ->where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)
    ->whereIn('id', $documentIds)
    ->get()
    ->keyBy('id');
~~~

Pass `$sourceDocumentsById` into every `formatMovement()` call. Change its signature to `private function formatMovement(StockMovement $movement, Collection $sourceDocumentsById): array`, resolve the document with `$sourceDocumentsById->get($movement->reference_id)` only for the two accepted document reference types, and delete `resolveSourceDocument()`. Return the existing six-field meta object: `current_page`, `last_page`, `per_page`, `total`, `from`, and `to`.

- [ ] **Step 5: Adapt the existing mocked-useQuery page harness instead of inventing a wrapper.** Add `waitFor`/`fireEvent`/`beforeEach`, hoist `apiGetMock` and `queryCapture`, preserve the real API exports, and make `useQuery` capture options while returning `mockReturn`.

~~~tsx
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

interface CapturedQuery {
  queryFn: () => Promise<unknown>
}

const apiGetMock = vi.hoisted(() => vi.fn())
const queryCapture = vi.hoisted(() => ({ current: null as CapturedQuery | null }))

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return { ...actual, api: { ...actual.api, get: apiGetMock } }
})

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: (options: CapturedQuery) => {
      queryCapture.current = options
      return mockReturn
    },
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})
~~~

Replace the existing declaration with the following one complete, parse-valid declaration. It contains exactly one initializer (`const mockReturn: { ... } = {`), not a second `} = {`; retain both movement rows and all six `meta` fields:

~~~tsx
interface StockMovementsResponse {
  data: StockMovement[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

const mockReturn: {
  data: StockMovementsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeMovement({ id: '1', product_name: 'Alpha', movement_type: 'receipt', quantity: '5.0000' }),
      makeMovement({ id: '2', product_name: 'Beta', movement_type: 'issue', quantity: '-3.0000', quantity_after: '2.0000' }),
    ],
    meta: { current_page: 1, last_page: 3, per_page: 25, total: 60, from: 1, to: 25 },
  },
  isLoading: false,
  error: null,
}
~~~

Keep the existing “renders a row per movement” assertion unchanged; both Alpha and Beta remain in the fixture.

Add this executable test:

~~~tsx
it('requests bounded server filters and renders the real OffsetPagination DOM', async () => {
  apiGetMock.mockResolvedValue({ data: mockReturn.data })
  render(<StockMovementsPage />)

  const firstQuery = queryCapture.current
  if (firstQuery === null) throw new Error('StockMovementsPage did not register its query')
  await firstQuery.queryFn()
  expect(apiGetMock).toHaveBeenLastCalledWith('/stock-movements?page=1&per_page=25')
  expect(screen.getByText('pagination.page 1 pagination.of 3')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'pagination.next' })).toBeEnabled()
  expect(screen.getByDisplayValue('25')).toBeInTheDocument()

  fireEvent.click(screen.getByRole('button', { name: 'pagination.next' }))
  await waitFor(() => expect(queryCapture.current).not.toBe(firstQuery))
  const secondQuery = queryCapture.current
  if (secondQuery === null) throw new Error('Page 2 did not register its query')
  await secondQuery.queryFn()
  expect(apiGetMock).toHaveBeenLastCalledWith('/stock-movements?page=2&per_page=25')
})
~~~

- [ ] **Step 6: Implement the page with every import named.**

~~~tsx
import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import type { OffsetPaginationMeta } from '../../types/pagination'

interface StockMovementsResponse {
  data: StockMovement[]
  meta: OffsetPaginationMeta
}

const [page, setPage] = useState(1)
const [perPage, setPerPage] = useState(25)

useEffect(() => {
  setPage(1)
}, [searchQuery, movementFilter, scope])

// queryKey
locationScopedKey(['stock-movements', searchQuery, movementFilter, page, perPage], scope)

// queryFn parameter mapping, in this order
if (searchQuery) params.append('search', searchQuery)
effectiveLocationIds.forEach((id) => { params.append('location_ids[]', id) })
if (movementFilter === 'transfer') {
  params.append('movement_type', 'transfer')
} else if (movementFilter === 'write_off') {
  params.append('reason', 'write_off')
} else if (movementFilter !== 'all') {
  params.append('movement_type', movementFilter)
}
params.append('page', String(page))
params.append('per_page', String(perPage))

// useQuery option
placeholderData: keepPreviousData,
~~~

Delete the client-side movement filtering. Use const movements = data?.data ?? []. Remove every FilterTabs count property because a selected page cannot supply global counts for the other tabs. Use data?.meta.total ?? 0 in the PageHeader subtitle. Render below DataTable:

~~~tsx
{data?.meta ? (
  <OffsetPagination
    currentPage={data.meta.current_page}
    lastPage={data.meta.last_page}
    total={data.meta.total}
    perPage={data.meta.per_page}
    from={data.meta.from}
    to={data.meta.to}
    onPageChange={setPage}
    onPerPageChange={(next) => {
      setPerPage(next)
      setPage(1)
    }}
  />
) : null}
~~~

- [ ] **Step 7: i18n disposition.** Do not add `inventory:movements.filters.writeOffPageScoped`; page-local filtering was removed. `OffsetPagination` already owns all copy. Verify the existing en/fr/ar `common:pagination` values remain present; locale files are not modified.
- [ ] **Step 8: Update both real key fixtures.** At the only material stock-movement page-key assertion, `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:273`, expect `['stock-movements', '', 'all', 1, 25, { locScope: 'all' }]` before the tenant/company suffix. In `apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx`, replace the old-shaped movement fixture with `['stock-movements', '', 'all', 1, 25, { locScope: 'all' }, tenant, company]`.
- [ ] **Step 9: Bound the W4 whole-result helper and prove the bound.** Add `expect` to the existing `@playwright/test` import and replace `stockMovements()` with this executable helper:

~~~ts
export async function stockMovements(page: Page, query = ''): Promise<ApiResult> {
  const params = new URLSearchParams(query.startsWith('?') ? query.slice(1) : query)
  params.set('page', '1')
  params.set('per_page', '100')
  const result = await apiRequest(page, 'GET', `/stock-movements?${params.toString()}`)
  const body = result.body as { meta?: { last_page?: number } }
  expect(body.meta?.last_page, 'W4 stock-movement helper must remain a complete one-page read').toBe(1)

  return result
}
~~~

If a W4 scenario legitimately grows past 100 matching rows, replace this assertion with a real page iterator; never raise the endpoint cap or silently read page 1.
- [ ] **Step 10: Verify by path on both drivers.** Run `StockMovementTest.php`, `StockMovementLocationFilterTest.php`, `ReverseWriteOffRouteTest.php`, and `InventoryTenantIsolationTest.php` on default SQLite and again with `DB_DATABASE=autoerp_test_<letter> DB_CENTRAL_DATABASE=autoerp_test_<letter> php artisan test -c phpunit-pgsql.xml`, serially. The latter’s calls at lines 713 and 811 prove existing no-`page` consumers still receive bounded page one and metadata. Frontend: run `StockMovementsPage.test.tsx`, `inventory/__tests__/tenantScope.test.tsx`, and `stock-adjustments/__tests__/queries.test.tsx` by path, then typecheck and lint. With the live W4 stack, run `apps/web/e2e/money-campaign/inventory-costing.spec.ts`, `inventory-counting.spec.ts`, `inventory-opening.spec.ts`, and `inventory-stock.spec.ts` by path. `ProductMovementsTab.tsx` already sends `page/per_page` and remains unchanged.
- [ ] **Step 11: Browser probe and gate.** Confirm product-name, SKU, reference, literal `%`, literal `_`, transfer, and write-off searches issue server params, totals remain global on page 2, and the pager changes requests. Gate: inventory-costing-reviewer plus frontend-conventions-reviewer.

---

## Task 3: Bounded payment reads and required list pagination (S-2 treasury half)

**Files**

- Create: apps/api/app/Modules/Treasury/Presentation/Requests/ListPaymentsRequest.php
- Modify: apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php
- Modify: apps/api/tests/Feature/Treasury/PaymentTest.php
- Modify: apps/web/src/features/treasury/PaymentListPage.tsx
- Modify: apps/web/src/features/treasury/PaymentListPage.search.test.tsx
- Modify: apps/web/src/features/treasury/PaymentListPage.test.tsx
- Modify: apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx
- Modify: apps/web/src/features/dashboard/Dashboard.tsx
- Modify: apps/web/e2e/money-campaign/w5c-support.ts
- Modify: apps/web/e2e/money-campaign/expenses-lifecycle.spec.ts
- Verify existing search consumer: apps/api/tests/Feature/Treasury/TreasuryCompanyIsolationTest.php:415

**Contract:** `ListPaymentsRequest` validates every accepted list parameter: `partner_id`, `status`, `search`, `page`, and `per_page`. `PaymentController::index()` consumes only `$request->validated()` values, defaults to 25, caps at 100, always returns the six-field offset meta object, and orders by `payment_date DESC, id DESC`. Search remains server-side across reference and partner name. The deterministic tie-break is proven with 30 rows sharing one payment date on both SQLite and PostgreSQL.

- [ ] **Step 1: Create the dedicated list request.**

~~~php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<object|string>> */
    public function rules(): array
    {
        return [
            'partner_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'string', Rule::enum(PaymentStatus::class)],
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
~~~

- [ ] **Step 2: Extend PaymentTest using its existing setUp fixtures.** Add `use App\Modules\Treasury\Domain\Enums\PaymentStatus;` and `use Carbon\CarbonImmutable;`, and keep the existing fixture graph.

~~~php
public function test_index_without_page_is_bounded_to_25(): void
{
    foreach (range(1, 30) as $index) {
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1.000',
            'currency' => 'TND',
            'payment_date' => now()->subMinutes($index),
            'status' => 'completed',
            'reference' => 'CAP-'.$index,
            'created_by' => $this->user->id,
        ]);
    }

    $response = $this->actingAs($this->user)->getJson('/api/v1/payments');
    $response->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.total', 30);
}

public function test_tied_payment_dates_cross_two_pages_without_duplicates_or_omissions(): void
{
    $paymentDate = CarbonImmutable::parse('2026-09-03 12:00:00');
    foreach (range(1, 30) as $index) {
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1.000',
            'currency' => 'TND',
            'payment_date' => $paymentDate,
            'status' => PaymentStatus::Completed,
            'reference' => 'TIED-'.$index,
            'created_by' => $this->user->id,
        ]);
    }

    $expectedIds = Payment::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('company_id', $this->company->id)
        ->where('reference', 'like', 'TIED-%')
        ->orderByDesc('payment_date')
        ->orderByDesc('id')
        ->pluck('id')
        ->all();
    $pageOne = $this->actingAs($this->user)
        ->getJson('/api/v1/payments?search=TIED-&page=1&per_page=15')->assertOk()->json('data');
    $pageTwo = $this->actingAs($this->user)
        ->getJson('/api/v1/payments?search=TIED-&page=2&per_page=15')->assertOk()->json('data');
    $actualIds = array_column([...$pageOne, ...$pageTwo], 'id');

    self::assertCount(30, $actualIds);
    self::assertCount(30, array_unique($actualIds));
    self::assertSame($expectedIds, $actualIds);
}
~~~

- [ ] **Step 3: Run both database legs red.** SQLite: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php tests/Feature/Treasury/TreasuryCompanyIsolationTest.php`. PostgreSQL, serially: `DB_DATABASE=autoerp_test_<letter> DB_CENTRAL_DATABASE=autoerp_test_<letter> php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php tests/Feature/Treasury/TreasuryCompanyIsolationTest.php`.
- [ ] **Step 4: Replace the index branch with validated-only reads and stable pagination.** Change only `index()` to accept `ListPaymentsRequest`; the other controller actions retain `Request`. Import the request and keep `Builder`.

~~~php
public function index(ListPaymentsRequest $request): JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();
    $tenantId = $this->companyContext->requireCompany()->tenant_id;
    $validated = $request->validated();
    $query = Payment::query()
        ->where('tenant_id', $tenantId)
        ->where('company_id', $companyId)
        ->with(['partner', 'paymentMethod', 'allocations.document']);

    if (is_string($validated['partner_id'] ?? null)) {
        $query->where('partner_id', $validated['partner_id']);
    }
    if (is_string($validated['status'] ?? null)) {
        $query->where('status', $validated['status']);
    }
    $search = $validated['search'] ?? null;
    if (is_string($search) && $search !== '') {
        $pattern = '%'.$search.'%';
        $query->where(static function (Builder $searchQuery) use ($pattern): void {
            $searchQuery->where('reference', 'like', $pattern)
                ->orWhereHas('partner', static function (Builder $partnerQuery) use ($pattern): void {
                    $partnerQuery->where('partners.name', 'like', $pattern);
                });
        });
    }

    $payments = $query
        ->orderByDesc('payment_date')
        ->orderByDesc('id')
        ->paginate(
            (int) ($validated['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

    return response()->json([
        'data' => $payments->getCollection()
            ->map(fn (Payment $payment): array => $this->formatPayment($payment))
            ->values(),
        'meta' => [
            'current_page' => $payments->currentPage(),
            'last_page' => $payments->lastPage(),
            'per_page' => $payments->perPage(),
            'total' => $payments->total(),
            'from' => $payments->firstItem(),
            'to' => $payments->lastItem(),
        ],
    ]);
}
~~~

No `$request->has()`, `$request->input()`, `$request->query()`, or `$request->integer()` remains in `index()`.

- [ ] **Step 5: Make PaymentListPage a required modification with exact imports and state.**

~~~tsx
import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import type { OffsetPaginationMeta } from '../../types/pagination'

interface PaymentsResponse {
  data: Payment[]
  meta: OffsetPaginationMeta
}

const [page, setPage] = useState(1)
const [perPage, setPerPage] = useState(25)

useEffect(() => {
  setPage(1)
}, [search])

queryKey: tenantScopedKey(['payments', search, page, perPage]),
placeholderData: keepPreviousData,
~~~

Build the URL in this exact order:

~~~tsx
const params = new URLSearchParams()
if (search) params.set('search', search)
params.set('page', String(page))
params.set('per_page', String(perPage))
const response = await api.get<PaymentsResponse>('/payments?'+params.toString())
~~~

Render OffsetPagination after DataTable with the six meta fields; onPageChange=setPage and onPerPageChange resets page to 1 exactly as Task 2.

- [ ] **Step 6: Update the existing real QueryClient search harness.** Change the mock to an Axios-shaped response and exact assertions:

~~~tsx
const apiGet = vi.fn((_url: string) => Promise.resolve({
  data: {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
  },
}))

expect(apiGet).toHaveBeenCalledWith('/payments?page=1&per_page=25')
expect(apiGet).toHaveBeenCalledWith('/payments?search=Alice&page=1&per_page=25')
expect(screen.getByText('pagination.page 1 pagination.of 1')).toBeInTheDocument()
~~~

- [ ] **Step 7: Update both existing list-contract fixtures.** In `apps/web/src/features/treasury/PaymentListPage.test.tsx`, replace the optional `{ total: number }` meta type and line-53 fixture with the required six fields: `{ current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 }`. In `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx`, update the line-181 comment and assertion to `['payments', '', 1, 25, 'tenant-A', 'company-1']`.
- [ ] **Step 8: Make `allPaymentIds()` actually read all pages.** Replace the single call in `apps/web/e2e/money-campaign/w5c-support.ts` with this direct raw-response loop; `get()` cannot be used because `asJsonResult()` deliberately unwraps `data` and discards sibling `meta`.

~~~ts
export async function allPaymentIds(
  request: APIRequestContext,
  session: Session,
): Promise<string[]> {
  const ids: string[] = []
  let page = 1
  let lastPage = 1

  do {
    const response = await request.get(
      `${API_BASE}/payments?page=${page}&per_page=100`,
      { headers: authHeaders(session) },
    )
    expect(response.ok(), `payments page ${page} -> ${response.status()}`).toBeTruthy()
    const body = await response.json() as {
      data: Array<{ id: string }>
      meta: { current_page: number; last_page: number }
    }
    expect(body.meta.current_page).toBe(page)
    ids.push(...body.data.map((payment) => payment.id))
    lastPage = body.meta.last_page
    page += 1
  } while (page <= lastPage)

  return ids.sort()
}
~~~

- [ ] **Step 9: Remove the stale consumer comment and fix Dashboard.** Delete the obsolete comment at `apps/web/e2e/money-campaign/expenses-lifecycle.spec.ts:245` claiming payment IDs intentionally omit page parameters; `allPaymentIds()` now proves complete pagination. Replace Dashboard’s `/payments?limit=5&sort=-created_at` with `/payments?page=1&per_page=5`; the controller’s mandatory `payment_date DESC, id DESC` order supplies newest first.
- [ ] **Step 10: Verify by path and gate.** Run `PaymentTest.php`, `PaymentCompanyScopeTest.php`, and `TreasuryCompanyIsolationTest.php` on both SQLite and PostgreSQL with the commands from Step 3; the three search calls around `TreasuryCompanyIsolationTest.php:415` must remain bounded page-one reads with metadata. Frontend: run `apps/web/src/features/treasury/PaymentListPage.search.test.tsx`, `apps/web/src/features/treasury/PaymentListPage.test.tsx`, `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx`, `apps/web/src/features/dashboard/dashboard.test.tsx`, and `apps/web/src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx` by path, then typecheck and lint. With the live W5c stack, run `apps/web/e2e/money-campaign/expenses-lifecycle.spec.ts` and `apps/web/e2e/money-campaign/w8-isolation.spec.ts` by path. `PartnerDetailPage.tsx` already sends page/per_page and remains unchanged. Browser-check the five-row dashboard and payment list page 2. Gate: treasury-reviewer plus frontend-conventions-reviewer.

---

## Task 4: Bounded audit trail and legacy document limit (S-3, S-33)

**Files**

- Create: apps/api/app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php
- Modify: apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php
- Modify: apps/api/app/Modules/Compliance/Services/AuditService.php
- Modify: apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php
- Modify: apps/api/tests/Feature/Compliance/AuditTrailTest.php
- Modify: apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php
- Modify: apps/api/tests/Feature/Document/ListDocumentsTest.php
- Modify: apps/api/lang/en/validation.php
- Modify: apps/api/lang/fr/validation.php
- Create: apps/api/lang/ar/validation.php
- Verify existing legacy-limit consumer: apps/web/e2e/campaign/onboarding.campaign.ts:293
- Verify existing legacy-limit consumer: apps/web/src/features/dashboard/Dashboard.tsx:140

**Contract:** date_format:Y-m-d, paired from/to, maximum 92 days, per_page 1..100 default 50. Payload and metadata are absent by default and present only for include=payload. Aggregate and range branches retain ascending occurred_at; company and event-type branches retain descending occurred_at.

- [ ] **Step 1: Add the concrete FormRequest.**

~~~php
<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ListAuditEventsRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'string', 'max:120'],
            'aggregate_type' => ['required_with:aggregate_id', 'string', 'max:120'],
            'aggregate_id' => ['required_with:aggregate_type', 'uuid'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'include' => ['sometimes', 'string', 'in:payload'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }
            $from = $this->input('from');
            $to = $this->input('to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }
            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', (string) __('validation.audit_date_range_max', [
                    'max' => self::MAX_SPAN_DAYS,
                ]));
            }
        });
    }
}
~~~

Add the same `audit_date_range_max` key to every backend validation locale. The English text is the executable contract and `:max` must remain a parameter:

~~~php
// apps/api/lang/en/validation.php
'audit_date_range_max' => 'The date range may not exceed :max days.',

// apps/api/lang/fr/validation.php
'audit_date_range_max' => 'La période ne peut pas dépasser :max jours.',

// apps/api/lang/ar/validation.php (this locale has no validation file yet)
<?php

declare(strict_types=1);

return [
    'audit_date_range_max' => 'لا يجوز أن تتجاوز الفترة :max يومًا.',
];
~~~

- [ ] **Step 2: Add the service paginator with exact imports and stable ordering.**

~~~php
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @return LengthAwarePaginator<AuditEvent> */
public function paginateEvents(
    string $companyId,
    ?string $eventType,
    ?string $aggregateType,
    ?string $aggregateId,
    ?CarbonInterface $from,
    ?CarbonInterface $to,
    int $perPage,
    int $page,
    bool $oldestFirst,
): LengthAwarePaginator {
    $query = AuditEvent::query()->where('company_id', $companyId);
    if ($eventType !== null) {
        $query->where('event_type', $eventType);
    }
    if ($aggregateType !== null && $aggregateId !== null) {
        $query->where('aggregate_type', $aggregateType)->where('aggregate_id', $aggregateId);
    }
    if ($from !== null && $to !== null) {
        $query->whereBetween('occurred_at', [$from, $to]);
    }

    if ($oldestFirst) {
        $query->orderBy('occurred_at');
    } else {
        $query->orderByDesc('occurred_at');
    }
    return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
}
~~~

Also broaden getEventsInRange and countEventsByType from Illuminate\Support\Carbon to CarbonInterface; existing Illuminate Carbon callers remain compatible.

- [ ] **Step 3: Replace AuditController::index’s raw Request with ListAuditEventsRequest.** Import CarbonImmutable and ListAuditEventsRequest. Parse only validated strings, from->startOfDay(), to->endOfDay(), set oldestFirst when the aggregate pair or date pair is present, call paginateEvents, map the existing fields, conditionally merge payload/metadata, and return the same six meta fields used by Task 2. Because `required_with` now rejects every missing half before the controller runs, each validated pair is either complete or absent: `$hasAggregate` still selects the aggregate branch, otherwise `$hasRange` still selects the range branch, and neither half-specified input can fall through to the broader event-type/company branches. Keep the existing `test_can_query_audit_events_by_aggregate`, `test_can_query_audit_events_by_date_range`, and `test_audit_events_aggregate_branch_scopes_by_company` green to confirm those branch selections.

~~~php
$eventTypeInput = $request->validated('event_type');
$aggregateTypeInput = $request->validated('aggregate_type');
$aggregateIdInput = $request->validated('aggregate_id');
$fromInput = $request->validated('from');
$toInput = $request->validated('to');
$eventType = is_string($eventTypeInput) ? $eventTypeInput : null;
$aggregateType = is_string($aggregateTypeInput) ? $aggregateTypeInput : null;
$aggregateId = is_string($aggregateIdInput) ? $aggregateIdInput : null;
$from = is_string($fromInput) ? CarbonImmutable::parse($fromInput)->startOfDay() : null;
$to = is_string($toInput) ? CarbonImmutable::parse($toInput)->endOfDay() : null;
$hasAggregate = $aggregateType !== null && $aggregateId !== null;
$hasRange = ! $hasAggregate && $from !== null && $to !== null;
$oldestFirst = $hasAggregate || $hasRange;

$events = $this->auditService->paginateEvents(
    companyId: $this->companyContext->requireCompanyId(),
    eventType: $hasAggregate ? null : $eventType,
    aggregateType: $hasAggregate ? $aggregateType : null,
    aggregateId: $hasAggregate ? $aggregateId : null,
    from: $hasRange ? $from : null,
    to: $hasRange ? $to : null,
    perPage: $request->integer('per_page', 50),
    page: $request->integer('page', 1),
    oldestFirst: $oldestFirst,
);

$includePayload = $request->validated('include') === 'payload';
$rows = $events->getCollection()->map(
    static fn (AuditEvent $event): array => array_merge([
        'id' => $event->id,
        'event_type' => $event->event_type,
        'aggregate_type' => $event->aggregate_type,
        'aggregate_id' => $event->aggregate_id,
        'user_id' => $event->user_id,
        'event_hash' => $event->event_hash,
        'occurred_at' => $event->occurred_at->toIso8601String(),
    ], $includePayload ? [
        'payload' => $event->payload,
        'metadata' => $event->metadata,
    ] : []),
)->values();

return response()->json([
    'data' => $rows,
    'meta' => [
        'current_page' => $events->currentPage(),
        'last_page' => $events->lastPage(),
        'per_page' => $events->perPage(),
        'total' => $events->total(),
        'from' => $events->firstItem(),
        'to' => $events->lastItem(),
    ],
]);
~~~

- [ ] **Step 4: Make the audit tests genuinely red, including the default-50 cap, page traversal, and the global validation envelope.** In AuditTrailTest change line 264 to /api/v1/audit/events?include=payload and keep its payload structure assertion. Add:

~~~php
public function test_audit_api_omits_payload_by_default(): void
{
    app(AuditService::class)->record(
        companyId: $this->company->id,
        userId: $this->user->id,
        eventType: 'document.created',
        aggregateType: 'Document',
        aggregateId: 'doc-red',
        payload: ['secret' => 'red'],
        metadata: ['ip' => '127.0.0.1'],
    );

    $event = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events')
        ->assertOk()
        ->json('data.0');

    self::assertIsArray($event);
    self::assertArrayNotHasKey('payload', $event);
    self::assertArrayNotHasKey('metadata', $event);
}

public function test_malformed_or_oversized_date_range_returns_422_not_500(): void
{
    app()->setLocale('en');
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?from=not-a-date&to=2026-09-03')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.errors.from.0', 'The from field must match the format Y-m-d.');
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?from=2026-01-01&to=2026-06-01')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.errors.to.0', 'The date range may not exceed 92 days.');
}

public function test_aggregate_type_without_aggregate_id_returns_validation_error(): void
{
    app()->setLocale('en');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?aggregate_type=Document')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.errors.aggregate_id.0',
            'The aggregate id field is required when aggregate type is present.',
        );
}

public function test_aggregate_id_without_aggregate_type_returns_validation_error(): void
{
    app()->setLocale('en');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?aggregate_id=00000000-0000-4000-8000-000000000001')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.errors.aggregate_type.0',
            'The aggregate type field is required when aggregate id is present.',
        );
}

public function test_from_without_to_returns_validation_error(): void
{
    app()->setLocale('en');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?from=2026-09-01')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.errors.to.0',
            'The to field is required when from is present.',
        );
}

public function test_to_without_from_returns_validation_error(): void
{
    app()->setLocale('en');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?to=2026-09-03')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.errors.from.0',
            'The from field is required when to is present.',
        );
}

public function test_audit_api_defaults_to_50_and_page_two_contains_the_remaining_event(): void
{
    /** @var AuditService $auditService */
    $auditService = app(AuditService::class);
    $seededIds = [];
    foreach (range(1, 51) as $index) {
        $seededIds[] = $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'audit.pagination.probe',
            aggregateType: 'Document',
            aggregateId: 'page-'.$index,
            payload: ['index' => $index],
        )->id;
    }

    $pageOne = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?event_type=audit.pagination.probe');
    $pageOne->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.total', 51)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 50);

    $pageTwo = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?event_type=audit.pagination.probe&page=2');
    $pageTwo->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 51)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 50);

    $actualIds = array_column([
        ...$pageOne->json('data'),
        ...$pageTwo->json('data'),
    ], 'id');
    self::assertCount(51, $actualIds);
    self::assertCount(51, array_unique($actualIds));
    self::assertEqualsCanonicalizing($seededIds, $actualIds);
}

public function test_audit_api_rejects_per_page_above_100_with_validation_envelope(): void
{
    app()->setLocale('en');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/audit/events?per_page=101')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.errors.per_page.0',
            'The per page field must not be greater than 100.',
        );
}
~~~

In `ComplianceCrossTenantHardeningTest`, append `&include=payload` to the aggregate-branch URL currently at line 265 (the assertion at line 275 reads `payload`), and change the same-tenant URL at line 310 to `/api/v1/audit/events?include=payload`; keep the foreign-company request unchanged because it asserts authorization before serialization.

- [ ] **Step 5: Clamp the legacy document branch and prove 0, -5, and 5000.** Compute `$cappedLimit = max(1, min((int) $limit, 100))`, use `take($cappedLimit)`, and return both `meta.total=$documents->count()` and `meta.per_page=$cappedLimit`. Add these executable tests to the existing `ListDocumentsTest` fixture:

~~~php
private function seedLegacyLimitDocuments(int $count): void
{
    foreach (range(1, $count) as $index) {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'LIMIT-'.$index,
            'document_date' => now(),
            'currency' => 'EUR',
        ]);
    }
}

public function test_legacy_limit_zero_clamps_to_one(): void
{
    $this->seedLegacyLimitDocuments(3);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/documents?limit=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1);
}

public function test_legacy_limit_negative_five_clamps_to_one(): void
{
    $this->seedLegacyLimitDocuments(3);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/documents?limit=-5')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1);
}

public function test_legacy_limit_5000_returns_exactly_100_of_101_documents(): void
{
    $this->seedLegacyLimitDocuments(101);

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/documents?limit=5000');
    $response->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);
    self::assertCount(100, $response->json('data'));
}
~~~

- [ ] **Step 6: Verify by path and gate.** Run `cd apps/api && ./vendor/bin/phpunit tests/Feature/Compliance/AuditTrailTest.php tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php tests/Feature/Document/ListDocumentsTest.php`; expand every other existing Compliance feature file from `rg --files tests/Feature/Compliance | sort` into explicit file paths rather than invoking the whole backend suite. Run PHPStan on the request, audit controller/service, document controller, and all three validation locale files. The four half-pair tests must each fail red before the rules change, then return `VALIDATION_ERROR` at the exact counterpart path; the existing aggregate/range/company-scope tests must remain green after validation to prove controller branch selection. The English max-span assertion proves translation resolution and `:max` substitution; review fr/ar keys during locale QA. Run the Dashboard document-list tests by path, then use the live onboarding campaign to exercise its `limit=100` request at `onboarding.campaign.ts:293` and browser-check Dashboard’s `limit=5` request at `Dashboard.tsx:140`; both must remain within the 1..100 clamp. Gate: general Opus; add fiscal-pos-reviewer only if audit-chain behavior changes.

---

## Task 5: Debounce LineItemEntryBar product search (S-4)

**Files**

- Modify: apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx
- Modify: apps/web/src/components/molecules/line-items/LineItemEntryBar.test.tsx

- [ ] **Step 1: Use the existing apiClientGetMock, setTenant, wrapper(), and onAddProduct prop.** Add act and ensure afterEach restores timers. The three changes are distinct awaited React commits.

~~~tsx
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'

afterEach(() => {
  vi.useRealTimers()
  resetTenant()
})

it('waits 250 ms and sends exactly the final product search', async () => {
  vi.useFakeTimers()
  apiClientGetMock.mockResolvedValue({ data: { data: [] } })
  render(<LineItemEntryBar onAddProduct={vi.fn()} />, { wrapper: wrapper() })
  const input = screen.getByRole('combobox', { name: 'Search or scan a product' })

  await act(async () => {
    fireEvent.focus(input)
    await Promise.resolve()
    await Promise.resolve()
  })
  expect(apiClientGetMock).toHaveBeenCalledWith('/products', { params: { per_page: 20 } })
  apiClientGetMock.mockClear()
  await act(async () => { fireEvent.change(input, { target: { value: 'a' } }); await Promise.resolve() })
  await act(async () => { fireEvent.change(input, { target: { value: 'ab' } }); await Promise.resolve() })
  await act(async () => { fireEvent.change(input, { target: { value: 'abc' } }); await Promise.resolve() })

  const searchCalls = () => apiClientGetMock.mock.calls.filter(
    ([, config]) => (config as { params?: { search?: string } } | undefined)?.params?.search !== undefined,
  )
  await act(async () => { vi.advanceTimersByTime(249); await Promise.resolve() })
  expect(searchCalls()).toHaveLength(0)
  await act(async () => { vi.advanceTimersByTime(1); await Promise.resolve() })
  expect(searchCalls()).toHaveLength(1)
  expect(searchCalls()[0]?.[1]).toEqual({ params: { per_page: 20, search: 'abc' } })
})
~~~

- [ ] **Step 2: Run red.** The current query changes on each committed value.
- [ ] **Step 3: Implement with exact imports.**

~~~tsx
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '../../../lib/hooks'

const trimmedQuery = query.trim()
const debouncedQuery = useDebouncedValue(trimmedQuery, 250)

queryKey: tenantScopedKey(['line-entry-products', debouncedQuery]),
// use debouncedQuery in params
placeholderData: keepPreviousData,
~~~

- [ ] **Step 4: Verify by path and gate.** Run `apps/web/src/components/molecules/line-items/LineItemEntryBar.test.tsx` by path, then typecheck, lint, and browser-check Sales, transfer, replenishment, and counting product entry. Gate: frontend-conventions-reviewer.

---

## Task 6: Debounce bulk pricing context (S-5) — **WAIT for wave-2 PO lane merge**

**Files**

- Modify after WAIT clears: apps/web/src/features/documents/components/DocumentLineEditor.tsx
- Modify after WAIT clears: apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx

- [ ] **Step 1: Do not start until the wave-2 PO lane is merged and this lane is rebased.**
- [ ] **Step 2: Extend the existing createWrapper(), makeLine(), apiPost mock, onChange setup, and controlled-editor pattern.** Add act/afterEach imports and use the real items response plus MoneyInput string value.

~~~tsx
import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

afterEach(() => {
  vi.useRealTimers()
})

it('waits 250 ms and sends only the final unit price to bulk pricing', async () => {
  vi.useFakeTimers()
  vi.mocked(apiPost).mockResolvedValue({ items: {} })

  function ControlledEditor() {
    const [currentLines, setCurrentLines] = useState<DocumentLine[]>([
      makeLine({ product_id: 'prod-1', unit_price: '0', line_total: '0' }),
    ])
    return (
      <DocumentLineEditor
        partnerId="partner-1"
        lines={currentLines}
        onChange={setCurrentLines}
      />
    )
  }

  render(<ControlledEditor />, { wrapper: createWrapper() })
  const price = screen.getByRole('spinbutton', { name: 'Unit Price' })
  await act(async () => {
    fireEvent.focus(price)
    await Promise.resolve()
    await Promise.resolve()
  })
  expect(apiPost).toHaveBeenCalledTimes(1)
  vi.mocked(apiPost).mockClear()
  await act(async () => { fireEvent.change(price, { target: { value: '1' } }); await Promise.resolve() })
  await act(async () => { fireEvent.change(price, { target: { value: '12' } }); await Promise.resolve() })
  await act(async () => { fireEvent.change(price, { target: { value: '125' } }); await Promise.resolve() })

  await act(async () => { vi.advanceTimersByTime(249); await Promise.resolve() })
  expect(apiPost).not.toHaveBeenCalled()
  await act(async () => { vi.advanceTimersByTime(1); await Promise.resolve() })
  expect(apiPost).toHaveBeenCalledTimes(1)
  expect(apiPost).toHaveBeenCalledWith('/line-entry/pricing-context/bulk', {
    partner_id: 'partner-1',
    lines: [{ product_id: 'prod-1', variant_id: null, unit_price: '125' }],
  })
})
~~~

- [ ] **Step 3: Implement with exact imports and current-body ref.**

~~~tsx
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useDebouncedValue } from '../../../lib/hooks'

const debouncedPricingSignature = useDebouncedValue(pricingContextSignature, 250)
const pricingContextLinesRef = useRef(pricingContextLines)
pricingContextLinesRef.current = pricingContextLines

queryKey: tenantScopedKey([
  'line-entry-pricing-context',
  partnerId ?? null,
  debouncedPricingSignature,
]),
queryFn: () => apiPost<PricingContextResponse>('/line-entry/pricing-context/bulk', {
  partner_id: partnerId ?? null,
  lines: pricingContextLinesRef.current,
}),
placeholderData: keepPreviousData,
~~~

- [ ] **Step 4: Verify by path and gate.** Run `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` by path, then typecheck, lint, and browser-check purchase-order plus `apps/web/src/features/documents/CreateCreditNotePage.tsx` pricing. Gate: frontend-conventions-reviewer. WAIT remains binding until Step 1.

---

## Task 7: Deduplicate stock-level requests between transfer components (S-6 partial)

**Files**

- Create: apps/web/src/features/products/api/useProductStockLevels.ts
- Modify: apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx
- Modify: apps/web/src/features/stock-transfers/components/TransferSourceSuggestion.tsx
- Create: apps/web/src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx
- Modify: apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx
- Verify prefix invalidation consumer: apps/web/src/features/purchases/GoodsReceiptListPage.tsx:282

**Contract and Phase A boundary:** key root stays stock-levels so useCreateStockTransfer, useCompleteStockTransfer, and useCancelStockTransfer invalidations in features/stock-transfers/api/queries.ts continue to match. Phase A **partially** closes S-6 only by deduplicating `AvailabilityCell` and `TransferSourceSuggestion` when they request the same product/variant. The page still makes one stock-level request for each distinct product/variant; eliminating that N-distinct-product fan-out requires the B-8 bulk stock-level endpoint and is not claimed here.

- [ ] **Step 1: Add the hook test under the real feature test directory.**

~~~tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useProductStockLevels } from '@/features/products/api/useProductStockLevels'
import { getProductStock } from '@/features/products/api/productStock'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

vi.mock('@/features/products/api/productStock', () => ({ getProductStock: vi.fn() }))
const getProductStockMock = vi.mocked(getProductStock)

beforeEach(() => {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles: [],
      email_verified_at: null,
    },
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1' })
})

afterEach(() => {
  useAuthStore.setState({ user: null })
  useCompanyStore.setState({ currentCompanyId: null })
  vi.clearAllMocks()
})

it('dedupes two concurrent consumers under the stock-levels root', async () => {
  getProductStockMock.mockResolvedValue({
    locations: [],
    totals: {
      quantity: '0.0000',
      quantity_decimals: 4,
      reserved: '0.0000',
      available: '0.0000',
      incoming: '0.0000',
      projected_available: '0.0000',
    },
  })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )

  const first = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })
  const second = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })
  await waitFor(() => expect(first.result.current.isSuccess && second.result.current.isSuccess).toBe(true))

  expect(getProductStockMock).toHaveBeenCalledTimes(1)
  expect(client.getQueryCache().findAll({ queryKey: ['stock-levels'] })).toHaveLength(1)
})

it('does not request until both tenant and company scopes exist', async () => {
  useCompanyStore.setState({ currentCompanyId: null })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  const hook = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })

  expect(hook.result.current.fetchStatus).toBe('idle')
  expect(getProductStockMock).not.toHaveBeenCalled()
  act(() => { useCompanyStore.setState({ currentCompanyId: 'company-1' }) })
  await waitFor(() => expect(hook.result.current.isSuccess).toBe(true))
  expect(getProductStockMock).toHaveBeenCalledTimes(1)
})
~~~

- [ ] **Step 2: Implement the hook with reactive scope subscriptions and its own enablement gate.** Reading scope only inside `tenantScopedKey()` is insufficient because that helper uses `getState()` and cannot rerender the hook when authentication/company bootstrap completes.

~~~tsx
import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getProductStock } from './productStock'

export function useProductStockLevels(
  productId: string,
  variantId: string | null,
  requestedEnabled: boolean,
) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['stock-levels', 'product', productId, variantId]),
    queryFn: () => getProductStock(productId, variantId),
    enabled:
      requestedEnabled
      && productId !== ''
      && tenantId !== null
      && companyId !== null,
    staleTime: 15_000,
  })
}
~~~

- [ ] **Step 3: Replace both local queries.** AvailabilityCell calls useProductStockLevels(productId, variantId, sourceLocationId !== ''). TransferSourceSuggestion calls useProductStockLevels(productId, variantId, true). Remove its useQuery, locationScopedKey, and useViewScope imports; the endpoint already returns all company locations.
- [ ] **Step 4: Extend the existing availability harness with a URL-filtered count.** Selecting a product also calls the variants endpoint, so never count all `apiGet` traffic. After the existing “5.0000” wait, add:

~~~tsx
const stockLevelCalls = () => mockApiGet.mock.calls.filter(
  ([url]) => typeof url === 'string' && /^\/products\/[^/]+\/stock-levels$/.test(url),
)
expect(stockLevelCalls()).toHaveLength(1)
~~~

Before the shared hook, `AvailabilityCell` and `TransferSourceSuggestion` produce **2 stock-level requests** for the same product row; after the hook they produce **1 stock-level request**. The unrelated product-variant call remains in the harness and is deliberately excluded by the URL predicate, proving the assertion cannot pass by hiding variant traffic.
- [ ] **Step 5: Verify by path and gate.** Run `apps/web/src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx`, `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx`, and `apps/web/src/features/stock-transfers/__tests__/queries.test.tsx` by path, then typecheck and lint. Do not edit `features/stock-transfers/api/queries.ts` or `GoodsReceiptListPage.tsx`; confirm the latter’s `scopedNamespacePredicate('stock-levels', ...)` invalidation at line 282 still matches the new shared key root. In the browser network panel, two rows of the same product/variant must produce **one stock-level request**; adding one distinct product must bring the total to **two stock-level requests**. Record that the second request is the expected remaining per-distinct-product behavior and stays open until B-8; this task must not be reported as full S-6 closure. Gate: frontend-conventions-reviewer.

---

## Task 8: Cooldown-only reconnect recovery (S-7)

**Files**

- Modify: apps/web/src/providers/WebSocketReconnectProvider.tsx
- Create: apps/web/src/providers/WebSocketReconnectProvider.test.tsx

**Contract:** invalidate all active queries after a disconnected-to-connected transition; suppress only transitions inside 30 seconds. There is no reference-data allowlist.

- [ ] **Step 1: Add the complete fake-timer test with a real spy.**

~~~tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { WebSocketReconnectProvider } from './WebSocketReconnectProvider'

let connected = true
vi.mock('../hooks/useWebSocketConnection', () => ({
  useWebSocketConnection: () => ({ isConnected: connected }),
}))

function tree(client: QueryClient, children: ReactNode = <div />) {
  return (
    <QueryClientProvider client={client}>
      <WebSocketReconnectProvider>{children}</WebSocketReconnectProvider>
    </QueryClientProvider>
  )
}

describe('WebSocketReconnectProvider', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(0)
    connected = true
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('invalidates all active queries on first reconnect and applies a 30 second cooldown', () => {
    const client = new QueryClient()
    const spy = vi.spyOn(client, 'invalidateQueries').mockResolvedValue(undefined)
    const view = render(tree(client))

    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenLastCalledWith({ refetchType: 'active' })

    act(() => { vi.advanceTimersByTime(10_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)

    act(() => { vi.advanceTimersByTime(20_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(2)
  })
})
~~~

- [ ] **Step 2: Implement with the nullable sentinel.**

~~~tsx
const RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000
const lastInvalidationAt = useRef<number | null>(null)

useEffect(() => {
  if (!isConnected) {
    wasDisconnected.current = true
    return
  }
  if (!wasDisconnected.current) return
  wasDisconnected.current = false

  const now = Date.now()
  if (
    lastInvalidationAt.current !== null
    && now - lastInvalidationAt.current < RECONNECT_INVALIDATION_COOLDOWN_MS
  ) return

  lastInvalidationAt.current = now
  void queryClient.invalidateQueries({ refetchType: 'active' })
}, [isConnected, queryClient])
~~~

- [ ] **Step 3: Preserve the separate company-switch behavior.** CompanySelector’s queryClient.invalidateQueries() is intentional and untouched.
- [ ] **Step 4: Verify by path and gate.** Run `apps/web/src/providers/WebSocketReconnectProvider.test.tsx` by path, then typecheck, lint, and an authenticated-layout browser reconnect probe through `DashboardLayout`. Gate: frontend-conventions-reviewer.

---

## Task 9: Cache store fail-closed default and boot validation (S-16)

**Files**

- Modify: apps/api/config/cache.php
- Modify: apps/api/docker/entrypoint.sh
- Modify: apps/api/docker/entrypoint-worker.sh
- Modify: apps/api/docker/entrypoint-scheduler.sh
- Create: apps/api/docker/verify-cache-store.sh
- Create: apps/api/app/Console/Commands/VerifyCacheStoreCommand.php
- Create: apps/api/tests/Unit/Console/VerifyCacheStoreCommandTest.php
- Modify: .github/workflows/ci.yml

- [ ] **Step 1: Add the executable command tests.**

~~~php
<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Tests\TestCase;

final class VerifyCacheStoreCommandTest extends TestCase
{
    public function test_database_store_fails_tag_capability_check(): void
    {
        config(['cache.default' => 'database']);
        $this->artisan('cache:verify-store')->assertExitCode(1);
    }

    public function test_array_store_passes_tag_capability_check(): void
    {
        config(['cache.default' => 'array']);
        $this->artisan('cache:verify-store')->assertExitCode(0);
    }

    public function test_cache_config_defaults_to_redis_when_cache_store_is_unset(): void
    {
        $hadEnv = array_key_exists('CACHE_STORE', $_ENV);
        $previousEnv = $_ENV['CACHE_STORE'] ?? null;
        $hadServer = array_key_exists('CACHE_STORE', $_SERVER);
        $previousServer = $_SERVER['CACHE_STORE'] ?? null;
        $previousProcessValue = getenv('CACHE_STORE');

        try {
            putenv('CACHE_STORE');
            unset($_ENV['CACHE_STORE'], $_SERVER['CACHE_STORE']);

            /** @var array{default: string} $cacheConfig */
            $cacheConfig = require base_path('config/cache.php');
            self::assertSame('redis', $cacheConfig['default']);
        } finally {
            if ($hadEnv) {
                $_ENV['CACHE_STORE'] = $previousEnv;
            } else {
                unset($_ENV['CACHE_STORE']);
            }
            if ($hadServer) {
                $_SERVER['CACHE_STORE'] = $previousServer;
            } else {
                unset($_SERVER['CACHE_STORE']);
            }
            if ($previousProcessValue === false) {
                putenv('CACHE_STORE');
            } else {
                putenv('CACHE_STORE='.$previousProcessValue);
            }
        }
    }
}
~~~

- [ ] **Step 2: Implement VerifyCacheStoreCommand with the exact imports and body.**

~~~php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Cache\CacheManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Store;

final class VerifyCacheStoreCommand extends Command
{
    protected $signature = 'cache:verify-store';

    protected $description = 'Fail when the default cache store cannot serve tenant-tagged operations';

    public function __construct(private readonly CacheManager $cache)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $default = (string) config('cache.default');
        /** @var Store $store */
        $store = $this->cache->store($default)->getStore();
        if (! method_exists($store, 'tags')) {
            $this->error(sprintf("Cache store '%s' does not support tags; set CACHE_STORE=redis.", $default));
            return self::FAILURE;
        }
        $this->info(sprintf("Cache store '%s' supports tags.", $default));
        return self::SUCCESS;
    }
}
~~~
- [ ] **Step 3: Run the isolated config test red, then change `config/cache.php` default fallback from `database` to `redis`.** `phpunit.xml` pins `CACHE_STORE=array`, so ordinary booted config assertions cannot prove this change; the direct `require` above removes/restores the process, `$_ENV`, and `$_SERVER` values around the assertion. `ArrayStore` supports tags, so the capability test remains valid.
- [ ] **Step 4: Pin the infrastructure-free artisan CI job explicitly.** In `.github/workflows/ci.yml`, give the `types-drift` job—which intentionally has no Redis or database—this job-level environment before `defaults`. Its `php artisan typescript:transform` invocation must never inherit the new Redis fallback:

~~~yaml
  types-drift:
    name: Generated Types Drift Guard
    runs-on: ubuntu-latest
    env:
      CACHE_STORE: array
    defaults:
      run:
        working-directory: apps/api
~~~

- [ ] **Step 5: Create one fail-closed helper and source it after `config:cache` in every runtime entrypoint.** Create `apps/api/docker/verify-cache-store.sh` with the exact body below. It checks store capability only; it does not prove Redis connectivity.

~~~bash
#!/bin/sh

if ! php artisan cache:verify-store; then
    echo "FATAL: cache store cannot serve tenant-tagged operations" >&2
    exit 1
fi
~~~

Add this validation-only branch immediately after `set -e` in **each** of `docker/entrypoint.sh`, `docker/entrypoint-worker.sh`, and `docker/entrypoint-scheduler.sh`. Deriving the directory from `$0` makes the real entrypoint executable both from the source checkout and from `/var/www/html` in the image:

~~~bash
if [ "${1:-}" = "--check-only" ]; then
    SCRIPT_DIR="$(CDPATH= cd "$(dirname "$0")" && pwd)"
    cd "$SCRIPT_DIR/.."
    php artisan config:cache
    . "$SCRIPT_DIR/verify-cache-store.sh"
    exit 0
fi
~~~

For normal boot, source the helper immediately after each entrypoint's existing `config:cache` command/block and before any runtime process starts. The web/bundled entrypoint adds it after the `if ! php artisan config:cache; then ... fi` block near current line 214; the worker adds it after `php artisan config:cache 2>/dev/null || true` near current line 39; the scheduler adds it after the same command near current line 29:

~~~bash
. /var/www/html/docker/verify-cache-store.sh
~~~

No role may treat this helper as `|| true`; `exit 1` is the permanent fail-closed boot behavior.

- [ ] **Step 6: Exercise the real validation-only branch for all three roles.** Run this shell harness from the repository. The first loop proves every role exits non-zero with the forbidden database store; the second proves the same entrypoint path reaches a taggable infrastructure-free store. The trap removes the generated config cache even on failure.

~~~bash
cd apps/api
set -eu
cleanup_cache_config() {
    php artisan config:clear >/dev/null 2>&1 || true
}
trap cleanup_cache_config EXIT

for entrypoint in \
    docker/entrypoint.sh \
    docker/entrypoint-worker.sh \
    docker/entrypoint-scheduler.sh
do
    if output="$(CACHE_STORE=database sh "$entrypoint" --check-only 2>&1)"; then
        echo "ERROR: $entrypoint accepted CACHE_STORE=database" >&2
        exit 1
    fi
    case "$output" in
        *"does not support tags"*"FATAL: cache store cannot serve tenant-tagged operations"*) ;;
        *)
            echo "ERROR: $entrypoint failed before the cache capability guard" >&2
            echo "$output" >&2
            exit 1
            ;;
    esac
done

for entrypoint in \
    docker/entrypoint.sh \
    docker/entrypoint-worker.sh \
    docker/entrypoint-scheduler.sh
do
    output="$(CACHE_STORE=array sh "$entrypoint" --check-only 2>&1)"
    case "$output" in
        *"supports tags"*) ;;
        *)
            echo "ERROR: $entrypoint did not reach the passing capability guard" >&2
            echo "$output" >&2
            exit 1
            ;;
    esac
done

sh -n docker/entrypoint.sh
sh -n docker/entrypoint-worker.sh
sh -n docker/entrypoint-scheduler.sh
sh -n docker/verify-cache-store.sh
~~~

Expected: all three `CACHE_STORE=database` commands reach `cache:verify-store` and exit non-zero; all three `CACHE_STORE=array` commands exit zero; every `sh -n` exits zero.

- [ ] **Step 7: Verify by path and enforce the environment gate.** Run `cd apps/api && ./vendor/bin/phpunit tests/Unit/Console/VerifyCacheStoreCommandTest.php` and PHPStan on `app/Console/Commands/VerifyCacheStoreCommand.php`; run the Step 6 shell harness and inspect the `types-drift` job YAML. Promotion is forbidden until **each environment independently**—web, worker, scheduler, and CLI—shows `CACHE_STORE=redis`, boots `cache:verify-store` successfully, and completes a real Redis write/read/delete probe from that environment. Evidence from one environment cannot stand in for another. The command proves tag capability, not network reachability. Gate: general Opus.

---

## Task 10: Restorable query counting and log-only lazy-load guard

**Files**

- Create: apps/api/tests/Traits/CountsQueries.php
- Modify: apps/api/app/Providers/AppServiceProvider.php
- Modify: apps/api/tests/Feature/Inventory/StockMovementTest.php
- Verify exact-log contract: apps/api/tests/Feature/Inventory/InventoryGlPostingSeamTest.php
- Verify exact-log contract: apps/api/tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php
- Verify exact-log contract: apps/api/tests/Feature/POS/RefundReportingFieldsTest.php
- Verify exact-log contract: apps/api/tests/Unit/POS/ReceiptReturnServiceTest.php:292

- [ ] **Step 1: Add the query helper using only the restorable query-log API.**

~~~php
<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;

trait CountsQueries
{
    /**
     * Do not nest this helper. It flushes the connection's pre-existing query
     * log on entry and exit, so an inner call would destroy the outer sample.
     */
    protected function countQueries(callable $fn): int
    {
        $wasLogging = DB::connection()->logging();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $fn();
            return count(DB::getQueryLog());
        } finally {
            DB::flushQueryLog();
            if (! $wasLogging) {
                DB::disableQueryLog();
            }
        }
    }

    protected function assertQueryCountAtMost(int $max, callable $fn, string $message = ''): void
    {
        $actual = $this->countQueries($fn);
        self::assertLessThanOrEqual(
            $max,
            $actual,
            $message !== '' ? $message : 'Expected at most '.$max.' queries; ran '.$actual.'.',
        );
    }
}
~~~

No `DB::listen` listener is registered or retained. The helper restores whether logging was enabled, but intentionally destroys pre-existing log contents and therefore **must never be nested**.

- [ ] **Step 2: Extend StockMovementTest with proportional document linkage.** Add imports `Carbon\CarbonImmutable`, `Illuminate\Support\Facades\Log`, `Tests\Traits\CountsQueries`, and `App\Modules\Inventory\Domain\StockMovement`. Reuse the existing `test_list_exposes_source_document_provenance` `Document::create` attributes. Seed 50 receipt rows, force each movement to the distinct explicit timestamp `2026-09-03 12:00:00 + index seconds`, and link indexes whose modulo-5 value is 1, 2, or 3 to the document. With newest-first ordering, indexes 50..46 have modulo values 0, 4, 3, 2, 1, so the newest 5 deterministically contain exactly 3 linked rows; all 50 contain 30 linked rows. This is red against the current per-row `Document` lookup and only becomes flat after Task 2’s page-level `whereIn()->get()->keyBy('id')` lookup.

~~~php
use CountsQueries;

public function test_index_query_count_is_flat_and_includes_document_linkage(): void
{
    $service = app(StockAdjustmentService::class);
    $document = Document::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'location_id' => $this->warehouse->id,
        'type' => DocumentType::Invoice,
        'fiscal_category' => FiscalCategory::TaxInvoice,
        'fiscal_status' => FiscalStatus::Draft,
        'status' => DocumentStatus::Posted,
        'document_number' => 'INV-QUERY-BUDGET',
        'document_date' => now()->toDateString(),
        'currency' => 'TND',
        'subtotal' => '50.000',
        'discount_amount' => '0.000',
        'tax_amount' => '0.000',
        'total' => '50.000',
        'balance_due' => '50.000',
    ]);
    foreach (range(1, 50) as $index) {
        $movement = $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '1.0000',
            reference: 'QB-'.$index,
            userId: $this->user->id,
        );
        $createdAt = CarbonImmutable::parse('2026-09-03 12:00:00')->addSeconds($index);
        $isLinked = in_array($index % 5, [1, 2, 3], true);
        $movement->forceFill([
            'reference_type' => $isLinked ? 'Document' : null,
            'reference_id' => $isLinked ? $document->id : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    $small = $this->countQueries(fn () => $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?page=1&per_page=5')->assertOk());
    $large = $this->countQueries(fn () => $this->actingAs($this->user)
        ->getJson('/api/v1/stock-movements?page=1&per_page=50')->assertOk());
    self::assertLessThanOrEqual($small + 2, $large, '5 rows (3 linked)='.$small.', 50 rows (30 linked)='.$large);
}
~~~

- [ ] **Step 3: Add a red production-behavior test that lazy-loads from a multi-model result, logs once, and does not throw.**

~~~php
public function test_lazy_load_logs_warning_and_relation_still_loads(): void
{
    $service = app(StockAdjustmentService::class);
    $service->receive(
        productId: $this->product->id,
        locationId: $this->warehouse->id,
        quantity: '1.0000',
        reference: 'LAZY-1',
        userId: $this->user->id,
    );
    $service->receive(
        productId: $this->product->id,
        locationId: $this->warehouse->id,
        quantity: '1.0000',
        reference: 'LAZY-2',
        userId: $this->user->id,
    );
    Log::spy();

    $movement = StockMovement::query()->orderBy('created_at')->get()->firstOrFail();
    self::assertFalse($movement->relationLoaded('product'));
    self::assertSame($this->product->name, $movement->product->name);

    Log::shouldHaveReceived('warning')->once()->with('lazy-load', [
        'model' => StockMovement::class,
        'relation' => 'product',
    ]);
}
~~~

Before the provider change it does not log and is red. Using two loaded models makes Laravel set preventsLazyLoading on the hydrated models.

- [ ] **Step 4: Register the non-production log-only handler with exact imports.**

~~~php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
    Log::warning('lazy-load', ['model' => $model::class, 'relation' => $relation]);
});
Model::preventLazyLoading(! $this->app->isProduction());
~~~

- [ ] **Step 5: Verify focused files locally, including all four identified exact-log contracts, then require the CI whole-suite leg.** Locally run only explicit paths: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php tests/Feature/Inventory/InventoryGlPostingSeamTest.php tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php tests/Feature/POS/RefundReportingFieldsTest.php tests/Unit/POS/ReceiptReturnServiceTest.php tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php tests/Feature/Compliance/AuditTrailTest.php tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php tests/Feature/Document/ListDocumentsTest.php`; run PHPStan on `app/Providers/AppServiceProvider.php` and the Task 2 stock-movement controller. The exact assertions are `InventoryGlPostingSeamTest.php:513` (no warning and no error), `TreasuryMovementServiceRecordTest.php:252` (no warning), `POS/RefundReportingFieldsTest.php:134` (no warning), and `ReceiptReturnServiceTest.php:292` (no warning); all four must stay green under the new global handler. The full backend suite is **NEVER run locally (laptop rule)**. Push the lane branch and require the CI-only whole-backend-suite invocation (`cd apps/api && ./vendor/bin/phpunit`) to validate every other log spy/mock. This CI result is a mandatory merge gate, not optional follow-up. Gate: general Opus. Production behavior is unchanged.

---

## Task 11: Shared idempotency-key hook (ID-1..ID-4 foundation)

**Files**

- Create: apps/web/src/lib/hooks/useIdempotencyKey.ts
- Create: apps/web/src/lib/hooks/useIdempotencyKey.test.tsx

- [ ] **Step 1: Add the module-not-found red test.**

~~~tsx
import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useIdempotencyKey } from './useIdempotencyKey'

it('keeps one UUID until reset', () => {
  const hook = renderHook(() => useIdempotencyKey())
  const first = hook.result.current.key
  hook.rerender()
  expect(hook.result.current.key).toBe(first)
  expect(first).toMatch(/^[0-9a-f-]{36}$/)
  act(() => { hook.result.current.reset() })
  expect(hook.result.current.key).not.toBe(first)
})
~~~

- [ ] **Step 2: Implement the stable API.**

~~~ts
import { useCallback, useState } from 'react'

export function useIdempotencyKey(): { key: string; reset: () => void } {
  const [key, setKey] = useState(() => crypto.randomUUID())
  const reset = useCallback(() => { setKey(crypto.randomUUID()) }, [])
  return { key, reset }
}
~~~

- [ ] **Step 3: Verify by path.** Run `apps/web/src/lib/hooks/useIdempotencyKey.test.tsx` by path and typecheck. The key deliberately survives failed submits; only consumers call reset after success. Gate with Task 12.

---

## Task 12: Payment surfaces send keys and synchronously block double submit (ID-1, ID-2)

**Files**

- Modify: apps/web/src/features/treasury/PaymentForm.tsx
- Modify: apps/web/src/features/treasury/SplitPaymentForm.tsx:39
- Modify: apps/web/src/features/treasury/PaymentForm.test.tsx
- Modify: apps/web/src/features/treasury/SplitPaymentForm.test.tsx:81,132
- Modify: apps/web/src/features/treasury/treasury.test.tsx:1205,1225,1247,1274,1297,1332,1378
- Modify: apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:170,213
- Modify: apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:18,65,120
- Modify: apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx
- Modify: apps/web/src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx
- Verify active RecordPaymentModal host: apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895
- Verify active RecordPaymentModal host: apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780
- Verify active RecordPaymentModal host: apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709

**Signatures:** PaymentForm takes no props and is rendered as `<PaymentForm />`. `SplitPaymentFormProps.totalAmount` is a decimal `string`; SplitPaymentForm also takes documentId, onSuccess, onCancel, and optional currency. The deprecated `SplitPaymentModalProps.totalAmount` is the same decimal `string` and passes it through unchanged. RecordPaymentModal takes isOpen, onClose, optional onSuccess, and prefill. All three active payment surfaces use the existing QueryClient/store/API mocks and the shared `useIdempotencyKey`. The grep inventory for `<SplitPaymentForm` and `<SplitPaymentModal` under `apps/web/src` is exhausted by the file:line Modify entries above; there is no active in-repository `SplitPaymentModal` caller, only its JSDoc example and its own pass-through render.

- [ ] **Step 1: Extend PaymentForm.test.tsx’s real harness.** Add act to its Testing Library import. Use mockLookups, selectMethod, CARD_METHOD, BANK_REPO, and the no-prop render:

~~~tsx
it('adds a key and a ref lock rejects a second synchronous submit', async () => {
  let resolvePost: ((value: unknown) => void) | null = null
  mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
  mockLookups([CARD_METHOD], [BANK_REPO])
  render(<PaymentForm />, { wrapper: wrapper(createClient()) })

  await selectMethod(CARD_METHOD.id)
  fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), {
    target: { value: '100' },
  })
  fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
    target: { value: BANK_REPO.id },
  })
  fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
    target: { value: 'partner-1' },
  })
  const save = screen.getByRole('button', { name: 'common:save' })
  const form = save.closest('form')
  if (form === null) throw new Error('PaymentForm submit button has no form')
  act(() => {
    fireEvent.submit(form)
    fireEvent.submit(form)
  })

  await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
  expect(mockApiPost.mock.calls[0]?.[1]).toEqual(expect.objectContaining({
    idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
    amount: '100',
    payment_method_id: CARD_METHOD.id,
    repository_id: BANK_REPO.id,
    partner_id: 'partner-1',
  }))
  await act(async () => { resolvePost?.({ id: 'payment-1' }); await Promise.resolve() })
})
~~~

- [ ] **Step 2: Convert every local split-payment caller and extend SplitPaymentForm.test.tsx with type and precision regressions.** Add `ComponentProps` to the existing React type import, add `import type { SplitPaymentModalProps } from '@/components/organisms/SplitPaymentModal'`, and add `fireEvent`, `waitFor`, and `act` to the Testing Library/Vitest imports. Change the `renderForm` default from `totalAmount={100}` to `totalAmount="100"`; change its `{ totalAmount: 100 }` overrides to `{ totalAmount: '100' }`; change all seven `<SplitPaymentForm totalAmount={1000}>` callers in `treasury.test.tsx` to `totalAmount="1000"`; and change both `TreasuryTenantScope.test.tsx` callers from `{100}` to `"100"`. Because production now passes decimal strings to the currency formatter, make the existing `useCurrency` mock accept the real formatter input without numeric coercion:

~~~tsx
format: (value: string | number) => String(value),
~~~

Update the existing exact assertion at line 147:

~~~tsx
expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
  splits: [{ payment_method_id: 'method-1', amount: '100' }],
  idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
})
~~~

Add this compile-time contract test. Each directive is deliberately consumed only while its `totalAmount` is a string; if either prop regresses to `number`, `pnpm typecheck` must fail with `Unused '@ts-expect-error' directive`:

~~~tsx
it('rejects number-typed split-payment totalAmount props at compile time', () => {
  const numericTotalAmount = 0.3
  const invalidFormProps: ComponentProps<typeof SplitPaymentForm> = {
    documentId: 'doc-1',
    // @ts-expect-error Monetary totals must enter SplitPaymentForm as decimal strings.
    totalAmount: numericTotalAmount,
    onSuccess: vi.fn(),
    onCancel: vi.fn(),
  }
  const invalidModalProps: SplitPaymentModalProps = {
    isOpen: false,
    onClose: vi.fn(),
    documentId: 'doc-1',
    // @ts-expect-error Monetary totals must enter SplitPaymentModal as decimal strings.
    totalAmount: numericTotalAmount,
    currency: 'TND',
  }

  expect(invalidFormProps.totalAmount).toBe(numericTotalAmount)
  expect(invalidModalProps.totalAmount).toBe(numericTotalAmount)
})
~~~

Add this delayed-promise double-click test using renderForm() and its real labels:

~~~tsx
it('uses the synchronous ref lock before React can rerender pending state', async () => {
  let resolvePost: ((value: { data: { ok: boolean } }) => void) | null = null
  mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
  renderForm({ totalAmount: '100' })

  await screen.findByRole('option', { name: 'Cash' })
  await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
  await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
  const submit = screen.getByRole('button', { name: 'common:actions.submit' })
  act(() => {
    fireEvent.click(submit)
    fireEvent.click(submit)
  })

  await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
  expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
    splits: [{ payment_method_id: 'method-1', amount: '100' }],
    idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
  })
  await act(async () => {
    resolvePost?.({ data: { ok: true } })
    await Promise.resolve()
  })
})
~~~

Add this concrete helper and the two three-decimal cases. The first keeps the total as the exact decimal string `"0.300"` and proves `"0.100" + "0.200"` is accepted without ever constructing the IEEE-754 result `0.30000000000000004`; the second proves a one-millime shortfall is rejected before the API call.

~~~tsx
async function fillTwoSplits(amounts: readonly [string, string]) {
  await screen.findByRole('option', { name: 'Cash' })
  const addLine = screen.getByRole('button', { name: 'treasury:splitPayment.addPayment' })
  await userEvent.click(addLine)

  const methodInputs = screen.getAllByLabelText('treasury:payments.method')
  const amountInputs = screen.getAllByLabelText('treasury:payments.amount')
  expect(methodInputs).toHaveLength(2)
  expect(amountInputs).toHaveLength(2)
  for (const [index, amount] of amounts.entries()) {
    await userEvent.selectOptions(methodInputs[index]!, 'method-1')
    await userEvent.type(amountInputs[index]!, amount)
  }
}

it('accepts 0.100 plus 0.200 against the exact decimal-string total 0.300', async () => {
  renderForm({ totalAmount: '0.300' })
  await fillTwoSplits(['0.100', '0.200'])

  await userEvent.click(screen.getByRole('button', { name: 'common:actions.submit' }))

  await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
  expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
    splits: [
      { payment_method_id: 'method-1', amount: '0.100' },
      { payment_method_id: 'method-1', amount: '0.200' },
    ],
    idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
  })
})

it('rejects a three-decimal split total that is short by 0.001', async () => {
  renderForm({ totalAmount: '0.300' })
  await fillTwoSplits(['0.100', '0.199'])

  await userEvent.click(screen.getByRole('button', { name: 'common:actions.submit' }))

  expect(screen.getByText('treasury:splitPayment.amountDoesNotMatch')).toBeInTheDocument()
  expect(mockApiPost).not.toHaveBeenCalled()
})
~~~

- [ ] **Step 3: Implement PaymentForm.** It already imports `useRef`. Add `useIdempotencyKey`, keep the key across failures, and enforce the invariant before `mutate()` can trigger a React rerender:

~~~tsx
import { useIdempotencyKey } from '@/lib/hooks/useIdempotencyKey'

const { key: idempotencyKey, reset: resetIdempotencyKey } = useIdempotencyKey()
const submitLockRef = useRef<boolean>(false)

// Replace the existing mutationFn return object; allocations and notes are
// the existing local values computed immediately above this return.
return apiPost<Payment>('/payments', {
  idempotency_key: idempotencyKey,
  amount: data.amount,
  payment_method_id: data.payment_method_id,
  repository_id: data.repository_id,
  partner_id: data.partner_id,
  payment_date: data.payment_date,
  reference: data.reference,
  notes,
  ...(selectedMethod?.has_maturity && {
    instrument: {
      reference: data.instrument_number,
      maturity_date: data.maturity_date || undefined,
      drawer_name: data.drawer_name || undefined,
      bank_id: data.bank_id || undefined,
      bank_name: data.bank_name || undefined,
      bank_branch: data.bank_branch || undefined,
      bank_account: data.bank_account || undefined,
    },
  }),
  allocations: allocations.length > 0 ? allocations : undefined,
  withholding_enabled: withholdingEnabled,
  withholding_rate: withholdingEnabled && withholdingRate ? withholdingRate : undefined,
  withholding_transaction_type:
    withholdingEnabled && withholdingTransactionType ? withholdingTransactionType : undefined,
  withholding_override_reason: data.withholding_override_reason,
})

const onSubmit = (data: PaymentFormData): void => {
  if (submitLockRef.current) return
  submitLockRef.current = true
  createMutation.mutate(data, {
    onSettled: () => { submitLockRef.current = false },
  })
}
~~~

Add `resetIdempotencyKey()` as the first statement of the existing async `onSuccess`, before its `Promise.all` invalidations. Change the submit button to `disabled={isSubmitting || createMutation.isPending}` and use the same combined condition for its loading label: `(isSubmitting || createMutation.isPending) ? t('common:saving') : t('common:save')`.

- [ ] **Step 4: Implement SplitPaymentForm and its deprecated wrapper with a string-only money boundary.** In `SplitPaymentForm.tsx`, change `SplitPaymentFormProps.totalAmount` from `number` to `string`, change the React import to `useRef, useState`, import `bcadd`, `bccomp`, and `bcsub` from `@/lib/decimal`, add `useIdempotencyKey`, and replace both float-derived totals plus the handler with the exact code below. In `SplitPaymentModal.tsx`, change `SplitPaymentModalProps.totalAmount` from `number` to `string`, replace the JSDoc example’s `totalAmount={parseFloat(document.balance_due)}` with `totalAmount={document.balance_due}`, and keep `totalAmount={totalAmount}` as an unchanged string pass-through to SplitPaymentForm. No `parseFloat`, `Number`, arithmetic operator, `Math.abs`, or numeric accumulator may touch a payment amount in the rewritten SplitPaymentForm path. Keep `submitMutation.isPending` on the button.

~~~tsx
import { bcadd, bccomp, bcsub } from '@/lib/decimal'

const { key: idempotencyKey, reset: resetIdempotencyKey } = useIdempotencyKey()
const submitLockRef = useRef<boolean>(false)

const currentTotal = paymentLines.reduce(
  (sum, line) => bcadd(sum, line.amount === '' ? '0' : line.amount, 3),
  '0.000',
)
const remaining = bcsub(totalAmount, currentTotal, 3)

const submitMutation = useMutation({
  mutationFn: async (
    splits: Array<{
      payment_method_id: string
      amount: string
      repository_id?: string
      reference?: string
    }>,
  ) => api.post('/documents/'+documentId+'/split-payment', {
    splits,
    idempotency_key: idempotencyKey,
  }),
  onSuccess: () => {
    resetIdempotencyKey()
    onSuccess()
  },
})

const handleSubmit = (): void => {
  if (submitLockRef.current) return
  if (bccomp(currentTotal, totalAmount) !== 0) {
    setValidationError(t('treasury:splitPayment.amountDoesNotMatch'))
    return
  }
  const invalidLines = paymentLines.filter(
    (line) => !line.payment_method_id || line.amount === '' || bccomp(line.amount, '0') <= 0,
  )
  if (invalidLines.length > 0) {
    setValidationError(t('treasury:splitPayment.incompleteLines'))
    return
  }
  const splits = paymentLines.map((line) => ({
    payment_method_id: line.payment_method_id,
    amount: line.amount,
    ...(line.repository_id ? { repository_id: line.repository_id } : {}),
    ...(line.reference ? { reference: line.reference } : {}),
  }))
  submitLockRef.current = true
  submitMutation.mutate(splits, {
    onSettled: () => { submitLockRef.current = false },
  })
}
~~~

Delete the old numeric `currentTotal`/`remaining` declarations; do not introduce `String(totalAmount)` or `Number(...)`. Change the local formatter to `(amount: string): string => formatCurrencyHook(amount)`, render `formatCurrency(totalAmount)` for the required total, and drive the remaining color only with `bccomp(remaining, '0') === 0` / `bccomp(remaining, '0') > 0`. `totalAmount` must flow directly into `bcsub` and `bccomp` as shown, preserving all caller-provided decimal digits.
- [ ] **Step 5: Add the same red proof to active RecordPaymentModal.** In `RecordPaymentModal.tsx`, add `useRef`, `useIdempotencyKey`, `submitLockRef`, and `idempotency_key: idempotencyKey` to the existing `/payments` body. Set the lock after validation and before `mutation.mutate(undefined, { onSettled: () => { submitLockRef.current = false } })`; call `resetIdempotencyKey()` first in the existing `onSuccess`, never on error. This idempotency edit does not authorize refactoring the modal’s pre-existing float-based total/remaining/validation block around line 210; that precision debt is explicitly tracked in Phase B B-7. In its existing `__tests__/tenantScope.test.tsx` harness, add `fireEvent`, hold `mockApiPost` unresolved, populate and confirm one line with the existing labels, then invoke both `fireEvent.click(recordButton)` calls inside **one** `act()` and assert exactly one `/payments` call whose body contains the UUID key before resolving the request:

~~~tsx
it('adds a key and synchronously locks duplicate payment recording', async () => {
  let resolvePost: (value: unknown) => void = () => {}
  mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
  render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />, {
    wrapper: wrapper(createClient()),
  })

  await screen.findByRole('option', { name: 'Cash' })
  await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
  await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), '100')
  await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
  await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
  const record = screen.getByRole('button', { name: /treasury:payments.record/ })

  act(() => {
    fireEvent.click(record)
    fireEvent.click(record)
  })

  await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
  expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
    idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
  }))
  await act(async () => {
    resolvePost({
      payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '100.00' }],
      document: { id: 'doc-1', document_number: 'INV-1', balance_due: '0.00', status: 'paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })
    await Promise.resolve()
  })
})
~~~

- [ ] **Step 6: Verify by path, type boundary, caller inventory, and gate.** Run `apps/web/src/lib/hooks/useIdempotencyKey.test.tsx`, `apps/web/src/features/treasury/PaymentForm.test.tsx`, `apps/web/src/features/treasury/SplitPaymentForm.test.tsx`, `apps/web/src/features/treasury/treasury.test.tsx`, `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx`, and `apps/web/src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` by path, then run `pnpm typecheck` and lint from `apps/web`. Expected typecheck result: PASS with both numeric-prop `@ts-expect-error` directives consumed; changing either split-payment `totalAmount` prop back to `number` must make typecheck FAIL with an unused-directive diagnostic. Re-run `rg -n '<SplitPayment(Form|Modal)' apps/web/src` and reconcile every hit with the file:line Modify inventory above. Run `rg -n 'parseFloat|Math\\.abs' apps/web/src/features/treasury/SplitPaymentForm.tsx` and require no matches. Run `rg -n 'totalAmount\\s*:\\s*number|Number\\(' apps/web/src/features/treasury/PaymentForm.tsx apps/web/src/features/treasury/SplitPaymentForm.tsx apps/web/src/features/treasury/PaymentForm.test.tsx apps/web/src/features/treasury/SplitPaymentForm.test.tsx apps/web/src/features/treasury/treasury.test.tsx apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx apps/web/src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` and require no matches across every Task 12 Modify file. Run throttled-network browser double-submit probes on all three active surfaces; prove the `"0.300"` total accepts `"0.100" + "0.200"` and rejects `"0.100" + "0.199"`. Open payments from InvoiceDetailPage, SalesOrderDetailPage, and PurchaseOrderDetailPage to verify the active RecordPaymentModal key lifecycle remains intact. Gate: treasury-reviewer plus frontend-conventions-reviewer.

---

## Task 13: Transfer/adjustment keys and transfer collision replay (ID-3, ID-4)

**Files**

- Modify: apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php
- Modify: apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
- Create: apps/api/tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php
- Modify: apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx
- Modify: apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx
- Modify: apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx
- Modify: apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx
- Verify consumer (no edit): apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php
- Verify consumer test: apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php

**Exact names:** backend DTO is InitiateTransferData. Existing database index is stock_transfers_idempotency_unique; stock_transfers_company_number_unique is the required different-index rethrow case. CreateStockTransferInput and CreateStockAdjustmentInput already contain idempotency_key, so no DTO/type generation change is allowed.

- [ ] **Step 1: Refactor the service behind two protected test seams and catch only after rollback.**

~~~php
use Illuminate\Database\UniqueConstraintViolationException;

private const IDEMPOTENCY_CONSTRAINT = 'stock_transfers_idempotency_unique';

public function initiate(InitiateTransferData $data): StockTransfer
{
    if (count($data->lines) === 0) {
        throw new InvalidArgumentException('Transfer must have at least one line.');
    }
    if ($data->sourceLocationId === $data->destinationLocationId) {
        throw new InvalidArgumentException('Source and destination must be different.');
    }
    if ($data->transferType === TransferType::Intercompany) {
        throw new InvalidArgumentException(
            'Inter-company transfers are not yet supported. Use Intracompany.'
        );
    }

    try {
        return DB::transaction(function () use ($data): StockTransfer {
            if ($data->idempotencyKey !== null) {
                $existing = $this->findExistingTransfer($data);
                if ($existing !== null) {
                    return $existing->loadMissing('lines');
                }
            }

            $source = $this->loadLocationOrFail($data->sourceLocationId, $data->companyId, 'source');
            $destination = $this->loadLocationOrFail($data->destinationLocationId, $data->companyId, 'destination');
            if ($source->company_id !== $destination->company_id) {
                throw new InvalidArgumentException('Source and destination locations must belong to the same company.');
            }
            $productIds = $this->collectProductIds($data->lines);
            $this->loadAndVerifyProducts($productIds, $data->tenantId, $data->companyId);
            $lines = $data->autoAllocateBatchesFefo
                ? $this->resolveFefoAllocations($data)
                : $data->lines;
            $transferNumber = $data->transferNumber
                ?? $this->generateTransferNumber($data->tenantId, $data->companyId);

            $transfer = $this->insertTransfer($data, $transferNumber);

            foreach ($lines as $line) {
                if (bccomp($line->quantity, '0', self::QTY_SCALE) <= 0) {
                    throw new InvalidArgumentException('Each transfer line must have quantity greater than zero.');
                }
                $this->assertVariantValidForProduct($line->productId, $line->variantId);
                $transferLine = StockTransferLine::create([
                    'id' => Str::uuid()->toString(),
                    'transfer_id' => $transfer->id,
                    'tenant_id' => $data->tenantId,
                    'company_id' => $data->companyId,
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'quantity' => $line->quantity,
                ]);
                foreach ($line->batchAllocations as $allocation) {
                    StockTransferLineBatchAllocation::create([
                        'id' => Str::uuid()->toString(),
                        'stock_transfer_line_id' => $transferLine->id,
                        'tenant_id' => $data->tenantId,
                        'company_id' => $data->companyId,
                        'batch_id' => $allocation->batchId,
                        'quantity' => $allocation->quantity,
                    ]);
                }
            }

            return $this->moveSourceToInTransit($transfer->id, $data->initiatedByUserId);
        }, attempts: 3);
    } catch (UniqueConstraintViolationException $exception) {
        if (
            $data->idempotencyKey === null
            || ! str_contains($exception->getMessage(), self::IDEMPOTENCY_CONSTRAINT)
        ) {
            throw $exception;
        }
        $existing = $this->findExistingTransfer($data);
        if ($existing === null) {
            throw $exception;
        }
        return $existing->loadMissing('lines');
    }
}

protected function findExistingTransfer(InitiateTransferData $data): ?StockTransfer
{
    return StockTransfer::query()
        ->where('tenant_id', $data->tenantId)
        ->where('company_id', $data->companyId)
        ->where('idempotency_key', $data->idempotencyKey)
        ->first();
}

protected function insertTransfer(InitiateTransferData $data, string $transferNumber): StockTransfer
{
    return StockTransfer::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $data->tenantId,
        'company_id' => $data->companyId,
        'transfer_number' => $transferNumber,
        'transfer_type' => $data->transferType,
        'status' => TransferStatus::Draft,
        'source_location_id' => $data->sourceLocationId,
        'destination_location_id' => $data->destinationLocationId,
        'notes' => $data->notes,
        'transfer_cost' => $data->transferCost,
        'transfer_cost_label' => $data->transferCostLabel,
        'transfer_cost_distribution' => $data->transferCostDistribution,
        'idempotency_key' => $data->idempotencyKey,
        'initiated_by_user_id' => $data->initiatedByUserId,
    ]);
}
~~~

The extraction preserves the existing transfer/line/batch attributes verbatim; only the two protected calls and the outer catch are new.

- [ ] **Step 2: Add the PostgreSQL collision harness that is red against current code at both transaction depths.** This test class must not use `RefreshDatabase`: the winner connection must see committed fixture rows, and its committed winner must survive rollback of the losing transaction or savepoint. Mark both methods `#[Group('pg')]`, clone the private session database’s default connection, and clean every owned row. The instrumented subclass overrides the new protected seams, not `initiate()`.

~~~php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class StockTransferIdempotencyCollisionPostgresTest extends TestCase
{
    private const WRITER = 'stock_transfer_collision_writer';

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Location $source;
    private Location $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The real unique-collision harness is PostgreSQL-only.');
        }

        $suffix = Str::lower(Str::random(10));
        $this->tenant = Tenant::create([
            'name' => 'Collision '.$suffix,
            'slug' => 'collision-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Collision Company '.$suffix,
            'legal_name' => 'Collision Company '.$suffix,
            'tax_id' => 'COL-'.$suffix,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'en',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Collision User',
            'email' => 'collision-'.$suffix.'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->source = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SRC-'.$suffix,
            'name' => 'Collision Source',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->destination = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DST-'.$suffix,
            'name' => 'Collision Destination',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'COL-'.$suffix,
            'name' => 'Collision Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->source->id,
            quantity: '50.0000',
            reference: 'COLLISION-SEED',
            userId: $this->user->id,
        );
    }

    protected function tearDown(): void
    {
        DB::purge(self::WRITER);
        config(['database.connections.'.self::WRITER => null]);
        if (! isset($this->tenant)) {
            parent::tearDown();

            return;
        }
        DB::table('stock_transfer_line_batch_allocations')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_transfer_lines')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_transfers')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_levels')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('products')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('locations')->where('company_id', $this->company->id)->delete();
        DB::table('users')->where('id', $this->user->id)->delete();
        DB::table('companies')->where('id', $this->company->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        parent::tearDown();
    }

    private function writer(): Connection
    {
        $default = (string) config('database.default');
        config(['database.connections.'.self::WRITER => config('database.connections.'.$default)]);
        DB::purge(self::WRITER);

        return DB::connection(self::WRITER);
    }

    #[Group('pg')]
    public function test_initiate_rereads_committed_winner_after_real_insert_collision(): void
    {
        $data = new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->source->id,
            destinationLocationId: $this->destination->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->product->id, '5.0000')],
            transferNumber: 'TR-COLLISION-LOSER',
            idempotencyKey: 'race-key-'.$this->tenant->id,
        );
        $service = new InsertCollidingStockTransferService(
            app(StockAdjustmentService::class),
            app(WeightedAverageCostService::class),
            app(ProductCostLock::class),
            app(ProductVariantLookup::class),
            $this->writer(),
        );

        $winner = $service->initiate($data);

        self::assertSame($service->winnerId, $winner->id);
        self::assertSame(1, $service->insertCalls);
        self::assertSame(2, $service->findCalls);
        self::assertSame(0, $service->secondLookupTransactionLevel);
        self::assertSame(1, StockTransfer::query()
            ->where('tenant_id', $data->tenantId)
            ->where('company_id', $data->companyId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->count());
    }

    #[Group('pg')]
    public function test_initiate_inside_outer_transaction_rereads_after_savepoint_rollback(): void
    {
        $data = new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->source->id,
            destinationLocationId: $this->destination->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->product->id, '5.0000')],
            transferNumber: 'TR-COLLISION-NESTED-LOSER',
            idempotencyKey: 'nested-race-key-'.$this->tenant->id,
        );
        $service = new InsertCollidingStockTransferService(
            app(StockAdjustmentService::class),
            app(WeightedAverageCostService::class),
            app(ProductCostLock::class),
            app(ProductVariantLookup::class),
            $this->writer(),
        );

        $winner = DB::transaction(function () use ($data, $service): StockTransfer {
            self::assertSame(1, DB::transactionLevel());
            $result = $service->initiate($data);
            self::assertSame(1, DB::transactionLevel());

            return $result;
        });

        self::assertSame($service->winnerId, $winner->id);
        self::assertSame(1, $service->insertCalls);
        self::assertSame(2, $service->findCalls);
        self::assertSame(1, $service->secondLookupTransactionLevel);
        self::assertSame(0, DB::transactionLevel());
        self::assertSame(1, StockTransfer::query()
            ->where('tenant_id', $data->tenantId)
            ->where('company_id', $data->companyId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->count());
    }
}

final class InsertCollidingStockTransferService extends StockTransferService
{
    public int $findCalls = 0;
    public int $insertCalls = 0;
    public ?int $secondLookupTransactionLevel = null;
    public string $winnerId = '';

    public function __construct(
        StockAdjustmentService $stockAdjustmentService,
        WeightedAverageCostService $wacService,
        ProductCostLock $costLock,
        ProductVariantLookup $variantLookup,
        private readonly Connection $writer,
    ) {
        parent::__construct($stockAdjustmentService, $wacService, $costLock, $variantLookup);
    }

    protected function findExistingTransfer(InitiateTransferData $data): ?StockTransfer
    {
        $this->findCalls++;
        if ($this->findCalls === 1) {
            return null;
        }
        $this->secondLookupTransactionLevel = DB::transactionLevel();

        return parent::findExistingTransfer($data);
    }

    protected function insertTransfer(InitiateTransferData $data, string $transferNumber): StockTransfer
    {
        $this->insertCalls++;
        $this->winnerId = Str::uuid()->toString();
        $this->writer->table('stock_transfers')->insert([
            'id' => $this->winnerId,
            'tenant_id' => $data->tenantId,
            'company_id' => $data->companyId,
            'transfer_number' => $transferNumber.'-WINNER',
            'transfer_type' => $data->transferType->value,
            'status' => TransferStatus::Draft->value,
            'source_location_id' => $data->sourceLocationId,
            'destination_location_id' => $data->destinationLocationId,
            'notes' => $data->notes,
            'transfer_cost' => $data->transferCost,
            'transfer_cost_label' => $data->transferCostLabel,
            'transfer_cost_distribution' => $data->transferCostDistribution->value,
            'idempotency_key' => $data->idempotencyKey,
            'initiated_by_user_id' => $data->initiatedByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return parent::insertTransfer($data, $transferNumber);
    }
}
~~~

The first override call deliberately returns `null`, simulating the pre-check miss. The second calls the real lookup and records the transaction level after rollback. The top-level case requires level 0; the outer-transaction case requires level 1, proving the failed inner `DB::transaction()` rolled back its savepoint without discarding the caller's transaction. The insert override commits the winner immediately before the parent’s real insert hits `stock_transfers_idempotency_unique`. Before seam extraction, this file parses but its assertions fail because production never calls the seams. After seam extraction it reaches a real failing INSERT, so it cannot pass by faking an exception or pre-seeding the winner.

- [ ] **Step 3: Keep both rethrow cases in InventoryTransferServiceTest.** Add the service dependencies, `UniqueConstraintViolationException`, and `PDOException` imports used below. Each test calls `initiate()`; neither may be replaced by testing a helper directly.

~~~php
private function uniqueViolation(string $constraint): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException(
        'testing',
        'insert into stock_transfers',
        [],
        new PDOException('duplicate key value violates unique constraint "'.$constraint.'"'),
    );
}

private function throwingService(
    UniqueConstraintViolationException $collision,
): ThrowingStockTransferService {
    return new ThrowingStockTransferService(
        app(StockAdjustmentService::class),
        app(WeightedAverageCostService::class),
        app(ProductCostLock::class),
        app(ProductVariantLookup::class),
        $collision,
    );
}

public function test_initiate_rethrows_unique_violation_when_no_key_was_supplied(): void
{
    $this->seedStock($this->productA, $this->warehouse, '50.0000');
    $collision = $this->uniqueViolation('stock_transfers_idempotency_unique');

    $this->expectExceptionObject($collision);
    $this->throwingService($collision)->initiate($this->initiateData(
        $this->warehouse->id,
        $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
    ));
}

public function test_initiate_rethrows_collision_on_different_unique_index(): void
{
    $this->seedStock($this->productA, $this->warehouse, '50.0000');
    $collision = $this->uniqueViolation('stock_transfers_company_number_unique');

    $this->expectExceptionObject($collision);
    $this->throwingService($collision)->initiate($this->initiateData(
        $this->warehouse->id,
        $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
        idempotencyKey: 'different-index',
    ));
}

final class ThrowingStockTransferService extends StockTransferService
{
    public function __construct(
        StockAdjustmentService $stockAdjustmentService,
        WeightedAverageCostService $wacService,
        ProductCostLock $costLock,
        ProductVariantLookup $variantLookup,
        private readonly UniqueConstraintViolationException $collision,
    ) {
        parent::__construct($stockAdjustmentService, $wacService, $costLock, $variantLookup);
    }

    protected function findExistingTransfer(InitiateTransferData $data): ?StockTransfer
    {
        return null;
    }

    protected function insertTransfer(InitiateTransferData $data, string $transferNumber): StockTransfer
    {
        throw $this->collision;
    }
}
~~~

- [ ] **Step 4: Add keys at page scope, not mutation-hook scope.** Both pages import useIdempotencyKey. Add idempotency_key to their existing typed payloads. Immediately after the awaited mutateAsync resolves, call resetIdempotencyKey(), then toast/navigate. Failed calls do not reset.

~~~tsx
const { key: idempotencyKey, reset: resetIdempotencyKey } = useIdempotencyKey()

const payload: CreateStockTransferInput = {
  idempotency_key: idempotencyKey,
  source_location_id: sourceLocationId,
  destination_location_id: destinationLocationId,
  notes: notes.trim() === '' ? null : notes.trim(),
  transfer_cost: transferCost.trim() === '' ? '0' : transferCost.trim(),
  transfer_cost_label: transferCostLabel.trim() === '' ? null : transferCostLabel.trim(),
  transfer_cost_distribution: distribution,
  lines: cleanLines.map((line) => ({
    product_id: line.product.id,
    ...(line.variantId !== null ? { variant_id: line.variantId } : {}),
    quantity: line.quantity,
    ...(line.batchAllocations.length > 0
      ? {
          batch_allocations: line.batchAllocations.map((allocation) => ({
            batch_id: allocation.batch_id,
            quantity: allocation.quantity,
          })),
        }
      : {}),
  })),
}
const result = await createMutation.mutateAsync(payload)
resetIdempotencyKey()

const created = await createMutation.mutateAsync({
  idempotency_key: idempotencyKey,
  location_id: values.locationId,
  note: values.note === '' ? null : values.note,
  post_immediately: postImmediately,
  ...(acknowledgeCode !== null ? overrideFlagFor(acknowledgeCode) : {}),
  lines: values.lines.map((line) => ({
    product_id: line.productId,
    batch_uuid: line.batchUuid === '' ? null : line.batchUuid,
    reason_code: line.reason,
    delta_quantity: signedDelta(line),
    observed_before: line.observedBefore,
    line_note: line.note === '' ? null : line.note,
  })),
})
resetIdempotencyKey()
~~~

features/stock-transfers/api/queries.ts and features/stock-adjustments/api/queries.ts stay untouched. QuickStockAdjustmentModal shares useCreateStockAdjustment and is explicitly untouched.

- [ ] **Step 5: Use the actual feature tests.** In CreateStockTransferPage.lineEntry.test.tsx add the hoisted reset and module mock:

~~~tsx
const mockResetIdempotencyKey = vi.hoisted(() => vi.fn())

vi.mock('@/lib/hooks/useIdempotencyKey', () => ({
  useIdempotencyKey: () => ({
    key: 'transfer-key',
    reset: mockResetIdempotencyKey,
  }),
}))
~~~

Update “submits the complete header and line payload” so the exact object begins with idempotency_key: 'transfer-key'. After its existing mockCreate expectation add:

~~~tsx
await waitFor(() => {
  expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
})
~~~

In stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx add a separate hoisted reset and mock returning adjustment-key. Extend the existing payload type with idempotency_key: string, then assert:

~~~tsx
expect(payload.idempotency_key).toBe('adjustment-key')
await waitFor(() => {
  expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
})
~~~

The adjustment test does **not** currently call `vi.clearAllMocks()`. Add it and explicitly reset the new spy so test order cannot leak calls:

~~~tsx
beforeEach(() => {
  vi.clearAllMocks()
  mockResetIdempotencyKey.mockReset()
  createMutate.mockReset()
  createMutate.mockResolvedValue({ id: 'adj-1' })
  stockLevel.mockReset()
  stockLevel.mockResolvedValue(freshLevel)
  navigate.mockReset()
  grantedPermissions.clear()
  grantedPermissions.add('inventory.adjustments.post')
})
~~~

- [ ] **Step 6: Verify the PG collision, replenishment consumer, and all named paths, then gate.** Run both top-level and nested-transaction collision methods together and serially with `DB_DATABASE=autoerp_test_<letter> DB_CENTRAL_DATABASE=autoerp_test_<letter> php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php`. `ReplenishmentFulfillmentService.php:102` calls the refactored `StockTransferService::initiate()` inside its grouped fulfillment transaction, so run its coverage explicitly. Backend paths: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php tests/Feature/Inventory/StockTransferLocationScopeTest.php tests/Feature/Inventory/StockTransferRestrictedMembershipTest.php tests/Feature/Inventory/StockTransferShowBatchAllocationsTest.php tests/Feature/Inventory/StockTransferVariantTest.php tests/Feature/Replenishment/ReplenishmentActionsTest.php`; run PHPStan on the service, new PG test, and replenishment service. Frontend paths: `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx`, `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx`, and `apps/web/src/features/stock-transfers/__tests__/queries.test.tsx`; then typecheck and lint. Browser double-click both forms. Gate: inventory-costing-reviewer plus frontend-conventions-reviewer.

---

## Task 14: Strictly serialized draft autosave (ID-12)

**Files**

- Modify: apps/web/src/hooks/useDraftAutoSave.ts
- Modify: apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx

**Exact API:** useDraftAutoSave(data, config); DraftData uses type, not document_type. The only consumer remains DocumentForm.tsx and is not modified.

- [ ] **Step 1: Add seven tests to the existing `api.post` Axios-shaped harness.** Add `import { StrictMode, type ReactNode } from 'react'`; use the existing draft fixture, `renderHook`, `act`, the suite’s fake timers, and `apiPost` mock.

~~~tsx
it('strictly serializes three callers and forwards the first draft id', async () => {
  let resolveFirst: ((value: {
    data: { draft_id: string | null; saved_at: string }
  }) => void) = () => {}
  apiPost
    .mockImplementationOnce(() => new Promise<{
      data: { draft_id: string | null; saved_at: string }
    }>((resolve) => { resolveFirst = resolve }))
    .mockResolvedValueOnce({ data: { draft_id: 'd1', saved_at: new Date(1).toISOString() } })
    .mockResolvedValueOnce({ data: { draft_id: 'd1', saved_at: new Date(2).toISOString() } })
  const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))

  const saves = [result.current.saveNow(), result.current.saveNow(), result.current.saveNow()]
  await act(() => Promise.resolve())
  expect(apiPost).toHaveBeenCalledTimes(1)
  expect(apiPost.mock.calls[0]?.[1]).toEqual(expect.objectContaining({ draft_id: null, type: 'invoice' }))

  await act(async () => {
    resolveFirst({ data: { draft_id: 'd1', saved_at: new Date(0).toISOString() } })
    await Promise.all(saves)
  })
  expect(apiPost).toHaveBeenCalledTimes(3)
  expect(apiPost.mock.calls[1]?.[1]).toEqual(expect.objectContaining({ draft_id: 'd1' }))
  expect(apiPost.mock.calls[2]?.[1]).toEqual(expect.objectContaining({ draft_id: 'd1' }))
})

it('continues the promise tail after a failed save', async () => {
  let rejectFirst: (reason: Error) => void = () => {}
  apiPost
    .mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject }))
    .mockResolvedValueOnce({ data: { draft_id: 'd2', saved_at: new Date(0).toISOString() } })
  const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))

  const first = result.current.saveNow()
  const second = result.current.saveNow()
  await act(() => Promise.resolve())
  expect(apiPost).toHaveBeenCalledTimes(1)

  await act(async () => {
    rejectFirst(new Error('first failed'))
    await Promise.all([first, second])
  })
  expect(apiPost).toHaveBeenCalledTimes(2)
  expect(result.current.draftId).toBe('d2')
})

it('runs the next save even when the onError callback throws', async () => {
  let rejectFirst: (reason: Error) => void = () => {}
  const onError = vi.fn(() => { throw new Error('onError failed') })
  apiPost
    .mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject }))
    .mockResolvedValueOnce({
      data: { draft_id: 'after-callback-error', saved_at: new Date(0).toISOString() },
    })
  const { result } = renderHook(() => useDraftAutoSave(draft, {
    debounceMs: 100_000,
    onError,
  }))

  const first = result.current.saveNow()
  const second = result.current.saveNow()
  await act(() => Promise.resolve())
  expect(apiPost).toHaveBeenCalledTimes(1)

  await act(async () => {
    rejectFirst(new Error('request failed'))
    await expect(first).rejects.toThrow('onError failed')
    await second
  })
  expect(onError).toHaveBeenCalledTimes(1)
  expect(apiPost).toHaveBeenCalledTimes(2)
  expect(result.current.draftId).toBe('after-callback-error')
})

it('still saves after StrictMode replays mount cleanup and setup', async () => {
  apiPost.mockResolvedValueOnce({
    data: { draft_id: 'strict-draft', saved_at: new Date(0).toISOString() },
  })
  const StrictWrapper = ({ children }: { children: ReactNode }) => (
    <StrictMode>{children}</StrictMode>
  )
  const { result } = renderHook(
    () => useDraftAutoSave(draft, { debounceMs: 100_000 }),
    { wrapper: StrictWrapper },
  )

  await act(async () => { await result.current.saveNow() })

  expect(apiPost).toHaveBeenCalledTimes(1)
  expect(result.current.draftId).toBe('strict-draft')
})

it('reset clears the synchronous id and cancels queued work', async () => {
  let resolveFirst: ((value: {
    data: { draft_id: string | null; saved_at: string }
  }) => void) = () => {}
  apiPost.mockImplementationOnce(() => new Promise<{
    data: { draft_id: string | null; saved_at: string }
  }>((resolve) => { resolveFirst = resolve }))
  const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))
  const first = result.current.saveNow()
  const queued = result.current.saveNow()
  await act(() => Promise.resolve())
  expect(apiPost).toHaveBeenCalledTimes(1)
  act(() => { result.current.reset() })
  await act(async () => {
    resolveFirst({ data: { draft_id: 'ignored', saved_at: new Date(0).toISOString() } })
    await Promise.all([first, queued])
  })
  expect(apiPost).toHaveBeenCalledTimes(1)
  expect(result.current.draftId).toBeNull()
})

it('unmount prevents queued network work after the current save settles', async () => {
  let resolveFirst: ((value: {
    data: { draft_id: string | null; saved_at: string }
  }) => void) = () => {}
  apiPost.mockImplementationOnce(() => new Promise<{
    data: { draft_id: string | null; saved_at: string }
  }>((resolve) => { resolveFirst = resolve }))
  const hook = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))
  const first = hook.result.current.saveNow()
  const queued = hook.result.current.saveNow()
  await act(() => Promise.resolve())
  expect(apiPost).toHaveBeenCalledTimes(1)
  hook.unmount()
  resolveFirst({ data: { draft_id: 'ignored', saved_at: new Date(0).toISOString() } })
  await Promise.all([first, queued])
  expect(apiPost).toHaveBeenCalledTimes(1)
})

it('coalesces rapid data changes into one request carrying the final data', async () => {
  apiPost.mockResolvedValue({
    data: { draft_id: 'debounced-draft', saved_at: new Date(0).toISOString() },
  })
  const hook = renderHook(
    ({ data }) => useDraftAutoSave(data, { debounceMs: 250 }),
    { initialProps: { data: { ...draft, notes: 'first' } } },
  )

  act(() => { vi.advanceTimersByTime(100) })
  hook.rerender({ data: { ...draft, notes: 'second' } })
  act(() => { vi.advanceTimersByTime(100) })
  hook.rerender({ data: { ...draft, notes: 'final' } })
  act(() => { vi.advanceTimersByTime(249) })
  expect(apiPost).not.toHaveBeenCalled()

  await act(async () => {
    vi.advanceTimersByTime(1)
    await Promise.resolve()
  })
  expect(apiPost).toHaveBeenCalledTimes(1)
  expect(apiPost).toHaveBeenCalledWith('/documents/auto-save', expect.objectContaining({
    draft_id: null,
    notes: 'final',
    type: 'invoice',
  }))
})
~~~

- [ ] **Step 2: Implement a promise-tail mutex with identity-safe cleanup.** Existing imports already include useRef.

~~~ts
const draftIdRef = useRef<string | null>(null)
const tailRef = useRef<Promise<void>>(Promise.resolve())
const generationRef = useRef(0)

const performSave = useCallback((): Promise<void> => {
  if (!data || !enabled || isUnmountedRef.current) return Promise.resolve()
  const generation = generationRef.current
  let scheduled: Promise<void>

  const run = async (): Promise<void> => {
    // This executes only after the previous tail settles: re-check lifecycle.
    if (isUnmountedRef.current || generation !== generationRef.current) return
    setIsSaving(true)
    try {
      const { data: body } = await api.post<{ draft_id: string | null; saved_at: string }>(
        '/documents/auto-save',
        { draft_id: existingDraftId || draftIdRef.current, ...data },
      )
      if (isUnmountedRef.current || generation !== generationRef.current) return
      draftIdRef.current = body.draft_id
      setDraftId(body.draft_id)
      setAutosavePending(false)
      setAutosaveFailed(false)
      setLastError(null)
      if (body.draft_id !== null) {
        setLastSavedAt(new Date(body.saved_at))
        onSuccess?.(body.draft_id)
      }
    } catch (error) {
      if (isUnmountedRef.current || generation !== generationRef.current) return
      const surfacedError = new Error(getErrorMessage(error))
      setAutosaveFailed(true)
      setLastError(surfacedError)
      setAutosavePending(false)
      onError?.(surfacedError)
      console.error('Auto-save failed:', error)
    } finally {
      if (
        !isUnmountedRef.current
        && generation === generationRef.current
        && tailRef.current === scheduled
      ) {
        setIsSaving(false)
      }
    }
  }

  tailRef.current = tailRef.current.catch(() => undefined).then(run)
  scheduled = tailRef.current
  return scheduled.finally(() => {
    if (tailRef.current === scheduled) {
      tailRef.current = Promise.resolve()
    }
  })
}, [data, enabled, existingDraftId, onError, onSuccess])
~~~

Every new job chains from a swallowed predecessor tail. The caller whose `onError` throws still receives that rejection, but the stored tail used to schedule later work is recoverable, so callback behavior cannot poison the queue.

- [ ] **Step 3: Make reset and the mount lifecycle cancel generations without breaking serialization.**

~~~ts
const reset = useCallback(() => {
  generationRef.current += 1
  draftIdRef.current = null
  setDraftId(null)
  setLastSavedAt(null)
  setIsSaving(false)
  setAutosavePending(false)
  setAutosaveFailed(false)
  setLastError(null)
  if (debounceTimerRef.current) {
    clearTimeout(debounceTimerRef.current)
    debounceTimerRef.current = null
  }
}, [])

useEffect(() => {
  // Required for React StrictMode's development setup -> cleanup -> setup replay.
  isUnmountedRef.current = false

  return () => {
    isUnmountedRef.current = true
    generationRef.current += 1
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current)
    }
  }
}, [])
~~~

Do not replace tailRef on reset: new work must still queue behind the physical in-flight request. The generation increment makes already queued work no-op and makes the in-flight response unable to repopulate state/ref.

- [ ] **Step 4: Verify by path and gate.** Run `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` by path, then expand every other existing hook test from `rg --files apps/web/src/hooks | rg 'test\.(ts|tsx)$' | sort` into explicit paths; run typecheck, lint, and a new-document manual-save browser race under the app’s actual StrictMode root. Gate: frontend-conventions-reviewer; rebase after the PO lane before review.

---

## Dispatch order and calendar

| Order | Task | Dependency / onboarding gate | Estimate |
|---|---|---|---|
| 1 | T1 permission cache | none | 0.5 d |
| 2 | T11 then T12 | T12 depends on T11 | 0.5 d |
| 3 | T13 | T11 | 0.75 d |
| 4 | T2 movements | none; owns the controller bulk lookup and StockMovementTest first | 0.75 d |
| 5 | T10 guards | depends on T2 bulk lookup; rebase after T2 and serialize StockMovementTest edits | 0.5 d |
| 6 | T3 payments | none | 0.5 d |
| 7 | T4 audit/doc limit | coordinate document lane | 0.75 d |
| 8 | T5 product search | shared-consumer browser gate | 0.25 d |
| 9 | T7 stock dedupe | none | 0.5 d |
| 10 | T8 reconnect | authenticated-layout gate | 0.25 d |
| 11 | T9 cache store | staging env proof first | 0.25 d |
| 12 | T14 autosave | rebase after PO lane | 0.5 d |
| 13 | T6 pricing debounce | **WAIT for wave-2 PO merge** | 0.25 d |

- [ ] Promotion batch 1: T1, T9, T11/T12, T13; do not promote T1 before the staging database-per-tenant topology/database/migration preflight, and do not promote T9 before independent web/worker/scheduler/CLI Redis capability plus write/read/delete probes. Then run permission:cache-reset and idempotency double-click staging probes.
- [ ] Promotion batch 2: T2, then rebased T10, plus T3 and T4; do not promote T2/T3 before every absent external POS/mobile owner supplies pagination/stable-traversal contract evidence or that consumer rollout is blocked; require T10’s CI-only whole-suite green, then verify movement filters/totals/search, payment page 2, dashboard 5, audit include behavior, and the 1..100 document clamp.
- [ ] Promotion batch 3: T5, T7, T8, T14; add T6 only after its WAIT gate clears.

## Phase B roster (not planned here; entry criterion = tenant #1 live for one quiet week)

| Lane | Findings | Reviewer | Precondition |
|---|---|---|---|
| B-1 rate limiting | S-9 | tenancy-authz | one week of staging POS traffic |
| B-2 request memoisation + Sanctum last_used debounce | S-10, S-13 | tenancy-authz | T10 staging logs |
| B-3 list projections | S-11, S-12, S-14 | inventory-costing + treasury | no live DocumentData/ProductController lane |
| B-4 posting/conversion locks | S-8, S-19, ID-8..ID-11, ID-14, ID-15 | fiscal-pos + treasury + inventory-costing | duplicate census before indexes |
| B-5 POS sync batching/jitter | S-20 | fiscal-pos | POS release slot |
| B-6 cache topology/ETags/route cache | S-17, S-25 | general | B-2 |
| B-7 remaining frontend hygiene | S-22, S-27..S-29; convert the pre-existing float-based totals, remaining amount, positivity checks, and excess comparisons around `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:210` to decimal-string arithmetic | frontend-conventions + treasury | none |
| B-8 bulk stock-level endpoint | S-6b | inventory-costing | T7 |
| B-9 treasury request identity | ID-5..ID-7, ID-22 | treasury + inventory-costing | none |
| B-10 document request identity | ID-13, ID-16, ID-17 | fiscal-pos + treasury | migration preflight |
| B-11 external side effects | ID-18..ID-21, ID-23, ID-24 | general | none |

## Self-review

- [ ] Structure retained: header, Global Constraints, Phase 0, Tasks 1–14, Dispatch order, Phase B roster, Self-review.
- [ ] Gate blockers mapped: T1 database-per-tenant resolver plus real central database worker and compatibility shared-key proof; T2 portable wildcard search, dedicated request, PHP-computed exact `created_at DESC, id DESC` traversal on both drivers, one valid two-row/six-meta fixture declaration, harness/keys/W4 cap; T3 dedicated request, stable order on both drivers, required pagination/exact key/six-field fixture/page iterator; T4 validation/types/all payload contracts/four missing-counterpart errors/validated-pair branch selection/translated global envelope/default-50 pagination and page-2 traversal/per-page rejection/1..100 document clamp; T5/T6 real debounce harnesses; T7 reactive scope gate/root/URL-filtered component dedupe with remaining distinct-product fan-out deferred to B-8; T8 full cooldown; T9 isolated fallback, `types-drift` array pin, all three fail-closed runtime entrypoints, role-by-role shell proof, plus four-environment Redis proof; T10 restorable logging/red handler/distinct timestamps/proportional flat guard/four exact-log tests/CI suite; T12 three real surfaces, pre-rerender ref locks, decimal-string props across every local split caller and deprecated wrapper, direct bcmath input, IEEE-754 regression, numeric-prop type guard, and forbidden-pattern greps; T13 real PG collision at transaction levels 0 and 1/exact DTO/index/initiate seams/reset spy/replenishment line 102; T14 swallowed predecessor tail/throwing-onError recovery/StrictMode/lifecycle/debounce tests.
- [ ] No placeholder helpers remain. Every test snippet is a complete method using named existing fixtures/wrappers/mocks; each new file has its import list.
- [ ] Exact signatures checked: PaymentForm has no props; useDraftAutoSave(data, config); DraftData.type; MoneyInput emits decimal strings; both SplitPaymentForm and deprecated SplitPaymentModal require `totalAmount: string`; SplitPaymentForm passes that string directly to `bcsub`/`bccomp` while split sums use `bcadd`; pricing response is items.
- [ ] Exact backend names checked: InitiateTransferData, stock_transfers_idempotency_unique, stock_transfers_company_number_unique, CarbonInterface, LengthAwarePaginator.
- [ ] Query invalidation checked: T7 retains the stock-levels root; T8 intentionally invalidates all active queries; CompanySelector remains globally invalidating.
- [ ] Existing consumers checked: ProductMovementsTab, both InventoryTenantIsolation unpaged reads, PartnerDetailPage, and TreasuryCompanyIsolation search reads remain compatible; W4 is bounded; W5c iterates; Dashboard loses ignored payment sort while its document `limit=5` remains clamped; onboarding `limit=100` remains valid; GoodsReceiptListPage’s stock-level prefix invalidation still matches; RecordPaymentModal and all three document-detail hosts are protected; Replenishment line 102 and its outer transaction are verified; QuickStockAdjustmentModal and both mutation-hook files remain untouched; external POS/mobile owner evidence or rollout block remains binding.
- [ ] Behavior checked: audit aggregate/range order remains ascending; each missing half of aggregate and date pairs returns `VALIDATION_ERROR` at the counterpart field path; every payload-reading test opts in; audit default 50, page 2, total/current/last metadata, and `per_page=101` envelope are proven; document limit proves 0/-5 -> 1 and 5000 -> 100; movement search covers name/SKU/reference and escaped wildcard; split payment accepts exact string `"0.300"` from `"0.100" + "0.200"` and rejects `"0.100" + "0.199"` without a numeric money boundary.
- [ ] Locale disposition checked for en/fr/ar: T2 uses existing common:pagination keys and creates no page-local-warning key; T4 adds `validation.audit_date_range_max` in all three backend locales with `:max` substitution and an exact English assertion.
- [ ] No migration is introduced.
- [ ] Deployment and promotion conditions remain binding: staging topology and tenant-DB count must pass the database-per-tenant preflight; web, worker, scheduler, and CLI must each independently pass Redis configuration/capability/connectivity write-read-delete probes; T10 requires the CI-only full backend suite; and absent POS/mobile clients require external-owner pagination evidence or a blocked rollout.

## Gate r1 disposition

| Finding number | What changed in the plan |
|---|---|
| 1 | T2 now uses server-side transfer/write-off aliases, removes page-local filtering/counts, captures the existing useQuery harness, tests OffsetPagination’s actual div/button/select DOM, lists imports, and documents all en/fr/ar keys. |
| 2 | T3 makes PaymentListPage mandatory, adds page/per_page state, keepPreviousData, OffsetPagination, exact URL updates, and removes Dashboard’s unsupported sort. |
| 3 | T4 uses guarded date_format:Y-m-d validation, CarbonInterface, LengthAwarePaginator, updates both include=payload consumers, adds a real default-omission red test, and asserts 100 rows from 101 documents. |
| 4 | T5 uses the existing wrapper/mocks and distinct awaited renders, with zero search calls through 249 ms and one at 250 ms; both imports are explicit. |
| 5 | T6 uses the controlled editor, createWrapper/makeLine, items response, string '125', distinct renders, fake timers, and explicit imports. |
| 6 | T7 retains tenantScopedKey(['stock-levels', 'product', productId, variantId]) and places both tests under features/stock-transfers/__tests__. |
| 7 | T8 deletes the allowlist design, uses useRef<number or null>, invalidates all active queries, and supplies a complete epoch-zero/cooldown spy test. |
| 8 | T10 replaces DB::listen with scoped query-log methods, restores logging enablement, tests log-without-throw, and includes a document-linked movement. |
| 9 | T12 uses PaymentForm’s no-prop harness, updates SplitPaymentForm’s exact body, and tests a synchronous boolean ref lock with an unresolved request. |
| 10 | T13 uses InitiateTransferData and stock_transfers_idempotency_unique, actual feature test paths, page-level post-await reset, untouched hooks/modal, and initiate()-level collision plus two rethrow tests. |
| 11 | T14 uses useDraftAutoSave(data, config), payload type, the existing hooks/__tests__ suite, a strict promise tail, identity checks, generation cancellation, and tests three callers/failure/reset/unmount. |
| NB-1 | T1 names only GenerateRecurringExpensesCommand, BatchExpiryDailyCheckCommand, and TreasuryAlertRecipients; explains compatibility mode and adds queue lifecycle coverage. |
| NB-2 | T4 explicitly preserves ascending occurred_at for aggregate/range calls. |
| NB-3 | T9 states method_exists tags is correct and that boot validation does not test Redis connectivity. |
| NB-4 | T10’s handler is explicitly log-only and its regression asserts relation loading continues. |
| NB-5 | T11 retains stable-until-reset semantics. |
| NB-6 | T13 catches outside DB::transaction and rereads only after rollback. |
| NB-7 | T14 retains debounce while correcting only serialization and lifecycle handling. |
| M-1 | Drifted Task 4/6/7 line references were removed in favor of current method/block/file targets. |
| BR | Every blast-radius consumer from the gate is named in its task and carried into focused verification or an explicit untouched note. |
| OS | The onboarding table now starts from the gate verdicts and records the mitigation; T2/T3/T8/T14 become Yes, T7 remains conditional because Phase A closes only the duplicate-component half of S-6, and T6 remains WAIT. |

## Gate r2 disposition

| Finding | What changed |
|---|---|
| B1 — Task 1 lifecycle | Revision 4 scopes the feature to deployed database-per-tenant mode, provisions two real tenant PostgreSQL databases, initializes both through `TenancyResolver`, dispatches to the central database queue after BootstrapTenancy installs the payload hook, runs the real worker twice, and asserts tenant keys plus central restoration. Compatibility mode now has an explicit shared-key-by-design test through the resolver. |
| B2 — Task 2 exact inventory key | Added `features/inventory/__tests__/tenantScope.test.tsx` to scope and verification and updates its one material page key to include page 1/per-page 25; revision 4 also corrects the stock-adjustment invalidation fixture. |
| B3 — Task 3 exact treasury key and full-set helper | Updated TreasuryTenantScope to the page-aware key and replaced W5c’s single unpaged read with a raw-response loop through `last_page`; the consuming expenses lifecycle spec is run by path. |
| B4 — Task 4 payload and negative limits | Added `include=payload` to the aggregate and same-tenant contracts, changed the clamp to `max(1, min(..., 100))`, and added executable coverage for 0, -5, and 5000. |
| B5 — Task 7 unrelated request traffic | The feature assertion filters mock calls with `/products/{id}/stock-levels`; it records two stock-level calls before and one after, while browser wording now consistently says “stock-level requests.” |
| B6 — Task 9 default fallback | Added an isolated direct require of `config/cache.php` with CACHE_STORE removed from putenv/`$_ENV`/`$_SERVER`, restores all three sources, and asserts the unset default is redis. |
| B7 — Task 10 false flat-query proof | Task 2 now bulk-loads page document IDs with whereIn/get/keyBy. The query guard links 3/5 and 30/50 rows and requires `large <= small + 2`; the helper is documented non-nestable, the lazy-load warning test starts red, InventoryGlPostingSeamTest is rechecked, and a CI-only full backend suite is a non-mergeable gate. |
| B8 — Task 12 lock proof and pending state | PaymentForm and SplitPaymentForm tests submit twice in one act boundary; PaymentForm’s disabled/loading condition becomes `isSubmitting || createMutation.isPending`. Active RecordPaymentModal now receives the same hook, body key, synchronous ref lock, unresolved-request test, and success-only reset. Deprecated SplitPaymentModal retains the pass-through behavior while its `totalAmount` prop is updated to the same decimal-string contract as SplitPaymentForm. |
| B9 — Task 14 StrictMode | The mount effect resets `isUnmountedRef.current=false` and cleanup sets it true. Added a StrictMode replay regression and changed failure recovery to hold request one pending, prove request two has not started, then reject request one and prove request two starts. |
| NB1 — Task 2 honest search | Added max-120 validation plus escaped production ILIKE across product name, SKU, and the confirmed movement reference column, with matching/wildcard/overlength tests. |
| NB2 — Task 3 six-field fixtures | Updated PaymentListPage.test.tsx’s response type and fixture to current_page, last_page, per_page, total, from, and to; it is run explicitly. |
| NB3 — Task 10 query-log nesting | Added a docblock and task warning that countQueries flushes pre-existing contents and must never be nested. |
| NB4 — Task 13 spy isolation | Added `vi.clearAllMocks()` and an explicit idempotency reset-spy reset to the adjustment test’s beforeEach. |
| NB5 — Task 9 connectivity caveat | Kept environment connectivity proof as a mandatory promotion condition; cache:verify-store proves tag capability only. |
| BR — unpaged and active consumers | Added W4’s bounded stock-movement helper, W5c’s payment page iterator, RecordPaymentModal protection, and ReplenishmentFulfillmentService/ReplenishmentActionsTest verification by path. |
| V — path-scoped verification | Every gate-named impacted existing test is listed by file path in its task; the only whole-backend run is the Task 10 CI lane leg and is explicitly forbidden locally. |

## Gate r3 disposition

| Finding | What changed |
|---|---|
| B1 — Task 1 topology/lifecycle | Applied the orchestrator’s database-per-tenant-only scope. The plan states why compatibility mode correctly keeps the shared key, proves an unprovisioned compatibility tenant through `TenancyResolver::initializeIfProvisioned()`, provisions two real tenant PostgreSQL databases, drives both through the resolver, dispatches two real central database-queue jobs after BootstrapTenancy installs the payload hook, runs `queue:work database --once` twice, and proves tenant keys plus final central restoration. |
| B2 — Task 2 SQLite wildcard failure | Replaced backslash escaping with bound `LOWER(...) LIKE ? ESCAPE '!'` predicates and escapes `!`, `%`, and `_` in that order. Normal name/SKU/reference searches, literal percent, literal underscore, and 121-character rejection run under SQLite and PostgreSQL. |
| B3 — Task 2 frontend fixture | Kept both Alpha and Beta movement rows, added `current_page`, `last_page`, `per_page`, `total`, `from`, and `to`, and explicitly preserves the existing row-per-movement assertion. |
| B4 — Tasks 2/3 validation and ordering | Added `ListStockMovementsRequest` and `ListPaymentsRequest` with every accepted parameter, removed raw list-input reads, avoided explicit `mixed`, ordered by `created_at DESC, id DESC` and `payment_date DESC, id DESC`, and added 30-row tied-sort page-boundary tests on both drivers. |
| B5 — Task 4 hardcoded message | Added `validation.audit_date_range_max` to en/fr/ar, uses `:max`, resolves it through `__()`, and asserts the substituted English response text. |
| B6 — Task 13 false-positive race | Replaced the pre-seeded winner with a PG-only two-connection harness. First lookup returns `null`, the insert override commits a winner immediately before the parent INSERT collides, and the second lookup calls the real parent after rollback. Assertions require one insert, two lookups, transaction level zero for a top-level call, transaction level one for a call nested in an outer transaction after savepoint rollback, and one surviving key. Both rethrow cases remain. |
| NB1 — r2 carry-forward | Retained every genuine r2 fix. The lone open r2 lifecycle item is superseded by the explicit production-topology decision and the new resolver/worker proof, and the r2 disposition now says so. |
| NB2 — stale inventory line | The tenant-scope instruction cites only the material page-key assertion at `features/inventory/__tests__/tenantScope.test.tsx:273`. |
| NB3 — stock-adjustment key fixture | Updated `features/stock-adjustments/__tests__/queries.test.tsx:64` to the real page-aware stock-movement key including page 1 and per-page 25. |
| NB4 — W4 completeness | The W4 helper fixes page 1/per-page 100 and asserts `body.meta.last_page === 1`; the plan requires a real iterator if a scenario outgrows one page. |
| NB5 — obsolete expense comment | Added `expenses-lifecycle.spec.ts` to Task 3 and explicitly deletes its no-page explanation now that `allPaymentIds()` traverses `last_page`. |
| NB6 — Task 7 store context | The shared hook now subscribes directly to auth/company stores and computes its own `enabled` gate from caller intent, product ID, tenant ID, and company ID; a store-transition test proves it wakes when scope arrives. |
| NB7 — Task 9 environment proof | Promotion now independently requires web, worker, scheduler, and CLI to show redis configuration, pass the capability command, and complete a real Redis write/read/delete probe. Revision 5 also sources the permanent command after `config:cache` in web/bundled, worker, and scheduler entrypoints and supplies a role-by-role check-only shell harness. |
| NB8 — Task 10 log contracts | Lists and locally runs `InventoryGlPostingSeamTest.php:513`, `TreasuryMovementServiceRecordTest.php:252`, and `POS/RefundReportingFieldsTest.php:134`; the CI-only full backend suite is an explicit non-optional merge gate. |
| NB9 — Task 14 debounce | Added a fake-timer rerender test that changes data three times inside 250 ms, observes zero early calls, and asserts one request with only the final data. |
| NB10 — external POS/mobile | Records that these consumers are absent from the checkout and makes external owner contract evidence—or blocking their rollout—mandatory before capped endpoints promote. |
| BR — cited consumers | Preserved or explicitly verifies ProductMovementsTab, W4, PartnerDetailPage, W8 isolation, Dashboard, all shared entry/pricing consumers, transfer invalidators, DashboardLayout/CompanySelector, all four process roles, payment modal hosts, replenishment at its actual line 102 call, QuickStockAdjustmentModal, and DocumentForm. |

## Gate r4 disposition

| Finding | What changed |
|---|---|
| B1 — Task 2 deterministic ordering proof | The tied-row fixture now records each seeded `id` and raw `created_at`, sorts those rows in PHP by `created_at DESC, id DESC`, traverses pages 1 and 2, and uses `assertSame($expectedIds, $actualIds)`. Count, uniqueness, SQLite, and PostgreSQL checks remain. |
| B2 — Task 4 validation response path | Both malformed-date and translated max-span assertions now require `error.code=VALIDATION_ERROR`; the translated assertion reads `error.errors.to.0`, matching the renderer in `apps/api/bootstrap/app.php`. |
| B3 — Task 4 audit pagination/cap proof | A red test records 51 audit events, asserts 50 rows and total/current/last/per-page metadata on page 1, traverses page 2, asserts its one remaining row, and proves all 51 seeded IDs were returned exactly once. A separate red test rejects `per_page=101` with the global validation envelope. |
| B4 — Task 9 permanent role boot validation | Added worker and scheduler entrypoints plus a shared `docker/verify-cache-store.sh` to Task 9. Web/bundled, worker, and scheduler source the helper immediately after their own `config:cache`; none may suppress failure. Each entrypoint also has a real `--check-only` branch. |
| B5 — Task 12 monetary precision | SplitPaymentForm removes both float paths, requires `totalAmount: string`, and computes current/remaining values with string `bcadd`, `bcsub`, and `bccomp` imports from `@/lib/decimal`. Revision 6 extends the boundary to deprecated SplitPaymentModal and every local caller, passes `totalAmount` directly to bcmath, accepts `"0.100" + "0.200"` against `"0.300"`, rejects `"0.100" + "0.199"`, locks out a numeric prop through typecheck, and expands forbidden-pattern verification. |
| NB1 — Task 13 replenishment citation | The consumer citation now points to `ReplenishmentFulfillmentService.php:102`, the actual `StockTransferService::initiate()` call inside the grouped outer transaction. |
| NB2 — Task 13 pre-seam wording | The plan now says the PG harness parses before seam extraction but fails because current production never calls the subclass seams; it no longer claims the subclass cannot compile. |
| NB3 — Task 13 nested transaction | Added a second real PostgreSQL collision call inside an outer `DB::transaction`; it asserts the post-savepoint second lookup runs at transaction level 1, the outer transaction remains live, and the final level is 0 after commit. |
| NB4 — Task 7 Phase A scope | Task 7 is explicitly partial S-6 closure: it deduplicates the two same-product component consumers, while one request per distinct product/variant remains and is deferred to the B-8 bulk endpoint. |
| NB5 — Task 9 infrastructure-free CI | The `types-drift` job at `.github/workflows/ci.yml` now receives a plan-level `CACHE_STORE=array` job environment, protecting its infrastructure-free artisan reflection contract from the Redis fallback. |
| NB6 — Task 14 callback-poisoned tail | New work is assigned with `tailRef.current = tailRef.current.catch(() => undefined).then(run)`. A test makes `onError` throw, observes the first caller's rejection, and proves the next queued save still reaches the API and updates the draft ID. |
| NB7 — Phase 0 staging fact | Removed the unverifiable 16-database claim. The staging topology and tenant-DB count are explicitly “to be confirmed in the deployment preflight,” with enumeration, existence/migration proof, and a promotion block on any mismatch. |
| NB8 — external POS/mobile consumers | Retained the binding Phase 0 requirement: because those clients are absent from the checkout, Tasks 2 and 3 cannot promote without each external owner's pagination/traversal contract evidence or a blocked consumer rollout. |

## Gate r5 disposition

| Finding | What changed in revision 6 |
|---|---|
| B1 — Task 2 invalid fixture declaration | Task 2 now explicitly prescribes one complete `const mockReturn: { ... } = {` declaration with no second initializer. The parse-valid snippet retains both Alpha/Beta movement rows and exactly six meta fields: `current_page`, `last_page`, `per_page`, `total`, `from`, and `to`. |
| B2 — Task 4 `sometimes` bypasses paired validation | Removed `sometimes` from `aggregate_type`, `aggregate_id`, `from`, and `to`; `required_with` is first in all four rule arrays. Added four distinct red tests for each missing counterpart, each asserting `error.code === 'VALIDATION_ERROR'` and the exact `error.errors.<counterpart>.0` path. Task 4 also binds the existing aggregate, range, and company-scope tests to prove complete validated pairs still select the intended controller branches. |
| B3 — Task 12 numeric required-amount boundary | `SplitPaymentFormProps.totalAmount` and `SplitPaymentModalProps.totalAmount` are decimal strings. Every `<SplitPaymentForm`/`<SplitPaymentModal` hit under `apps/web/src` is inventoried as a file:line Modify entry; tests and the deprecated wrapper pass strings, and the wrapper example no longer uses `parseFloat`. SplitPaymentForm passes `totalAmount` directly to `bcsub`/`bccomp`, proves `"0.100" + "0.200"` equals exact total `"0.300"`, rejects a 0.001 shortfall, and consumes two `@ts-expect-error` directives that make either numeric-prop regression fail `pnpm typecheck`. Verification rejects `totalAmount: number` and `Number(` in the changed files. |
| NB1 — Task 10 tied creation timestamps | Every seeded movement receives a distinct explicit `created_at`/`updated_at`. Newest-first indexes 50..46 deterministically contain exactly three linked rows, while all 50 contain 30; the proportional `large <= small + 2` assertion remains unchanged. |
| NB2 — RecordPaymentModal precision debt | Phase B B-7 now names the pre-existing float totals, remaining amount, validation, and excess comparisons around `RecordPaymentModal.tsx:210`. Task 12 remains limited to its idempotency edit on that active modal. |
| NB3 — Task 7 partial S-6 closure | The Task 7 contract, browser proof, self-review, and B-8 dependency continue to state that Phase A only deduplicates same-product/component consumers; one request per distinct product/variant remains. GoodsReceiptListPage’s matching prefix invalidation is now an explicit verification consumer. |
| NB4 — Task 9 connectivity proof | The capability command remains explicitly insufficient as a connectivity test. Promotion is still forbidden until web, worker, scheduler, and CLI each independently prove Redis configuration, tag capability, and a real write/read/delete round trip; evidence cannot be shared across roles. |
| NB5 — Task 10 global log-spy risk | Added `ReceiptReturnServiceTest.php:292` to the identified exact-log contracts and focused local command. The CI-only full backend suite remains a mandatory non-mergeable gate because other global log spies may exist; the laptop whole-suite prohibition remains. |
| NB6 — external deployment facts | The staging topology/database enumeration and migration preflight remains binding for Task 1. External POS/mobile pagination and stable-traversal contract evidence—or a blocked consumer rollout—remains binding before Tasks 2/3 promote. |
| Blast radius — existing consumers | Added focused verification for InventoryTenantIsolationTest’s two unpaged stock reads, TreasuryCompanyIsolationTest’s three search reads, onboarding/Dashboard legacy document limits, GoodsReceiptListPage invalidation, ReceiptReturnService exact logging, and all three active RecordPaymentModal document-detail hosts. |
