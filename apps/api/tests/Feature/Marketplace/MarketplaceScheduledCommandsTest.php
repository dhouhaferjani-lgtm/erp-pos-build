<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Jobs\ReconcileListingsJob;
use App\Modules\Marketplace\Infrastructure\Jobs\SyncSellerListingsJob;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\EnablesMarketplaceModule;

/**
 * `marketplace:delta-sync` / `marketplace:reconcile` — the TenantScopedCommand
 * replacements for the two `$schedule->call(closure)` registrations in
 * MarketplaceServiceProvider. The closures ran `MarketplaceSeller::active()`
 * inside the scheduler's CENTRAL container, which under database-per-tenant
 * throws before anything reaches the queue (so these failures never even
 * appeared in `failed_jobs`).
 *
 * NOTE on `Queue::fake()`: the fake short-circuits the real connection, so
 * QueueTenancyBootstrapper never stamps the tenant onto the payload and the
 * stamping is INVISIBLE to the dispatch-set assertions below. What makes the
 * stamping correct is that the dispatch happens while tenancy is initialized —
 * that is what the db-per-tenant probe test at the bottom of this file pins.
 */
final class MarketplaceScheduledCommandsTest extends TestCase
{
    // Marketplace ships behind `config('marketplace.enabled')` (default FALSE),
    // which is read at BOOT time to gate route + schedule registration — so this
    // suite has to boot with the flag on rather than set config() at runtime.
    use EnablesMarketplaceModule;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_delta_sync_dispatches_one_job_per_active_seller_per_tenant(): void
    {
        Queue::fake();

        $fixture = $this->createSellers();

        $exitCode = Artisan::call('marketplace:delta-sync');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 4 marketplace delta-sync job(s).', $output);

        Queue::assertPushed(SyncSellerListingsJob::class, 4);
        $this->assertSame(
            $fixture['expectedDispatchedIds'],
            $this->dispatchedSellerIds(SyncSellerListingsJob::class),
        );
        $this->assertSame(
            $fixture['expectedDispatchedTenantIds'],
            $this->dispatchedTenantIds(SyncSellerListingsJob::class),
        );
    }

    public function test_reconcile_dispatches_one_job_per_active_seller_per_tenant(): void
    {
        Queue::fake();

        $fixture = $this->createSellers();

        $exitCode = Artisan::call('marketplace:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 4 marketplace reconciliation job(s).', $output);

        Queue::assertPushed(ReconcileListingsJob::class, 4);
        $this->assertSame(
            $fixture['expectedDispatchedIds'],
            $this->dispatchedSellerIds(ReconcileListingsJob::class),
        );
        $this->assertSame(
            $fixture['expectedDispatchedTenantIds'],
            $this->dispatchedTenantIds(ReconcileListingsJob::class),
        );
    }

    /**
     * Plan amendment A6: a tenant with no active seller must short-circuit
     * before any fan-out.
     */
    public function test_commands_dispatch_nothing_when_no_active_seller_exists(): void
    {
        Queue::fake();

        $this->createTenant('marketplace-empty');

        $this->assertSame(0, Artisan::call('marketplace:delta-sync'));
        $this->assertSame(0, Artisan::call('marketplace:reconcile'));

        Queue::assertNothingPushed();
    }

