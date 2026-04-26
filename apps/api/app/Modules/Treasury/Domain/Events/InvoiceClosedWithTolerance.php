<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;
use DateTimeImmutable;

/**
 * Event raised when a B2B invoice is closed with a payment tolerance write-off.
 *
 * The remaining balance was within the per-country tolerance threshold and was
 * written off to GL 658 (PaymentToleranceExpense). This event is immutable from
 * v1 (Rule #8) and carries enough context for downstream auditors / reports to
 * trace the close action without re-querying the journal entry.
 *
 * Non-fiscal: tolerance write-offs are accounting adjustments, not VAT-reducing
 * fiscal events. They are logged to the audit chain (TimescaleDB) only — the
 * SHA-256 fiscal chain is untouched.
 */
final class InvoiceClosedWithTolerance extends DomainEvent
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $amountWrittenOff,
        public readonly string $currency,
        public readonly string $glEntryId,
        public readonly string $closedBy,
        public readonly DateTimeImmutable $occurredAtTimestamp,
    ) {
        parent::__construct($invoiceId);
    }

    public function getEventName(): string
    {
        return 'invoice.closed_with_tolerance';
    }

    /**
     * @return array<string, string>
     */
    public function getHashableData(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'company_id' => $this->companyId,
            'partner_id' => $this->partnerId,
            'amount_written_off' => $this->amountWrittenOff,
            'currency' => $this->currency,
            'gl_entry_id' => $this->glEntryId,
            'closed_by' => $this->closedBy,
            'occurred_at' => $this->occurredAtTimestamp->format(DATE_ATOM),
        ];
    }
}
