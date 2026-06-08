<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Service entity for catalog management.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $category_id
 * @property PricingType $pricing_type
 * @property string $base_price
 * @property string $currency
 * @property int|null $default_duration_minutes
 * @property string|null $hourly_rate
 * @property string|null $tax_rate
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read ServiceCategory|null $category
 * @property-read Collection<int, DocumentLine> $documentLines
 *
 * @use HasFactory<ServiceFactory>
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'services';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'category_id',
        'pricing_type',
        'base_price',
        'currency',
        'default_duration_minutes',
        'hourly_rate',
        'tax_rate',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'pricing_type' => 'flat_rate',
        'base_price' => '0.000',
        'currency' => 'TND',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'is_active' => 'boolean',
            'default_duration_minutes' => 'integer',
            'base_price' => 'decimal:3',
            'hourly_rate' => 'decimal:3',
            'tax_rate' => 'decimal:3',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
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
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * @return HasMany<DocumentLine, $this>
     */
    public function documentLines(): HasMany
    {
        return $this->hasMany(DocumentLine::class);
    }

    /**
     * Check if this service uses flat rate pricing.
     */
    public function isFlatRate(): bool
    {
        return $this->pricing_type === PricingType::FlatRate;
    }

    /**
     * Check if this service uses hourly pricing.
     */
    public function isHourly(): bool
    {
        return $this->pricing_type === PricingType::Hourly;
    }

    /**
     * Check if this service uses percentage pricing.
     */
    public function isPercentage(): bool
    {
        return $this->pricing_type === PricingType::Percentage;
    }

    /**
     * Scope a query to only include active services.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include services for a specific company.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include services for a specific tenant.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include services of a specific pricing type.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeByPricingType(Builder $query, PricingType $type): Builder
    {
        return $query->where('pricing_type', $type);
    }

    /**
     * Scope a query to only include services in a specific category.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeInCategory(Builder $query, string $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }
}
