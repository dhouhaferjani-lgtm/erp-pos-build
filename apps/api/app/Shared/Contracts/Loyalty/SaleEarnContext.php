<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Loyalty;

use Carbon\CarbonInterface;

/**
 * Immutable, primitives-only snapshot of a completed sale needed to credit
 * loyalty points. Crosses the POS→Loyalty module boundary (rule 6) — carries
 * no Loyalty or POS model, only scalars. Money is a numeric-string (rule 19).
 */
final readonly class SaleEarnContext
{
    public function __construct(
        public string $tenantId,
        public ?string $contactId,
        public ?string $partnerId,
        public string $currency,
        public string $sourceType,
        public string $sourceId,
        public ?string $receiptNumber,
        public CarbonInterface $postedAt,
        public string $earnBase,
        /**
         * Sale line snapshot for Item/Category/Quantity earning rules —
         * list<array{product_id: string, quantity: string}>. Category is
         * resolved on the Loyalty side (the fiscal canonical payload carries no
         * category); price is deliberately omitted (no EarningRuleType reads it).
         *
         * Known limits (documented, not fixed — acceptable for the parapharmacy
         * launch, adversarial review MINOR-7):
         *  - `product_id` is the canonical LineItemDTO PARENT product id, so an
         *    Item rule keyed on a variant id never matches a device sale.
         *  - `PointEarningService::calculateItemPoints`/`getTotalQuantity`
         *    int-cast the quantity, truncating fractional quantities
         *    ("2.500" → 2) when awarding per-unit points.
         *
         * @var list<array{product_id: string, quantity: string}>
         */
        public array $items = [],
    ) {}
}
