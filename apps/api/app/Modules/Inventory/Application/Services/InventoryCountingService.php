<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\Exceptions\CountingTransitionException;
use App\Modules\Inventory\Domain\Exceptions\OpeningCostRequiredException;
use App\Modules\Inventory\Domain\Exceptions\OverlappingCountingException;
use App\Modules\Inventory\Domain\Exceptions\TerminalSyncAcknowledgementRequiredException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Inventory\Domain\Services\FinalQuantityAsOfResolver;
use App\Modules\Inventory\Domain\Services\OpeningCostGate;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing inventory counting operations.
 */
class InventoryCountingService
{
    /**
     * Statuses considered "active" for the overlap guard: from count_1 start
     * through pending_review (inclusive). Deliberately NOT
     * InventoryCounting::scopeActive() / CountingStatus::isActive(), which
     * only cover the *InProgress cases and would miss a counting sitting in
     * count_N_completed or pending_review — both still hold an unresolved
     * claim on their items' stock grain.
     *
     * @var list<CountingStatus>
     */
    private const ACTIVE_OVERLAP_STATUSES = [
        CountingStatus::Count1InProgress,
        CountingStatus::Count1Completed,
        CountingStatus::Count2InProgress,
        CountingStatus::Count2Completed,
        CountingStatus::Count3InProgress,
        CountingStatus::Count3Completed,
        CountingStatus::PendingReview,
    ];

