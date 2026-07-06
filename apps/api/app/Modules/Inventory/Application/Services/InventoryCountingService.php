<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\StockLevel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing inventory counting operations.
 */
class InventoryCountingService
{
    public function __construct(
        private readonly CountingReconciliationService $reconciliationService,
    ) {}

    /**
     * Create a new counting operation.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $createdBy, string $companyId): InventoryCounting
    {
        return DB::transaction(function () use ($data, $createdBy, $companyId): InventoryCounting {
            $countingNumber = $this->generateCountingNumber($companyId);

            /** @var InventoryCounting $counting */
            $counting = InventoryCounting::create([
                'company_id' => $companyId,
                'created_by_user_id' => $createdBy->id,
                'tenant_id' => $createdBy->tenant_id ?? null,
                'counting_number' => $countingNumber,
                'scope_type' => $data['scope_type'],
                'scope_filters' => $data['scope_filters'] ?? [],
                'execution_mode' => $data['execution_mode'] ?? 'parallel',
                'requires_count_2' => $data['requires_count_2'] ?? true,
                'requires_count_3' => $data['requires_count_3'] ?? false,
                'allow_unexpected_items' => $data['allow_unexpected_items'] ?? false,
                'count_1_user_id' => $data['count_1_user_id'],
                'count_2_user_id' => $data['count_2_user_id'] ?? null,
                'count_3_user_id' => $data['count_3_user_id'] ?? null,
                'scheduled_start' => $data['scheduled_start'] ?? null,
                'scheduled_end' => $data['scheduled_end'] ?? null,
                'instructions' => $data['instructions'] ?? null,
            ]);

            // Generate items based on scope
            $this->generateCountingItems($counting, $companyId);

