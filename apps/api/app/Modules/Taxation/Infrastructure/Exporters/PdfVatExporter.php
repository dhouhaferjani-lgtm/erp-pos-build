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

/**
 * PDF VAT Exporter.
 *
 * Renders an HTML summary of the VAT report. When a PDF library (e.g., DomPDF)
 * is available, this will pipe the HTML through it. Currently outputs HTML
 * with Content-Type application/pdf for download — a PDF library must be
 * integrated for production use.
 */
class PdfVatExporter implements VatExporterInterface
{
    public function supports(VatExportFormat $format): bool
    {
        return $format === VatExportFormat::Pdf;
    }

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse
    {
        $html = $this->renderHtml($summary, $declaration);

        $callback = static function () use ($html): void {
            echo $html;
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => $this->getContentType(),
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function getContentType(): string
    {
        return 'application/pdf';
    }

    public function getFilename(VatPeriod $period): string
    {
        $start = $period->period_start->format('Ymd');
        $end = $period->period_end->format('Ymd');

        return "vat_report_{$period->country_code}_{$start}_{$end}.pdf";
    }

    private function renderHtml(VatSummary $summary, VatDeclarationData $declaration): string
    {
        $outputRows = $this->renderBreakdownRows($summary->outputBreakdowns);
        $inputRows = $this->renderBreakdownRows($summary->inputBreakdowns);

        $declarationFields = '';
        foreach ($declaration->fields as $key => $value) {
            $label = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
            $val = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $declarationFields .= "<tr><td>{$label}</td><td style=\"text-align:right\">{$val}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VAT Report</title>
<style>
body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
h1 { font-size: 18px; margin-bottom: 5px; }
h2 { font-size: 14px; margin-top: 20px; margin-bottom: 5px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 15px; }
th, td { border: 1px solid #333; padding: 4px 8px; text-align: left; }
th { background-color: #f0f0f0; }
td.num { text-align: right; }
.totals { font-weight: bold; background-color: #f9f9f9; }
</style>
</head>
<body>
<h1>VAT Report — {$declaration->formReference}</h1>

<h2>Output VAT (Collected)</h2>
<table>
<thead><tr><th>Rate</th><th>Base HT</th><th>VAT Amount</th><th>Documents</th></tr></thead>
<tbody>{$outputRows}</tbody>
<tfoot><tr class="totals"><td colspan="2">Total Output VAT</td><td class="num">{$summary->totalOutputVat}</td><td></td></tr></tfoot>
</table>

<h2>Input VAT (Deductible)</h2>
<table>
<thead><tr><th>Rate</th><th>Base HT</th><th>VAT Amount</th><th>Documents</th></tr></thead>
<tbody>{$inputRows}</tbody>
<tfoot><tr class="totals"><td colspan="2">Total Input VAT</td><td class="num">{$summary->totalInputVat}</td><td></td></tr></tfoot>
</table>

<h2>Declaration Fields ({$declaration->formReference})</h2>
<table>
<thead><tr><th>Field</th><th>Value</th></tr></thead>
<tbody>{$declarationFields}</tbody>
</table>

</body>
</html>
HTML;
    }

    /**
     * @param  VatAggregation[]  $breakdowns
     */
    private function renderBreakdownRows(array $breakdowns): string
    {
        $rows = '';
        foreach ($breakdowns as $breakdown) {
            $rate = htmlspecialchars($breakdown->taxRate, ENT_QUOTES, 'UTF-8');
            $base = htmlspecialchars($breakdown->baseAmount, ENT_QUOTES, 'UTF-8');
            $vat = htmlspecialchars($breakdown->vatAmount, ENT_QUOTES, 'UTF-8');
            $count = $breakdown->documentCount;
            $rows .= "<tr><td>{$rate}%</td><td class=\"num\">{$base}</td><td class=\"num\">{$vat}</td><td class=\"num\">{$count}</td></tr>";
        }

        return $rows;
    }
}
