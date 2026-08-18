<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;

final class DeliveryNoteBillingState
{
    public function __construct(
        public readonly ?string $invoiced_at,
        public readonly ?string $invoice_id,
        public readonly ?DeliveryNoteBillingLane $invoiced_via,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $invoicedAt = isset($payload['invoiced_at']) ? (string) $payload['invoiced_at'] : null;
        $invoiceId = isset($payload['invoice_id']) ? (string) $payload['invoice_id'] : null;
        $invoicedVia = isset($payload['invoiced_via']) && is_string($payload['invoiced_via'])
            ? DeliveryNoteBillingLane::tryFrom($payload['invoiced_via'])
            : null;

        if ($invoicedAt !== null && $invoicedVia === null) {
            $invoicedVia = DeliveryNoteBillingLane::LegacyUnknown;
        }

        return new self($invoicedAt, $invoiceId, $invoicedVia);
    }

    /** @return array{invoiced_at: string|null, invoice_id: string|null, invoiced_via: string|null} */
    public function toPayloadPatch(): array
    {
        return [
            'invoiced_at' => $this->invoiced_at,
            'invoice_id' => $this->invoice_id,
            'invoiced_via' => $this->invoiced_via?->value,
        ];
    }
}