            // Create assignments
            $this->createAssignments($counting);

            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_CREATED,
                'event_data' => [
                    'scope_type' => $counting->scope_type->value,
                    'scope_filters' => $counting->scope_filters,
                    'items_count' => $counting->items()->count(),
                ],
                'user_id' => $createdBy->id,
            ]);

            return $counting->fresh(['items', 'assignments']) ?? $counting;
        });
    }

    /**
     * Generate counting items based on scope.
     */
    public function generateCountingItems(InventoryCounting $counting, string $companyId): void
    {
        $stockLevels = $this->getStockLevelsForScope(
            $companyId,
            $counting->scope_type,
            $counting->scope_filters
        );

        foreach ($stockLevels as $stock) {
            // variant_id is propagated from the StockLevel row (Task 20).
            // For product-level stock rows (variant_id IS NULL) this remains
            // null — preserving backward compat with non-variant products.
            InventoryCountingItem::create([
                'counting_id' => $counting->id,
                'product_id' => $stock->product_id,
                'variant_id' => $stock->variant_id,
                'location_id' => $stock->location_id,
                'theoretical_qty' => $stock->quantity,
            ]);
        }
    }

    /**
     * Get stock levels based on counting scope.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, StockLevel>
     */
    private function getStockLevelsForScope(
        string $companyId,
        CountingScopeType $scopeType,
        array $filters
    ): Collection {
        $query = StockLevel::query()
            ->forCompany($companyId)
            ->with(['product', 'location']);

        switch ($scopeType) {
            case CountingScopeType::ProductLocation:
                $productIds = $filters['product_ids'] ?? [];
                if (! empty($productIds)) {
                    $query->whereIn('product_id', $productIds);
                }
                if (isset($filters['location_id'])) {
                    $query->where('location_id', $filters['location_id']);
                }
                break;

            case CountingScopeType::Product:
                $productIds = $filters['product_ids'] ?? [];
                if (! empty($productIds)) {
                    $query->whereIn('product_id', $productIds);
                }
                break;

            case CountingScopeType::Location:
                $locationIds = $filters['location_ids'] ?? [];
                if (! empty($locationIds)) {
                    $query->whereIn('location_id', $locationIds);
                }
                break;

            case CountingScopeType::Category:
                $categoryIds = $filters['category_ids'] ?? [];
                if (! empty($categoryIds)) {
                    $query->whereHas('product', function ($q) use ($categoryIds): void {
                        $q->whereIn('category_id', $categoryIds);
                    });
                }
                break;

            case CountingScopeType::FullInventory:
                // No additional filters
                break;
        }

        return $query->where('quantity', '>', 0)->get();
    }

    /**
     * Create counter assignments.
     */
    private function createAssignments(InventoryCounting $counting): void
    {
        $totalItems = $counting->items()->count();

        // Assignment for Count 1
        if ($counting->count_1_user_id !== null) {
            InventoryCountingAssignment::create([
                'counting_id' => $counting->id,
                'user_id' => $counting->count_1_user_id,
                'count_number' => 1,
                'assigned_at' => now(),
                'deadline' => $counting->scheduled_end,
                'total_items' => $totalItems,
            ]);
        }

        // Assignment for Count 2 (if required)
        if ($counting->requires_count_2 && $counting->count_2_user_id !== null) {
            InventoryCountingAssignment::create([
                'counting_id' => $counting->id,
                'user_id' => $counting->count_2_user_id,
                'count_number' => 2,
                'assigned_at' => now(),
                'deadline' => $counting->scheduled_end,
                'total_items' => $totalItems,
            ]);
        }

        // Assignment for Count 3 (if required)
        if ($counting->requires_count_3 && $counting->count_3_user_id !== null) {
            InventoryCountingAssignment::create([
                'counting_id' => $counting->id,
                'user_id' => $counting->count_3_user_id,
                'count_number' => 3,
                'assigned_at' => now(),
                'deadline' => $counting->scheduled_end,
                'total_items' => 0, // Will be updated when 3rd count triggered
            ]);
        }
    }

    /**
     * Activate a draft counting operation.
     *
     * Generates counting number, items, assignments, and transitions to active.
     */
    public function activateDraft(
        InventoryCounting $counting,
        string $companyId,
        User $user,
        bool $activateImmediately = true,
    ): InventoryCounting {
        if ($counting->status !== CountingStatus::Draft) {
            throw new \InvalidArgumentException('Counting is not in draft status');
        }

        return DB::transaction(function () use ($counting, $companyId, $user, $activateImmediately): InventoryCounting {
            // Generate counting number if not already set
            if ($counting->counting_number === null) {
                $counting->counting_number = $this->generateCountingNumber($companyId);
            }

            // Set tenant_id if not already set
            if ($counting->tenant_id === null) {
                $counting->tenant_id = $user->tenant_id ?? null;
            }

            $counting->save();

            // Generate counting items from scope
            $this->generateCountingItems($counting, $companyId);

            // Create assignments
            $this->createAssignments($counting);

            if ($activateImmediately) {
                $counting->transitionTo(CountingStatus::Count1InProgress);

                $assignment = $counting->assignments()
                    ->where('count_number', 1)
                    ->first();
                $assignment?->start();
            } else {
                $counting->transitionTo(CountingStatus::Scheduled);
            }

            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_ACTIVATED,
                'event_data' => [
                    'items_count' => $counting->items()->count(),
                    'activate_immediately' => $activateImmediately,
                ],
                'user_id' => $user->id,
            ]);

            return $counting->fresh() ?? $counting;
        });
    }

    /**
     * Activate a counting operation.
     */
    public function activate(InventoryCounting $counting, User $user): void
    {
        if ($counting->status !== CountingStatus::Draft &&
            $counting->status !== CountingStatus::Scheduled) {
            throw new \InvalidArgumentException('Counting is not in draft or scheduled status');
        }

        DB::transaction(function () use ($counting, $user): void {
            $counting->transitionTo(CountingStatus::Count1InProgress);

            // Start assignment for count 1
            $assignment = $counting->assignments()
                ->where('count_number', 1)
                ->first();
            $assignment?->start();

            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_ACTIVATED,
                'event_data' => [],
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Submit a count for an item.
     *
     * CRITICAL: This is the only method that should modify count values.
     *
     * @param  numeric-string  $quantity  Canonical numeric string (quantity scale 4, e.g. '1.2345')
     * @param  CarbonInterface|null  $countedAtDevice  Raw device-clock instant of the count, if supplied
     * @param  CarbonInterface|null  $deviceNow  Device clock's own "now" reading at submission, used to derive skew
     */
    public function submitCount(
        InventoryCountingItem $item,
        int $countNumber,
        string $quantity,
        ?string $notes,
        User $user,
        ?CarbonInterface $countedAtDevice = null,
        ?CarbonInterface $deviceNow = null,
    ): void {
        $counting = $item->counting;

        // Validate count number
        if (! in_array($countNumber, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Invalid count number');
        }

        // Validate user is assigned to this count number
        $expectedUserId = match ($countNumber) {
            1 => $counting->count_1_user_id,
            2 => $counting->count_2_user_id,
            3 => $counting->count_3_user_id,
        };

        if ($expectedUserId !== (string) $user->id) {
            throw new \InvalidArgumentException('User is not assigned to this count phase');
        }

        // Validate counting is in correct status
        $expectedStatus = match ($countNumber) {
            1 => CountingStatus::Count1InProgress,
            2 => CountingStatus::Count2InProgress,
            3 => CountingStatus::Count3InProgress,
        };

        if ($counting->status !== $expectedStatus) {
            throw new \InvalidArgumentException('Counting is not in correct phase');
        }

        $userId = (string) $user->id;

        DB::transaction(function () use ($item, $countNumber, $quantity, $notes, $userId, $counting, $countedAtDevice, $deviceNow): void {
            // Submit the count
            $item->submitCount($countNumber, $quantity, $notes, $countedAtDevice, $deviceNow);

            // Record event
            InventoryCountingEvent::recordCountSubmitted(
                $item,
                $countNumber,
                $quantity,
                $notes,
                $userId
            );

            // Update assignment progress
            $assignment = $counting->assignments()
                ->where('count_number', $countNumber)
                ->first();
            $assignment?->incrementProgress();

            // Check if this phase is complete
            $this->checkPhaseCompletion($counting, $countNumber);
        });
    }

    /**
     * Check if a counting phase is complete and transition status.
     */
    private function checkPhaseCompletion(InventoryCounting $counting, int $countNumber): void
    {
        $column = "count_{$countNumber}_qty";
        $totalItems = $counting->items()->count();
        $countedItems = $counting->items()->whereNotNull($column)->count();

        if ($countedItems < $totalItems) {
            return; // Phase not complete
        }

        // Phase is complete
        $assignment = $counting->assignments()
            ->where('count_number', $countNumber)
            ->first();
        $assignment?->complete();

        // Transition to completed status first
        $counting->transitionTo(match ($countNumber) {
            1 => CountingStatus::Count1Completed,
            2 => CountingStatus::Count2Completed,
            3 => CountingStatus::Count3Completed,
            default => throw new \InvalidArgumentException('Invalid count number'),
        });

        // Determine next status
        $nextStatus = match ($countNumber) {
            1 => $counting->requires_count_2
                ? CountingStatus::Count2InProgress
                : CountingStatus::PendingReview,
            2, 3 => CountingStatus::PendingReview,
            default => throw new \InvalidArgumentException('Invalid count number'),
        };

        // If moving to next count phase
        if ($nextStatus === CountingStatus::Count2InProgress) {
            $counting->transitionTo($nextStatus);
            $counting->assignments()
                ->where('count_number', 2)
                ->first()
                ?->start();
        }

        // If moving to pending review, run reconciliation
        if ($nextStatus === CountingStatus::PendingReview) {
            $counting->transitionTo($nextStatus);
            $this->reconciliationService->runReconciliation($counting);
        }
    }

    /**
     * Get items for a counter (BLIND - no theoretical qty!).
     *
     * CRITICAL: This method must NEVER return theoretical_qty or other counters' results.
     *
     * @return Collection<int, InventoryCountingItem>
     */
    public function getItemsForCounter(
        InventoryCounting $counting,
        User $user,
        bool $uncountedOnly = false
    ): Collection {
        $countNumber = $counting->getUserCountNumber((string) $user->id);

        if ($countNumber === null) {
            throw new \InvalidArgumentException('User is not assigned to this counting');
        }

        $column = "count_{$countNumber}_qty";

        $query = $counting->items()
            ->with(['product', 'location']);

        if ($uncountedOnly) {
            $query->whereNull($column);
        }

        return $query->get();
    }

    /**
     * Get full item details (admin view - includes all data).
     *
     * @return Collection<int, InventoryCountingItem>
     */
    public function getItemsForAdmin(InventoryCounting $counting): Collection
    {
        return $counting->items()
            ->with(['product', 'location', 'resolvedBy'])
            ->get();
    }

    /**
     * Trigger third count for specific items.
     *
     * @param  array<string>  $itemIds
     */
    public function triggerThirdCount(
        InventoryCounting $counting,
        array $itemIds,
        User $triggeredBy
    ): void {
        if ($counting->count_3_user_id === null) {
            throw new \InvalidArgumentException('No user assigned for third count');
        }

        DB::transaction(function () use ($counting, $itemIds, $triggeredBy): void {
            // Update items to require third count
            InventoryCountingItem::whereIn('id', $itemIds)
                ->where('counting_id', $counting->id)
                ->update([
                    'resolution_method' => ItemResolutionMethod::Pending,
                    'is_flagged' => true,
                    'flag_reason' => 'third_count_requested',
                ]);

            // Update count 3 assignment
            $assignment = $counting->assignments()
                ->where('count_number', 3)
                ->first();

            if ($assignment !== null) {
                $assignment->total_items = count($itemIds);
                $assignment->save();
            }

            // Transition to count 3 in progress
            if ($counting->status === CountingStatus::PendingReview) {
                $counting->transitionTo(CountingStatus::Count3InProgress);
                $assignment?->start();
            }

            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::THIRD_COUNT_TRIGGERED,
                'event_data' => [
                    'item_ids' => $itemIds,
                    'count' => count($itemIds),
                ],
                'user_id' => $triggeredBy->id,
            ]);
        });
    }

    /**
     * Manual override for an item.
     *
     * @param  numeric-string  $quantity  Canonical numeric string (quantity scale 4, e.g. '1.2345')
     */
    public function manualOverride(
        InventoryCountingItem $item,
        string $quantity,
        string $notes,
        User $user
    ): void {
        $userId = (string) $user->id;

        DB::transaction(function () use ($item, $quantity, $notes, $userId): void {
            $now = now();
            $item->final_qty = $quantity;
            $item->resolution_method = ItemResolutionMethod::ManualOverride;
            $item->resolution_notes = $notes;
            $item->resolved_by_user_id = $userId;
            $item->resolved_at = $now;
            // Replay boundary for a manual override is fixed to resolved_at — the
            // override request carries no count timestamp of its own (B3 §1).
            $item->final_qty_as_of = $now;
            $item->is_flagged = true;
            $item->flag_reason = 'manual_override';
            $item->save();

            InventoryCountingEvent::recordManualOverride(
                $item,
                $quantity,
                $notes,
                $userId
            );
        });
    }

    /**
     * Finalize counting and create stock adjustments.
     */
    public function finalize(InventoryCounting $counting, User $user): void
    {
        // Ensure all items are resolved
        $unresolvedCount = $counting->items()
            ->where('resolution_method', ItemResolutionMethod::Pending)
            ->count();

        if ($unresolvedCount > 0) {
            throw new \InvalidArgumentException(
                "Cannot finalize: {$unresolvedCount} items still pending resolution"
            );
        }

        DB::transaction(function () use ($counting, $user): void {
            // Freeze each auto-resolved item's replay boundary before the
            // finalize event fires (the queued listener reads final_qty_as_of to
            // choose the replay path vs the legacy delta path). Manual overrides
            // already stamped their own as-of at override time.
            foreach ($counting->items as $item) {
                if ($item->final_qty_as_of !== null) {
                    continue;
                }

                $asOf = $this->resolveFinalQtyAsOf($item);
                if ($asOf !== null) {
                    $item->final_qty_as_of = Carbon::instance($asOf);
                    $item->save();
                }
            }

            // Finalize the counting
            $counting->transitionTo(CountingStatus::Finalized);

            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_FINALIZED,
                'event_data' => [
                    'total_items' => $counting->items()->count(),
                    'items_with_variance' => $counting->items()->whereRaw('final_qty != theoretical_qty')->count(),
                ],
                'user_id' => $user->id,
            ]);

            // Capture data for event dispatch after commit
            $eventData = [
                'counting' => $counting,
                'user' => $user,
            ];

            // Dispatch event AFTER transaction commits
            DB::afterCommit(function () use ($eventData): void {
                $this->dispatchInventoryCountingCompletedEvent(
                    $eventData['counting'],
                    $eventData['user']
                );
            });
        });
    }

    /**
     * Resolve the replay boundary (`final_qty_as_of`) for an auto-resolved item:
     * the skew-corrected estimate of the count that produced `final_qty`.
     *
     * The lowest-numbered count whose value equals `final_qty` supplies the
     * boundary. When counters agree (or all match theoretical) any agreeing
     * count yields the same replay result — no net movement can sit between two
     * agreeing counts — so the earliest is a safe, deterministic choice. Manual
     * overrides never reach here (they stamp resolved_at at override time).
     * Returns null when no count matches, which routes the item to the legacy
     * delta path.
     */
    private function resolveFinalQtyAsOf(InventoryCountingItem $item): ?CarbonInterface
    {
        if ($item->resolution_method === ItemResolutionMethod::ManualOverride) {
            return $item->resolved_at;
        }

        $finalQty = $item->final_qty;
        if ($finalQty === null) {
            return null;
        }

        foreach ([1, 2, 3] as $phase) {
            /** @var numeric-string|null $qty */
            $qty = $item->{"count_{$phase}_qty"};
            /** @var CarbonInterface|null $estimate */
            $estimate = $item->{"count_{$phase}_at_estimate"};

            if ($qty !== null && $estimate !== null && bccomp((string) $qty, (string) $finalQty, InventoryScale::QUANTITY_SCALE) === 0) {
                return $estimate;
            }
        }

        return null;
    }

    /**
     * Cancel a counting operation.
     */
    public function cancel(InventoryCounting $counting, string $reason, User $user): void
    {
        if ($counting->status === CountingStatus::Finalized ||
            $counting->status === CountingStatus::Cancelled) {
            throw new \InvalidArgumentException('Cannot cancel finalized or already cancelled counting');
        }

        DB::transaction(function () use ($counting, $reason, $user): void {
            $previousStatus = $counting->status->value;
            $counting->cancellation_reason = $reason;
            $counting->save();
            $counting->transitionTo(CountingStatus::Cancelled);

            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_CANCELLED,
                'event_data' => [
                    'reason' => $reason,
                    'previous_status' => $previousStatus,
                ],
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Dispatch InventoryCountingCompleted event for audit trail.
     *
     * @param  InventoryCounting  $counting  The completed counting operation
     * @param  User  $user  The user who completed the counting
     */
    private function dispatchInventoryCountingCompletedEvent(
        InventoryCounting $counting,
        User $user
    ): void {
        // Calculate total variance across all items
        $items = $counting->items;
        $totalVariance = '0.00';

        foreach ($items as $item) {
            $theoreticalQty = (string) $item->theoretical_qty;
            $finalQty = (string) ($item->final_qty ?? '0.0000');
            $variance = bcsub($finalQty, $theoreticalQty, 4);
            $totalVariance = bcadd($totalVariance, $variance, 4);
        }

        // Get tenant_id from counting or fallback to user's tenant_id
        $tenantId = $counting->tenant_id ?? $user->tenant_id;

        event(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $tenantId,
            companyId: $counting->company_id,
            locationId: $counting->scope_filters['location_id'] ?? '',
            countingNumber: $counting->counting_number ?? 'COUNTING-'.$counting->id,
            itemsCount: $items->count(),
            totalVariance: $totalVariance,
            completedBy: (string) $user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    /**
     * Generate a sequential counting number in the format CNT-{YYYY}-{NNNN}.
     *
     * Resets yearly per company.
     */
    private function generateCountingNumber(string $companyId): string
    {
        $year = now()->year;
        $prefix = "CNT-{$year}-";

        $lastNumber = InventoryCounting::where('company_id', $companyId)
            ->where('counting_number', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('counting_number')
            ->value('counting_number');

        $sequence = 1;
        if ($lastNumber !== null) {
            $sequence = (int) substr($lastNumber, strlen($prefix)) + 1;
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
