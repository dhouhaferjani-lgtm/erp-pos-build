<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use Database\Factories\Workshop\WorkOrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Aggregate root for the Workshop/WorkOrder submodule.
 *
 * IMPORTANT: `status` must only be mutated via
 * `App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService`.
 * A custom PHPStan rule (Task 12) enforces this at static-analysis time —
 * direct assignments `$wo->status = ...` anywhere else will fail CI.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $location_id
 * @property string $work_order_number
 * @property WorkOrderStatus $status
 * @property WorkOrderType $type
 * @property string $customer_partner_id
 * @property string $vehicle_id
 * @property string $opened_by_user_id
 * @property string|null $primary_technician_profile_id
 * @property string|null $appointment_id
 * @property int|null $mileage_at_intake
 * @property string|null $customer_complaint
 * @property string|null $diagnosis
 * @property string|null $internal_notes
 * @property Carbon|null $scheduled_start_at
 * @property Carbon|null $scheduled_end_at
 * @property Carbon|null $promised_at
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property CancellationReason|null $cancellation_reason
 * @property Carbon|null $approval_captured_at
 * @property ApprovalMethod|null $approval_method
 * @property string|null $approval_captured_by_user_id
 * @property string|null $approval_reference
 * @property string $currency
 * @property string $estimated_parts_total
 * @property string $estimated_labor_total
 * @property string $estimated_other_total
 * @property string $estimated_tax_total
 * @property string $estimated_grand_total
 * @property string $actual_parts_total
 * @property string $actual_labor_total
 * @property string $actual_other_total
 * @property string $actual_tax_total
 * @property string $actual_grand_total
 * @property string|null $quote_document_id
 * @property string|null $invoice_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location|null $location
 * @property-read Partner $customer
 * @property-read Vehicle $vehicle
 * @property-read User $openedBy
 * @property-read TechnicianProfile|null $primaryTechnician
 * @property-read Appointment|null $appointment
 * @property-read Document|null $quoteDocument
 * @property-read Document|null $invoiceDocument
 * @property-read Collection<int, WorkOrderLine> $lines
 * @property-read Collection<int, WorkOrderAssignment> $assignments
 * @property-read Collection<int, WorkOrderStatusTransition> $statusTransitions
 */
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'workshop_work_orders';

    protected static function newFactory(): WorkOrderFactory
    {
        return WorkOrderFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'work_order_number',
        'status',
        'type',
        'customer_partner_id',
        'vehicle_id',
        'opened_by_user_id',
        'primary_technician_profile_id',
        'appointment_id',
        'mileage_at_intake',
        'customer_complaint',
        'diagnosis',
        'internal_notes',
        'scheduled_start_at',
        'scheduled_end_at',
        'promised_at',
        'started_at',
        'paused_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'approval_captured_at',
        'approval_method',
        'approval_captured_by_user_id',
        'approval_reference',
        'currency',
        'estimated_parts_total',
        'estimated_labor_total',
        'estimated_other_total',
        'estimated_tax_total',
        'estimated_grand_total',
        'actual_parts_total',
        'actual_labor_total',
        'actual_other_total',
        'actual_tax_total',
        'actual_grand_total',
        'quote_document_id',
        'invoice_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'type' => WorkOrderType::class,
            'cancellation_reason' => CancellationReason::class,
            'approval_method' => ApprovalMethod::class,
            'mileage_at_intake' => 'integer',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'promised_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approval_captured_at' => 'datetime',
            'estimated_parts_total' => 'decimal:3',
            'estimated_labor_total' => 'decimal:3',
            'estimated_other_total' => 'decimal:3',
            'estimated_tax_total' => 'decimal:3',
            'estimated_grand_total' => 'decimal:3',
            'actual_parts_total' => 'decimal:3',
            'actual_labor_total' => 'decimal:3',
            'actual_other_total' => 'decimal:3',
            'actual_tax_total' => 'decimal:3',
            'actual_grand_total' => 'decimal:3',
        ];
    }

    // -- Relations --

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_partner_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<TechnicianProfile, $this> */
    public function primaryTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class, 'primary_technician_profile_id');
    }

    /**
     * Source appointment (reverse side of the bidirectional link — finding 🟠-1).
     * Nullable because direct-intake WOs have no source appointment.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function quoteDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'quote_document_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function invoiceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_document_id');
    }

    /** @return HasMany<WorkOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class)->orderBy('display_order');
    }

    /** @return HasMany<WorkOrderAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    /** @return HasMany<WorkOrderStatusTransition, $this> */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(WorkOrderStatusTransition::class)->orderBy('triggered_at');
    }
}
