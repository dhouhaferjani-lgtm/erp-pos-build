<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\SellableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property int|null $category_id
 * @property VerticalType $vertical_type
 * @property string $base_price
 * @property ProductionType $production_type
 * @property PricingMode $pricing_mode
 * @property string|null $tax_rate
 * @property string|null $default_recipe_id
 * @property string|null $stock_unit_id
 * @property bool $is_active
 * @property bool $is_available
 * @property string|null $image_url
 * @property int $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Category|null $category
 * @property-read Unit|null $unitOfMeasure
 * @property-read Recipe|null $activeRecipe
 * @property-read Recipe|null $defaultRecipe
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Recipe> $recipes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CompositeItemVariant> $variants
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ModifierGroup> $modifierGroups
 */
class CompositeItem extends Model implements SellableContract
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<CompositeItem>> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'category_id',
        'vertical_type',
        'base_price',
        'production_type',
        'pricing_mode',
        'tax_rate',
        'default_recipe_id',
        'stock_unit_id',
        'is_active',
        'is_available',
        'image_url',
        'display_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_available' => true,
        'display_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vertical_type' => VerticalType::class,
            'production_type' => ProductionType::class,
            'pricing_mode' => PricingMode::class,
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- SellableContract implementation --

    public function getSellableId(): string
    {
        return $this->id;
    }

    public function getSellableType(): string
    {
        return 'composite_item';
    }

    public function getSellableName(): string
    {
        return $this->name;
    }

    public function getSellableBasePrice(): string
    {
        return (string) $this->base_price;
    }

    public function getSellableUnit(): ?string
    {
        return $this->unitOfMeasure?->symbol;
    }

    public function isStockTracked(): bool
    {
        return $this->production_type === ProductionType::Stock;
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->is_available;
    }

    // -- Relations --

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
     * @return BelongsTo<Unit, $this>
     */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    /**
     * @return HasMany<Recipe, $this>
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /**
     * @return HasOne<Recipe, $this>
     */
    public function activeRecipe(): HasOne
    {
        return $this->hasOne(Recipe::class)->where('is_active', true);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function defaultRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'default_recipe_id');
    }

    /**
     * @return HasMany<CompositeItemVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CompositeItemVariant::class)->orderBy('display_order');
    }

    /**
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'composite_item_modifier_groups')
            ->withPivot('display_order')
            ->withTimestamps()
            ->orderByPivot('display_order');
    }

    // -- Scopes --

    /**
     * @param  Builder<CompositeItem>  $query
     * @return Builder<CompositeItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<CompositeItem>  $query
     * @return Builder<CompositeItem>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_available', true);
    }

    /**
     * @param  Builder<CompositeItem>  $query
     * @return Builder<CompositeItem>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<CompositeItem>  $query
     * @return Builder<CompositeItem>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<CompositeItem>  $query
     * @return Builder<CompositeItem>
     */
    public function scopeByVertical(Builder $query, VerticalType $vertical): Builder
    {
        return $query->where('vertical_type', $vertical);
    }
}