    public function __construct(
        private readonly CountingReconciliationService $reconciliationService,
        private readonly LocationNodeService $zoneService,
        private readonly OpeningCostGate $openingCostGate,
        private readonly TerminalSyncHealthService $terminalSyncHealthService,
        private readonly FinalQuantityAsOfResolver $finalQuantityAsOfResolver,
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
                'block_sales' => $data['block_sales'] ?? false,
                'ambiguity_window_minutes' => $data['ambiguity_window_minutes'] ?? 15,
                'count_1_user_id' => $data['count_1_user_id'],
                'count_2_user_id' => $data['count_2_user_id'] ?? null,
                'count_3_user_id' => $data['count_3_user_id'] ?? null,
                'scheduled_start' => $data['scheduled_start'] ?? null,
                'scheduled_end' => $data['scheduled_end'] ?? null,
                'instructions' => $data['instructions'] ?? null,
            ]);

            // Generate items based on scope
            $this->generateCountingItems($counting, $companyId, $this->readIncludeZeroStockFlag($data));

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
     *
     * Resolves — and persists on the counting — whether the item set includes
     * zero/negative/no-stock-row active products (`includes_zero_stock`), then
     * seeds one item per resolved (product, location[, variant]) grain.
     *
     * @param  bool|null  $explicitIncludeZeroStock  Caller-supplied opt-in
     *                                               (from CreateCountingRequest). When null the flag defaults to the
     *                                               onboarding state of the target location(s).
     */
    public function generateCountingItems(
        InventoryCounting $counting,
        string $companyId,
        ?bool $explicitIncludeZeroStock = null,
    ): void {
        $includesZeroStock = $this->resolveIncludesZeroStock(
            $counting->scope_type,
            $counting->scope_filters,
            $explicitIncludeZeroStock,
            $companyId,
        );

        if ($counting->includes_zero_stock !== $includesZeroStock) {
            $counting->includes_zero_stock = $includesZeroStock;
            $counting->save();
        }

        $seeds = $this->resolveCountingItemSeeds(
            $companyId,
            $counting->scope_type,
            $counting->scope_filters,
            $includesZeroStock,
        );

        foreach ($seeds as $seed) {
            // variant_id is propagated from the StockLevel row (Task 20).
            // For product-level stock rows (variant_id IS NULL) this remains
            // null — preserving backward compat with non-variant products.
            InventoryCountingItem::create([
                'counting_id' => $counting->id,
                'product_id' => $seed['product_id'],
                'variant_id' => $seed['variant_id'],
                'location_id' => $seed['location_id'],
                'theoretical_qty' => $seed['theoretical_qty'],
            ]);
        }
    }

    /**
     * Coerce the request-supplied `include_zero_stock` flag to a nullable bool.
     * Absent → null (defer to onboarding-based default).
     *
     * @param  array<string, mixed>  $data
     */
    private function readIncludeZeroStockFlag(array $data): ?bool
    {
        if (! array_key_exists('include_zero_stock', $data)) {
            return null;
        }

        return filter_var($data['include_zero_stock'], FILTER_VALIDATE_BOOL);
    }

    /**
     * Whether this counting's item set is sourced from the full active catalog
     * (zero/negative/no-stock-row products included) rather than the legacy
     * `quantity > 0` filter. Only `full_inventory` / `location` counts qualify —
     * they are the whole-location scopes C3's onboarding auto-exit gates on. An
     * explicit opt-in wins; otherwise it defaults to true when a target
     * location is in onboarding mode. Zone scope is partial-by-shelf and never
     * sets this flag (its own item generation still includes zero-qty products).
     *
     * @param  array<string, mixed>  $filters
     */
    private function resolveIncludesZeroStock(
        CountingScopeType $scopeType,
        array $filters,
        ?bool $explicit,
        string $companyId,
    ): bool {
        if (! in_array($scopeType, [CountingScopeType::FullInventory, CountingScopeType::Location], true)) {
            return false;
        }

        if ($explicit !== null) {
            return $explicit;
        }

        return $this->anyLocationOnboarding($scopeType, $filters, $companyId);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function anyLocationOnboarding(
        CountingScopeType $scopeType,
        array $filters,
        string $companyId,
    ): bool {
        $locationIds = $this->resolveLocationIds($scopeType, $filters, $companyId);

        if ($locationIds === []) {
            return false;
        }

        return Location::query()
            ->whereIn('id', $locationIds)
            ->where('company_id', $companyId)
            ->where('onboarding_mode', true)
            ->exists();
    }

    /**
     * The concrete location ids a whole-location scope targets.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function resolveLocationIds(
        CountingScopeType $scopeType,
        array $filters,
        string $companyId,
    ): array {
        if ($scopeType === CountingScopeType::Location) {
            /** @var list<string> $ids */
            $ids = array_values(array_filter((array) ($filters['location_ids'] ?? [])));

            return $ids;
        }

        if ($scopeType === CountingScopeType::FullInventory) {
            /** @var list<string> $ids */
            $ids = Location::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            return $ids;
        }

        return [];
    }

    /**
     * Resolve the (product, location, variant, theoretical_qty) seeds for a
     * scope. Zone scope reads `product_placements`; onboarding/opt-in
     * whole-location scopes read the full active catalog LEFT JOIN stock
     * (theoretical `'0.0000'` when no stock row); every other case keeps the
     * legacy `quantity > 0` stock-level query.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{product_id: string, variant_id: string|null, location_id: string, theoretical_qty: numeric-string}>
     */
    private function resolveCountingItemSeeds(
        string $companyId,
        CountingScopeType $scopeType,
        array $filters,
        bool $includesZeroStock,
    ): array {
        if ($scopeType === CountingScopeType::Zone) {
            return $this->zoneItemSeeds($companyId, $filters);
        }

        if ($includesZeroStock) {
            return $this->catalogItemSeeds($companyId, $scopeType, $filters);
        }

        $seeds = [];
        foreach ($this->getStockLevelsForScope($companyId, $scopeType, $filters) as $stock) {
            $seeds[] = [
                'product_id' => $stock->product_id,
                'variant_id' => $stock->variant_id,
                'location_id' => $stock->location_id,
                'theoretical_qty' => $stock->quantity,
            ];
        }

        return $seeds;
    }

    /**
     * Seeds for a node scope: every product placed directly in any selected
     * node or descendant. Products with variants expand to variant-grain
     * items; products without variants retain the null-variant grain.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{product_id: string, variant_id: string|null, location_id: string, theoretical_qty: numeric-string}>
     */
    private function zoneItemSeeds(string $companyId, array $filters): array
    {
        /** @var list<string> $zoneIds */
        $zoneIds = array_values(array_filter((array) ($filters['zone_ids'] ?? [])));

        if ($zoneIds === []) {
            return [];
        }

        // Defense-in-depth: scope_filters.location_id is validated company-owned
        // by CreateCountingRequest, and zone_ids are validated to belong to it.
        // Re-pin here too so a stale/malicious scope_filters row (e.g. one that
        // bypassed the FormRequest via a direct service call) can never seed
        // items from a zone at a foreign location.
        $locationId = $filters['location_id'] ?? null;

        if ($locationId === null || $locationId === '') {
            return [];
        }

        /** @var Collection<int, LocationNode> $selectedNodes */
        $selectedNodes = LocationNode::query()
            ->atLocation($locationId)
            ->whereIn('id', $zoneIds)
            ->get();

        if ($selectedNodes->isEmpty()) {
            return [];
        }

        /** @var list<string> $subtreeNodeIds */
        $subtreeNodeIds = LocationNode::query()
            ->atLocation($locationId)
            ->where(function (Builder $query) use ($selectedNodes): void {
                foreach ($selectedNodes as $node) {
                    $query->orWhere(
                        fn (Builder $subtree): Builder => $subtree->subtreeOf($node->path)
                    );
                }
            })
            ->pluck('id')
            ->all();

        if ($subtreeNodeIds === []) {
            return [];
        }

        // SoftDeletes default scope excludes tombstoned placements (live only).
        $assignments = ProductPlacement::query()
            ->whereIn('node_id', $subtreeNodeIds)
            ->where('location_id', $locationId)
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        /** @var list<string> $productIds */
        $productIds = $assignments->pluck('product_id')->unique()->values()->all();

        /** @var array<string, list<string>> $variantIdsByProduct */
        $variantIdsByProduct = [];
        foreach (ProductVariant::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get() as $variant) {
            $variantIdsByProduct[$variant->product_id][] = $variant->id;
        }

        /** @var array<string, numeric-string> $stockByGrain */
        $stockByGrain = [];
        foreach (StockLevel::query()
            ->forCompany($companyId)
            ->whereIn('product_id', $productIds)
            ->where('location_id', $locationId)
            ->get() as $stock) {
            $stockByGrain[$this->stockGrainKey($stock->product_id, $stock->variant_id)] = $stock->quantity;
        }

        $seeds = [];
        foreach ($assignments as $assignment) {
            $variantIds = $variantIdsByProduct[$assignment->product_id] ?? [];
            if ($variantIds === []) {
                $seeds[] = $this->nodeItemSeed(
                    $assignment->product_id,
                    null,
                    $assignment->location_id,
                    $stockByGrain,
                );

                continue;
            }

            foreach ($variantIds as $variantId) {
                $seeds[] = $this->nodeItemSeed(
                    $assignment->product_id,
                    $variantId,
                    $assignment->location_id,
                    $stockByGrain,
                );
            }
        }

        return $seeds;
    }

    private function stockGrainKey(string $productId, ?string $variantId): string
    {
        return $productId.'|'.($variantId ?? '');
    }

    /**
     * @param  array<string, numeric-string>  $stockByGrain
     * @return array{product_id: string, variant_id: string|null, location_id: string, theoretical_qty: numeric-string}
     */
    private function nodeItemSeed(
        string $productId,
        ?string $variantId,
        string $locationId,
        array $stockByGrain,
    ): array {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'theoretical_qty' => $stockByGrain[$this->stockGrainKey($productId, $variantId)] ?? '0.0000',
        ];
    }

    /**
     * Seeds for an onboarding/opt-in whole-location scope: the cartesian of
     * every active company product with each target location, LEFT JOIN stock
     * (theoretical `'0.0000'` when absent). Never-received and already-sold
     * (negative) products — exactly the ones the legacy `quantity > 0` filter
     * drops — are included.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{product_id: string, variant_id: string|null, location_id: string, theoretical_qty: numeric-string}>
     */
    private function catalogItemSeeds(string $companyId, CountingScopeType $scopeType, array $filters): array
    {
        $locationIds = $this->resolveLocationIds($scopeType, $filters, $companyId);

        if ($locationIds === []) {
            return [];
        }

        /** @var list<string> $productIds */
        $productIds = Product::query()
            ->where('company_id', $companyId)
            ->active()
            ->pluck('id')
            ->all();

        if ($productIds === []) {
            return [];
        }

        // Product-grain on-hand keyed by "product|location".
        /** @var array<string, numeric-string> $stockByKey */
        $stockByKey = [];
        StockLevel::query()
            ->forCompany($companyId)
            ->whereIn('location_id', $locationIds)
            ->whereNull('variant_id')
            ->get(['product_id', 'location_id', 'quantity'])
            ->each(function (StockLevel $stock) use (&$stockByKey): void {
                $stockByKey[$stock->product_id.'|'.$stock->location_id] = $stock->quantity;
            });

        $seeds = [];
        foreach ($locationIds as $locationId) {
            foreach ($productIds as $productId) {
                $seeds[] = [
                    'product_id' => $productId,
                    'variant_id' => null,
                    'location_id' => $locationId,
                    'theoretical_qty' => $stockByKey[$productId.'|'.$locationId] ?? '0.0000',
                ];
            }
        }

        return $seeds;
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
                $this->assertNoOverlappingActiveCounting($counting);

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
            $this->assertNoOverlappingActiveCounting($counting);

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
            // Typed (not `\InvalidArgumentException`): "the session moved on"
            // is a business-rule refusal the counter must be able to read, and
            // the bare type had no render handler, so a counter whose phase had
            // advanced got a 500 with no guidance. See H-2.
            throw new CountingTransitionException($counting->id, $counting->status, $expectedStatus);
        }

        $userId = (string) $user->id;

        DB::transaction(function () use ($item, $countNumber, $quantity, $notes, $userId, $counting, $expectedStatus, $countedAtDevice, $deviceNow): void {
            // H-2: lock the counting header FIRST, so the count-then-act in
            // checkPhaseCompletion() below serializes against every other
            // submitter. Two counters finishing the last items of a phase
            // concurrently used to each hold a stale in-memory counting: either
            // both saw an incomplete phase (nobody advanced it — the count
            // wedged fully-counted but in_progress) or the loser re-drove the
            // transition on a stale status, silently regressing the counting and
            // re-running the phase's side effects.
            $lockedCounting = $this->lockCounting($counting->id);

            // TERMINAL re-assert (gate r1 BLOCKER-1). The lock WAIT is itself
            // the window: this request passed the pre-transaction phase guard on
            // a live snapshot, then blocked here while another request finalized
            // or cancelled the counting, and now resumes holding the lock. An
            // earlier revision of this method argued the pre-transaction guard
            // made a re-assert unnecessary — that was WRONG, and probe A proved
            // it by writing count_1_qty = 99.0000 into a FINALIZED counting
            // whose variance had already been posted to stock.
            //
            // Terminal statuses ONLY. Finalized and Cancelled have no outgoing
            // edge (CountingStatus::allowedTransitions() => []), so a write
            // against them can never be repaired in-product. Every FORWARD phase
            // difference stays tolerated below — re-asserting the phase here is
            // what would resurrect the rollback this lane exists to remove
            // (the counter's quantity, its audit row and the assignment
            // increment all discarded for an interleave the lock made safe).
            $this->assertNotTerminal($lockedCounting, $expectedStatus);

            // Submit the count
            $item->submitCount($countNumber, $quantity, $notes, $countedAtDevice, $deviceNow);

            // Assign-as-you-count: the first count of an item in a single-zone
            // session preserves a precise descendant placement and re-homes only
            // an unplaced or out-of-subtree product. Multiple zones in scope are
            // ambiguous, so assignment is skipped.
            $this->assignCountedItemToZone($item, $lockedCounting, $countNumber);

            // Record event
            InventoryCountingEvent::recordCountSubmitted(
                $item,
                $countNumber,
                $quantity,
                $notes,
                $userId
            );

            // Update assignment progress
            $assignment = $lockedCounting->assignments()
                ->where('count_number', $countNumber)
                ->first();
            $assignment?->incrementProgress();

            // Check if this phase is complete — on the LOCKED instance, whose
            // status is the committed truth rather than the caller's snapshot.
            $this->checkPhaseCompletion($lockedCounting, $countNumber);
        });
    }

    /**
     * Re-read a counting header FOR UPDATE inside the caller's transaction.
     *
     * Gate r1: the lifecycle-mutating paths (submitCount, triggerThirdCount,
     * finalize, cancel) take this before they write, so the status they decide
     * on is the committed truth rather than the snapshot the request loaded —
     * the lock WAIT is long enough for another request to finalize or cancel
     * the same counting. NOT yet covered (gate r2 NEW-2, pre-existing, out of
     * lane Q-2 scope — LEDGER): manualOverride() writes item quantities with
     * neither this lock nor a terminal-state guard.
     */
    private function lockCounting(string $countingId): InventoryCounting
    {
        /** @var InventoryCounting $locked */
        $locked = InventoryCounting::query()
            ->whereKey($countingId)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * Refuse the attempted action when the LOCKED counting has already reached a
     * terminal status.
     *
     * Finalized and Cancelled have no outgoing edge, so anything written against
     * them is unreachable by any in-product repair: a finalized counting has
     * already posted its variance to stock (and, since lane Q-2, holds a unique
     * counting-apply movement per grain, so the correction cannot be re-posted),
     * and a cancelled one asserts that nothing was posted at all.
     *
     * Deliberately terminal-ONLY. Forward-phase differences are the ordinary
     * concurrent-counter case and must stay tolerated by the caller.
     */
    private function assertNotTerminal(InventoryCounting $locked, CountingStatus $attempted): void
    {
        if ($locked->status === CountingStatus::Finalized || $locked->status === CountingStatus::Cancelled) {
            throw new CountingTransitionException($locked->id, $locked->status, $attempted);
        }
    }

    /**
     * Place the just-counted product in the session's single zone when it is
     * unplaced or currently outside that zone's subtree. Preserve a precise
     * descendant placement. No-op for every other scope, multi-zone sessions,
     * and counts after the first (a later count must not overwrite a deliberate
     * mid-count reassignment).
     */
    private function assignCountedItemToZone(
        InventoryCountingItem $item,
        InventoryCounting $counting,
        int $countNumber,
    ): void {
        if ($countNumber !== 1 || $counting->scope_type !== CountingScopeType::Zone) {
            return;
        }

        /** @var list<string> $zoneIds */
        $zoneIds = array_values(array_filter((array) ($counting->scope_filters['zone_ids'] ?? [])));

        if (count($zoneIds) !== 1) {
            return;
        }

        $zone = LocationNode::query()
            ->atLocation($item->location_id)
            ->whereKey($zoneIds[0])
            ->first();
        $currentPlacement = ProductPlacement::query()
            ->where('product_id', $item->product_id)
            ->where('location_id', $item->location_id)
            ->lockForUpdate()
            ->first();

        if (
            $zone !== null
            && $currentPlacement !== null
            && LocationNode::query()
                ->atLocation($item->location_id)
                ->subtreeOf($zone->path)
                ->whereKey($currentPlacement->node_id)
                ->exists()
        ) {
            return;
        }

        // Old flat-zone rows resolved tenant from the zone itself; the node
        // service takes it explicitly. tenant_id is nullable on the model but
        // always set for zone-scoped sessions — resolve via the node when absent.
        $tenantId = $counting->tenant_id ?? $zone?->tenant_id;

        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }

        $this->zoneService->assignProduct($tenantId, $item->product_id, $item->location_id, $zoneIds[0]);
    }

    /**
     * Check if a counting phase is complete and transition status.
     *
     * H-2: the caller must hand in a counting instance re-read UNDER the row
     * lock (see submitCount). Two things follow from that contract:
     *
     *  - the item counts below are consistent with every committed sibling
     *    submission, so the phase advances exactly once instead of twice or
     *    never; and
     *  - `$counting->status` is the committed truth, so the "already advanced"
     *    check is meaningful. When another submitter closed this phase first
     *    this is a NO-OP, never a throw: throwing here would roll back the
     *    caller's own count, its audit row and its assignment progress inside
     *    the shared transaction, which is the wedge this lane removes.
     */
    private function checkPhaseCompletion(InventoryCounting $counting, int $countNumber): void
    {
        $column = "count_{$countNumber}_qty";
        $totalItems = $counting->items()->count();
        $countedItems = $counting->items()->whereNotNull($column)->count();

        if ($countedItems < $totalItems) {
            return; // Phase not complete
        }

        $completedStatus = match ($countNumber) {
            1 => CountingStatus::Count1Completed,
            2 => CountingStatus::Count2Completed,
            3 => CountingStatus::Count3Completed,
            default => throw new \InvalidArgumentException('Invalid count number'),
        };

        if (! $counting->canTransitionTo($completedStatus)) {
            // Another submitter already closed this phase (or the session was
            // cancelled). Leave the counting exactly where it is.
            return;
        }

        // Phase is complete
        $assignment = $counting->assignments()
            ->where('count_number', $countNumber)
            ->first();
        $assignment?->complete();

        // Transition to completed status first
        $counting->transitionTo($completedStatus);

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
            ->with(['product.unitOfMeasure', 'location', 'resolvedBy'])
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
            // Gate r1 BLOCKER-2. This path had NO lock and tested the caller's
            // snapshot, so a reviewer holding a pending_review handle could send
            // an already-FINALIZED counting back to a third count: probe B
            // regressed finalized -> count_3_in_progress. The re-finalize that
            // follows double-fires InventoryCountingCompleted, the listener's
            // applied-markers skip every item, and the counting-apply unique
            // index makes re-posting the corrected quantities impossible — the
            // stock correction is silently lost.
            //
            // Lock FIRST, so neither the item reset below nor the assignment
            // write can touch a terminal counting.
            $lockedCounting = $this->lockCounting($counting->id);
            $this->assertNotTerminal($lockedCounting, CountingStatus::Count3InProgress);

            // Update items to require third count
            InventoryCountingItem::whereIn('id', $itemIds)
                ->where('counting_id', $counting->id)
                ->update([
                    'resolution_method' => ItemResolutionMethod::Pending,
                    'is_flagged' => true,
                    'flag_reason' => 'third_count_requested',
                ]);

            // Update count 3 assignment
            $assignment = $lockedCounting->assignments()
                ->where('count_number', 3)
                ->first();

            if ($assignment !== null) {
                $assignment->total_items = count($itemIds);
                $assignment->save();
            }

            // Transition to count 3 in progress — decided on the LOCKED
            // instance's committed status, not the caller's snapshot.
            if ($lockedCounting->status === CountingStatus::PendingReview) {
                $lockedCounting->transitionTo(CountingStatus::Count3InProgress);
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
            // ...and its same-second tie-break is the movement order AT that
            // instant (W4-6 gate r2, NEW-1). The override is authored now, so
            // everything already on the line is baseline.
            $item->final_qty_movement_marker = $item->latestMovementMarker();
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
    public function finalize(
        InventoryCounting $counting,
        User $user,
        bool $terminalSyncRiskAcknowledged = false,
        ?string $terminalSyncHealthSignature = null,
    ): void {
        // Ensure all items are resolved
        $unresolvedCount = $counting->items()
            ->where('resolution_method', ItemResolutionMethod::Pending)
            ->count();

        if ($unresolvedCount > 0) {
            throw new \InvalidArgumentException(
                "Cannot finalize: {$unresolvedCount} items still pending resolution"
            );
        }

        // Re-validate the overlap guard at finalize time: the counting was
        // clear of conflicts at activation, but another counting may have
        // since become active over the same stock grain (e.g. via a
        // different activation path, or an unexpected item added mid-flight).
        $this->assertNoOverlappingActiveCounting($counting);

        // Pre-finalize opening-cost gate (D3): a cost-less onboarding opening
        // must be caught BEFORE the transition to Finalized. Finalized has no
        // outgoing transition, so a cost backfilled afterwards could never post
        // — the counted quantity would be silently stranded. Reject here so the
        // reviewer supplies (or explicitly zeroes) the cost first.
        $this->assertOpeningCostsResolved($counting);

        $terminalSyncHealth = $this->terminalSyncHealthService->forCounting($counting);
        $validAcknowledgement = $terminalSyncRiskAcknowledged
            && is_string($terminalSyncHealth['acknowledgement_signature'])
            && is_string($terminalSyncHealthSignature)
            && hash_equals($terminalSyncHealth['acknowledgement_signature'], $terminalSyncHealthSignature);
        if ($terminalSyncHealth['requires_acknowledgement'] && ! $validAcknowledgement) {
            throw new TerminalSyncAcknowledgementRequiredException;
        }

        DB::transaction(function () use (
            $counting,
            $user,
            $terminalSyncHealth,
            $validAcknowledgement,
            $terminalSyncHealthSignature,
        ): void {
            // H-1: serialize concurrent finalizes on the counting row. Every
            // sibling stock-moving document in this module locks its header
            // before posting (GoodsReceiptService::post, StockAdjustment-
            // DocumentService::post, SupplierGoodsReturnNoteService, Stock-
            // TransferService); counting was the outlier. Without this, two
            // requests that both read `pending_review` (double click, client
            // retry, two supervisors) both passed canTransitionTo() on their
            // own stale in-memory instance, both committed, and both fired
            // InventoryCountingCompleted — applying the count variance twice
            // onto on-hand. Finalized has no outgoing edge, so there is no
            // in-product way back.
            //
            // The lock is taken BEFORE the replay-boundary loop below so a
            // losing finalize does not stamp final_qty_as_of either.
            /** @var InventoryCounting $lockedCounting */
            $lockedCounting = InventoryCounting::query()
                ->whereKey($counting->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCounting->status !== CountingStatus::PendingReview) {
                throw new CountingTransitionException(
                    $counting->id,
                    $lockedCounting->status,
                    CountingStatus::Finalized,
                );
            }

            // Re-assert on the caller's instance too: it is the one that gets
            // transitioned and saved below, and it may have been loaded before
            // the row reached its current status.
            $counting->status = $lockedCounting->status;

            // Freeze each auto-resolved item's replay boundary before the
            // finalize event fires (the queued listener reads final_qty_as_of to
            // choose the replay path vs the legacy delta path). Manual overrides
            // already stamped their own as-of at override time.
            foreach ($counting->items as $item) {
                if ($item->final_qty_as_of !== null) {
                    continue;
                }

                $asOf = $this->finalQuantityAsOfResolver->resolve($item);
                if ($asOf !== null) {
                    $item->final_qty_as_of = Carbon::instance($asOf);
                    // Freeze the SAME phase's movement-order marker with it, so
                    // the boundary and its same-second tie-break can never come
                    // from different counts (W4-6 gate r2, NEW-1).
                    $item->final_qty_movement_marker = $this->finalQuantityAsOfResolver->resolveMarker($item);
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
                    'terminal_sync_health_acknowledged' => $validAcknowledgement,
                    'terminal_sync_health_acknowledged_by' => $validAcknowledgement ? $user->id : null,
                    'terminal_sync_health_acknowledged_at' => $validAcknowledgement ? now()->toIso8601String() : null,
                    'terminal_sync_health_signature' => $validAcknowledgement
                        ? $terminalSyncHealthSignature
                        : null,
                    'terminal_sync_health' => $terminalSyncHealth,
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
     * Cancel a counting operation.
     */
    public function cancel(InventoryCounting $counting, string $reason, User $user): void
    {
        if ($counting->status === CountingStatus::Finalized ||
            $counting->status === CountingStatus::Cancelled) {
            // Typed for the same reason as the locked re-assert below: leaving
            // this bare would mean the COMMON path (a fresh load of an already
            // terminal counting) 500s while the rare lost race 422s, for one
            // and the same refusal.
            throw new CountingTransitionException($counting->id, $counting->status, CountingStatus::Cancelled);
        }

        DB::transaction(function () use ($counting, $reason, $user): void {
            // Gate r1 IMPORTANT-3. The guard above reads the caller's snapshot,
            // so a stale handle cancelled a FINALIZED counting (probe C): the
            // stock had already moved, but the document then read `cancelled`.
            // Re-assert under the lock, before the cancellation_reason write.
            $lockedCounting = $this->lockCounting($counting->id);
            $this->assertNotTerminal($lockedCounting, CountingStatus::Cancelled);

            $previousStatus = $lockedCounting->status->value;
            $lockedCounting->cancellation_reason = $reason;
            $lockedCounting->save();
            $lockedCounting->transitionTo(CountingStatus::Cancelled);

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
     * Pre-finalize opening-cost gate (D3, spec §5): reject finalize when any
     * line that WILL post as an onboarding opening balance still lacks a
     * resolvable positive cost. Uses OpeningCostGate — the SAME computation the
     * web review payload surfaces (`opening_cost_missing`) — so the FE gate and
     * the server guarantee never diverge. Items with no `final_qty` post
     * nothing and are skipped. Reads items fresh (with `product`) so a cost
     * backfilled via the opening-cost endpoint since load is honoured.
     *
     * @throws OpeningCostRequiredException
     */
    private function assertOpeningCostsResolved(InventoryCounting $counting): void
    {
        /** @var Collection<int, InventoryCountingItem> $items */
        $items = $counting->items()->with(['product', 'location'])->get();

        $missing = [];
        foreach ($items as $item) {
            if ($item->final_qty === null) {
                continue;
            }

            $onboarding = (bool) $item->location->onboarding_mode;
            $result = $this->openingCostGate->evaluateItem($item, $onboarding);

            if (! $result['opening_cost_missing']) {
                continue;
            }

            $missing[] = $item->product->name.' ('.$item->product->sku.')';
        }

        if ($missing !== []) {
            throw new OpeningCostRequiredException(array_values(array_unique($missing)));
        }
    }

    /**
     * Guard against a counting being active (or finalizing) while any of its
     * items overlap another counting's items on
     * `(product_id, location_id, variant_id)` — null-variant-aware: a
     * NULL-variant row on both sides is treated as the same grain, but a
     * NULL-variant row never matches a specific-variant row for the same
     * product/location. Countings in a non-active status (draft, scheduled,
     * finalized, cancelled) never conflict.
     *
     * @throws OverlappingCountingException
     */
    private function assertNoOverlappingActiveCounting(InventoryCounting $counting): void
    {
        $activeStatusValues = array_map(
            static fn (CountingStatus $status): string => $status->value,
            self::ACTIVE_OVERLAP_STATUSES,
        );

        $conflict = DB::table('inventory_counting_items as a')
            ->join('inventory_counting_items as b', function ($join): void {
                $join->on('a.product_id', '=', 'b.product_id')
                    ->on('a.location_id', '=', 'b.location_id')
                    ->where(function ($nested): void {
                        $nested->whereColumn('a.variant_id', '=', 'b.variant_id')
                            ->orWhere(function ($bothNull): void {
                                $bothNull->whereNull('a.variant_id')->whereNull('b.variant_id');
                            });
                    });
            })
            ->join('inventory_countings as c', 'b.counting_id', '=', 'c.id')
            ->where('a.counting_id', $counting->id)
            ->where('b.counting_id', '!=', $counting->id)
            ->where('c.company_id', $counting->company_id)
            ->whereIn('c.status', $activeStatusValues)
            ->select(['b.counting_id as conflicting_counting_id', 'a.product_id', 'a.location_id', 'a.variant_id'])
            ->first();

        if ($conflict !== null) {
            throw new OverlappingCountingException(
                $counting->id,
                (string) $conflict->conflicting_counting_id,
                (string) $conflict->product_id,
                (string) $conflict->location_id,
                $conflict->variant_id !== null ? (string) $conflict->variant_id : null,
            );
        }
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
