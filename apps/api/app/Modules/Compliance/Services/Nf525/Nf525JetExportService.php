<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services\Nf525;

use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use Illuminate\Support\Carbon;

/**
 * Service for exporting NF525 JET (Journal des Evenements Techniques) XML.
 *
 * After H3: this service consumes a Nf525ExportSnapshot from the POS-side
 * provider via Nf525DataProviderContract and hands it to the private XML
 * builder. It has zero direct knowledge of POS Eloquent models.
 *
 * Byte stability of the output XML is locked by
 * `tests/Feature/Compliance/Nf525ExportSnapshotTest.php`.
 */
final class Nf525JetExportService
{
    public function __construct(
        private readonly Nf525XmlBuilder $xmlBuilder,
        private readonly Nf525DataProviderContract $dataProvider,
    ) {}

    /**
     * Export JET XML for a company within a date range.
     *
     * @param  string  $companyId  Company UUID
     * @param  Carbon  $from  Start date (inclusive)
     * @param  Carbon  $to  End date (inclusive)
     * @return string XML string
     */
    public function exportJet(string $companyId, Carbon $from, Carbon $to): string
    {
        $snapshot = $this->dataProvider->buildExportSnapshot($companyId, $from, $to);

        $headerData = [
            'company_name' => $snapshot->company->name,
            'company_id' => $snapshot->company->id,
            'siret' => $snapshot->company->siret,
            'address' => $snapshot->company->address,
            'software_name' => 'AutoERP',
            'software_version' => (string) config('app.version', '1.0.0'),
            'certification_number' => (string) config('compliance.nf525_certification_number', ''),
            'period_start' => $snapshot->periodStart,
            'period_end' => $snapshot->periodEnd,
            'export_date' => Carbon::now()->toIso8601String(),
        ];

        $this->xmlBuilder->createDocument()
            ->addHeader($headerData)
            ->addReceipts($snapshot->sales)
            ->addVoids($snapshot->voidedReceipts)
            ->addReturns($snapshot->returnReceipts)
            ->addReprints($snapshot->reprints)
            ->addZReports($snapshot->zReports)
            ->addGrandTotals($snapshot->grandTotals)
            ->addCashDrawer($snapshot->cashDrawerOperations)
            ->addTechnicalEvents($snapshot->shifts)
            ->addTerminalEvents($snapshot->terminalLifecycleEvents)
            ->addTrainingMode($snapshot->trainingCounts)
            ->addHashChains($snapshot->terminals);

        return $this->xmlBuilder->toString();
    }

    /**
     * Export JET XML to a file.
     */
    public function exportJetToFile(string $companyId, Carbon $from, Carbon $to, string $outputPath): void
    {
        $xml = $this->exportJet($companyId, $from, $to);
        file_put_contents($outputPath, $xml);
    }
}
