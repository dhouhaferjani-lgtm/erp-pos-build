<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Emitted when a Quote document has been generated for the WorkOrder
 * (status Diagnosed → Quoted). `estimated_grand_total` is a scale-preserving
 * numeric-string (never float) in `currency`.
 */
final readonly class WorkOrderQuoted
{
    public function __construct(
        public string $work_order_id,
        public string $quote_document_id,
        public string $estimated_grand_total,
        public string $currency,
        public \DateTimeImmutable $quoted_at,
    ) {}
}
