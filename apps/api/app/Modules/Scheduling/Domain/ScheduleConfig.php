<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Scheduling\ScheduleConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-location scheduling configuration — one row per (tenant, company, location).
 *
 * Drives:
 *   - calendar time-slot granularity (15 / 30 / 60 min)
 *   - default duration / walk-in buffer / overbooking threshold
 *   - online-booking toggles + auto-confirm
 *   - per-channel reminder windows (SMS / email, hours before start)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property int $time_slot_minutes
 * @property int $default_appointment_duration_minutes
 * @property string $walk_in_buffer_hours_per_day
 * @property int $overbooking_threshold_percent
 * @property bool $online_booking_enabled
 * @property int $online_booking_advance_days
 * @property int $online_booking_min_notice_hours
 * @property bool $online_booking_auto_confirm
 * @property int|null $reminder_sms_hours_before
 * @property int|null $reminder_email_hours_before
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 */
final class ScheduleConfig extends Model
{
    /** @use HasFactory<ScheduleConfigFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $table = 'scheduling_configs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'time_slot_minutes',
        'default_appointment_duration_minutes',
        'walk_in_buffer_hours_per_day',
        'overbooking_threshold_percent',
        'online_booking_enabled',
        'online_booking_advance_days',
        'online_booking_min_notice_hours',
        'online_booking_auto_confirm',
        'reminder_sms_hours_before',
        'reminder_email_hours_before',
    ];

    protected static function newFactory(): ScheduleConfigFactory
    {
        return ScheduleConfigFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_slot_minutes' => 'integer',
            'default_appointment_duration_minutes' => 'integer',
            'walk_in_buffer_hours_per_day' => 'decimal:2',
            'overbooking_threshold_percent' => 'integer',
            'online_booking_enabled' => 'boolean',
            'online_booking_advance_days' => 'integer',
            'online_booking_min_notice_hours' => 'integer',
            'online_booking_auto_confirm' => 'boolean',
            'reminder_sms_hours_before' => 'integer',
            'reminder_email_hours_before' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
