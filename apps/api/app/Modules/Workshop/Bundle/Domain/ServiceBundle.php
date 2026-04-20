<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use Database\Factories\ServiceBundleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property BundlePricingMode $pricing_mode
 * @property string|null $base_price
 * @property string $currency
 * @property string|null $tax_rate
 * @property string|null $estimated_labor_hours
 * @property int|null $service_interval_km
 * @property int|null $service_interval_months
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceBundleComponent> $components
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceBundleVehicleApplicability> $vehicleApplicabilities
 */
class ServiceBundle extends Model
{
    /** @use HasFactory<ServiceBundleFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'workshop_service_bundles';

    protected static function newFactory(): ServiceBundleFactory
    {
        return ServiceBundleFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'pricing_mode',
        'base_price',
        'currency',
        'tax_rate',
        'estimated_labor_hours',
        'service_interval_km',
        'service_interval_months',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pricing_mode' => 'standard',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_mode' => BundlePricingMode::class,
            'base_price' => 'decimal:3',
            'tax_rate' => 'decimal:3',
            'estimated_labor_hours' => 'decimal:2',
            'service_interval_km' => 'integer',
            'service_interval_months' => 'integer',
            'is_active' => 'boolean',
        ];
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
     * @return HasMany<ServiceBundleComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ServiceBundleComponent::class, 'bundle_id')->orderBy('display_order');
    }

    /**
     * @return HasMany<ServiceBundleVehicleApplicability, $this>
     */
    public function vehicleApplicabilities(): HasMany
    {
        return $this->hasMany(ServiceBundleVehicleApplicability::class, 'bundle_id');
    }

    // -- Scopes --

    /**
     * @param  Builder<ServiceBundle>  $query
     * @return Builder<ServiceBundle>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ServiceBundle>  $query
     * @return Builder<ServiceBundle>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<ServiceBundle>  $query
     * @return Builder<ServiceBundle>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
