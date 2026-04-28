<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use App\Shared\Domain\Events\DomainEvent;

final class CashCountRecorded extends DomainEvent
{
    /**
     * @param  array<CashCountBreakdownDTO>  $tenderBreakdown
     * @param  array<string, string|int|float|bool|null>  $descriptionParams
     */
    public function __construct(
        public readonly string $zReportId,
        public readonly string $shiftId,
        public readonly string $terminalId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $cashierId,
        public readonly ?string $managerOverrideBy,
        public readonly bool $blindCountUsed,
        public readonly string $currencyCode,
        public readonly VarianceAmount $aggregateVariance,
        public readonly VarianceDirection $varianceDirection,
        public readonly VarianceSeverity $severity,
        public readonly array $tenderBreakdown,
        public readonly string $descriptionCode,
        public readonly array $descriptionParams,
        public readonly string $recordedAt,
    ) {
        parent::__construct($zReportId);
    }

    public function getEventName(): string
    {
        return 'pos.cash_count_recorded';
    }
}
