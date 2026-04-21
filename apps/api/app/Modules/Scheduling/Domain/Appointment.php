<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Database\Factories\Scheduling\AppointmentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Appointment aggregate root — a customer booking for a bay window.
 *
 * Resolves into a WorkOrder on check-in via the AppointmentConversionService.
 * Post-conversion, the 4 system-mirrored states (InProgress / Completed /
 * Closed / Cancelled-from-WO) are NOT writable via the public transition
 * endpoint — the Mirror* listeners are the authorized path.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $appointment_number
 * @property string|null $bay_id
 * @property string|null $primary_technician_profile_id
 * @property string|null $customer_partner_id
 * @property string|null $vehicle_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string|null $vehicle_plate
 * @property string|null $vehicle_description
 * @property AppointmentType $appointment_type
 * @property WaitType $wait_type
 * @property AppointmentStatus $status
 * @property Carbon $scheduled_start
 * @property Carbon $scheduled_end
 * @property int $estimated_duration_minutes
 * @property Carbon|null $actual_arrival_at
 * @property Carbon|null $actual_start_at
 * @property Carbon|null $actual_end_at
 * @property string|null $services_summary
 * @property string|null $customer_notes
 * @property string|null $internal_notes
 * @property string|null $color_label
 * @property AppointmentSource $source
 * @property string|null $online_booking_token
 * @property bool $is_auto_confirmed
 * @property string|null $work_order_id
 * @property Carbon|null $last_reminder_sms_sent_at
 * @property Carbon|null $last_reminder_email_sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read Bay|null $bay
 * @property-read Partner|null $partner
 * @property-read Vehicle|null $vehicle
 * @property-read TechnicianProfile|null $primaryTechnician
 * @property-read WorkOrder|null $workOrder
 * @property-read Collection<int, AppointmentService> $services
 * @property-read Collection<int, AppointmentStatusTransition> $statusTransitions
 */
final class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var string */
    protected $table = 'scheduling_appointments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'appointment_number',
        'bay_id',
        'primary_technician_profile_id',
        'customer_partner_id',
        'vehicle_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'vehicle_plate',
        'vehicle_description',
        'appointment_type',
        'wait_type',
        'status',
        'scheduled_start',
        'scheduled_end',
        'estimated_duration_minutes',
        'actual_arrival_at',
        'actual_start_at',
        'actual_end_at',
        'services_summary',
        'customer_notes',
        'internal_notes',
        'color_label',
        'source',
        'online_booking_token',
        'is_auto_confirmed',
        'work_order_id',
        'last_reminder_sms_sent_at',
        'last_reminder_email_sent_at',
    ];

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_type' => AppointmentType::class,
            'wait_type' => WaitType::class,
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'last_reminder_sms_sent_at' => 'datetime',
            'last_reminder_email_sent_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'is_auto_confirmed' => 'boolean',
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
     * @return BelongsTo<Bay, $this>
     */
    public function bay(): BelongsTo
    {
        return $this->belongsTo(Bay::class, 'bay_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * @return BelongsTo<TechnicianProfile, $this>
     */
    public function primaryTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class, 'primary_technician_profile_id');
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    /**
     * @return HasMany<AppointmentService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(AppointmentService::class, 'appointment_id')->orderBy('display_order');
    }

    /**
     * @return HasMany<AppointmentStatusTransition, $this>
     */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(AppointmentStatusTransition::class, 'appointment_id')->orderBy('triggered_at');
    }
}
