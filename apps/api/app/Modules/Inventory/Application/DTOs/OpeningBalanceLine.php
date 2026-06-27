<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

final readonly class OpeningBalanceLine
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $locationId,
        public string $quantity,
        public string $unitCost,
    ) {}

    public static function make(
        string $productId,
        ?string $variantId,
        string $locationId,
        string $quantity,
        string $unitCost,
        int $currencyScale,
    ): self {
        self::assertUnsignedDecimal($quantity, 4, 'quantity');
        self::assertUnsignedDecimal($unitCost, $currencyScale, 'unitCost');

        return new self(
            $productId,
            $variantId,
            $locationId,
            CurrencyScale::bcformatStrict($quantity, 4),
            CurrencyScale::bcformatStrict($unitCost, $currencyScale),
        );
    }

    private static function assertUnsignedDecimal(string $value, int $maxScale, string $field): void
    {
        if (preg_match('/^\d+(\.\d{1,'.$maxScale.'})?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Opening {$field} must be a non-negative decimal with at most {$maxScale} places, got: {$value}",
            );
        }
    }
}
