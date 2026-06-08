<?php

declare(strict_types=1);

namespace App\Modules\Cart\Domain\Models;

use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $cart_id
 * @property string|null $product_id
 * @property string|null $platform_article_id
 * @property string|null $article_number
 * @property string $article_name
 * @property string|null $supplier_brand
 * @property string $quantity
 * @property string|null $unit_price
 * @property string|null $currency
 * @property CartItemSource $source
 * @property string|null $marketplace_listing_id
 * @property string|null $preferred_supplier_partner_id
 * @property string|null $reservation_id
 * @property Carbon|null $reservation_expires_at
 * @property string|null $notes
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CatalogCart $cart
 * @property-read Product|null $product
 * @property-read MarketplaceListing|null $marketplaceListing
 */
class CatalogCartItem extends Model
{
    use HasUuids;

    protected $table = 'catalog_cart_items';

    /** @var list<string> */
    protected $fillable = [
        'cart_id',
        'product_id',
        'platform_article_id',
        'article_number',
        'article_name',
        'supplier_brand',
        'quantity',
        'unit_price',
        'currency',
        'source',
        'marketplace_listing_id',
        'preferred_supplier_partner_id',
        'reservation_id',
        'reservation_expires_at',
        'notes',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => CartItemSource::class,
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:3',
            'sort_order' => 'integer',
            'reservation_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogCart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(CatalogCart::class, 'cart_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<MarketplaceListing, $this> */
    public function marketplaceListing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }
}
