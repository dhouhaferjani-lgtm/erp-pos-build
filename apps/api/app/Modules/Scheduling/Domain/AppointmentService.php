<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Scheduling\AppointmentServiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satellite record on an Appointment — one entry per planned service/bundle.
 *
 * `service_ref_type` is 'service' | 'bundle' (polymorphic). Application
 * adapters resolve the reference to the Service/Bundle read model when the
 * appointment is converted to a WorkOrder — the appointment row does NOT
 * hold a typed FK (keeps the Scheduling module decoupled from
 * Workshop/Services at the schema level).
 *
 * Monetary column `estimated_price` is NUMERIC(14,3); the Application DTO
 * MUST serialize via `CurrencyScale::bcformat($value, 3)` per the
 * project_monetary_precision convention.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property string $service_ref_type
 * @property string $service_ref_id
 * @property string $display_name
 * @property int $estimated_duration_minutes
 * @property string|null $estimated_price
 * @property int $display_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Appointment $appointment
 */
final class AppointmentService extends Model
{
    /** @use HasFactory<AppointmentServiceFactory> */
    use HasFactory;

    use HasUuids;

    public const REF_TYPE_SERVICE = 'service';

    public const REF_TYPE_BUNDLE = 'bundle';

    /** @var string */
    protected $table = 'scheduling_appointment_services';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'service_ref_type',
        'service_ref_id',
        'display_name',
        'estimated_duration_minutes',
        'estimated_price',
        'display_order',
    ];

    protected static function newFactory(): AppointmentServiceFactory
    {
        return AppointmentServiceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_duration_minutes' => 'integer',
            'estimated_price' => 'decimal:3',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}
