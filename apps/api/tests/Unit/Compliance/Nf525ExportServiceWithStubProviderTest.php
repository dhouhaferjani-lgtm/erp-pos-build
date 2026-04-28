<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use App\Modules\Compliance\Services\Nf525\Nf525XmlBuilder;
use App\Shared\Contracts\Compliance\DTOs\Nf525ChainVerificationResult;
use App\Shared\Contracts\Compliance\DTOs\Nf525CompanyHeaderData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ExportSnapshot;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogFilter;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogPage;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit test — Compliance's NF525 export pipeline can run end-to-end against
 * a pure in-process stub of the data-provider contract, with zero database
 * and zero POS Domain knowledge. If this test runs without booting Laravel
 * and without instantiating any POS class, the architectural goal of H3 is
 * achieved.
 */
class Nf525ExportServiceWithStubProviderTest extends TestCase
{
    public function test_compliance_can_render_xml_using_only_a_contract_stub(): void
    {
        $stub = new class implements Nf525DataProviderContract
        {
            public function buildExportSnapshot(string $companyId, Carbon $from, Carbon $to): Nf525ExportSnapshot
            {
                return new Nf525ExportSnapshot(
                    company: new Nf525CompanyHeaderData(
                        id: $companyId,
                        name: 'Stub Company',
                        siret: null,
                        address: null,
                    ),
                    periodStart: $from->toDateString(),
                    periodEnd: $to->toDateString(),
                    sales: [],
                    voidedReceipts: [],
                    returnReceipts: [],
                    reprints: [],
                    zReports: [],
                    grandTotals: [],
                    cashDrawerOperations: [],
                    shifts: [],
                    terminalLifecycleEvents: [],
                    trainingCounts: [],
                    terminals: [],
                );
            }

            public function listTerminalsForCompany(string $companyId): array
            {
                return [];
            }

            public function verifyReceiptChain(string $terminalId): Nf525ChainVerificationResult
            {
                return new Nf525ChainVerificationResult(true, 0, 0, null, null);
            }

            public function verifyZReportChain(string $terminalId): Nf525ChainVerificationResult
            {
                return new Nf525ChainVerificationResult(true, 0, 0, null, null);
            }

            public function fetchReprintLog(Nf525ReprintLogFilter $filter): Nf525ReprintLogPage
            {
                return new Nf525ReprintLogPage(rows: [], currentPage: 1, lastPage: 1, perPage: $filter->perPage, total: 0);
            }
        };

        Carbon::setTestNow(Carbon::parse('2026-04-28T12:00:00+00:00'));

        $service = new Nf525JetExportService(
            xmlBuilder: new Nf525XmlBuilder,
            dataProvider: $stub,
        );

        $xml = $service->exportJet(
            'stub-company-id',
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
        );

        $this->assertStringContainsString('<JET', $xml);
        $this->assertStringContainsString('Stub Company', $xml);
        $this->assertStringContainsString('<DateExport>2026-04-28T12:00:00+00:00</DateExport>', $xml);
        $this->assertStringContainsString('<Tickets type="TICKET" count="0"', $xml);

        Carbon::setTestNow();
    }
}
