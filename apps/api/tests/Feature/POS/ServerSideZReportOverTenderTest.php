<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReportCount;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ServerSideZReportOverTenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_side_z_report_cash_expected_subtracts_receipt_change_due_once(): void
    {
        $this->assertCashExpectedSubtractsReceiptChangeDue('CASH', 'CASH');
    }

    public function test_server_side_z_report_treats_lowercase_cash_code_as_cash(): void
    {
        $this->assertCashExpectedSubtractsReceiptChangeDue('cash', 'cash');
    }

    public function test_server_side_z_report_does_not_subtract_change_due_from_legacy_net_cash_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($company->id);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        $this->seedCashReceipt($terminal, $cashier, $cashMethod, '', 1, '10.000', '10.000', '10.000', 1);

        /** @var ReportGenerationService $service */
        $service = $this->app->make(ReportGenerationService::class);
        $zReport = $service->generateZReport(
            $terminal,
            $cashier,
            [new CashCountInputDTO(
                paymentMethodId: $cashMethod->id,
                currencyCode: 'EUR',
                actualAmount: '10.0000',
            )],
        );

        $count = ZReportCount::where('z_report_id', $zReport->id)
            ->where('payment_method_id', $cashMethod->id)
            ->first();

        $this->assertNotNull($count);
        $this->assertSame('10.0000', $count->expected_amount);
        $this->assertSame('10.0000', $count->actual_amount);
        $this->assertSame('0.0000', $count->variance_amount);
    }

    private function assertCashExpectedSubtractsReceiptChangeDue(string $methodCode, string $paymentMethodCode): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($company->id);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => $methodCode,
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        $this->seedCashReceipt($terminal, $cashier, $cashMethod, $paymentMethodCode, 1, '50.000', '50.000', '0.000', 1);
        $this->seedCashReceipt($terminal, $cashier, $cashMethod, $paymentMethodCode, 2, '50.000', '120.000', '70.000', 2);

        /** @var ReportGenerationService $service */
        $service = $this->app->make(ReportGenerationService::class);
        $zReport = $service->generateZReport(
            $terminal,
            $cashier,
            [new CashCountInputDTO(
                paymentMethodId: $cashMethod->id,
                currencyCode: 'EUR',
                actualAmount: '100.0000',
            )],
        );

        $count = ZReportCount::where('z_report_id', $zReport->id)
            ->where('payment_method_id', $cashMethod->id)
            ->first();

        $this->assertNotNull($count);
        $this->assertSame('100.0000', $count->expected_amount);
        $this->assertSame('100.0000', $count->actual_amount);
        $this->assertSame('0.0000', $count->variance_amount);
    }

    private function seedCashReceipt(
        Terminal $terminal,
        User $cashier,
        PaymentMethod $cashMethod,
        string $paymentMethodCode,
        int $sequence,
        string $total,
        string $tendered,
        string $changeDue,
        int $postedAtOffsetMinutes,
    ): void {
        $receipt = Receipt::create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', $sequence),
            'chain_sequence' => $sequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'fiscal-'.$sequence),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.$sequence),
            'payment_methods_hash' => hash('sha256', 'pay-'.$sequence),
            'posted_at' => Carbon::now()->subHour()->addMinutes($postedAtOffsetMinutes),
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'change_due' => $changeDue,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $cashMethod->id,
            'payment_type' => 'CASH',
            'payment_method_code' => $paymentMethodCode,
            'amount' => $tendered,
        ]);
    }
}
