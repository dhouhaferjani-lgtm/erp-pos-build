<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $partner_id
 * @property string $license_plate
 * @property string $brand
 * @property string $model
 * @property int|null $year
 * @property string|null $color
 * @property int|null $mileage
 * @property string|null $vin
 * @property string|null $engine_code
 * @property string|null $fuel_type
 * @property string|null $transmission
 * @property string|null $body_type
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Partner|null $partner
 * @property-read VehicleOwnership|null $currentOwnership
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VehicleOwnership> $ownershipHistory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VehicleMileageReading> $mileageReadings
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> forPartner(string $partnerId)
 */
class Vehicle extends Model
{
    /** @use HasFactory<\Database\Factories\VehicleFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'vehicles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'partner_id',
        'license_plate',
        'brand',
        'model',
        'year',
        'color',
        'mileage',
        'vin',
        'engine_code',
        'fuel_type',
        'transmission',
        'body_type',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mileage' => 'integer',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\VehicleFactory
    {
        return \Database\Factories\VehicleFactory::new();
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
     * @return BelongsTo<Partner, $this>
     *
     * @deprecated Use vehicle_ownership_history via currentOwnership() for current owner.
     *             Legacy partner_id will be dropped in a fast-follow migration once all
     *             callers switch to the ownership repository.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasOne<VehicleOwnership, $this>
     */
    public function currentOwnership(): HasOne
    {
        return $this->hasOne(VehicleOwnership::class)->whereNull('released_at');
    }

    /**
     * @return HasMany<VehicleOwnership, $this>
     */
    public function ownershipHistory(): HasMany
    {
        return $this->hasMany(VehicleOwnership::class)->orderByDesc('acquired_at');
    }

    /**
     * @return HasMany<VehicleMileageReading, $this>
     */
    public function mileageReadings(): HasMany
    {
        return $this->hasMany(VehicleMileageReading::class)->orderByDesc('recorded_at');
    }

    /**
     * Get a display name for the vehicle
     */
    public function getDisplayName(): string
    {
        $name = "{$this->license_plate} - {$this->brand} {$this->model}";

        if ($this->year !== null) {
            $name .= " ({$this->year})";
        }

        return $name;
    }

    /**
     * Scope to filter vehicles by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter vehicles by partner (legacy).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     *
     * @deprecated Use VehicleRepositoryInterface::paginateForOwner() which queries the
     *             vehicle_ownership_history table for the current owner.
     */
    public function scopeForPartner(Builder $query, string $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    /**
     * Scope to filter vehicles by company
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
