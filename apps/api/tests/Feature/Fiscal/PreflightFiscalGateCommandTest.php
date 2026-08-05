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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `fiscal:preflight-gate` — the gate that must clear before a
 * schema-destructive fiscal rebuild.
 *
 * The 2026-08-05 cat-(b) re-sweep found this command producing the single most
 * dangerous verdict in the fleet: `tableCount()` returned `0` for a table that
 * does not exist, and after the 2026-05-28 database-per-tenant flip NONE of the
 * four probed tables exist on the console's central connection. So the gate
 * printed "SERVER SURFACE: clear" and exited 0 having inspected nothing, in
 * front of a task that destroys fiscal schema.
 *
 * The contract asserted here is therefore FAIL-CLOSED: a verdict of "clear" is
 * only ever emitted for a tenant whose four fiscal tables were actually read.
 * Missing table, unreachable tenant database, or zero tenants visited all
 * produce "unable to verify" and a non-zero exit.
 */
final class PreflightFiscalGateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =================================================================
    // Fail-closed contract
    // =================================================================

    /**
     * The regression that motivated the conversion. Before it, this exact
     * scenario — a console run that can see no fiscal table at all — printed
     * "SERVER SURFACE: clear" and exited 0.
     */
    public function test_gate_fails_closed_when_no_tenant_was_verified(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('SERVER SURFACE: unable to verify - no tenant database was inspected')
            ->expectsOutputToContain('FAILED CLOSED: zero tenants verified')
            ->doesntExpectOutputToContain('SERVER SURFACE: clear')
            ->assertExitCode(1);
    }

    public function test_gate_fails_closed_when_a_probed_table_is_missing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createTerminal();

        Schema::drop('pos_receipt_prints');

        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('MISSING TABLE pos_receipt_prints')
            ->doesntExpectOutputToContain('SERVER SURFACE: clear')
            ->assertExitCode(1);
    }

    public function test_gate_reports_an_unknown_tenant_filter_instead_of_clearing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createTerminal();

        $this->artisan('fiscal:preflight-gate', ['--tenant' => (string) Str::uuid()])
            ->doesntExpectOutputToContain('SERVER SURFACE: clear')
            ->assertFailed();
    }

    // =================================================================
    // Per-tenant verdicts
    // =================================================================

    public function test_gate_passes_when_a_reachable_tenant_has_no_live_fiscal_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $terminal = $this->createTerminal();

        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain(sprintf('TENANT %s', $terminal->tenant_id))
            ->expectsOutputToContain('SERVER SURFACE: clear')
            ->expectsOutputToContain('DEVICE SURFACE: requires manual inventory')
            ->expectsOutputToContain('WEB-POS SURFACE: live receipt-creation path detected')
            ->assertExitCode(0);
    }

    /**
     * The evidence requirement from the launch program: an operator reading the
     * gate output must be able to attribute every verdict to a tenant. One
     * dirty tenant must not be able to hide behind a clean one, and vice versa.
     */
    public function test_gate_emits_one_verdict_per_tenant_and_fails_when_any_tenant_is_dirty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cleanTerminal = $this->createTerminal();
        $dirtyTerminal = $this->createTerminal();
        $this->createReceiptFor($dirtyTerminal);

        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain(sprintf('TENANT %s (', $cleanTerminal->tenant_id))
            ->expectsOutputToContain(sprintf('TENANT %s (', $dirtyTerminal->tenant_id))
            ->expectsOutputToContain('SERVER SURFACE: NON-EMPTY')
            ->expectsOutputToContain('pos_receipts: 1')
            ->assertExitCode(1);
    }

    public function test_tenant_filter_narrows_the_gate_to_one_tenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cleanTerminal = $this->createTerminal();
        $dirtyTerminal = $this->createTerminal();
        $this->createReceiptFor($dirtyTerminal);

        $this->artisan('fiscal:preflight-gate', ['--tenant' => $cleanTerminal->tenant_id])
            ->expectsOutputToContain(sprintf('TENANT %s (', $cleanTerminal->tenant_id))
            ->doesntExpectOutputToContain(sprintf('TENANT %s (', $dirtyTerminal->tenant_id))
            ->assertExitCode(0);
    }

    // =================================================================
    // Findings — one per probed surface
    // =================================================================

    public function test_gate_fails_when_pos_receipts_present(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createReceiptFor($this->createTerminal());

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
        $terminal = $this->createTerminal();
        $receipt = $this->createReceiptFor($terminal);

        DB::table('pos_receipt_prints')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'terminal_id' => $terminal->id,
            'user_id' => $receipt->cashier_id,
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
     * `pos_terminals` carries no `tenant_id` predicate confusion of its own,
     * but the chain-state probe is an OR of two columns — without an explicit
     * grouping the tenant predicate would be defeated by operator precedence
     * and every tenant would inherit every other tenant's chain state.
     */
    public function test_chain_state_finding_is_attributed_to_the_owning_tenant_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $clean = $this->createTerminal();
        $dirty = $this->createTerminal(['current_sequence' => 4]);

        $this->artisan('fiscal:preflight-gate', ['--tenant' => $clean->tenant_id])
            ->expectsOutputToContain('SERVER SURFACE: clear')
            ->assertExitCode(0);

        $this->artisan('fiscal:preflight-gate', ['--tenant' => $dirty->tenant_id])
            ->expectsOutputToContain('pos_terminals_with_chain_state: 1')
            ->assertExitCode(1);
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

    private function createReceiptFor(Terminal $terminal): Receipt
    {
        $cashier = User::factory()->create(['tenant_id' => $terminal->tenant_id]);

        return Receipt::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
        ]);
    }

    private function assertGateFailsWithNonEmptyServerSurface(string $expectedFinding): void
    {
        $this->artisan('fiscal:preflight-gate')
            ->expectsOutputToContain('SERVER SURFACE: NON-EMPTY')
            ->expectsOutputToContain($expectedFinding)
            ->assertExitCode(1);
    }
}
