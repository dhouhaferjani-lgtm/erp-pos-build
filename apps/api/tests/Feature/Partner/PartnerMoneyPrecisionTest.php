<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
