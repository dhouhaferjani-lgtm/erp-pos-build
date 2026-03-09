<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

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
        'min_quantity' => 'decimal:2',
        'max_quantity' => 'decimal:2',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalog\Domain\Product::class);
    }

    public function matchesQuantity(string $quantity): bool
    {
        if (bccomp($quantity, $this->min_quantity, 2) < 0) {
            return false;
        }

        if ($this->max_quantity !== null && bccomp($quantity, $this->max_quantity, 2) > 0) {
            return false;
        }

        return true;
    }
}
