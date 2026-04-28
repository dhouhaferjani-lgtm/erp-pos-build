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
use App\Modules\POS\Presentation\Resources\ZReportResource;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZReportResourceWithCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fully resolve a ZReportResource to a plain PHP array (including nested resources).
     *
     * @return array<string, mixed>
     */
    private function resolveResource(ZReport $zReport): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) json_encode((new ZReportResource($zReport))->jsonSerialize()),
            true,
        );

        return $data;
    }

    public function test_counts_collection_serialises_all_three_tenders(): void
    {
        [$zReport] = $this->scaffoldZWithThreeCounts();

        $zReport->load('counts.paymentMethod', 'shift.managerOverride');

        $data = $this->resolveResource($zReport);

        $this->assertCount(3, $data['counts']);
    }

    public function test_each_count_has_required_fields(): void
    {
        [$zReport] = $this->scaffoldZWithThreeCounts();

        $zReport->load('counts.paymentMethod', 'shift.managerOverride');
        $data = $this->resolveResource($zReport);

        foreach ($data['counts'] as $count) {
            $this->assertArrayHasKey('payment_method_id', $count);
            $this->assertArrayHasKey('expected_amount', $count);
            $this->assertArrayHasKey('actual_amount', $count);
            $this->assertArrayHasKey('variance_amount', $count);
            $this->assertArrayHasKey('variance_direction', $count);
            $this->assertArrayHasKey('transaction_count', $count);
            $this->assertIsInt($count['transaction_count']);
            $this->assertIsString($count['expected_amount']);
            $this->assertIsString($count['actual_amount']);
            $this->assertIsString($count['variance_amount']);
        }
    }

    public function test_payment_method_code_and_name_resolve_when_loaded(): void
    {
        [$zReport, $pmCash] = $this->scaffoldZWithThreeCounts();

        $zReport->load('counts.paymentMethod', 'shift.managerOverride');
        $data = $this->resolveResource($zReport);

        $cashCount = collect($data['counts'])
            ->first(fn (array $c) => $c['payment_method_id'] === $pmCash->id);

        $this->assertNotNull($cashCount);
        $this->assertSame('CASH', $cashCount['payment_method_code']);
    }

    public function test_variance_severity_reflects_shift_value(): void
    {
        [$zReport] = $this->scaffoldZWithThreeCounts();

        $zReport->load('counts.paymentMethod', 'shift.managerOverride');
        $data = $this->resolveResource($zReport);

        $this->assertSame('warning', $data['variance_severity']);
    }

    public function test_blind_count_used_is_bool(): void
    {
        [$zReport] = $this->scaffoldZWithThreeCounts();

        $zReport->load('counts.paymentMethod', 'shift.managerOverride');
        $data = $this->resolveResource($zReport);

        $this->assertIsBool($data['blind_count_used']);
        $this->assertTrue($data['blind_count_used']);
    }

    public function test_counts_absent_when_relation_not_loaded(): void
    {
        [$zReport] = $this->scaffoldZWithThreeCounts();

        // Do NOT load counts — resource must not trigger N+1.
        // jsonSerialize() omits MissingValue keys, so 'counts' must be absent.
        $data = $this->resolveResource($zReport);

        $this->assertArrayNotHasKey('counts', $data);
    }

    /**
     * Build a Z report with 3 payment method counts (cash, card, voucher).
     * The shift is configured with blind_count_used = true and variance_severity = 'warning'.
     *
     * @return array{0:ZReport,1:PaymentMethod,2:PaymentMethod,3:PaymentMethod}
     */
    private function scaffoldZWithThreeCounts(): array
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
            'blind_count_used' => true,
            'variance_severity' => 'warning',
            'notes' => 'Cashier reported slight shortage',
        ]);

        $zReport = ZReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => hash('sha256', 'z-report-resource-test'),
            'previous_z_hash' => null,
            'report_data' => [
                'sales_count' => 10,
                'gross_sales' => '1200.00',
                'variance_summary' => [
                    'aggregate_amount' => '-5.0000',
                    'aggregate_direction' => 'under',
                    'severity' => 'warning',
                    'currency_code' => 'EUR',
                ],
                'tolerance_summary' => null,
            ],
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);

        $pmCash = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
        ]);

        $pmCard = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CARD',
            'name' => 'Credit Card',
            'is_physical' => false,
        ]);

        $pmVoucher = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'VOUCHER',
            'name' => 'Voucher',
            'is_physical' => false,
        ]);

        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pmCash->id,
            'currency_code' => 'EUR',
            'expected_amount' => '800.0000',
            'actual_amount' => '795.0000',
            'variance_amount' => '-5.0000',
            'variance_direction' => 'under',
            'transaction_count' => 5,
        ]);

        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pmCard->id,
            'currency_code' => 'EUR',
            'expected_amount' => '300.0000',
            'actual_amount' => '300.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 4,
        ]);

        ZReportCount::create([
            'z_report_id' => $zReport->id,
            'payment_method_id' => $pmVoucher->id,
            'currency_code' => 'EUR',
            'expected_amount' => '100.0000',
            'actual_amount' => '100.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 1,
        ]);

        return [$zReport, $pmCash, $pmCard, $pmVoucher];
    }
}
