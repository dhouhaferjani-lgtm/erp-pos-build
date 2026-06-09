<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use Database\Seeders\CoffeeShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coffee-shop e2e smoke harness (2026-06-09) — verify/coffeeshop-e2e.
 *
 * Shift-open requires a row in `pos_terminals` (ShiftController::open validates
 * `terminal_code` exists) but the seeder historically created none, forcing the
 * smoke-test operator to hand-create a terminal via the POS "Request new
 * terminal" flow + admin activation before they could open a shift. This pins
 * the stabilization fix: CoffeeShopSeeder must leave one CLAIMABLE physical
 * terminal — active, unclaimed (hardware_identifier null) — so the cashier can
 * walk straight into TerminalSetupPage → "Claim existing terminal".
 */
final class CoffeeShopSeederTerminalTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_claimable_physical_terminal(): void
    {
        $this->seed(CoffeeShopSeeder::class);

        $company = Company::where('name', 'Cafe Tunis')->firstOrFail();

        $this->assertSame(1, Terminal::forCompany($company->id)->count(), 'CoffeeShopSeeder must seed exactly one POS terminal');

        $terminal = Terminal::forCompany($company->id)->firstOrFail();
        $this->assertSame(TerminalType::Physical, $terminal->type, 'Seeded terminal must be physical (claimable from the device)');
        $this->assertTrue($terminal->is_active, 'Seeded terminal must be active so it appears in /pos/terminals/available');
        $this->assertNull($terminal->hardware_identifier, 'Seeded terminal must be unclaimed so a device can claim it');
    }

    public function test_seeded_terminal_has_a_valid_fiscal_genesis_seed(): void
    {
        $this->seed(CoffeeShopSeeder::class);

        $company = Company::where('name', 'Cafe Tunis')->firstOrFail();
        $terminal = Terminal::forCompany($company->id)->firstOrFail();

        // NF525 hash-chain genesis: TerminalController::store seeds a 256-bit
        // (64 hex char) random seed. A blank/short seed would break the first
        // receipt's chain initialization.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $terminal->genesis_seed);
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertSame((int) now()->format('Y'), $terminal->current_year);
    }
}
