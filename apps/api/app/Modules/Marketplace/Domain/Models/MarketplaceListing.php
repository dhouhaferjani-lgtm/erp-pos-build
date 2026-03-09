<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use Database\Factories\MarketplaceListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $seller_id
 * @property string $country_code
 * @property string|null $platform_article_id
 * @property string|null $article_number
 * @property string|null $barcode
 * @property string $product_name
 * @property string|null $supplier_brand
 * @property string|null $quality_tier
 * @property string $price
 * @property string $currency
 * @property string $quantity_available
 * @property string $min_order_quantity
 * @property ListingStatus $listing_status
 * @property string|null $source_product_id
 * @property \Illuminate\Support\Carbon|null $price_updated_at
 * @property \Illuminate\Support\Carbon|null $stock_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MarketplaceSeller $seller
 *
 * @method static Builder<static> active()
 * @method static Builder<static> available()
 * @method static Builder<static> forCountry(string $countryCode)
 * @method static Builder<static> forArticle(?string $platformArticleId, ?string $articleNumber, ?string $barcode)
 */
class MarketplaceListing extends Model
{
    /** @use HasFactory<MarketplaceListingFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'marketplace_listings';

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'country_code',
        'platform_article_id',
        'article_number',
        'barcode',
        'product_name',
        'supplier_brand',
        'quality_tier',
        'price',
        'currency',
        'quantity_available',
        'min_order_quantity',
        'listing_status',
        'source_product_id',
        'price_updated_at',
        'stock_updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'listing_status' => ListingStatus::class,
            'price' => 'decimal:3',
            'quantity_available' => 'decimal:2',
            'min_order_quantity' => 'decimal:2',
            'price_updated_at' => 'datetime',
            'stock_updated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MarketplaceListingFactory
    {
        return MarketplaceListingFactory::new();
    }

    /** @return BelongsTo<MarketplaceSeller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSeller::class, 'seller_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('listing_status', ListingStatus::Active);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('listing_status', ListingStatus::Active)
            ->where('quantity_available', '>', 0);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForArticle(
        Builder $query,
        ?string $platformArticleId = null,
        ?string $articleNumber = null,
        ?string $barcode = null,
    ): Builder {
        return $query->where(function (Builder $q) use ($platformArticleId, $articleNumber, $barcode): void {
            if ($platformArticleId !== null) {
                $q->orWhere('platform_article_id', $platformArticleId);
            }
            if ($articleNumber !== null) {
                $q->orWhere('article_number', $articleNumber);
            }
            if ($barcode !== null) {
                $q->orWhere('barcode', $barcode);
            }
        });
    }
}
