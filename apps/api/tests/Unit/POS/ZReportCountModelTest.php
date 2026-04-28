<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Domain\ZReportCount;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZReportCountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_z_report_count_row(): void
    {
        [, , , , , , $zReport, $pm] = $this->scaffoldZReportContext();

        $count = ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pm->id,
            'currency_code' => 'EUR',
            'expected_amount' => '830.0000',
            'actual_amount' => '830.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 3,
        ]);

        $this->assertSame('830.0000', $count->expected_amount);
        $this->assertSame('balanced', $count->variance_direction);
    }

    public function test_rejects_check_violation_variance_direction_mismatches_sign(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints only enforced on PostgreSQL');
        }

        [, , , , , , $zReport, $pm] = $this->scaffoldZReportContext();

        $this->expectException(QueryException::class);
        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pm->id,
            'currency_code' => 'EUR',
            'expected_amount' => '100.0000',
            'actual_amount' => '95.0000',
            'variance_amount' => '-5.0000',
            'variance_direction' => 'over', // wrong! sign says 'under'
            'transaction_count' => 1,
        ]);
    }

    public function test_rejects_check_violation_variance_equals_actual_minus_expected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints only enforced on PostgreSQL');
        }

        [, , , , , , $zReport, $pm] = $this->scaffoldZReportContext();

        $this->expectException(QueryException::class);
        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pm->id,
            'currency_code' => 'EUR',
            'expected_amount' => '100.0000',
            'actual_amount' => '95.0000',
            'variance_amount' => '-3.0000',  // wrong arithmetic
            'variance_direction' => 'under',
            'transaction_count' => 1,
        ]);
    }

    public function test_unique_constraint_prevents_duplicate_method_per_z_report(): void
    {
        [, , , , , , $zReport, $pm] = $this->scaffoldZReportContext();

        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pm->id,
            'currency_code' => 'EUR',
            'expected_amount' => '100.0000',
            'actual_amount' => '100.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 1,
        ]);

        $this->expectException(QueryException::class);
        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pm->id,
            'currency_code' => 'EUR',
            'expected_amount' => '200.0000',
            'actual_amount' => '200.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 1,
        ]);
    }

    /**
     * @return array{0:Tenant,1:Company,2:Location,3:User,4:Terminal,5:Shift,6:ZReport,7:PaymentMethod}
     */
    private function scaffoldZReportContext(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.0000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $zReport = ZReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => hash('sha256', 'z-report-1'),
            'previous_z_hash' => null,
            'report_data' => ['sales_count' => 3, 'gross_sales' => '830.00'],
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);

        $pm = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'is_physical' => true,
        ]);

        return [$tenant, $company, $location, $user, $terminal, $shift, $zReport, $pm];
    }
}