    public function test_commands_are_registered_with_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan marketplace:delta-sync', $output);
        $this->assertStringContainsString('php artisan marketplace:reconcile', $output);
    }

    /**
     * db-per-tenant probe (plan amendment A5), modeled on
     * tests/Feature/Accounting/SubledgerReconciliationCommandTest.php:38-64.
     *
     * SCOPE — read before trusting this test: it exercises a PROBE SUBCLASS,
     * not MarketplaceDeltaSyncCommand / MarketplaceReconcileCommand. It pins
     * the CONTRACT the two commands rely on — that `forEachTenant()` opens each
     * tenant, exposes it to the closure, and ends the context afterwards, which
     * is what makes QueueTenancyBootstrapper stamp the tenant onto every
     * per-seller payload. It CANNOT detect a command that stopped calling
     * `forEachTenant()`. The behavioural guard for that is the 2-tenant
     * dispatch-count fixture in {@see self::createSellers()}.
     *
     * The real commands cannot be driven under `db_per_tenant=true` in this
     * suite: TenancyServiceProvider would then run BootstrapTenancy and swap
     * the connection to a per-tenant SQLite database that does not exist, so
     * the seller query inside the closure would throw and be swallowed by
     * forEachTenant()'s continue-on-throw contract.
     */
    public function test_for_each_tenant_enters_and_ends_tenant_context_in_db_per_tenant_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenantA = $this->createTenant('marketplace-probe-a');
        $tenantB = $this->createTenant('marketplace-probe-b');

        $command = new MarketplaceTenantScopedProbeCommand(app(CompanyContext::class));

        $seen = [];

        $exitCode = $command->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = [
                'argument' => $tenant->id,
                'helper' => tenant('id'),
                'initialized' => tenancy()->initialized,
            ];

            return 0;
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'argument'));
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'helper'));
        $this->assertSame([true, true], array_column($seen, 'initialized'));
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenancy()->tenant);
    }

    /**
     * @param  class-string  $jobClass
     * @return list<string>
     */
    private function dispatchedSellerIds(string $jobClass): array
    {
        return $this->dispatchedPayloadValues($jobClass, 'sellerId');
    }

    /**
     * Finding B closure (2026-08-05): each fan-out payload must carry the
     * ITERATING tenant so the worker can rebind it via BindsTenantContext even
     * when QueueTenancyBootstrapper's stamp is absent (queue:retry, manual
     * re-queue). Note it is NOT `$seller->tenant_id` — that column is NULL for
     * the external seller in this fixture.
     *
     * @param  class-string  $jobClass
     * @return list<string>
     */
    private function dispatchedTenantIds(string $jobClass): array
    {
        return $this->dispatchedPayloadValues($jobClass, 'tenantId');
    }

    /**
     * @param  class-string  $jobClass
     * @return list<string>
     */
    private function dispatchedPayloadValues(string $jobClass, string $property): array
    {
        $values = Queue::pushed($jobClass)
            ->map(function (object $job) use ($property): string {
                $reflected = new ReflectionProperty($job, $property);

                return (string) $reflected->getValue($job);
            })
            ->all();

        sort($values);

        return array_values($values);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(Tenant $tenant, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Marketplace Company {$suffix}",
            'legal_name' => "Marketplace Company {$suffix} LLC",
            'tax_id' => "TAX-MKT-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    /**
     * TWO tenants and TWO active sellers — this fixture is the behavioural
     * guard that the commands still iterate tenants.
     *
     * The dispatch arithmetic, under the suite's legacy row-level mode
     * (`tenancy_resolver.db_per_tenant=false`, forced by phpunit.xml): every
     * tenant pass shares ONE database and the seller query is deliberately not
     * tenant-filtered, so each of the 2 tenant passes sees BOTH active sellers
     * → 2 tenants x 2 active sellers = 4 dispatches. Delete `forEachTenant()`
     * from either command and the body runs once → 2 dispatches → RED. (Under
     * database-per-tenant, which is what ships, each pass sees only its own
     * tenant's database, so the same code dispatches 1 job per active seller.)
     *
     * Fixture shape:
     *   - tenant A owns a SUSPENDED erp_tenant seller (proves `active()`
     *     filters, and keeps tenant A a real iteration slot);
     *   - tenant B owns an ACTIVE erp_tenant seller (the "second tenant owns
     *     its own seller" leg);
     *   - one ACTIVE EXTERNAL seller with `tenant_id => null`. This is the row
     *     that justifies NOT adding `->where('tenant_id', $tenant->id)` to the
     *     seller query: `marketplace_sellers.tenant_id` is nullable and such a
     *     predicate would silently drop this seller in every mode.
     *
     * `marketplace_sellers_tenant_company_unique` is a PARTIAL unique index on
     * (tenant_id, company_id) `WHERE seller_type = 'erp_tenant'` — so each
     * erp_tenant seller gets its own company, and the external seller (which
     * carries no tenant/company at all) is exempt.
     *
     * @return array{activeExternal: MarketplaceSeller, activeTenantB: MarketplaceSeller, suspended: MarketplaceSeller, expectedDispatchedIds: list<string>, expectedDispatchedTenantIds: list<string>}
     */
    private function createSellers(): array
    {
        $tenantA = $this->createTenant('marketplace-sellers-a');
        $tenantB = $this->createTenant('marketplace-sellers-b');

        $companyA = $this->createCompany($tenantA, 'A');
        $companyB = $this->createCompany($tenantB, 'B');

        $suspended = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenantA->id,
            'company_id' => $companyA->id,
            'seller_status' => SellerStatus::Suspended,
            'display_name' => 'Suspended Seller (tenant A)',
        ]);

        $activeTenantB = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
            'seller_status' => SellerStatus::Active,
            'display_name' => 'Active Seller (tenant B)',
        ]);

        $activeExternal = MarketplaceSeller::factory()->external()->create([
            'seller_status' => SellerStatus::Active,
            'display_name' => 'Active External Seller (no tenant)',
        ]);
        $this->assertNull($activeExternal->tenant_id);

        // Each active seller is dispatched once per tenant pass (2 tenants).
        $expected = [
            $activeTenantB->id,
            $activeTenantB->id,
            $activeExternal->id,
            $activeExternal->id,
        ];
        sort($expected);

        // Each tenant pass stamps ITS OWN id on both of the jobs it dispatches
        // — never $seller->tenant_id, which is NULL for $activeExternal.
        $expectedTenantIds = [
            $tenantA->id,
            $tenantA->id,
            $tenantB->id,
            $tenantB->id,
        ];
        sort($expectedTenantIds);

        return [
            'activeExternal' => $activeExternal,
            'activeTenantB' => $activeTenantB,
            'suspended' => $suspended,
            'expectedDispatchedIds' => array_values($expected),
            'expectedDispatchedTenantIds' => array_values($expectedTenantIds),
        ];
    }
}

/**
 * @cross-tenant-by-design Test-only probe subclass (never registered as an
 * Artisan command) that exposes the protected forEachTenant() helper so the
 * db-per-tenant iteration contract can be asserted directly.
 */
final class MarketplaceTenantScopedProbeCommand extends TenantScopedCommand
{
    protected function executeCommand(): int
    {
        return self::SUCCESS;
    }

    /**
     * @param  callable(Tenant): int  $fn
     */
    public function runForEachTenant(callable $fn): int
    {
        return $this->forEachTenant($fn);
    }
}
