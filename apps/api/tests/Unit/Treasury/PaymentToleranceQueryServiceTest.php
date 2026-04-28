<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentBreakdownDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentReceiptDTO;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PaymentToleranceQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentToleranceQueryService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentToleranceQueryService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    public function test_total_for_shift_sums_tolerance_writeoffs_across_receipts(): void
    {
        // 3 receipts: writeoffs = [0.020, 0.050, 0.000]
        // Only the two non-zero rows should be summed and counted.
        $shiftId = $this->seedShiftWithTolerance([
            ['amount' => '0.020', 'cashier' => 'alice'],
            ['amount' => '0.050', 'cashier' => 'alice'],
            ['amount' => '0.000', 'cashier' => 'alice'],
        ]);

        $totals = $this->service->totalForShift($shiftId);

        $this->assertSame('0.070', $totals->totalAmount);
        $this->assertSame(2, $totals->writeoffCount);
        $this->assertSame('EUR', $totals->currencyCode);
    }

    public function test_breakdown_for_shift_groups_by_cashier(): void
    {
        $shiftId = $this->seedShiftWithTolerance([
            ['amount' => '0.020', 'cashier' => 'alice'],
            ['amount' => '0.050', 'cashier' => 'bob'],
            ['amount' => '0.030', 'cashier' => 'alice'],
        ]);

        $breakdown = $this->service->breakdownForShift($shiftId);

        $this->assertCount(2, $breakdown);
        foreach ($breakdown as $row) {
            $this->assertInstanceOf(TolerancePaymentBreakdownDTO::class, $row);
        }

        $alice = $this->findRow($breakdown, 'alice');
        $this->assertNotNull($alice);
        $this->assertSame('0.050', $alice->totalAmount);
        $this->assertSame(2, $alice->writeoffCount);
        $this->assertSame('EUR', $alice->currencyCode);

        $bob = $this->findRow($breakdown, 'bob');
        $this->assertNotNull($bob);
        $this->assertSame('0.050', $bob->totalAmount);
        $this->assertSame(1, $bob->writeoffCount);
    }

    public function test_receipts_with_tolerance_returns_drilldown(): void
    {
        // Seed at distinct ascending posted_at values so the asc-by-posted_at
        // ordering can be observed: 0.020 first (earliest), then 0.050.
        // Seeder spaces receipts one minute apart via `posted_at = now-1h + N min`.
        $shiftId = $this->seedShiftWithTolerance([
            ['amount' => '0.020', 'cashier' => 'alice'],
            ['amount' => '0.050', 'cashier' => 'bob'],
            // a zero-tolerance row should be filtered out of the drill-down
            ['amount' => '0.000', 'cashier' => 'alice'],
            // a NULL-tolerance row should also be filtered out
            ['amount' => null, 'cashier' => 'alice'],
        ]);

        $receipts = $this->service->receiptsWithToleranceForShift($shiftId);

        $this->assertCount(2, $receipts);
        foreach ($receipts as $row) {
            $this->assertInstanceOf(TolerancePaymentReceiptDTO::class, $row);
        }
        $writeoffs = array_map(static fn (TolerancePaymentReceiptDTO $r): string => $r->writeoffAmount, $receipts);
        $this->assertContains('0.020', $writeoffs);
        $this->assertContains('0.050', $writeoffs);

        // Ordering: earliest posted_at first.
        $this->assertSame('0.020', $receipts[0]->writeoffAmount, 'receipts must be ordered by posted_at ascending');
        $this->assertSame('0.050', $receipts[1]->writeoffAmount);

        // Format: occurredAt is ISO-8601 UTC (e.g. "2026-04-25T14:32:11Z").
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $receipts[0]->occurredAt,
            'occurredAt must be ISO-8601 UTC with trailing Z',
        );
    }

    public function test_zero_tolerance_shift_returns_empty_totals(): void
    {
        $shiftId = $this->seedShiftWithTolerance([]);

        $totals = $this->service->totalForShift($shiftId);

        $this->assertSame('0.000', $totals->totalAmount);
        $this->assertSame(0, $totals->writeoffCount);
        // Invariant: an empty-shift result always carries a non-empty currency code
        // (defaulted to EUR), so consumers like ReportGenerationService can read
        // currencyCode directly without falling back to a sibling field.
        $this->assertSame('EUR', $totals->currencyCode);
    }

    public function test_training_receipts_are_excluded_from_totals_breakdown_and_drilldown(): void
    {
        // Mixed shift: 2 production receipts + 2 training receipts, all with
        // non-null tolerance_writeoff > 0. Training rows must be excluded from
        // every public method to match the codebase convention enforced in
        // ReportGenerationService::calculateShiftTotals, VerifyPosChainCommand,
        // and GrandtotalService.
        $shiftId = $this->seedShiftWithTolerance([
            ['amount' => '0.020', 'cashier' => 'alice', 'is_training' => false],
            ['amount' => '0.030', 'cashier' => 'bob', 'is_training' => false],
            ['amount' => '0.500', 'cashier' => 'alice', 'is_training' => true],
            ['amount' => '0.700', 'cashier' => 'bob', 'is_training' => true],
        ]);

        $totals = $this->service->totalForShift($shiftId);
        $this->assertSame('0.050', $totals->totalAmount, 'training receipts must not contribute to totals');
        $this->assertSame(2, $totals->writeoffCount, 'training receipts must not be counted');

        $breakdown = $this->service->breakdownForShift($shiftId);
        $this->assertCount(2, $breakdown, 'breakdown must include only production cashiers');
        foreach ($breakdown as $row) {
            $this->assertSame(1, $row->writeoffCount, 'each cashier should have exactly one production receipt');
        }
        $alice = $this->findRow($breakdown, 'alice');
        $this->assertNotNull($alice);
        $this->assertSame('0.020', $alice->totalAmount);
        $bob = $this->findRow($breakdown, 'bob');
        $this->assertNotNull($bob);
        $this->assertSame('0.030', $bob->totalAmount);

        $receipts = $this->service->receiptsWithToleranceForShift($shiftId);
        $this->assertCount(2, $receipts, 'drill-down must exclude training receipts');
        $writeoffs = array_map(static fn (TolerancePaymentReceiptDTO $r): string => $r->writeoffAmount, $receipts);
        $this->assertContains('0.020', $writeoffs);
        $this->assertContains('0.030', $writeoffs);
        $this->assertNotContains('0.500', $writeoffs, 'training writeoff 0.500 leaked into drill-down');
        $this->assertNotContains('0.700', $writeoffs, 'training writeoff 0.700 leaked into drill-down');
    }

    /**
     * Seed a shift on the test terminal with N receipts whose
     * tolerance_writeoff values are taken from $rows.
     *
     * Each row: ['amount' => string|null, 'cashier' => string, 'is_training' => bool?].
     *
     * Returns the shift UUID.
     *
     * @param  list<array{amount: string|null, cashier: string, is_training?: bool}>  $rows
     */
    private function seedShiftWithTolerance(array $rows): string
    {
        // One arbitrary cashier owns the shift; per-receipt cashier_id may differ
        // (multi-cashier shifts are legal in the contract — see breakdownForShift).
        $shiftOwner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'shift-owner',
        ]);

        $shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $shiftOwner->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now()->subHours(2),
            'tolerance_writeoff_total' => '0.000',
            'tolerance_writeoff_count' => 0,
        ]);

        // Memoise users by name so multiple receipts for the same cashier
        // share a single users row (group-by tests depend on this).
        /** @var array<string, User> $usersByName */
        $usersByName = [];

        $sequence = 0;
        foreach ($rows as $row) {
            $cashierName = $row['cashier'];
            if (! isset($usersByName[$cashierName])) {
                $usersByName[$cashierName] = User::factory()->create([
                    'tenant_id' => $this->tenant->id,
                    'name' => $cashierName,
                ]);
            }
            $cashier = $usersByName[$cashierName];

            $sequence++;
            Receipt::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'terminal_id' => $this->terminal->id,
                'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', $sequence),
                'chain_sequence' => $sequence,
                'receipt_year' => 2026,
                'fiscal_hash' => hash('sha256', 'fiscal-'.$sequence),
                'previous_hash' => null,
                'vat_breakdown_hash' => hash('sha256', 'vat-'.$sequence),
                'payment_methods_hash' => hash('sha256', 'pay-'.$sequence),
                'posted_at' => Carbon::now()->subHour()->addMinutes($sequence),
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'subtotal' => '10.000',
                'tax_amount' => '0.000',
                'total' => '10.000',
                'currency' => 'EUR',
                'tolerance_writeoff' => $row['amount'],
                'is_voided' => false,
                'is_training' => $row['is_training'] ?? false,
            ]);
        }

        return $shift->id;
    }

    /**
     * @param  array<int, TolerancePaymentBreakdownDTO>  $rows
     */
    private function findRow(array $rows, string $userName): ?TolerancePaymentBreakdownDTO
    {
        foreach ($rows as $row) {
            if ($row->userName === $userName) {
                return $row;
            }
        }

        return null;
    }
}
