<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Scheduling\AppointmentStatusTransitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable per-transition journal for an Appointment.
 *
 * Written on every status change — both public transitions (via
 * `AppointmentTransitionService`) and system-mirrored transitions (via
 * the 4 `MirrorAppointmentOn*` listeners). `context` JSONB carries
 * reason strings, overrides, or "sourced from WorkOrder <id>" metadata
 * depending on the source.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property AppointmentStatus|null $from_status
 * @property AppointmentStatus $to_status
 * @property string|null $reason_code
 * @property string|null $triggered_by_user_id
 * @property Carbon $triggered_at
 * @property array<string, mixed>|null $context
 * @property-read Tenant $tenant
 * @property-read Appointment $appointment
 * @property-read User|null $triggeredByUser
 */
final class AppointmentStatusTransition extends Model
{
    /** @use HasFactory<AppointmentStatusTransitionFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $table = 'scheduling_appointment_status_transitions';

    /** @var bool */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'from_status',
        'to_status',
        'reason_code',
        'triggered_by_user_id',
        'triggered_at',
        'context',
    ];

    protected static function newFactory(): AppointmentStatusTransitionFactory
    {
        return AppointmentStatusTransitionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'triggered_at' => 'datetime',
            'context' => 'array',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
