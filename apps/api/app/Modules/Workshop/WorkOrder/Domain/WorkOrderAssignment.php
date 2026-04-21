<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Database\Factories\Workshop\WorkOrderAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One technician-to-WorkOrder assignment. A WorkOrder may have multiple
 * assigned technicians but at most one active lead (enforced by partial
 * unique index per Spec §5.1).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_order_id
 * @property string $technician_profile_id
 * @property bool $is_lead
 * @property Carbon $assigned_at
 * @property Carbon|null $unassigned_at
 * @property string $assigned_by_user_id
 * @property string|null $notes
 * @property-read WorkOrder $workOrder
 * @property-read TechnicianProfile $technicianProfile
 * @property-read User $assignedBy
 */
class WorkOrderAssignment extends Model
{
    /** @use HasFactory<WorkOrderAssignmentFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'workshop_work_order_assignments';

    protected static function newFactory(): WorkOrderAssignmentFactory
    {
        return WorkOrderAssignmentFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'technician_profile_id',
        'is_lead',
        'assigned_at',
        'unassigned_at',
        'assigned_by_user_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<TechnicianProfile, $this> */
    public function technicianProfile(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
