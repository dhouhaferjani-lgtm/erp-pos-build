<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifyPosChainCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);
    }

    public function test_command_exits_zero_with_valid_empty_chains(): void
    {
        $this->artisan('pos:verify-chains', ['--terminal' => $this->terminal->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('All chains verified successfully');
    }

    public function test_command_exits_zero_when_no_terminals_found(): void
    {
        // Deactivate the only terminal
        $this->terminal->update(['is_active' => false]);

        $this->artisan('pos:verify-chains')
            ->assertExitCode(0)
            ->expectsOutputToContain('No active terminals found');
    }

    public function test_command_filters_by_type_receipts(): void
    {
        $this->artisan('pos:verify-chains', [
            '--terminal' => $this->terminal->id,
            '--type' => 'receipts',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Receipts');
    }

    public function test_command_filters_by_type_z_reports(): void
    {
        $this->artisan('pos:verify-chains', [
            '--terminal' => $this->terminal->id,
            '--type' => 'z-reports',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Z-Reports');
    }

    public function test_command_rejects_invalid_type(): void
    {
        $this->artisan('pos:verify-chains', [
            '--terminal' => $this->terminal->id,
            '--type' => 'invalid',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain("Invalid type 'invalid'");
    }

    public function test_command_filters_by_company(): void
    {
        // Create terminal in a different company
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'is_active' => true,
            'code' => 'OTHER01',
        ]);

        $this->artisan('pos:verify-chains', ['--company' => $this->company->id])
            ->assertExitCode(0)
            ->expectsOutputToContain($this->terminal->code);
    }

    public function test_command_exits_one_when_receipt_chain_is_broken(): void
    {
        // Create a receipt with an incorrect hash to simulate a broken chain
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'valid-first'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 2,
            'previous_hash' => 'wrong-previous-hash',
            'fiscal_hash' => hash('sha256', 'broken'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        $this->artisan('pos:verify-chains', [
            '--terminal' => $this->terminal->id,
            '--type' => 'receipts',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('FAILED');
    }

    public function test_command_reports_terminal_not_found(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $this->artisan('pos:verify-chains', ['--terminal' => $fakeId])
            ->assertExitCode(0)
            ->expectsOutputToContain('not found');
    }
}
