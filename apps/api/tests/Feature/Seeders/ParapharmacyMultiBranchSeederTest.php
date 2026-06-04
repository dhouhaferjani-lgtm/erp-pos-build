<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use Database\Seeders\ParapharmacyMultiBranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-branch parapharmacy demo fixture.
 *
 * Proves the seeder provisions a 3-location topology (1 warehouse + 2 POS
 * shops) within a single tenant + company, distributes stock across all
 * three, attaches one POS terminal per shop, and scopes the per-shop cashiers
 * to a single location so a Paris cashier cannot read Lyon stock.
 */
final class ParapharmacyMultiBranchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_one_warehouse_and_two_pos_shops(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        // Exactly 3 locations, all under the same single company.
        $this->assertSame(3, Location::count(), 'Multi-branch seeder must create exactly 3 locations');
        $this->assertSame(1, Location::distinct()->count('company_id'), 'All locations must share one company');

        $warehouses = Location::where('type', LocationType::Warehouse)->get();
        $shops = Location::where('type', LocationType::Shop)->get();

        $this->assertCount(1, $warehouses, 'Exactly one warehouse expected');
        $this->assertCount(2, $shops, 'Exactly two shops expected');

        // The warehouse is NOT POS-enabled; both shops ARE.
        $this->assertFalse((bool) $warehouses->first()->pos_enabled, 'Warehouse must not be POS-enabled');
        $this->assertTrue($shops->every(fn (Location $l): bool => (bool) $l->pos_enabled), 'Both shops must be POS-enabled');

        // Named shops present.
        $shopNames = $shops->pluck('name')->all();
        $this->assertContains('PharmaBio Paris', $shopNames);
        $this->assertContains('PharmaBio Lyon', $shopNames);
    }

    public function test_stock_exists_at_all_three_locations(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();
        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();

        $this->assertGreaterThan(0, StockLevel::where('location_id', $warehouse->id)->count(), 'Warehouse must hold stock');
        $this->assertGreaterThan(0, StockLevel::where('location_id', $paris->id)->count(), 'Paris shop must hold stock');
        $this->assertGreaterThan(0, StockLevel::where('location_id', $lyon->id)->count(), 'Lyon shop must hold stock');

        // Bulk of inventory sits at the warehouse: it should carry more stock
        // rows than either individual shop (warehouse 90% vs shop ~60%).
        $warehouseRows = StockLevel::where('location_id', $warehouse->id)->count();
        $this->assertGreaterThan(
            StockLevel::where('location_id', $paris->id)->count(),
            $warehouseRows,
            'Warehouse should carry more stock rows than the Paris shop',
        );
    }

    public function test_one_pos_terminal_per_shop(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $this->assertSame(2, Terminal::count(), 'Exactly two terminals (one per shop) expected');

        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();

        $this->assertSame(1, Terminal::where('location_id', $paris->id)->count(), 'Paris must have one terminal');
        $this->assertSame(1, Terminal::where('location_id', $lyon->id)->count(), 'Lyon must have one terminal');
        $this->assertSame(0, Terminal::where('location_id', $warehouse->id)->count(), 'Warehouse must have no POS terminal');

        $this->assertTrue(
            Terminal::where('type', TerminalType::Physical)->count() === 2,
            'Both terminals must be physical POS devices',
        );
    }

    public function test_owner_and_manager_can_access_all_locations(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        foreach (['owner@pharmabio.fr', 'manager@pharmabio.fr'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $membership = UserCompanyMembership::where('user_id', $user->id)->firstOrFail();

            // NULL allowed_location_ids = access to every location.
            $this->assertNull(
                $membership->allowed_location_ids,
                "{$email} must retain all-location access for cross-branch transfers",
            );

            foreach (Location::all() as $location) {
                $this->assertTrue(
                    $membership->canAccessLocation($location),
                    "{$email} must be able to access {$location->name}",
                );
            }
        }
    }

    public function test_paris_cashier_cannot_read_lyon_stock(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();

        $parisCashier = User::where('email', 'paris.cashier@pharmabio.fr')->firstOrFail();
        $membership = UserCompanyMembership::where('user_id', $parisCashier->id)->firstOrFail();

        // Scoped to exactly the Paris shop.
        $this->assertSame([$paris->id], $membership->allowed_location_ids, 'Paris cashier must be scoped to Paris only');
        $this->assertTrue($membership->canAccessLocation($paris), 'Paris cashier can access Paris');
        $this->assertFalse($membership->canAccessLocation($lyon), 'Paris cashier must NOT access Lyon');
        $this->assertFalse($membership->canAccessLocation($warehouse), 'Paris cashier must NOT access the warehouse');

        // The location scope, applied to a real stock query, must hide Lyon
        // stock from the Paris cashier and surface Paris stock.
        $allowed = $membership->allowed_location_ids;
        $this->assertNotNull($allowed);

        $visibleStock = StockLevel::whereIn('location_id', $allowed)->get();
        $this->assertGreaterThan(0, $visibleStock->count(), 'Paris cashier must see Paris stock');
        $this->assertTrue(
            $visibleStock->every(fn (StockLevel $s): bool => $s->location_id === $paris->id),
            'Every stock row visible to the Paris cashier must belong to the Paris shop',
        );
        $this->assertSame(
            0,
            StockLevel::whereIn('location_id', $allowed)->where('location_id', $lyon->id)->count(),
            'No Lyon stock row may be visible through the Paris cashier scope',
        );
    }

    public function test_lyon_cashier_is_scoped_to_lyon(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();

        $lyonCashier = User::where('email', 'lyon.cashier@pharmabio.fr')->firstOrFail();
        $membership = UserCompanyMembership::where('user_id', $lyonCashier->id)->firstOrFail();

        $this->assertSame([$lyon->id], $membership->allowed_location_ids, 'Lyon cashier must be scoped to Lyon only');
        $this->assertTrue($membership->canAccessLocation($lyon));
        $this->assertFalse($membership->canAccessLocation($paris));
    }
}
