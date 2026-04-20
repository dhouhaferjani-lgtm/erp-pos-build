<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use Database\Factories\VehicleOwnershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $vehicle_id
 * @property string $owner_partner_id
 * @property \Illuminate\Support\Carbon $acquired_at
 * @property \Illuminate\Support\Carbon|null $released_at
 * @property OwnershipReason $reason_code
 * @property string|null $notes
 * @property string|null $recorded_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Vehicle $vehicle
 * @property-read Partner|null $ownerPartner  Nullable when the partner is soft-deleted; FK is RESTRICT on hard delete only.
 */
class VehicleOwnership extends Model
{
    /** @use HasFactory<VehicleOwnershipFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'vehicle_ownership_history';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'vehicle_id',
        'owner_partner_id',
        'acquired_at',
        'released_at',
        'reason_code',
        'notes',
        'recorded_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'released_at' => 'datetime',
            'reason_code' => OwnershipReason::class,
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function ownerPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'owner_partner_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }

    protected static function newFactory(): VehicleOwnershipFactory
    {
        return VehicleOwnershipFactory::new();
    }
}
