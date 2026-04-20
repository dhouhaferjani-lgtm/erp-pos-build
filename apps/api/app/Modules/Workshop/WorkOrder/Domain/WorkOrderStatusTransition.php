<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use Database\Factories\Workshop\WorkOrderStatusTransitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit row for every status change on a WorkOrder.
 *
 * `context` carries free-form JSON metadata per transition type
 * (approval payload, cancellation sub-reason, etc.). Prefer strongly-typed
 * DTOs when producing context in the transition service.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_order_id
 * @property WorkOrderStatus|null $from_status
 * @property WorkOrderStatus $to_status
 * @property string|null $reason_code
 * @property string|null $triggered_by_user_id
 * @property Carbon $triggered_at
 * @property array<string, mixed>|null $context
 * @property-read WorkOrder $workOrder
 * @property-read User|null $triggeredBy
 */
class WorkOrderStatusTransition extends Model
{
    /** @use HasFactory<WorkOrderStatusTransitionFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'workshop_work_order_status_transitions';

    protected static function newFactory(): WorkOrderStatusTransitionFactory
    {
        return WorkOrderStatusTransitionFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'from_status',
        'to_status',
        'reason_code',
        'triggered_by_user_id',
        'triggered_at',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => WorkOrderStatus::class,
            'to_status' => WorkOrderStatus::class,
            'triggered_at' => 'datetime',
            'context' => 'array',
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

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
