<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $automotive_metadata_id
 * @property string|null $platform_vehicle_id
 * @property VehicleTypeRef $vehicle_type
 * @property string $vehicle_display
 * @property int|null $year_from
 * @property int|null $year_to
 * @property string|null $notes
 * @property Carbon|null $platform_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AutomotiveProductMetadata $metadata
 */
class AutomotiveProductVehicle extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'automotive_product_vehicles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'automotive_metadata_id',
        'platform_vehicle_id',
        'vehicle_type',
        'vehicle_display',
        'year_from',
        'year_to',
        'notes',
        'platform_synced_at',
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
            'platform_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomotiveProductMetadata, $this>
     */
    public function metadata(): BelongsTo
    {
        return $this->belongsTo(AutomotiveProductMetadata::class, 'automotive_metadata_id');
    }
}
