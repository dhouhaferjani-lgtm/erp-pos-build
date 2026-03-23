<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Support\Carbon;

interface VatReportStrategyInterface
{
    public function getDefaultPeriodType(): VatPeriodType;

    /** @return array<int, array{label: string, period_start: string, period_end: string, period_type: string}> */
    public function generatePeriods(int $fiscalYearStartMonth, int $year): array;

    public function mapToDeclaration(VatSummary $summary): VatDeclarationData;

    /** @return VatExportFormat[] */
    public function getSupportedExportFormats(): array;

    /** @return array<string, string|int|float> */
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array;

    /** @return string[] */
    public function getExpectedRates(): array;
}
