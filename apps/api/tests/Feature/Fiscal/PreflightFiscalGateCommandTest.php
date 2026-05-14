<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PreflightFiscalGateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_passes_when_no_live_fiscal_data_exists(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('SERVER SURFACE: clear')
            ->expectsOutputToContain('DEVICE SURFACE: requires manual inventory')
            ->expectsOutputToContain('WEB-POS SURFACE: live receipt-creation path detected')
            ->assertExitCode(0);
    }

    public function test_gate_fails_when_pos_receipts_present(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'current_sequence' => 0,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
        ]);

        $this->assertGateFailsWithNonEmptyServerSurface('pos_receipts: 1');
    }

    public function test_gate_fails_when_pos_z_reports_present(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $terminal = $this->createTerminal();
        $cashier = User::factory()->create(['tenant_id' => $terminal->tenant_id]);
        $shiftId = (string) Str::uuid();

        DB::table('pos_shifts')->insert([
            'id' => $shiftId,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'opening_cash' => '0.00',
            'status' => 'OPEN',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pos_z_reports')->insert([
            'id' => (string) Str::uuid(),
            'terminal_id' => $terminal->id,
            'shift_id' => $shiftId,
            'z_number' => 1,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_z_hash' => null,
            'report_data' => json_encode([], JSON_THROW_ON_ERROR),
            'generated_by' => $cashier->id,
            'generated_at' => now(),
        ]);

        $this->assertGateFailsWithNonEmptyServerSurface('pos_z_reports: 1');
    }

    public function test_gate_fails_when_receipt_prints_present(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'current_sequence' => 0,
        ]);
        $receipt = Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
        ]);

        DB::table('pos_receipt_prints')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'terminal_id' => $terminal->id,
            'user_id' => $cashier->id,
            'print_type' => 'original',
            'copy_number' => 1,
            'printed_at' => now(),
            'print_method' => 'pdf',
            'created_at' => now(),
        ]);

        $this->assertGateFailsWithNonEmptyServerSurface('pos_receipt_prints: 1');
    }

    public function test_gate_fails_when_terminal_sequence_has_advanced(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createTerminal(['current_sequence' => 1]);

        $this->assertGateFailsWithNonEmptyServerSurface('pos_terminals_with_chain_state: 1');
    }

    public function test_gate_fails_when_terminal_last_hash_exists(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createTerminal([
            'current_sequence' => 0,
            'last_hash' => str_repeat('b', 64),
        ]);

        $this->assertGateFailsWithNonEmptyServerSurface('pos_terminals_with_chain_state: 1');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTerminal(array $overrides = []): Terminal
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        return Terminal::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'current_sequence' => 0,
        ], $overrides));
    }

    private function assertGateFailsWithNonEmptyServerSurface(string $expectedFinding): void
    {
        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('SERVER SURFACE: NON-EMPTY')
            ->expectsOutputToContain($expectedFinding)
            ->assertExitCode(1);
    }
}
