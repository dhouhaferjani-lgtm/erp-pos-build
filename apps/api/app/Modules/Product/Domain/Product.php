<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\SellableContract;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $sku
 * @property ProductType|null $type
 * @property string|null $description
 * @property string|null $sale_price
 * @property string|null $purchase_price
 * @property string|null $tax_rate
 * @property string|null $unit
 * @property string|null $barcode
 * @property bool $is_active
 * @property bool $is_active_for_ecommerce
 * @property array<int, string>|null $oem_numbers
 * @property array<int, array{brand: string, reference: string}>|null $cross_references
 * @property string $cost_price
 * @property string|null $target_margin_override
 * @property string|null $minimum_margin_override
 * @property string|null $last_purchase_cost
 * @property \Illuminate\Support\Carbon|null $cost_updated_at
 * @property bool $is_physical False for services, true for parts/consumables
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $company_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $unit_id
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Unit|null $unitOfMeasure
 * @property-read ParapharmacyProductMetadata|null $parapharmacyMetadata
 * @property-read AutomotiveProductMetadata|null $automotiveMetadata
 */
class Product extends Model implements SellableContract
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'category_id',
        'name',
        'sku',
        'type',
        'is_physical',
        'description',
        'sale_price',
        'purchase_price',
        'tax_rate',
        'unit',
        'barcode',
        'is_active',
        'is_active_for_ecommerce',
        'oem_numbers',
        'cross_references',
        'cost_price',
        'target_margin_override',
        'minimum_margin_override',
        'last_purchase_cost',
        'cost_updated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_physical' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'is_active' => 'boolean',
            'is_active_for_ecommerce' => 'boolean',
            'is_physical' => 'boolean',
            'oem_numbers' => 'array',
            'cross_references' => 'array',
            'cost_updated_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\ProductFactory
    {
        return \Database\Factories\ProductFactory::new();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // -- SellableContract implementation --

    public function getSellableId(): string
    {
        return $this->id;
    }

    public function getSellableType(): string
    {
        return 'product';
    }

    public function getSellableName(): string
    {
        return $this->name;
    }

    public function getSellableBasePrice(): string
    {
        return (string) ($this->sale_price ?? '0');
    }

    public function getSellableUnit(): ?string
    {
        return $this->unit;
    }

    public function isStockTracked(): bool
    {
        return $this->is_physical;
    }

    public function isAvailable(): bool
    {
        return $this->is_active;
    }

    // -- Domain Methods --

    /**
     * Check if this product requires physical delivery.
     * Services are non-physical and don't require delivery notes.
     */
    public function isPhysical(): bool
    {
        return $this->is_physical;
    }

    /**
     * Scope a query to only include active products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include products for a specific tenant.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include products for a specific company.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get all images for this product, ordered by sort_order.
     *
     * @return HasMany<ProductImage>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->ordered();
    }

    /**
     * Get the primary image for this product.
     *
     * @return HasOne<ProductImage>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * Get the unit of measure for this product.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Get parapharmacy-specific metadata for this product.
     *
     * @return HasOne<ParapharmacyProductMetadata>
     */
    public function parapharmacyMetadata(): HasOne
    {
        return $this->hasOne(ParapharmacyProductMetadata::class);
    }

    /**
     * Get automotive-specific metadata for this product.
     *
     * @return HasOne<AutomotiveProductMetadata>
     */
    public function automotiveMetadata(): HasOne
    {
        return $this->hasOne(AutomotiveProductMetadata::class);
    }

    /**
     * Get all stock levels for this product across all locations.
     *
     * @return HasMany<\App\Modules\Inventory\Domain\StockLevel>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(\App\Modules\Inventory\Domain\StockLevel::class);
    }
}
