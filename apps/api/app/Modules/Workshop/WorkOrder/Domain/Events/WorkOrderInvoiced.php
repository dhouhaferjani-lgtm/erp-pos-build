<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Emitted after the Invoice document has been drafted, numbered, and posted
 * (status Completed → Invoiced). `invoice_document_id` points at the posted
 * fiscal document row.
 */
final readonly class WorkOrderInvoiced
{
    public function __construct(
        public string $work_order_id,
        public string $invoice_document_id,
        public \DateTimeImmutable $invoiced_at,
    ) {}
}
