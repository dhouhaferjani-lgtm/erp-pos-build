<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §9.4/§9.5/§5.3/§9.6-X3 —
 * `fiscal:enable-v4-refund-authoring`'s three preflight refusals.
 */
final class EnableV4RefundAuthoringCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'US', // Generic chart -- both purposes seeded correctly from day one.
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
    }

    private function createDeviceTerminal(): Terminal
    {
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
        ]);
    }

    private function freshTerminalEnabled(Terminal $terminal): bool
    {
        $fresh = Terminal::query()->findOrFail($terminal->id);

        return (bool) $fresh->v4_refund_authoring_enabled;
    }

    public function test_enables_the_single_qualifying_device_terminal(): void
    {
        $terminal = $this->createDeviceTerminal();

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertSuccessful();

        self::assertTrue($this->freshTerminalEnabled($terminal));
    }

    public function test_refuses_when_zero_device_terminals_exist(): void
    {
        // Only a web terminal — no physical device.
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Web,
            'is_active' => true,
        ]);

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertFailed();
    }

    public function test_refuses_when_more_than_one_device_terminal_exists(): void
    {
        $this->createDeviceTerminal();
        $terminal2 = $this->createDeviceTerminal();

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertFailed();

        self::assertFalse($this->freshTerminalEnabled($terminal2));
    }

    public function test_refuses_when_terminal_has_legacy_sealed_fiscalized_receipts(): void
    {
        $terminal = $this->createDeviceTerminal();
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        DB::table('pos_receipts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T001-C001-L01-POS01-2026-LEGACYV2',
            'receipt_type' => 'sale',
            'chain_sequence' => 1,
            'receipt_year' => (int) now()->format('Y'),
            'fiscal_hash' => hash('sha256', Str::uuid()->toString()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', Str::uuid()->toString()),
            'payment_methods_hash' => hash('sha256', Str::uuid()->toString()),
            'posted_at' => now(),
            'cashier_id' => $cashier->id,
            'cashier_name' => 'Legacy Cashier',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'EUR',
            'fiscal_status' => 'fiscalized',
            'is_voided' => false,
            'is_training' => false,
            'fiscal_event_id' => null, // legacy-sealed, v2 history
            'training_flag' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertFailed();

        self::assertFalse($this->freshTerminalEnabled($terminal));
    }

    public function test_refuses_when_a_required_account_purpose_is_missing(): void
    {
        $terminal = $this->createDeviceTerminal();

        DB::table('accounts')
            ->where('company_id', $this->company->id)
            ->where('system_purpose', 'refund_write_off')
            ->update(['system_purpose' => null]);

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
        ])->assertFailed();

        self::assertFalse($this->freshTerminalEnabled($terminal));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $terminal = $this->createDeviceTerminal();

        $this->artisanCommand('fiscal:enable-v4-refund-authoring', [
            '--tenant' => $this->tenant->id,
            '--company' => $this->company->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        self::assertFalse($this->freshTerminalEnabled($terminal));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
