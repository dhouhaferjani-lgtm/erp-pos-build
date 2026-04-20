<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use Database\Factories\Workshop\TechnicianTimeEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $technician_profile_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_minutes
 * @property TimeEntryType $entry_type
 * @property string|null $work_order_id Nullable plain UUID (no FK to work_orders)
 * @property TimeEntrySource $source
 * @property string|null $recorded_by_user_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TechnicianProfile $profile
 * @property-read Company $company
 */
final class TechnicianTimeEntry extends Model
{
    /** @use HasFactory<TechnicianTimeEntryFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $table = 'workshop_technician_time_entries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'technician_profile_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'entry_type',
        'work_order_id',
        'source',
        'recorded_by_user_id',
        'notes',
    ];

    protected static function newFactory(): TechnicianTimeEntryFactory
    {
        return TechnicianTimeEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
            'entry_type' => TimeEntryType::class,
            'source' => TimeEntrySource::class,
        ];
    }

    /**
     * @return BelongsTo<TechnicianProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class, 'technician_profile_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
