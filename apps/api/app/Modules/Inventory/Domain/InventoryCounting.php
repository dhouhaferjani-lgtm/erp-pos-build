<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $counting_number
 * @property string $company_id
 * @property string $created_by_user_id
 * @property CountingScopeType $scope_type
 * @property array<string, mixed> $scope_filters
 * @property CountingExecutionMode $execution_mode
 * @property CountingStatus $status
 * @property Carbon|null $scheduled_start
 * @property Carbon|null $scheduled_end
 * @property string|null $count_1_user_id
 * @property string|null $count_2_user_id
 * @property string|null $count_3_user_id
 * @property bool $requires_count_2
 * @property bool $requires_count_3
 * @property bool $allow_unexpected_items
 * @property string|null $instructions
 * @property bool $block_sales
 * @property int $ambiguity_window_minutes
 * @property array<int, array{receipt_id: string, occurred_at: string}>|null $late_sales_flags
 * @property bool $includes_zero_stock
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Company $company
 * @property-read User|null $createdBy
 * @property-read User|null $count1User
 * @property-read User|null $count2User
 * @property-read User|null $count3User
 * @property-read Collection<int, InventoryCountingItem> $items
 * @property-read Collection<int, InventoryCountingAssignment> $assignments
 * @property-read Collection<int, InventoryCountingEvent> $events
 */
class InventoryCounting extends Model
{
    use HasUuids;

    protected $table = 'inventory_countings';

    protected $fillable = [
        'tenant_id',
        'counting_number',
        'company_id',
        'created_by_user_id',
        'scope_type',
        'scope_filters',
        'execution_mode',
        'status',
        'scheduled_start',
        'scheduled_end',
        'count_1_user_id',
        'count_2_user_id',
        'count_3_user_id',
        'requires_count_2',
        'requires_count_3',
        'allow_unexpected_items',
        'instructions',
        'block_sales',
        'ambiguity_window_minutes',
        'late_sales_flags',
        'includes_zero_stock',
        'activated_at',
        'finalized_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => CountingScopeType::class,
            'status' => CountingStatus::class,
            'execution_mode' => CountingExecutionMode::class,
            'scope_filters' => 'array',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'activated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'requires_count_2' => 'boolean',
            'requires_count_3' => 'boolean',
            'allow_unexpected_items' => 'boolean',
            'block_sales' => 'boolean',
            'ambiguity_window_minutes' => 'integer',
            'late_sales_flags' => 'array',
            'includes_zero_stock' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function count1User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_1_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function count2User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_2_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function count3User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_3_user_id');
    }

    /**
     * @return HasMany<InventoryCountingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountingItem::class, 'counting_id');
    }

    /**
     * @return HasMany<InventoryCountingAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(InventoryCountingAssignment::class, 'counting_id');
    }

    /**
     * @return HasMany<InventoryCountingEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InventoryCountingEvent::class, 'counting_id');
    }

    /**
     * Scope to filter active countings.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CountingStatus::Count1InProgress,
            CountingStatus::Count2InProgress,
            CountingStatus::Count3InProgress,
        ]);
    }

    /**
     * Scope to filter countings pending review.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', CountingStatus::PendingReview);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Check if counting can transition to the given status.
     */
    public function canTransitionTo(CountingStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }

    /**
     * Transition to the given status.
     *
     * @throws \InvalidArgumentException
     */
    public function transitionTo(CountingStatus $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$this->status->value} to {$status->value}"
            );
        }

        $this->status = $status;

        match ($status) {
            CountingStatus::Count1InProgress => $this->activated_at = now(),
            CountingStatus::Finalized => $this->finalized_at = now(),
            CountingStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };

        $this->save();
    }

    /**
     * Get the current count number based on status.
     */
    public function getCurrentCountNumber(): ?int
    {
        return match ($this->status) {
            CountingStatus::Count1InProgress, CountingStatus::Count1Completed => 1,
            CountingStatus::Count2InProgress, CountingStatus::Count2Completed => 2,
            CountingStatus::Count3InProgress, CountingStatus::Count3Completed => 3,
            default => null,
        };
    }

    /**
     * Check if a user is assigned to this counting.
     */
    public function isUserAssigned(string $userId): bool
    {
        return in_array($userId, array_filter([
            $this->count_1_user_id,
            $this->count_2_user_id,
            $this->count_3_user_id,
        ]), true);
    }

    /**
     * Get the count number assigned to a user.
     */
    public function getUserCountNumber(string $userId): ?int
    {
        if ($this->count_1_user_id === $userId) {
            return 1;
        }
        if ($this->count_2_user_id === $userId) {
            return 2;
        }
        if ($this->count_3_user_id === $userId) {
            return 3;
        }

        return null;
    }

    /**
     * Get progress information.
     *
     * @return array{count_1: array{counted: int, total: int, percentage: float}, count_2: array{counted: int, total: int, percentage: float}|null, count_3: array{counted: int, total: int, percentage: float}|null, overall: float}
     */
    public function getProgress(): array
    {
        $totalItems = $this->items()->count();

        return [
            'count_1' => $this->calculateCountProgress(1, $totalItems),
            'count_2' => $this->requires_count_2 ? $this->calculateCountProgress(2, $totalItems) : null,
            'count_3' => $this->requires_count_3 ? $this->calculateCountProgress(3, $totalItems) : null,
            'overall' => $this->calculateOverallProgress($totalItems),
        ];
    }

    /**
     * Calculate progress for a specific count.
     *
     * @return array{counted: int, total: int, percentage: float}
     */
    private function calculateCountProgress(int $countNumber, int $total): array
    {
        if ($total === 0) {
            return ['counted' => 0, 'total' => 0, 'percentage' => 0.0];
        }

        $column = "count_{$countNumber}_qty";
        $counted = $this->items()->whereNotNull($column)->count();

        return [
            'counted' => $counted,
            'total' => $total,
            'percentage' => round(($counted / $total) * 100, 1),
        ];
    }

    /**
     * Calculate overall progress.
     */
    private function calculateOverallProgress(int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        $phases = $this->requires_count_2 ? ($this->requires_count_3 ? 3 : 2) : 1;
        $totalSteps = $total * $phases;

        $completed = $this->items()->whereNotNull('count_1_qty')->count();
        if ($this->requires_count_2) {
            $completed += $this->items()->whereNotNull('count_2_qty')->count();
        }
        if ($this->requires_count_3) {
            $completed += $this->items()->whereNotNull('count_3_qty')->count();
        }

        return round(($completed / $totalSteps) * 100, 1);
    }
}
