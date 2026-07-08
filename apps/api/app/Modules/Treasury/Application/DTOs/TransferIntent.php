<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * Intent to move funds between two treasury repositories as a paired out/in leg.
 *
 * Consumed by the write port's `transfer()` method (Task 12).
 */
final readonly class TransferIntent
{
    /**
     * @param  string  $fromRepositoryId  UUID of the source PaymentRepository (out leg)
     * @param  string  $toRepositoryId  UUID of the destination PaymentRepository (in leg)
     * @param  string  $tenantId  UUID of the owning tenant
     * @param  string  $companyId  UUID of the owning company
     * @param  numeric-string  $amount  positive decimal at currency scale (never float)
     * @param  string  $transferGroupId  Caller-assigned id shared by both legs, used for idempotency
     * @param  ?string  $journalEntryId  UUID of the GL journal entry this transfer is tied to, if any
     * @param  ?string  $createdBy  UUID of the acting user, if any
     */
    public function __construct(
        public string $fromRepositoryId,
        public string $toRepositoryId,
        public string $tenantId,
        public string $companyId,
        public string $amount,
        public string $currency,
        public string $transferGroupId,
        public ?string $journalEntryId,
        public ?CarbonImmutable $occurredAt,
        public ?string $createdBy,
        public ?string $notes,
    ) {}
}
