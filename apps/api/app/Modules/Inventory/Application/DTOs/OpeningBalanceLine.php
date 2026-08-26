<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class OpeningBalanceLine
{
    /**
     * @param  numeric-string  $quantity  Quantity at scale 4 — validated + formatted by make().
     * @param  numeric-string  $unitCost  Unit cost at currencyScale — validated + formatted by make().
     * @param  ?string  $expiryDate  Y-m-d expiry the operator supplied for THIS opening stock,
     *                               or null when none was given. Null is not "no expiry rule" —
     *                               the posting service still honours the product's configured
     *                               `default_shelf_life_days`; it means "the file said nothing",
     *                               and with no shelf life either the lot is minted undated
     *                               rather than with an invented date (W4-1).
     */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $locationId,
        public string $quantity,
        public string $unitCost,
        public ?string $expiryDate = null,
    ) {}

    public static function make(
        string $productId,
        ?string $variantId,
        string $locationId,
        string $quantity,
        string $unitCost,
        int $currencyScale,
        ?string $expiryDate = null,
    ): self {
        self::assertUnsignedDecimal($quantity, 4, 'quantity');
        self::assertUnsignedDecimal($unitCost, $currencyScale, 'unitCost');

        return new self(
            $productId,
            $variantId,
            $locationId,
            CurrencyScale::bcformatStrict($quantity, 4),
            CurrencyScale::bcformatStrict($unitCost, $currencyScale),
            self::normalizeExpiryDate($expiryDate),
        );
    }

    /**
     * Accept a blank cell as "not supplied" and pin the stored shape to Y-m-d,
     * so a lot's expiry can never be a half-parsed string from a spreadsheet.
     */
    private static function normalizeExpiryDate(?string $expiryDate): ?string
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return null;
        }

        $trimmed = trim($expiryDate);

        // hasFormat() BEFORE parsing: Carbon 3 throws on a malformed value rather
        // than returning false, and a raw InvalidFormatException out of a DTO
        // constructor would surface to the importer as an unlabelled 500. The
        // parsed result is then re-checked because createFromFormat is typed
        // nullable — a null here would silently drop the operator's expiry.
        $parsed = CarbonImmutable::hasFormat($trimmed, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('Y-m-d', $trimmed)
            : null;

        if ($parsed === null) {
            throw new InvalidArgumentException(
                "Opening expiryDate must be a Y-m-d date, got: {$expiryDate}",
            );
        }

        return $parsed->toDateString();
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
