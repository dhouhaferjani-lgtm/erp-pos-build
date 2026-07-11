<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentInstrumentPortfolioColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_fields_and_enum_casts_round_trip(): void
    {
        [$tenant, $company, $method] = $this->context();
        $bankId = (string) Str::uuid();
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
        ]);
        $remittance = InstrumentRemittance::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'number' => 'REM-TEST-0001',
            'remittance_type' => RemittanceType::Collection,
            'instrument_kind' => InstrumentKind::Effet,
            'bank_repository_id' => $bank->id,
            'status' => RemittanceStatus::Draft,
        ]);
        $remittanceId = $remittance->id;

        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'TRT-PORTFOLIO-001',
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Bounced,
            'direction' => InstrumentDirection::Inbound,
            'kind' => InstrumentKind::Effet,
            'origin' => InstrumentOrigin::Pos,
            'bank_id' => $bankId,
            'idempotency_key' => 'fiscal_event:test:instrument:0',
            'remittance_id' => $remittanceId,
            'needs_details' => true,
            'dishonor_routing' => DishonorRouting::RePresent,
        ])->fresh();

        $this->assertNotNull($instrument);
        $this->assertSame(InstrumentDirection::Inbound, $instrument->direction);
        $this->assertSame(InstrumentKind::Effet, $instrument->kind);
        $this->assertSame(InstrumentOrigin::Pos, $instrument->origin);
        $this->assertSame(DishonorRouting::RePresent, $instrument->dishonor_routing);
        $this->assertSame($bankId, $instrument->bank_id);
        $this->assertSame($remittanceId, $instrument->remittance_id);
        $this->assertTrue($instrument->needs_details);
        $this->assertTrue(InstrumentStatus::Bounced->canDeposit());
        $this->assertTrue(InstrumentStatus::Cleared->canBounce());
        $this->assertFalse(InstrumentStatus::Cleared->isTerminal());
        $this->assertSame(1, PaymentInstrument::query()->pendingPortfolio()->count());
    }

    public function test_idempotency_key_is_unique_while_null_keys_can_repeat(): void
    {
        [$tenant, $company, $method] = $this->context();

        $base = [
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
        ];

        PaymentInstrument::query()->create($base + ['reference' => 'NULL-1']);
        PaymentInstrument::query()->create($base + ['reference' => 'NULL-2']);

        PaymentInstrument::query()->create($base + [
            'reference' => 'KEY-1',
            'idempotency_key' => 'fiscal_event:unique:instrument:0',
        ]);

        $this->expectException(QueryException::class);
        PaymentInstrument::query()->create($base + [
            'reference' => 'KEY-2',
            'idempotency_key' => 'fiscal_event:unique:instrument:0',
        ]);
    }

    public function test_portfolio_migration_is_re_runnable(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_07_12_100000_add_instrument_portfolio_columns_to_payment_instruments.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('payment_instruments', [
            'direction',
            'kind',
            'origin',
            'bank_id',
            'idempotency_key',
            'remittance_id',
            'needs_details',
            'dishonor_routing',
        ]));
    }

    /**
     * @return array{Tenant, Company, PaymentMethod}
     */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $method = PaymentMethod::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'TRAITE-PORTFOLIO',
            'name' => 'Traite portfolio',
            'is_active' => true,
        ]);

        return [$tenant, $company, $method];
    }
}
