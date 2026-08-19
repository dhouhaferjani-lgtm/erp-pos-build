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

    /**
     * M2-round2 finding 7 — the Z row's Inspected cell used to reprint
     * `count`, which reads as a measurement of what the Z verdict spans. It
     * is not one: `ZReportHashService::verifyZReportChain()` ORs the legacy
     * `ZReport` walk with a walk over EVERY `z_session` /
     * `training_z_session` `fiscal_events` row on the terminal, while `count`
     * is only `ZReport::where('terminal_id', …)->count()`. Measuring the real
     * span would mean changing `ZReportHashService`, which R-1 forbids in
     * this wave, so the cell must say the number is not measured.
     */
    public function test_z_report_row_does_not_fabricate_an_inspected_count(): void
    {
        $this->artisan('pos:verify-chains', [
            '--terminal' => $this->terminal->id,
            '--type' => 'z-reports',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('unmeasured');
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

    /**
     * Fail-closed contract (2026-08-05 cat-(b) conversion). This used to exit
     * **0** with a "not found" line — a launch verifier reporting success for
     * a chain it never read.
     */
    public function test_command_fails_when_the_terminal_filter_matches_nothing(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $this->artisan('pos:verify-chains', ['--terminal' => $fakeId])
            ->expectsOutputToContain('matched no terminal in any reachable tenant')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_the_company_filter_matches_nothing(): void
    {
        $this->artisan('pos:verify-chains', ['--company' => '00000000-0000-0000-0000-000000000000'])
            ->expectsOutputToContain('matched no terminal in any reachable tenant')
            ->assertExitCode(1);
    }

    // =================================================================
    // Per-tenant iteration (cat-(b) wave 2)
    // =================================================================

    /**
     * The E-7 evidence requirement: a fleet run must attribute every verdict
     * to a tenant, and one broken tenant must fail the aggregate even when
     * another tenant is clean.
     */
    public function test_a_broken_chain_in_one_tenant_fails_the_fleet_run_with_per_tenant_verdicts(): void
    {
        $brokenTerminal = $this->createTerminalInNewTenant();
        $this->breakReceiptChainOn($brokenTerminal);

        $this->artisan('pos:verify-chains', ['--type' => 'receipts'])
            ->expectsOutputToContain(sprintf('TENANT %s (', $this->tenant->id))
            ->expectsOutputToContain(sprintf('TENANT %s (%s): chain verification FAILED', $brokenTerminal->tenant_id, $brokenTerminal->company->tenant->slug))
            ->assertExitCode(1);
    }

    public function test_tenant_filter_narrows_the_run_to_one_tenant(): void
    {
        $brokenTerminal = $this->createTerminalInNewTenant();
        $this->breakReceiptChainOn($brokenTerminal);

        // The clean tenant passes even though a broken chain exists elsewhere.
        $this->artisan('pos:verify-chains', ['--tenant' => $this->tenant->id, '--type' => 'receipts'])
            ->expectsOutputToContain('All chains verified successfully')
            ->assertExitCode(0);
    }

    public function test_an_unknown_tenant_filter_fails_loudly(): void
    {
        $this->artisan('pos:verify-chains', ['--tenant' => '00000000-0000-0000-0000-000000000000'])
            ->expectsOutputToContain('not found in the central tenant directory')
            ->assertExitCode(1);
    }

    /**
     * C3 (2026-08-05 fiscal review). A tenant with no active terminals emitted
     * NO line at all, so the E-7 evidence pack could only show the tenants that
     * happened to have data — it could not demonstrate coverage.
     */
    public function test_the_coverage_block_names_every_tenant_including_the_ones_with_no_terminals(): void
    {
        $emptyTenant = Tenant::factory()->create();

        $this->artisan('pos:verify-chains', ['--type' => 'receipts'])
            ->expectsOutputToContain('TENANT COVERAGE:')
            ->expectsOutputToContain(sprintf('  TENANT %s: verified (1 terminal(s))', $this->tenant->id))
            ->expectsOutputToContain(sprintf('  TENANT %s: NO-DATA (no terminal matched this run)', $emptyTenant->id))
            ->assertExitCode(0);
    }

    /**
     * The other half of C3: a FAILED tenant must be attributable in the same
     * block, not only in the mid-run error line.
     */
    public function test_a_failed_tenant_is_attributable_in_the_coverage_block(): void
    {
        $brokenTerminal = $this->createTerminalInNewTenant();
        $this->breakReceiptChainOn($brokenTerminal);

        $this->artisan('pos:verify-chains', ['--type' => 'receipts'])
            ->expectsOutputToContain('TENANT COVERAGE:')
            ->expectsOutputToContain(sprintf('  TENANT %s: FAILED (a chain is broken)', $brokenTerminal->tenant_id))
            ->assertExitCode(1);
    }

    private function createTerminalInNewTenant(): Terminal
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'is_active' => true,
            'code' => 'OTHTEN',
        ]);
    }

    private function breakReceiptChainOn(Terminal $terminal): void
    {
        $cashier = User::factory()->create(['tenant_id' => $terminal->tenant_id]);

        Receipt::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'chain_sequence' => 1,
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'valid-first'),
            'is_voided' => false,
            'cashier_id' => $cashier->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'chain_sequence' => 2,
            'previous_hash' => 'wrong-previous-hash',
            'fiscal_hash' => hash('sha256', 'broken'),
            'is_voided' => false,
            'cashier_id' => $cashier->id,
        ]);
    }
}
