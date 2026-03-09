<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use App\Modules\Company\Domain\Company;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\MarketplaceSellerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property SellerType $seller_type
 * @property SellerStatus $seller_status
 * @property string $display_name
 * @property string $country_code
 * @property string $currency
 * @property string $commission_rate
 * @property string $total_gmv
 * @property int $total_orders
 * @property int $total_items_sold
 * @property string $gmv_current_month
 * @property int $orders_current_month
 * @property string|null $average_rating
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property array<string, mixed>|null $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketplaceListing> $listings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketplaceOrder> $orders
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BuyerSellerMapping> $buyerSellerMappings
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forCountry(string $countryCode)
 */
class MarketplaceSeller extends Model
{
    /** @use HasFactory<MarketplaceSellerFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'marketplace_sellers';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'seller_type',
        'seller_status',
        'display_name',
        'country_code',
        'currency',
        'commission_rate',
        'total_gmv',
        'total_orders',
        'total_items_sold',
        'gmv_current_month',
        'orders_current_month',
        'average_rating',
        'last_sync_at',
        'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seller_type' => SellerType::class,
            'seller_status' => SellerStatus::class,
            'commission_rate' => 'decimal:2',
            'total_gmv' => 'decimal:3',
            'total_orders' => 'integer',
            'total_items_sold' => 'integer',
            'gmv_current_month' => 'decimal:3',
            'orders_current_month' => 'integer',
            'average_rating' => 'decimal:2',
            'last_sync_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function newFactory(): MarketplaceSellerFactory
    {
        return MarketplaceSellerFactory::new();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<MarketplaceListing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'seller_id');
    }

    /** @return HasMany<MarketplaceOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(MarketplaceOrder::class, 'seller_id');
    }

    /** @return HasMany<BuyerSellerMapping, $this> */
    public function buyerSellerMappings(): HasMany
    {
        return $this->hasMany(BuyerSellerMapping::class, 'seller_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('seller_status', SellerStatus::Active);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }
}
