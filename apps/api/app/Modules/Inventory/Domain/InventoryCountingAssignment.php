<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Models\User;
use App\Modules\Inventory\Domain\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $counting_id
 * @property string $user_id
 * @property int $count_number
 * @property AssignmentStatus $status
 * @property Carbon $assigned_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $deadline
 * @property int $total_items
 * @property int $counted_items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InventoryCounting $counting
 * @property-read User $user
 */
class InventoryCountingAssignment extends Model
{
    use HasUuids;

    protected $table = 'inventory_counting_assignments';

    protected $fillable = [
        'counting_id',
        'user_id',
        'count_number',
        'status',
        'assigned_at',
        'started_at',
        'completed_at',
        'deadline',
        'total_items',
        'counted_items',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'count_number' => 'integer',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'deadline' => 'datetime',
            'total_items' => 'integer',
            'counted_items' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InventoryCounting, $this>
     */
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter pending assignments.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Pending);
    }

    /**
     * Scope to filter active assignments.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::InProgress);
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_items === 0) {
            return 0.0;
        }

        return round(($this->counted_items / $this->total_items) * 100, 1);
    }

    /**
     * Check if assignment is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->deadline->isPast()
            && $this->status !== AssignmentStatus::Completed;
    }

    /**
     * Start the assignment.
     */
    public function start(): void
    {
        $this->status = AssignmentStatus::InProgress;
        $this->started_at = now();
        $this->save();
    }

    /**
     * Complete the assignment.
     */
    public function complete(): void
    {
        $this->status = AssignmentStatus::Completed;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Increment the progress counter.
     */
    public function incrementProgress(): void
    {
        $this->increment('counted_items');
    }
}
