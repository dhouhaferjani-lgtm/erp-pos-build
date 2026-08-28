<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal\Migrations;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * Owner ruling 2026-08-28: v4 refund authoring is default-on, with a
 * forward-only brownfield safety filter for legacy history and missing GL
 * purposes. Multi-till companies are deliberately eligible terminal by
 * terminal; web and virtual terminals are outside the device-authored flow.
 */
final class EnableV4RefundAuthoringByDefaultMigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const GATE_TOKEN = 'V4 REFUND AUTHORING DEFAULT-ON MIGRATION:';

    private const MIGRATION = '2026_08_28_110000_enable_v4_refund_authoring_by_default.php';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_enables_every_eligible_physical_terminal_and_reports_exact_skip_reasons(): void
    {
        $this->seedRefundPurposes($this->company);

        $firstTill = $this->terminal($this->company, $this->location, ['code' => 'POS01']);
        $secondTill = $this->terminal($this->company, $this->location, ['code' => 'POS02']);
        $legacyTill = $this->terminal($this->company, $this->location, ['code' => 'POS03']);
        $web = $this->terminal($this->company, $this->location, [
            'code' => 'WEB01',
            'type' => TerminalType::Web,
        ]);
        $virtual = $this->terminal($this->company, $this->location, [
            'code' => 'VADMIN',
            'type' => TerminalType::VirtualAdmin,
        ]);
        $this->seedLegacyReceipt($legacyTill);

        $missingCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $missingLocation = Location::factory()->create(['company_id' => $missingCompany->id]);
        Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $missingCompany->id,
            'code' => '7090',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::SalesReturn,
        ]);
        $missingAccountsTill = $this->terminal($missingCompany, $missingLocation, ['code' => 'POS04']);

        Log::spy();
        $this->runMigration();

        self::assertTrue($this->enabled($firstTill));
        self::assertTrue($this->enabled($secondTill), 'Multi-till companies are eligible; there is no single-till restriction.');
        self::assertFalse($this->enabled($legacyTill));
        self::assertFalse($this->enabled($missingAccountsTill));
        self::assertFalse($this->enabled($web));
        self::assertFalse($this->enabled($virtual));

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => $message === self::GATE_TOKEN
                && $context['status'] === 'ok'
                && $context['enabled'] === 2
                && $context['skipped'] === 2
                && $context['reasons'] === [
                    'legacy-history' => 1,
                    'missing-accounts' => 1,
                ])
            ->once();
    }

    public function test_a_second_run_does_not_rewrite_an_already_enabled_terminal(): void
    {
        $this->seedRefundPurposes($this->company);
        $terminal = $this->terminal($this->company, $this->location);

        $this->runMigration();

        DB::table('pos_terminals')->where('id', $terminal->id)->update(['updated_at' => now()->subYear()]);
        $before = (string) DB::table('pos_terminals')->where('id', $terminal->id)->value('updated_at');

        Log::spy();
        $this->runMigration();

        self::assertSame($before, (string) DB::table('pos_terminals')->where('id', $terminal->id)->value('updated_at'));
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => $message === self::GATE_TOKEN
                && $context['enabled'] === 0
                && $context['skipped'] === 0)
            ->once();
    }

    public function test_missing_required_tables_are_reported_and_skipped(): void
    {
        Schema::rename('accounts', 'accounts_refund_default_on_guard_probe');
        Log::spy();

        try {
            $this->runMigration();
        } finally {
            Schema::rename('accounts_refund_default_on_guard_probe', 'accounts');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => $message === self::GATE_TOKEN
                && $context['status'] === 'skipped'
                && $context['reason'] === 'required-schema-absent')
            ->once();
    }

    public function test_down_is_a_forward_only_no_op(): void
    {
        $this->seedRefundPurposes($this->company);
        $terminal = $this->terminal($this->company, $this->location);
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();
        self::assertTrue($this->enabled($terminal));

        $migration->down();
        self::assertTrue($this->enabled($terminal));
    }

    private function seedRefundPurposes(Company $company): void
    {
        foreach ([
            ['code' => '7090', 'purpose' => SystemAccountPurpose::SalesReturn],
            ['code' => '6590', 'purpose' => SystemAccountPurpose::RefundWriteOff],
        ] as $definition) {
            Account::factory()->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'code' => $definition['code'],
                'type' => AccountType::Expense,
                'system_purpose' => $definition['purpose'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function terminal(Company $company, Location $location, array $attributes = []): Terminal
    {
        return Terminal::factory()->create(array_merge([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Physical,
            'v4_refund_authoring_enabled' => false,
        ], $attributes));
    }

    private function seedLegacyReceipt(Terminal $terminal): void
    {
        $cashier = User::factory()->create(['tenant_id' => $terminal->tenant_id]);

        DB::table('pos_receipts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'LEGACY-'.Str::uuid(),
            'receipt_type' => ReceiptType::Sale->value,
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
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'fiscal_event_id' => null,
            'training_flag' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enabled(Terminal $terminal): bool
    {
        return (bool) DB::table('pos_terminals')->where('id', $terminal->id)->value('v4_refund_authoring_enabled');
    }

    private function runMigration(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);
        $migration->up();
    }
}
