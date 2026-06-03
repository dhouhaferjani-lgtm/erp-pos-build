<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'price_list_id',
        'product_id',
        'price',
        'min_quantity',
        'max_quantity',
    ];

    protected $casts = [
        'price' => 'decimal:3',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
    ];

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function matchesQuantity(string $quantity): bool
    {
        /** @var numeric-string $quantity */
        /** @var numeric-string $minQty */
        $minQty = $this->min_quantity;
        if (bccomp($quantity, $minQty, 2) < 0) {
            return false;
        }

        /** @var numeric-string|null $maxQty */
        $maxQty = $this->max_quantity;
        if ($maxQty !== null && bccomp($quantity, $maxQty, 2) > 0) {
            return false;
        }

        return true;
    }
}
