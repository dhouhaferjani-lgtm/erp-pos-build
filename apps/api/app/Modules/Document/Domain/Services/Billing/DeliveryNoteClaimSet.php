<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;

final readonly class DeliveryNoteClaimSet
{
    /** @param list<string> $deliveryNoteIds */
    private function __construct(
        public array $deliveryNoteIds,
        public string $companyId,
        public DeliveryNoteBillingLane $invoicedVia,
        public string $invoicedAt,
    ) {}

    /**
     * @internal Reserved claim sets are issued only by the billing claim service.
     *
     * @param  list<string>  $deliveryNoteIds
     */
    public static function fromReservation(
        array $deliveryNoteIds,
        string $companyId,
        DeliveryNoteBillingLane $invoicedVia,
        string $invoicedAt,
    ): self {
        return new self($deliveryNoteIds, $companyId, $invoicedVia, $invoicedAt);
    }

    public function count(): int
    {
        return count($this->deliveryNoteIds);
    }
}
