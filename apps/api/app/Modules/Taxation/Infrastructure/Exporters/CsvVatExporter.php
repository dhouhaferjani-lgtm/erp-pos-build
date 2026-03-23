<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Exporters;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvVatExporter implements VatExporterInterface
{
    public function supports(VatExportFormat $format): bool
    {
        return $format === VatExportFormat::Csv;
    }

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse
    {
        $callback = function () use ($summary): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // Header row
            fputcsv($handle, [
                'Rate',
                'Direction',
                'Base HT',
                'VAT Amount',
                'Document Count',
                'Is Recoverable',
            ], escape: '');

            // Output breakdowns
            foreach ($summary->outputBreakdowns as $breakdown) {
                $this->writeBreakdownRow($handle, $breakdown, 'Output');
            }

            // Input breakdowns
            foreach ($summary->inputBreakdowns as $breakdown) {
                $this->writeBreakdownRow($handle, $breakdown, 'Input');
            }

            fclose($handle);
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => $this->getContentType(),
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function getContentType(): string
    {
        return 'text/csv';
    }

    public function getFilename(VatPeriod $period): string
    {
        $start = $period->period_start->format('Ymd');
        $end = $period->period_end->format('Ymd');

        return "vat_report_{$period->country_code}_{$start}_{$end}.csv";
    }

    /**
     * @param  resource  $handle
     */
    private function writeBreakdownRow($handle, VatAggregation $breakdown, string $direction): void
    {
        fputcsv($handle, [
            $breakdown->taxRate,
            $direction,
            $breakdown->baseAmount,
            $breakdown->vatAmount,
            (string) $breakdown->documentCount,
            $breakdown->isRecoverable ? 'Yes' : 'No',
        ], escape: '');
    }
}
