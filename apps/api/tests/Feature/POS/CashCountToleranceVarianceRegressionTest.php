<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 / Task 11 — cash-variance integrity in the presence of tolerance writeoffs.
 *
 * The v1.1 interface contract resolved cash-variance to:
 *
 *   expected_cash = opening_float
 *                 + Σ(pos_receipt_payments.amount for cash payments)
 *                 − Σ(change_due)
 *                 − Σ(refunds)
 *
 * Crucially, the formula does NOT subtract any tolerance term. The "amount"
 * column already stores what the cashier physically tendered, so a €100 receipt
 * with €99.70 tender + €0.30 tolerance write-off contributes +€99.70 to drawer
 * cash — exactly what the cashier expects to count. Adding a tolerance
 * subtraction would silently understate the expected cash and turn a balanced
 * drawer into a phantom shortage.
 *
 * Regression: with one normal receipt and one tolerance receipt in the shift,
 * counting exactly the tendered total must produce VarianceSeverity::Info
 * (balanced), proving the formula is drawer-cash-only.
 */
final class CashCountToleranceVarianceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_severity_is_balanced_when_actual_cash_matches_tendered_total_with_tolerance_receipts(): void
    {
        [$service, $terminal, $cashier, $cashMethod, $shift] = $this->scaffold();

        // Receipt #1: normal, €50 receipt, €50 tendered, no tolerance.
        $this->seedCashReceipt(
            terminal: $terminal,
            cashier: $cashier,
            cashMethodId: $cashMethod->id,
            sequence: 1,
            total: '50.000',
            tendered: '50.000',
            toleranceWriteoff: null,
            postedAtOffsetMinutes: 1,
        );

        // Receipt #2: tolerance short-pay, €100 receipt, €99.700 tendered, €0.300 written off to GL 658.
        $this->seedCashReceipt(
            terminal: $terminal,
            cashier: $cashier,
            cashMethodId: $cashMethod->id,
            sequence: 2,
            total: '100.000',
            tendered: '99.700',
            toleranceWriteoff: '0.300',
            postedAtOffsetMinutes: 2,
        );

        // The validator's expectedPerMethod sums pos_receipt_payments.amount only
        // (opening cash is shift-level, not per-tender). So the cashier's per-tender
        // count is the sum of what was tendered for that method:
        //   50.000 + 99.700 = 149.700.
        // If anyone subtracted the 0.300 tolerance, expected would slip to 149.400
        // and an exact 149.700 count would register as +0.300 over (Warning/Critical).
        $expectedActual = '149.7000';

        $z = $service->generateZReport(
            $terminal,
            $cashier,
            [new CashCountInputDTO(
                paymentMethodId: $cashMethod->id,
                currencyCode: 'EUR',
                actualAmount: $expectedActual,
            )],
        );

        /** @var array<string, mixed> $reportData */
        $reportData = $z->report_data;

        // The aggregate variance is balanced when expected = actual.
        $this->assertArrayHasKey('variance_summary', $reportData);
        $variance = $reportData['variance_summary'];
        $this->assertSame('0.0000', $variance['aggregate_amount']);
        $this->assertSame(VarianceSeverity::Info->value, $variance['severity']);

        // And the per-tender expected matches the *tendered* total — proving the
        // expected-cash query summed pos_receipt_payments.amount (not receipt.total).
        $cashRow = collect($reportData['cash_counts'])
            ->firstWhere('payment_method_id', $cashMethod->id);
        $this->assertNotNull($cashRow);
        $this->assertSame('149.7000', $cashRow['expected_amount']);
    }

    /**
     * @return array{0: ReportGenerationService, 1: Terminal, 2: User, 3: PaymentMethod, 4: Shift}
     */
    private function scaffold(): array
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
        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash' => '100.0000',
        ]);
        $cash = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'is_physical' => true,
        ]);

        /** @var ReportGenerationService $service */
        $service = $this->app->make(ReportGenerationService::class);

        return [$service, $terminal, $cashier, $cash, $shift];
    }

    private function seedCashReceipt(
        Terminal $terminal,
        User $cashier,
        string $cashMethodId,
        int $sequence,
        string $total,
        string $tendered,
        ?string $toleranceWriteoff,
        int $postedAtOffsetMinutes,
    ): Receipt {
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
            'change_due' => '0.000',
            'tolerance_writeoff' => $toleranceWriteoff,
            'is_voided' => false,
            'is_training' => false,
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $cashMethodId,
            'payment_type' => 'CASH',
            'amount' => $tendered,
        ]);

        return $receipt;
    }
}
