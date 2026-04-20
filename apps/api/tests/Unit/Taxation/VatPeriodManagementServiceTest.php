<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Application\DTOs\VatPeriodData;
use App\Modules\Taxation\Application\DTOs\VatSummaryData;
use App\Modules\Taxation\Application\Services\VatPeriodManagementService;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Domain\Events\VatPeriodClosed;
use App\Modules\Taxation\Domain\Events\VatPeriodFiled;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use App\Modules\Taxation\Domain\Services\VatCreditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class VatPeriodManagementServiceTest extends TestCase
{
    private VatPeriodRepositoryInterface&MockObject $periodRepository;

    private VatReportGenerationService&MockObject $reportService;

    private VatCreditService $creditService;

    private VatPeriodManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodRepository = $this->createMock(VatPeriodRepositoryInterface::class);
        $this->reportService = $this->createMock(VatReportGenerationService::class);
        $this->creditService = new VatCreditService;

        $this->service = new VatPeriodManagementService(
            $this->periodRepository,
            $this->reportService,
            $this->creditService,
        );
    }

    public function test_close_period_sets_status_and_snapshots(): void
    {
        Event::fake([VatPeriodClosed::class]);

        $period = $this->makeOpenPeriod();

        $summaryData = $this->makeSummaryData('500.000', '200.000');

        $this->reportService
            ->expects($this->once())
            ->method('generateSummary')
            ->with(
                $period->company_id,
                $period->country_code,
                $period->period_start->toDateString(),
                $period->period_end->toDateString()
            )
            ->willReturn($summaryData);

        // Previous period has zero credit
        $this->periodRepository
            ->expects($this->once())
            ->method('findPreviousPeriod')
            ->with($period)
            ->willReturn(null);

        $this->periodRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $id, array $data) use ($period): VatPeriod {
                $this->assertSame($period->id, $id);
                $this->assertSame(VatPeriodStatus::Closed, $data['status']);
                $this->assertSame('500.000', $data['total_output_vat']);
                $this->assertSame('200.000', $data['total_input_vat']);
                $this->assertSame('300.000', $data['net_vat']);
                $this->assertSame('300.000', $data['amount_payable']);
                $this->assertSame('0.000', $data['credit_brought_forward']);
                $this->assertSame('0.000', $data['credit_carried_forward']);
                $this->assertNotNull($data['closed_at']);

                // Simulate returned updated period
                $period->status = VatPeriodStatus::Closed;
                $period->total_output_vat = $data['total_output_vat'];
                $period->total_input_vat = $data['total_input_vat'];
                $period->net_vat = $data['net_vat'];
                $period->amount_payable = $data['amount_payable'];
                $period->credit_brought_forward = $data['credit_brought_forward'];
                $period->credit_carried_forward = $data['credit_carried_forward'];
                $period->closed_at = $data['closed_at'];
                $period->closed_by = $data['closed_by'];

                return $period;
            });

        $result = $this->service->closePeriod($period, 'Test close', 'user-123');

        $this->assertInstanceOf(VatPeriodData::class, $result);

        Event::assertDispatched(VatPeriodClosed::class, function (VatPeriodClosed $event) use ($period): bool {
            return $event->period->id === $period->id;
        });
    }

    public function test_close_rejects_non_open_period(): void
    {
        $period = $this->makeOpenPeriod();
        $period->status = VatPeriodStatus::Closed;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only open periods can be closed');

        $this->service->closePeriod($period, null, 'user-123');
    }

    public function test_reopen_blocked_when_successor_is_closed(): void
    {
        $period = $this->makeOpenPeriod();
        $period->status = VatPeriodStatus::Closed;

        $this->periodRepository
            ->expects($this->once())
            ->method('hasClosedOrFiledSuccessor')
            ->with($period)
            ->willReturn(true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot reopen: a successor period is already closed or filed');

        $this->service->reopenPeriod($period);
    }

    public function test_reopen_deletes_breakdowns_and_clears_totals(): void
    {
        $period = $this->makeOpenPeriod();
        $period->status = VatPeriodStatus::Closed;
        $period->total_output_vat = '500.000';
        $period->total_input_vat = '200.000';
        $period->net_vat = '300.000';
        $period->amount_payable = '300.000';

        $this->periodRepository
            ->expects($this->once())
            ->method('hasClosedOrFiledSuccessor')
            ->with($period)
            ->willReturn(false);

        $this->periodRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $id, array $data) use ($period): VatPeriod {
                $this->assertSame($period->id, $id);
                $this->assertSame(VatPeriodStatus::Open, $data['status']);
                $this->assertNull($data['total_output_vat']);
                $this->assertNull($data['total_input_vat']);
                $this->assertNull($data['net_vat']);
                $this->assertNull($data['closed_at']);
                $this->assertNull($data['closed_by']);

                $period->status = VatPeriodStatus::Open;
                $period->total_output_vat = null;
                $period->total_input_vat = null;
                $period->net_vat = null;
                $period->closed_at = null;
                $period->closed_by = null;

                return $period;
            });

        $result = $this->service->reopenPeriod($period);

        $this->assertInstanceOf(VatPeriodData::class, $result);
    }

    public function test_file_rejects_open_period(): void
    {
        $period = $this->makeOpenPeriod();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only closed periods can be filed');

        $this->service->filePeriod($period, null, 'user-123');
    }

    public function test_file_sets_filed_status_and_dispatches_event(): void
    {
        Event::fake([VatPeriodFiled::class]);

        $period = $this->makeOpenPeriod();
        $period->status = VatPeriodStatus::Closed;
        $period->total_output_vat = '500.000';
        $period->total_input_vat = '200.000';
        $period->net_vat = '300.000';
        $period->amount_payable = '300.000';

        $this->periodRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $id, array $data) use ($period): VatPeriod {
                $this->assertSame($period->id, $id);
                $this->assertSame(VatPeriodStatus::Filed, $data['status']);
                $this->assertSame('REF-2026-001', $data['filing_reference']);
                $this->assertNotNull($data['filed_at']);
                $this->assertSame('user-456', $data['filed_by']);

                $period->status = VatPeriodStatus::Filed;
                $period->filing_reference = $data['filing_reference'];
                $period->filed_at = $data['filed_at'];
                $period->filed_by = $data['filed_by'];

                return $period;
            });

        $result = $this->service->filePeriod($period, 'REF-2026-001', 'user-456');

        $this->assertInstanceOf(VatPeriodData::class, $result);

        Event::assertDispatched(VatPeriodFiled::class, function (VatPeriodFiled $event) use ($period): bool {
            return $event->period->id === $period->id;
        });
    }

    /**
     * Create a mock open VatPeriod
     */
    private function makeOpenPeriod(): VatPeriod
    {
        $period = new VatPeriod;
        $period->id = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $period->company_id = '11111111-2222-3333-4444-555555555555';
        $period->country_code = 'TN';
        $period->period_type = VatPeriodType::Monthly;
        $period->label = 'January 2026';
        $period->period_start = Carbon::parse('2026-01-01');
        $period->period_end = Carbon::parse('2026-01-31');
        $period->status = VatPeriodStatus::Open;
        $period->credit_brought_forward = '0.000';
        $period->credit_carried_forward = '0.000';
        $period->amount_payable = '0.000';

        return $period;
    }

    /**
     * Create a VatSummaryData for testing
     */
    private function makeSummaryData(string $totalOutputVat, string $totalInputVat): VatSummaryData
    {
        $outputBreakdowns = [
            new VatAggregation(
                direction: 'OUTPUT',
                taxRate: '19.00',
                baseAmount: '2631.579',
                vatAmount: $totalOutputVat,
                documentCount: 5,
                isRecoverable: false,
            ),
        ];

        $inputBreakdowns = [
            new VatAggregation(
                direction: 'INPUT',
                taxRate: '19.00',
                baseAmount: '1052.632',
                vatAmount: $totalInputVat,
                documentCount: 3,
                isRecoverable: true,
            ),
        ];

        $vatSummary = new VatSummary(
            outputBreakdowns: $outputBreakdowns,
            inputBreakdowns: $inputBreakdowns,
            totalOutputVat: $totalOutputVat,
            totalInputVat: $totalInputVat,
        );

        $declaration = new VatDeclarationData(
            fields: ['total_output_vat' => $totalOutputVat],
            formReference: 'DGI',
        );

        return VatSummaryData::fromDomainObjects(
            vatSummary: $vatSummary,
            declaration: $declaration,
            specialItems: [],
            creditBroughtForward: '0.000',
            creditCarriedForward: '0.000',
            netVat: '300.000',
            amountPayable: '300.000',
        );
    }
}
