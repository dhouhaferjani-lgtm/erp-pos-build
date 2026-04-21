<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Scheduling\Domain\Enums\ReminderChannel;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Queued reminder row for an appointment (Task 14).
 *
 * Idempotency is enforced at the schema level via the unique
 * (appointment_id, channel, scheduled_for) index — callers use upsert to
 * insert. Once a reminder is dispatched `sent_at` is stamped and the row
 * is the permanent evidence that the message was delivered.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property ReminderChannel $channel
 * @property Carbon $scheduled_for
 * @property ReminderDeliveryStatus $delivery_status
 * @property Carbon|null $sent_at
 * @property string|null $external_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Appointment $appointment
 */
final class AppointmentReminder extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'scheduling_appointment_reminders';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'channel',
        'scheduled_for',
        'delivery_status',
        'sent_at',
        'external_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ReminderChannel::class,
            'delivery_status' => ReminderDeliveryStatus::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
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
}
