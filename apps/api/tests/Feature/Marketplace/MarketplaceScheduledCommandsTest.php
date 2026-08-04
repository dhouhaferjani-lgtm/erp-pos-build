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
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_delta_sync_dispatches_one_job_per_active_seller_only(): void
    {
        Queue::fake();

        $fixture = $this->createSellers();

        $exitCode = Artisan::call('marketplace:delta-sync');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 2 marketplace delta-sync job(s).', $output);

        Queue::assertPushed(SyncSellerListingsJob::class, 2);
        $this->assertSame(
            [$fixture['activeA']->id, $fixture['activeB']->id],
            $this->dispatchedSellerIds(SyncSellerListingsJob::class),
        );
    }

    public function test_reconcile_dispatches_one_job_per_active_seller_only(): void
    {
        Queue::fake();

        $fixture = $this->createSellers();

        $exitCode = Artisan::call('marketplace:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 2 marketplace reconciliation job(s).', $output);

        Queue::assertPushed(ReconcileListingsJob::class, 2);
        $this->assertSame(
            [$fixture['activeA']->id, $fixture['activeB']->id],
            $this->dispatchedSellerIds(ReconcileListingsJob::class),
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
     * db-per-tenant probe (plan amendment A5): without this, a command that
     * skipped `forEachTenant()` would still pass every other assertion in this
     * file — and the per-seller jobs would be dispatched from central context,
     * so QueueTenancyBootstrapper would stamp no tenant onto the payload.
     * Modeled on
     * tests/Feature/Accounting/SubledgerReconciliationCommandTest.php:38-64.
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
        $ids = Queue::pushed($jobClass)
            ->map(function (object $job): string {
                $property = new ReflectionProperty($job, 'sellerId');

                return (string) $property->getValue($job);
            })
            ->all();

        sort($ids);

        return array_values($ids);
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
     * A SINGLE tenant owning three companies (marketplace_sellers is UNIQUE on
     * (tenant_id, company_id), so one seller per company). One tenant keeps the
     * dispatch counts exact: the seller query is intentionally not tenant-
     * filtered (nullable tenant_id — see the command docblocks), so under the
     * suite's legacy row-level mode a second tenant would re-fan-out the same
     * sellers on its own pass.
     *
     * @return array{activeA: MarketplaceSeller, activeB: MarketplaceSeller, suspended: MarketplaceSeller}
     */
    private function createSellers(): array
    {
        $tenant = $this->createTenant('marketplace-sellers');

        $companyOne = $this->createCompany($tenant, '1');
        $companyTwo = $this->createCompany($tenant, '2');
        $companyThree = $this->createCompany($tenant, '3');

        $activeA = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyOne->id,
            'seller_status' => SellerStatus::Active,
            'display_name' => 'Active Seller A',
        ]);
        $activeB = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyTwo->id,
            'seller_status' => SellerStatus::Active,
            'display_name' => 'Active Seller B',
        ]);
        $suspended = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyThree->id,
            'seller_status' => SellerStatus::Suspended,
            'display_name' => 'Suspended Seller',
        ]);

        $ids = [$activeA->id, $activeB->id];
        sort($ids);

        return [
            'activeA' => $ids[0] === $activeA->id ? $activeA : $activeB,
            'activeB' => $ids[0] === $activeA->id ? $activeB : $activeA,
            'suspended' => $suspended,
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
