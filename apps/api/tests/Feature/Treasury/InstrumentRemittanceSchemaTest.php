<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentRemittanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_relations_and_enum_casts_round_trip(): void
    {
        [$tenant, $company, $bank, $instrument] = $this->context();
        $remittance = $this->createRemittance($tenant, $company, $bank);

        $line = InstrumentRemittanceLine::query()->create([
            'remittance_id' => $remittance->id,
            'instrument_id' => $instrument->id,
            'amount' => '100.000',
            'line_status' => RemittanceLineStatus::Pending,
        ])->fresh();
        $instrument->update(['remittance_id' => $remittance->id]);

        $this->assertNotNull($line);
        $this->assertSame(RemittanceType::Collection, $remittance->remittance_type);
        $this->assertSame(RemittanceStatus::Draft, $remittance->status);
        $this->assertSame(InstrumentKind::Cheque, $remittance->instrument_kind);
        $this->assertSame(RemittanceLineStatus::Pending, $line->line_status);
        $this->assertSame($bank->id, $remittance->bankRepository->id);
        $this->assertSame($instrument->id, $line->instrument->id);
        $this->assertSame($remittance->id, $instrument->fresh()?->remittance?->id);
        $this->assertCount(1, $remittance->lines);
    }

    public function test_number_allocation_is_gapless_per_company_and_year(): void
    {
        [$tenant, $company, $bank] = $this->context();

        $first = $this->createRemittance($tenant, $company, $bank);
        $second = $this->createRemittance($tenant, $company, $bank);

        $year = now()->format('Y');
        $this->assertSame("REM-{$year}-0001", $first->number);
        $this->assertSame("REM-{$year}-0002", $second->number);
    }

    public function test_number_allocation_issues_company_advisory_lock_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('advisory lock is PostgreSQL-specific');
        }

        [, $company] = $this->context();
        DB::enableQueryLog();
        DB::transaction(fn (): string => InstrumentRemittance::allocateNumber($company->id));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue(collect($queries)->contains(
            fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock')
                && in_array("remittance_number:{$company->id}", $query['bindings'], true),
        ));
    }

    public function test_duplicate_instrument_on_same_remittance_is_rejected(): void
    {
        [$tenant, $company, $bank, $instrument] = $this->context();
        $remittance = $this->createRemittance($tenant, $company, $bank);
        $row = [
            'remittance_id' => $remittance->id,
            'instrument_id' => $instrument->id,
            'amount' => '100.000',
            'line_status' => RemittanceLineStatus::Pending,
        ];
        InstrumentRemittanceLine::query()->create($row);

        $this->expectException(QueryException::class);
        InstrumentRemittanceLine::query()->create($row);
    }

    public function test_remittance_migration_is_re_runnable(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_07_12_100400_create_instrument_remittances.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('instrument_remittances'));
        $this->assertTrue(Schema::hasTable('instrument_remittance_lines'));
    }

    private function createRemittance(
        Tenant $tenant,
        Company $company,
        PaymentRepository $bank,
    ): InstrumentRemittance {
        return DB::transaction(function () use ($tenant, $company, $bank): InstrumentRemittance {
            return InstrumentRemittance::query()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'number' => InstrumentRemittance::allocateNumber($company->id),
                'remittance_type' => RemittanceType::Collection,
                'instrument_kind' => InstrumentKind::Cheque,
                'bank_repository_id' => $bank->id,
                'status' => RemittanceStatus::Draft,
            ]);
        });
    }

    /**
     * @return array{Tenant, Company, PaymentRepository, PaymentInstrument}
     */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
        ]);
        $method = PaymentMethod::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CHECK-'.Str::lower(Str::random(8)),
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
            'is_active' => true,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'CHK-'.Str::upper(Str::random(8)),
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
        ]);

        return [$tenant, $company, $bank, $instrument];
    }
}
