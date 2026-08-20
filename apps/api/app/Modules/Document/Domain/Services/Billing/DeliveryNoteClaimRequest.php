<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Exceptions\InvalidDeliveryNoteClaimRequestException;

final readonly class DeliveryNoteClaimRequest
{
    /** @param list<string> $deliveryNoteIds */
    public function __construct(
        public array $deliveryNoteIds,
        public string $companyId,
        public DeliveryNoteBillingLane $invoicedVia,
    ) {
        if ($deliveryNoteIds === []) {
            throw new InvalidDeliveryNoteClaimRequestException('At least one delivery note id is required.');
        }

        if ($companyId === '') {
            throw new InvalidDeliveryNoteClaimRequestException('A company id is required.');
        }

        foreach ($deliveryNoteIds as $deliveryNoteId) {
            if ($deliveryNoteId === '') {
                throw new InvalidDeliveryNoteClaimRequestException('Delivery note ids must be non-empty strings.');
            }
        }

        if (count(array_unique($deliveryNoteIds)) !== count($deliveryNoteIds)) {
            throw new InvalidDeliveryNoteClaimRequestException('Delivery note ids must be unique.');
        }

        if ($invoicedVia === DeliveryNoteBillingLane::LegacyUnknown) {
            throw new InvalidDeliveryNoteClaimRequestException('legacy_unknown is reserved for migration backfill.');
        }
    }
}
