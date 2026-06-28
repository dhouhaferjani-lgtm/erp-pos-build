<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\SellableContract;
use App\Shared\Enums\EnrichmentStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property string|null $default_tax_configuration_id
 * @property string|null $unit
 * @property string|null $barcode
 * @property bool $is_active
 * @property bool $is_active_for_ecommerce
 * @property bool $requires_batch_tracking
 * @property int|null $default_shelf_life_days
 * @property array<int, string>|null $oem_numbers
 * @property array<int, array{brand: string, reference: string}>|null $cross_references
 * @property string $cost_price
 * @property string|null $target_margin_override
 * @property string|null $minimum_margin_override
 * @property string|null $last_purchase_cost
 * @property Carbon|null $cost_updated_at
 * @property string|null $platform_product_id
 * @property string|null $platform_submission_id
 * @property EnrichmentStatus|null $enrichment_status
 * @property bool $is_physical False for services, true for parts/consumables
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $company_id
 * @property Carbon|null $deleted_at
 * @property string|null $unit_id
 * @property int|null $units_per_pack
 * @property string|null $shelf_location
 * @property string|null $reorder_point
 * @property string|null $reorder_quantity
 * @property string|null $brand_id
 * @property BrandSource|null $brand_source
 * @property-read Brand|null $brand
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Unit|null $unitOfMeasure
 * @property-read ParapharmacyProductMetadata|null $parapharmacyMetadata
 * @property-read AutomotiveProductMetadata|null $automotiveMetadata
 * @property-read EnrichmentResult|null $latestEnrichmentResult
 * @property-read Collection<int, EnrichmentResult> $enrichmentResults
 * @property-read Collection<int, ProductVariant> $activeVariants
 * @property-read int $active_variants_count Populated by withCount('activeVariants')
 */
class Product extends Model implements SellableContract
{
    /** @use HasFactory<ProductFactory> */
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
        'default_tax_configuration_id',
        'unit',
        'unit_id',
        'units_per_pack',
        'shelf_location',
        'reorder_point',
        'reorder_quantity',
        'barcode',
        'is_active',
        'is_active_for_ecommerce',
        'requires_batch_tracking',
        'default_shelf_life_days',
        'oem_numbers',
        'cross_references',
        'cost_price',
        'target_margin_override',
        'minimum_margin_override',
        'last_purchase_cost',
        'cost_updated_at',
        'platform_product_id',
        'platform_submission_id',
        'enrichment_status',
        'brand_id',
        'brand_source',
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
            'requires_batch_tracking' => 'boolean',
            'default_shelf_life_days' => 'integer',
            'is_physical' => 'boolean',
            'oem_numbers' => 'array',
            'cross_references' => 'array',
            'cost_updated_at' => 'datetime',
            'enrichment_status' => EnrichmentStatus::class,
            'brand_source' => BrandSource::class,
            'sale_price' => 'decimal:3',
            'purchase_price' => 'decimal:3',
            // WAC / cost-carrying columns carry higher internal precision (6 dp)
            // at rest; rounded HALF-UP to the currency scale only at the GL/COGS
            // posting (and display) boundary. See the scale-6 widening migration
            // and WeightedAverageCostService.
            'cost_price' => 'decimal:6',
            'last_purchase_cost' => 'decimal:6',
            'target_margin_override' => 'decimal:3',
            'minimum_margin_override' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'units_per_pack' => 'integer',
            'reorder_point' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (array_key_exists('requires_batch_tracking', $product->getAttributes())) {
                return;
            }

            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->find($product->tenant_id);
            $vertical = $tenant?->vertical;

            if ($vertical === null) {
                return;
            }

            if (! $product->is_physical) {
                $product->requires_batch_tracking = false;

                return;
            }

            $product->requires_batch_tracking = (bool) config(
                "verticals.{$vertical->value}.product_defaults.requires_batch_tracking",
                false,
            );
        });
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

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
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
     * Get the unit of measure for this product.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Get the default tax configuration for this product.
     *
     * @return BelongsTo<TaxConfiguration, $this>
     */
    public function defaultTaxConfiguration(): BelongsTo
    {
        return $this->belongsTo(
            TaxConfiguration::class,
            'default_tax_configuration_id'
        );
    }

    /**
     * Get parapharmacy-specific metadata for this product.
     *
     * @return HasOne<ParapharmacyProductMetadata, $this>
     */
    public function parapharmacyMetadata(): HasOne
    {
        return $this->hasOne(ParapharmacyProductMetadata::class);
    }

    /**
     * Get automotive-specific metadata for this product.
     *
     * @return HasOne<AutomotiveProductMetadata, $this>
     */
    public function automotiveMetadata(): HasOne
    {
        return $this->hasOne(AutomotiveProductMetadata::class);
    }

    /**
     * Get all stock levels for this product across all locations.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Get the latest enrichment result for this product.
     *
     * @return HasOne<EnrichmentResult, $this>
     */
    public function latestEnrichmentResult(): HasOne
    {
        return $this->hasOne(EnrichmentResult::class)->latestOfMany();
    }

    /**
     * Get all enrichment results for this product.
     *
     * @return HasMany<EnrichmentResult, $this>
     */
    public function enrichmentResults(): HasMany
    {
        return $this->hasMany(EnrichmentResult::class);
    }

    /**
     * Active variants of this product (is_active = true, not soft-deleted).
     * Used by withCount('activeVariants') in ProductController::index to avoid N+1.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true);
    }

    /**
     * Whether this product has at least one active variant.
     *
     * Resolution order (fastest first):
     *   1. `active_variants_count` populated by ->withCount('activeVariants') — no extra query.
     *   2. Loaded `activeVariants` relation — no extra query.
     *   3. Fallback exists() query — one query per product (N+1 risk; only used in
     *      non-index callsites that did not eager-load).
     */
    public function getHasVariantsAttribute(): bool
    {
        // Priority 1: withCount populated the aggregate column.
        if (isset($this->attributes['active_variants_count'])) {
            return ((int) $this->attributes['active_variants_count']) > 0;
        }

        // Priority 2: relation already loaded in memory.
        if ($this->relationLoaded('activeVariants')) {
            return $this->activeVariants->isNotEmpty();
        }

        // Priority 3: single exists() query (N+1 risk — avoid in list contexts).
        return $this->activeVariants()->exists();
    }
}
