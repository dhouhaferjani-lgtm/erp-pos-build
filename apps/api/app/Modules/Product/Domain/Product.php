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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $sku
 * @property ProductType $type
 * @property string|null $description
 * @property string|null $sale_price
 * @property string|null $purchase_price
 * @property string|null $tax_rate
 * @property string|null $unit
 * @property string|null $barcode
 * @property bool $is_active
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
 * @property-read Tenant $tenant
 * @property-read Company $company
 */
class Product extends Model
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

    public function isPart(): bool
    {
        return $this->type === ProductType::Part;
    }

    public function isService(): bool
    {
        return $this->type === ProductType::Service;
    }

    public function isConsumable(): bool
    {
        return $this->type === ProductType::Consumable;
    }

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
     * Scope a query to only include parts.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeParts(Builder $query): Builder
    {
        return $query->where('type', ProductType::Part);
    }

    /**
     * Scope a query to only include services.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeServices(Builder $query): Builder
    {
        return $query->where('type', ProductType::Service);
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
}
