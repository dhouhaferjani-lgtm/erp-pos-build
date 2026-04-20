<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Database\Factories\ServiceBundleVehicleApplicabilityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $bundle_id
 * @property string|null $platform_vehicle_id
 * @property VehicleTypeRef|null $vehicle_type
 * @property string|null $vehicle_display
 * @property int|null $year_from
 * @property int|null $year_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ServiceBundle $bundle
 */
class ServiceBundleVehicleApplicability extends Model
{
    /** @use HasFactory<ServiceBundleVehicleApplicabilityFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'workshop_service_bundle_vehicle_applicabilities';

    protected static function newFactory(): ServiceBundleVehicleApplicabilityFactory
    {
        return ServiceBundleVehicleApplicabilityFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'bundle_id',
        'platform_vehicle_id',
        'vehicle_type',
        'vehicle_display',
        'year_from',
        'year_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleTypeRef::class,
            'year_from' => 'integer',
            'year_to' => 'integer',
        ];
    }

    public function isUniversal(): bool
    {
        return $this->platform_vehicle_id === null && $this->vehicle_type === null;
    }

    // -- Relations --

    /**
     * @return BelongsTo<ServiceBundle, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class, 'bundle_id');
    }
}
