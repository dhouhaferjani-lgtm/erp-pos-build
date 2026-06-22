<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PartnerMoneyPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_money_casts_preserve_currency_scale_3(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'credit_limit' => '100.125',
            'receivable_balance' => '200.125',
            'credit_balance' => '50.125',
            'payable_balance' => '0.125',
        ]);

        $partner->refresh();

        $this->assertSame('100.125', $partner->credit_limit);
        $this->assertSame('200.125', $partner->receivable_balance);
        $this->assertSame('50.125', $partner->credit_balance);
        $this->assertSame('0.125', $partner->payable_balance);
    }

    public function test_partner_money_columns_are_decimal_15_3_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $columns = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name = 'partners'
              AND column_name IN ('credit_limit', 'receivable_balance', 'credit_balance', 'payable_balance')
            ORDER BY column_name
        ");

        $this->assertCount(4, $columns, 'partner money columns not found');

        foreach ($columns as $column) {
            $this->assertSame(15, (int) $column->numeric_precision, "{$column->column_name} precision should be 15");
            $this->assertSame(3, (int) $column->numeric_scale, "{$column->column_name} scale should be 3");
        }
    }

    public function test_partner_credit_balance_rejects_negative_cached_magnitude(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('credit_balance must be a non-negative magnitude');

        Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'credit_balance' => '-0.001',
        ]);
    }

    public function test_partner_payable_balance_rejects_negative_cached_magnitude(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payable_balance must be a non-negative magnitude');

        Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Supplier,
            'payable_balance' => '-0.001',
        ]);
    }

    public function test_partner_cached_liability_columns_have_non_negative_constraints_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_constraint is Postgres-specific');
        }

        $constraints = DB::select("
            SELECT conname, pg_get_constraintdef(pg_constraint.oid) AS definition
            FROM pg_constraint
            JOIN pg_class ON pg_class.oid = pg_constraint.conrelid
            WHERE pg_class.relname = 'partners'
              AND conname IN ('partners_credit_balance_non_negative', 'partners_payable_balance_non_negative')
            ORDER BY conname
        ");

        $this->assertCount(2, $constraints, 'partner non-negative liability constraints not found');

        $definitions = collect($constraints)->mapWithKeys(
            static fn (object $constraint): array => [$constraint->conname => $constraint->definition]
        );

        $this->assertStringContainsString('credit_balance >=', $definitions->get('partners_credit_balance_non_negative'));
        $this->assertStringContainsString('payable_balance >=', $definitions->get('partners_payable_balance_non_negative'));
    }

    public function test_partner_credit_balance_constraint_rejects_direct_negative_writes_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres CHECK constraint enforcement is required');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Both,
        ]);

        $this->expectException(QueryException::class);

        DB::table('partners')
            ->where('id', $partner->id)
            ->update(['credit_balance' => '-0.001']);
    }

    public function test_partner_payable_balance_constraint_rejects_direct_negative_writes_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres CHECK constraint enforcement is required');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Both,
        ]);

        $this->expectException(QueryException::class);

        DB::table('partners')
            ->where('id', $partner->id)
            ->update(['payable_balance' => '-0.001']);
    }
}
