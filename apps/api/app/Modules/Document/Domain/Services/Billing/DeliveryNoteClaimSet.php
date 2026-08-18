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
     * The private constructor blocks direct `new`, but PHP cannot make this
     * public factory visible to only the billing claim service. The app/ PHPStan
     * rule and structural audit gate literal static calls outside that service;
     * dynamic/non-literal dispatch and calls outside app/ remain uncovered.
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
