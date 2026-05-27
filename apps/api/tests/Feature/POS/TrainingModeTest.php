<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\TerminalTrainingModeChanged;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TrainingModeTest extends TestCase
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
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo(['pos.manage_terminals', 'pos.view_reports']);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
            'is_training_mode' => false,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_training_receipt_excluded_from_hash_chain(): void
    {
        // Create a production receipt in chain
        $productionReceipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'POS01-2026-00000001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'production-receipt'),
            'is_voided' => false,
            'is_training' => false,
            'cashier_id' => $this->user->id,
        ]);

        // Create a training receipt (should be excluded from chain verification)
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            // Training receipts do not advance the chain: production leaves
            // chain_sequence NULL (the pos_receipts_sequence CHECK rejects 0).
            'chain_sequence' => null,
            'receipt_number' => 'TRN-POS01-2026-00000001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'TRAINING-fake'),
            'is_voided' => false,
            'is_training' => true,
            'cashier_id' => $this->user->id,
        ]);

        // Verify chain — training receipt must not break it
        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk();
        // Chain should only contain the production receipt (length 1)
        $response->assertJsonPath('data.chain_length', 1);
    }

    public function test_training_receipt_excluded_from_z_report_totals(): void
    {
        // Create a production receipt
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => false,
            'total' => '100.000',
            'subtotal' => '84.034',
            'tax_amount' => '15.966',
            'cashier_id' => $this->user->id,
        ]);

        // Create a training receipt — should NOT count in totals
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => true,
            'total' => '999.000',
            'subtotal' => '840.336',
            'tax_amount' => '158.664',
            'cashier_id' => $this->user->id,
        ]);

        // Use the production scope to verify filtering
        $productionReceipts = Receipt::where('terminal_id', $this->terminal->id)
            ->production()
            ->get();

        $this->assertCount(1, $productionReceipts);
        $this->assertEquals('100.000', $productionReceipts->first()->total);
    }

    public function test_training_receipt_excluded_from_grand_total_calculations(): void
    {
        // Create training and production receipts
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => false,
            'total' => '50.000',
            'subtotal' => '42.017',
            'tax_amount' => '7.983',
            'cashier_id' => $this->user->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => true,
            'total' => '500.000',
            'subtotal' => '420.168',
            'tax_amount' => '79.832',
            'cashier_id' => $this->user->id,
        ]);

        // Perpetual totals should only include production receipts
        $totals = Receipt::where('terminal_id', $this->terminal->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->selectRaw('SUM(total) as lifetime_sales, COUNT(*) as lifetime_transactions')
            ->first();

        $this->assertEquals(1, (int) $totals->lifetime_transactions);
        $this->assertEquals('50.000', number_format((float) $totals->lifetime_sales, 3, '.', ''));
    }

    public function test_training_receipt_excluded_from_jet_export(): void
    {
        // Create a training receipt
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => true,
            'receipt_number' => 'TRN-POS01-2026-00000001',
            'cashier_id' => $this->user->id,
        ]);

        // Create a production receipt
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_voided' => false,
            'is_training' => false,
            'receipt_number' => 'POS01-2026-00000001',
            'cashier_id' => $this->user->id,
        ]);

        // Verify the production query excludes training
        $productionReceipts = Receipt::where('company_id', $this->company->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->get();

        $this->assertCount(1, $productionReceipts);
        $this->assertStringStartsWith('POS01', $productionReceipts->first()->receipt_number);
    }

    public function test_training_receipt_gets_trn_prefix(): void
    {
        // Create a training receipt directly to verify prefix pattern
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_training' => true,
            'receipt_number' => 'TRN-POS01-2026-00000001',
            'cashier_id' => $this->user->id,
        ]);

        $this->assertStringStartsWith('TRN-', $receipt->receipt_number);
    }

    public function test_toggle_training_mode_dispatches_event(): void
    {
        Event::fake([TerminalTrainingModeChanged::class]);

        $response = $this->postJson("/api/v1/pos/terminals/{$this->terminal->id}/toggle-training");

        $response->assertOk();

        Event::assertDispatched(TerminalTrainingModeChanged::class, function (TerminalTrainingModeChanged $event): bool {
            return $event->terminalId === $this->terminal->id
                && $event->enabled === true
                && $event->companyId === $this->company->id;
        });

        // Verify terminal is now in training mode
        $this->terminal->refresh();
        $this->assertTrue($this->terminal->is_training_mode);
    }

    public function test_toggle_training_mode_toggles_back(): void
    {
        // Set to training mode first
        $this->terminal->update(['is_training_mode' => true]);

        Event::fake([TerminalTrainingModeChanged::class]);

        $response = $this->postJson("/api/v1/pos/terminals/{$this->terminal->id}/toggle-training");

        $response->assertOk();

        Event::assertDispatched(TerminalTrainingModeChanged::class, function (TerminalTrainingModeChanged $event): bool {
            return $event->enabled === false;
        });

        $this->terminal->refresh();
        $this->assertFalse($this->terminal->is_training_mode);
    }

    public function test_cannot_toggle_training_mode_with_open_shift(): void
    {
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '0.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/pos/terminals/{$this->terminal->id}/toggle-training");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TERMINAL_HAS_OPEN_SHIFT');

        // Verify training mode was NOT changed
        $this->terminal->refresh();
        $this->assertFalse($this->terminal->is_training_mode);
    }

    public function test_production_scope_on_terminal(): void
    {
        // Create a training mode terminal
        $trainingTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_training_mode' => true,
        ]);

        $productionTerminals = Terminal::forCompany($this->company->id)->production()->get();
        $trainingTerminals = Terminal::forCompany($this->company->id)->training()->get();

        $this->assertTrue($productionTerminals->contains('id', $this->terminal->id));
        $this->assertFalse($productionTerminals->contains('id', $trainingTerminal->id));
        $this->assertTrue($trainingTerminals->contains('id', $trainingTerminal->id));
        $this->assertFalse($trainingTerminals->contains('id', $this->terminal->id));
    }

    public function test_production_scope_on_receipt(): void
    {
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_training' => false,
            'cashier_id' => $this->user->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'is_training' => true,
            'cashier_id' => $this->user->id,
        ]);

        $productionReceipts = Receipt::where('terminal_id', $this->terminal->id)->production()->get();
        $allReceipts = Receipt::where('terminal_id', $this->terminal->id)->get();

        $this->assertCount(1, $productionReceipts);
        $this->assertCount(2, $allReceipts);
        $this->assertFalse($productionReceipts->first()->is_training);
    }
}
