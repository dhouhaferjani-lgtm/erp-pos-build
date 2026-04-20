<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain;

use App\Modules\Vehicle\Domain\Enums\MileageSource;
use Database\Factories\VehicleMileageReadingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $vehicle_id
 * @property int $mileage
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property MileageSource $source
 * @property string|null $context_document_id
 * @property string|null $context_work_order_id
 * @property string|null $recorded_by_user_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 */
class VehicleMileageReading extends Model
{
    /** @use HasFactory<VehicleMileageReadingFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'vehicle_mileage_readings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'vehicle_id',
        'mileage',
        'recorded_at',
        'source',
        'context_document_id',
        'context_work_order_id',
        'recorded_by_user_id',
        'notes',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'recorded_at' => 'datetime',
            'source' => MileageSource::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    protected static function newFactory(): VehicleMileageReadingFactory
    {
        return VehicleMileageReadingFactory::new();
    }
}
