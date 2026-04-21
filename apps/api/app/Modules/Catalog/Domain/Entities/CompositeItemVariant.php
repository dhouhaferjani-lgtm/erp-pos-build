<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\PriceAdjustmentType;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $composite_item_id
 * @property string $code
 * @property string $name
 * @property PriceAdjustmentType $price_adjustment_type
 * @property string $price_adjustment
 * @property string $recipe_multiplier
 * @property bool $is_default
 * @property bool $is_active
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CompositeItem $compositeItem
 */
class CompositeItemVariant extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'price_adjustment_type' => 'absolute',
        'price_adjustment' => '0.0000',
        'recipe_multiplier' => '1.0000',
        'is_default' => false,
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'composite_item_id',
        'code',
        'name',
        'price_adjustment_type',
        'price_adjustment',
        'recipe_multiplier',
        'is_default',
        'is_active',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_adjustment_type' => PriceAdjustmentType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<CompositeItem, $this>
     */
    public function compositeItem(): BelongsTo
    {
        return $this->belongsTo(CompositeItem::class);
    }

    // -- Domain Methods --

    /**
     * Calculate the final price for this variant based on the base price.
     */
    public function calculatePrice(string $basePrice): string
    {
        $adjustment = CurrencyScale::bcformat($this->price_adjustment, 4);
        /** @phpstan-var numeric-string $adjustment */
        /** @var numeric-string $base */
        $base = $basePrice;

        return match ($this->price_adjustment_type) {
            PriceAdjustmentType::Absolute => bcadd($base, $adjustment, 4),
            PriceAdjustmentType::Percentage => bcadd(
                $base,
                bcdiv(bcmul($base, $adjustment, 4), '100', 4),
                4
            ),
            PriceAdjustmentType::Override => $adjustment,
        };
    }
}
