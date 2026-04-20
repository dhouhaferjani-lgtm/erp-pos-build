<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $listing_id
 * @property string|null $article_number
 * @property string $article_name
 * @property string|null $supplier_brand
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MarketplaceOrder $order
 * @property-read MarketplaceListing $listing
 */
class MarketplaceOrderLine extends Model
{
    use HasUuids;

    protected $table = 'marketplace_order_lines';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'listing_id',
        'article_number',
        'article_name',
        'supplier_brand',
        'quantity',
        'unit_price',
        'line_total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:3',
            'line_total' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<MarketplaceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'order_id');
    }

    /** @return BelongsTo<MarketplaceListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }
}
