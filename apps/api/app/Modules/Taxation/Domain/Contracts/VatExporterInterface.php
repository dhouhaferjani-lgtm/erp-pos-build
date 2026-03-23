<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface VatExporterInterface
{
    public function supports(VatExportFormat $format): bool;

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse;

    public function getContentType(): string;

    public function getFilename(VatPeriod $period): string;
}
