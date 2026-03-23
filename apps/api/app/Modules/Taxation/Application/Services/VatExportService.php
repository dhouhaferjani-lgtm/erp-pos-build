<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * VAT Export Service
 *
 * Application service for exporting VAT reports in various formats.
 * Delegates to the appropriate exporter based on the requested format.
 */
class VatExportService
{
    /** @var VatExporterInterface[] */
    private array $exporters;

    /**
     * @param  VatExporterInterface[]  $exporters
     */
    public function __construct(array $exporters = [])
    {
        $this->exporters = $exporters;
    }

    /**
     * Export a VAT report in the specified format.
     *
     * @throws \DomainException When no exporter supports the requested format
     */
    public function export(
        VatSummary $summary,
        VatDeclarationData $declaration,
        VatExportFormat $format,
        VatPeriod $period,
    ): StreamedResponse {
        $exporter = $this->resolveExporter($format);

        return $exporter->export($summary, $declaration);
    }

    /**
     * Get the filename for an export.
     *
     * @throws \DomainException When no exporter supports the requested format
     */
    public function getFilename(VatExportFormat $format, VatPeriod $period): string
    {
        $exporter = $this->resolveExporter($format);

        return $exporter->getFilename($period);
    }

    /**
     * Get the content type for an export format.
     *
     * @throws \DomainException When no exporter supports the requested format
     */
    public function getContentType(VatExportFormat $format): string
    {
        $exporter = $this->resolveExporter($format);

        return $exporter->getContentType();
    }

    /**
     * Check if a format is supported.
     */
    public function supportsFormat(VatExportFormat $format): bool
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($format)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all supported export formats.
     *
     * @return VatExportFormat[]
     */
    public function getSupportedFormats(): array
    {
        $formats = [];
        foreach (VatExportFormat::cases() as $format) {
            if ($this->supportsFormat($format)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * Resolve the exporter for a given format.
     *
     * @throws \DomainException When no exporter supports the format
     */
    private function resolveExporter(VatExportFormat $format): VatExporterInterface
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($format)) {
                return $exporter;
            }
        }

        throw new \DomainException("No exporter available for format: {$format->value}");
    }
}
