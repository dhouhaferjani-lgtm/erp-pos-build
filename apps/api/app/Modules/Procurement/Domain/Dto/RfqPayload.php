<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class RfqPayload
{
    public function __construct(
        public string $groupId,
        public ?string $validityDate,
        public ?string $supplierReference,
        public ?int $leadTimeDays,
        public ?string $responseRecordedAt,
        public ?string $sentAt,
        public ?string $closedReason,
    ) {}

    /**
     * @param  array{rfq?: array<string, int|string|null>}  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rfq = $payload['rfq'] ?? [];
        $groupId = $rfq['group_id'] ?? null;

        if (! is_string($groupId) || $groupId === '') {
            throw new \InvalidArgumentException('RFQ payload requires rfq.group_id.');
        }

        return new self(
            groupId: $groupId,
            validityDate: self::nullableString($rfq, 'validity_date'),
            supplierReference: self::nullableString($rfq, 'supplier_reference'),
            leadTimeDays: self::nullableInt($rfq, 'lead_time_days'),
            responseRecordedAt: self::nullableString($rfq, 'response_recorded_at'),
            sentAt: self::nullableString($rfq, 'sent_at'),
            closedReason: self::nullableString($rfq, 'closed_reason'),
        );
    }

    /**
     * @return array{rfq: array{group_id: string, validity_date: string|null, supplier_reference: string|null, lead_time_days: int|null, response_recorded_at: string|null, sent_at: string|null, closed_reason: string|null}}
     */
    public function toArray(): array
    {
        return [
            'rfq' => [
                'group_id' => $this->groupId,
                'validity_date' => $this->validityDate,
                'supplier_reference' => $this->supplierReference,
                'lead_time_days' => $this->leadTimeDays,
                'response_recorded_at' => $this->responseRecordedAt,
                'sent_at' => $this->sentAt,
                'closed_reason' => $this->closedReason,
            ],
        ];
    }

    /**
     * @param  array<string, int|string|null>  $payload
     */
    private static function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, int|string|null>  $payload
     */
    private static function nullableInt(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
