<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Scheduling\BayFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Physical bay / lift / ramp — the hard scheduling resource.
 *
 * `operating_hours` is a JSONB weekly-schedule (same shape as technician
 * `weekly_schedule`): `['mon' => [['start' => '08:00', 'end' => '17:00'], ...], ...]`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $code
 * @property string $name
 * @property BayType $bay_type
 * @property int $display_order
 * @property array<string, list<array{start: string, end: string}>> $operating_hours
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read Collection<int, Appointment> $appointments
 */
final class Bay extends Model
{
    /** @use HasFactory<BayFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var string */
    protected $table = 'scheduling_bays';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'code',
        'name',
        'bay_type',
        'display_order',
        'operating_hours',
        'notes',
        'is_active',
    ];

    protected static function newFactory(): BayFactory
    {
        return BayFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bay_type' => BayType::class,
            'operating_hours' => 'array',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'bay_id');
    }
}
