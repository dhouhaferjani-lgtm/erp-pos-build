<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `inventory:expire-reservations` — the TenantScopedCommand replacement for the
 * deleted `ExpireReservationsJob` (staging: 4,211 central `failed_jobs` rows
 * since 2026-07-03 with `relation "stock_reservations" does not exist`, because
 * the queue job queried a tenant table from CENTRAL context under
 * database-per-tenant).
 */
final class ExpireStockReservationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_command_expires_only_expired_reservations_and_releases_reserved_stock(): void
    {
        $tenantA = $this->createTenant('expire-reservations-tenant-a');
        $tenantB = $this->createTenant('expire-reservations-tenant-b');

        $fixtureA = $this->createReservationFixture($tenantA, 'A');
        $fixtureB = $this->createReservationFixture($tenantB, 'B');

        $exitCode = Artisan::call('inventory:expire-reservations');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Expired 2 reservation(s).', $output);

        $expiredA = $fixtureA['expired']->refresh();
        $this->assertNotNull($expiredA->released_at);
        $this->assertNotNull($expiredA->expired_at);
        $this->assertSame(ReleaseReason::Expired, $expiredA->release_reason);

        $expiredB = $fixtureB['expired']->refresh();
        $this->assertNotNull($expiredB->released_at);
        $this->assertSame(ReleaseReason::Expired, $expiredB->release_reason);

        $this->assertNull($fixtureA['active']->refresh()->released_at);
        $this->assertNull($fixtureB['active']->refresh()->released_at);

        // 5.0000 reserved minus the 3.0000 expired reservation.
        $this->assertEqualsWithDelta(
            2.0,
            (float) $fixtureA['stockLevel']->refresh()->reserved,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            2.0,
            (float) $fixtureB['stockLevel']->refresh()->reserved,
            0.0001,
        );
    }

    /**
     * Second run is a no-op: `StockReservation::expired()` filters on
     * `released_at IS NULL`, so the sweep never double-releases. This is what
     * makes the N-times-per-tenant execution in legacy row-level mode
     * (`tenancy_resolver.db_per_tenant=false`) correct-but-redundant rather
     * than a double-decrement bug — `stock_reservations` has no tenant_id
     * column, so the sweep cannot be tenant-scoped in that mode.
     */
    public function test_second_sweep_is_a_no_op(): void
    {
        $tenant = $this->createTenant('expire-reservations-idempotent');
        $fixture = $this->createReservationFixture($tenant, 'I');

        Artisan::call('inventory:expire-reservations');
        $reservedAfterFirst = (float) $fixture['stockLevel']->refresh()->reserved;

        $exitCode = Artisan::call('inventory:expire-reservations');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No reservations to expire.', $output);
        $this->assertEqualsWithDelta(
            $reservedAfterFirst,
            (float) $fixture['stockLevel']->refresh()->reserved,
            0.0001,
        );
    }

    public function test_command_is_registered_with_scheduler_and_the_old_job_entry_is_gone(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('inventory:expire-reservations', $output);
        $this->assertStringNotContainsString('ExpireReservationsJob', $output);
    }

    /**
     * db-per-tenant probe (plan amendment A5): without this, a command that
     * skipped `forEachTenant()` would still pass every other assertion in this
     * file. Modeled on
     * tests/Feature/Accounting/SubledgerReconciliationCommandTest.php:38-64.
     */
    public function test_for_each_tenant_enters_and_ends_tenant_context_in_db_per_tenant_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenantA = $this->createTenant('expire-reservations-probe-a');
        $tenantB = $this->createTenant('expire-reservations-probe-b');

        $command = new ExpireStockReservationsProbeCommand(app(CompanyContext::class));

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

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * @return array{company: Company, stockLevel: StockLevel, expired: StockReservation, active: StockReservation}
     */
    private function createReservationFixture(Tenant $tenant, string $suffix): array
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Reservation Company {$suffix}",
            'legal_name' => "Reservation Company {$suffix} LLC",
            'tax_id' => "TAX-RES-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => "Reservation Product {$suffix}",
            'sku' => "SKU-RES-{$suffix}",
            'type' => 'part',
            'is_active' => true,
            'cost_price' => '10.00',
        ]);

        $location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => "Reservation Location {$suffix}",
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '10.0000',
            'reserved' => '5.0000',
        ]);

        $expired = StockReservation::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '3.0000',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => Str::uuid()->toString(),
            'expires_at' => now()->subHour(),
            'priority' => 0,
        ]);

        $active = StockReservation::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '1.0000',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => Str::uuid()->toString(),
            'expires_at' => now()->addHour(),
            'priority' => 0,
        ]);

        return [
            'company' => $company,
            'stockLevel' => $stockLevel,
            'expired' => $expired,
            'active' => $active,
        ];
    }
}

/**
 * @cross-tenant-by-design Test-only probe subclass (never registered as an
 * Artisan command) that exposes the protected forEachTenant() helper so the
 * db-per-tenant iteration contract can be asserted directly.
 */
final class ExpireStockReservationsProbeCommand extends TenantScopedCommand
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
