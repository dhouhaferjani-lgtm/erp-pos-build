<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a Z report (end-of-day closing) is generated.
 *
 * NF525 RAPPORT_Z event - Z reports are fiscally critical closings
 * that are hash-chained for compliance verification.
 */
final class ZReportGenerated extends DomainEvent
{
    public function __construct(
        public readonly string $zReportId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly int $zNumber,
        public readonly string $fiscalHash,
        public readonly string $generatedAt,
    ) {
        parent::__construct($zReportId);
    }

    public function getEventName(): string
    {
        return 'z_report.generated';
    }
}
