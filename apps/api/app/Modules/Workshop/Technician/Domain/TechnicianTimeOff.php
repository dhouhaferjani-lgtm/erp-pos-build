<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use Database\Factories\Workshop\TechnicianTimeOffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $technician_profile_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property TimeOffReason $reason_code
 * @property bool $is_full_day
 * @property bool $is_approved
 * @property string|null $approved_by_user_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TechnicianProfile $profile
 */
final class TechnicianTimeOff extends Model
{
    /** @use HasFactory<TechnicianTimeOffFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $table = 'workshop_technician_time_off';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'technician_profile_id',
        'starts_at',
        'ends_at',
        'reason_code',
        'is_full_day',
        'is_approved',
        'approved_by_user_id',
        'notes',
    ];

    protected static function newFactory(): TechnicianTimeOffFactory
    {
        return TechnicianTimeOffFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reason_code' => TimeOffReason::class,
            'is_full_day' => 'boolean',
            'is_approved' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
