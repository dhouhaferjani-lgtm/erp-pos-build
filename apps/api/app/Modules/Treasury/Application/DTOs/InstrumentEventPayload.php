<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\DishonorRouting;

final readonly class InstrumentEventPayload
{
    /**
     * @param  array<string, array{old: string|null, new: string|null}>|null  $detailsDiff
     * @param  numeric-string|null  $feeAmount
     * @param  numeric-string|null  $feeVatAmount
     */
    public function __construct(
        public ?array $detailsDiff = null,
        public ?DishonorRouting $dishonorRouting = null,
        public ?string $feeAmount = null,
        public ?string $feeVatAmount = null,
        public ?string $reason = null,
        public ?string $alertKey = null,
    ) {}

    /**
     * @return array{
     *     details_diff: array<string, array{old: string|null, new: string|null}>|null,
     *     dishonor_routing: string|null,
     *     fee_amount: numeric-string|null,
     *     fee_vat_amount: numeric-string|null,
     *     reason: string|null,
     *     alert_key: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'details_diff' => $this->detailsDiff,
            'dishonor_routing' => $this->dishonorRouting?->value,
            'fee_amount' => $this->feeAmount,
            'fee_vat_amount' => $this->feeVatAmount,
            'reason' => $this->reason,
            'alert_key' => $this->alertKey,
        ];
    }

    /**
     * @param  array<string, string|array<string, array{old: string|null, new: string|null}>|null>  $data
     */
    public static function fromArray(array $data): self
    {
        $routing = $data['dishonor_routing'] ?? null;

        return new self(
            detailsDiff: self::detailsDiff($data['details_diff'] ?? null),
            dishonorRouting: is_string($routing) ? DishonorRouting::tryFrom($routing) : null,
            feeAmount: self::numericString($data['fee_amount'] ?? null),
            feeVatAmount: self::numericString($data['fee_vat_amount'] ?? null),
            reason: self::nullableString($data['reason'] ?? null),
            alertKey: self::nullableString($data['alert_key'] ?? null),
        );
    }

    /**
     * @param  array<string, array{old: string|null, new: string|null}>|string|null  $value
     * @return array<string, array{old: string|null, new: string|null}>|null
     */
    private static function detailsDiff(string|array|null $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return $value;
    }

    /** @param array<string, array{old: string|null, new: string|null}>|string|null $value */
    private static function nullableString(string|array|null $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, array{old: string|null, new: string|null}>|string|null  $value
     * @return numeric-string|null
     */
    private static function numericString(string|array|null $value): ?string
    {
        return is_string($value) && is_numeric($value) ? $value : null;
    }
}
