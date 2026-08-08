<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class StockLevelData extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public ?string $product_name,
        public string $location_id,
        public ?string $location_name,
        public string $quantity,
        public string $reserved,
        public string $available,
        public string $incoming,
        public string $projected_available,
        public ?string $min_quantity,
        public ?string $max_quantity,
        public bool $is_below_minimum,
        public int $quantity_decimals,
        // DPA V7 / T19. TWO fields, deliberately, because D1b part 2 decoupled
        // the two lot rules: `requires_batch_tracking` drives the REASON filter
        // (damage/write_off are refused on a batch-tracked product, D7a), while
        // `has_lots_at_location` drives the LOT-REQUIRED rule, which is
        // flag-INDEPENDENT. Without the second field the frontend cannot tell
        // door 1 (flag true, zero lots -> lot optional, the pharmacy onboarding
        // case) from the normal case, and could only discover
        // BATCH_REQUIRED_FOR_LINE by 422.
        public bool $requires_batch_tracking,
        public bool $has_lots_at_location,
    ) {}

    /**
     * @param  array<string, bool>|null  $lotPresenceByKey  Pre-resolved
     *                                                      "(product|location) => has a lot with stock here" map. Pass it when
     *                                                      rendering a LIST: without it every row issues its own EXISTS, so a page
     *                                                      of 20 costs 20 extra round-trips (gate code-review M-3).
     */
    public static function fromModel(
        StockLevel $stockLevel,
        string $incoming = '0.00',
        ?array $lotPresenceByKey = null,
    ): self {
        /** @var numeric-string $available */
        $available = $stockLevel->getAvailableQuantity();
        /** @var numeric-string $incoming */
        $projectedAvailable = bcadd($available, $incoming, 4);

        return new self(
            id: $stockLevel->id,
            product_id: $stockLevel->product_id,
            product_name: $stockLevel->product->name ?? null,
            location_id: $stockLevel->location_id,
            location_name: $stockLevel->location->name ?? null,
            quantity: (string) $stockLevel->quantity,
            reserved: (string) $stockLevel->reserved,
            available: $available,
            incoming: $incoming,
            projected_available: $projectedAvailable,
            min_quantity: $stockLevel->min_quantity !== null ? (string) $stockLevel->min_quantity : null,
            max_quantity: $stockLevel->max_quantity !== null ? (string) $stockLevel->max_quantity : null,
            is_below_minimum: $stockLevel->isBelowMinimum(),
            quantity_decimals: self::resolveQuantityDecimals($stockLevel),
            requires_batch_tracking: self::resolveRequiresBatchTracking($stockLevel),
            has_lots_at_location: $lotPresenceByKey !== null
                ? ($lotPresenceByKey[self::lotPresenceKey($stockLevel->product_id, $stockLevel->location_id)] ?? false)
                : self::resolveHasLotsAtLocation($stockLevel),
        );
    }

    private static function resolveRequiresBatchTracking(StockLevel $stockLevel): bool
    {
        if (! $stockLevel->relationLoaded('product')) {
            return false;
        }

        $product = $stockLevel->getRelation('product');

        return $product instanceof Product && $product->requires_batch_tracking;
    }

    /** The key shape used by {@see self::lotPresenceMapFor()}. */
    public static function lotPresenceKey(string $productId, string $locationId): string
    {
        return $productId.'|'.$locationId;
    }

    /**
     * Resolve lot presence for a whole page in ONE query (gate M-3).
     *
     * @param  iterable<StockLevel>  $stockLevels
     * @return array<string, bool>
     */
    public static function lotPresenceMapFor(iterable $stockLevels): array
    {
        $productIds = [];
        $locationIds = [];

        foreach ($stockLevels as $stockLevel) {
            $productIds[$stockLevel->product_id] = true;
            $locationIds[$stockLevel->location_id] = true;
        }

        /** @var list<string> $productIdList */
        $productIdList = array_keys($productIds);
        /** @var list<string> $locationIdList */
        $locationIdList = array_keys($locationIds);

        if ($productIdList === [] || $locationIdList === []) {
            return [];
        }

        /** @var list<object{product_id: string, location_id: string}> $rows */
        $rows = DB::table('inventory_batch_stock')
            ->join('product_batches', 'product_batches.id', '=', 'inventory_batch_stock.batch_id')
            ->whereIn('product_batches.product_id', $productIdList)
            ->whereIn('inventory_batch_stock.location_id', $locationIdList)
            ->where('inventory_batch_stock.quantity', '>', 0)
            ->select('product_batches.product_id', 'inventory_batch_stock.location_id')
            ->distinct()
            ->get()
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[self::lotPresenceKey((string) $row->product_id, (string) $row->location_id)] = true;
        }

        return $map;
    }

    /**
     * "This product has at least one lot holding stock at THIS location."
     *
     * Flag-independent by design (D1b part 2): the flag is user-toggleable and
     * nothing deletes BatchStock on the reverse flip, so a lot can hold stock
     * while the flag says otherwise. Naming the lot is required by what the lots
     * actually say, not by what the flag currently says.
     *
     * NOT variant-aware: it matches every lot of the product. That is consistent
     * with today's product-level-only authoring surface, but it will report
     * `true` for a variant-bearing line whose OWN variant holds no lot once
     * variants are surfaced (gate code-review M-4) — the follow-up that adds
     * `variant_id` to the authoring UI owns narrowing this too.
     */
    private static function resolveHasLotsAtLocation(StockLevel $stockLevel): bool
    {
        return BatchStock::query()
            ->where('location_id', $stockLevel->location_id)
            ->where('quantity', '>', 0)
            ->whereIn('batch_id', Batch::query()->where('product_id', $stockLevel->product_id)->select('id'))
            ->exists();
    }

    /**
     * Derive the product's unit precision (decimal_places) so the UI steps the
     * quantity by the unit. Falls back to the canonical storage scale (4) when
     * the product.unitOfMeasure chain is not eager-loaded.
     */
    private static function resolveQuantityDecimals(StockLevel $stockLevel): int
    {
        if (! $stockLevel->relationLoaded('product')) {
            return 4;
        }

        // getRelation() (not the typed `->product` accessor) so the null case is
        // visible to PHPStan: Product uses SoftDeletes, so the loaded relation is
        // null when the product was archived — guarding here avoids a 500.
        $product = $stockLevel->getRelation('product');

        if (! $product instanceof Product
            || ! $product->relationLoaded('unitOfMeasure')
            || $product->unitOfMeasure === null) {
            return 4;
        }

        return $product->unitOfMeasure->decimal_places;
    }
}
