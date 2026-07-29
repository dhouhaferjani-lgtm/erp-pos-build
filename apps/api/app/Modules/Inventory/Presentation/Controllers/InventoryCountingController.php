<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\CountingDiscrepancyReportService;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\TerminalSyncHealthService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Exceptions\TerminalSyncAcknowledgementRequiredException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Presentation\Requests\ActivateCountingRequest;
use App\Modules\Inventory\Presentation\Requests\AddProductToCountingRequest;
use App\Modules\Inventory\Presentation\Requests\CreateCountingRequest;
use App\Modules\Inventory\Presentation\Requests\CreateDraftCountingRequest;
use App\Modules\Inventory\Presentation\Requests\UpdateDraftCountingRequest;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\POS\TerminalSyncHealthSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryCountingController extends Controller
{
    private const BATCH_ERROR_PRODUCT_ALREADY_IN_COUNT = 'PRODUCT_ALREADY_IN_COUNT';

    private const BATCH_ERROR_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    private const BATCH_ERROR_INVALID_BARCODE = 'INVALID_BARCODE';

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly InventoryCountingService $countingService,
        private readonly CountingDiscrepancyReportService $reportService,
        private readonly TerminalSyncHealthService $terminalSyncHealthService,
        private readonly TerminalSyncHealthSource $terminalSyncHealthSource,
    ) {}

    /**
     * Onboarding worklist (C3): active-catalog products at a location that
     * still need attention before the location can safely exit onboarding —
     * negative on-hand, or no `stock_levels` row at all — excluding any
     * product already given a submitted count (any count_N_qty populated) in
     * an active or finalized counting at that location.
     */
    public function onboardingWorklist(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array{location_id: string} $validated */
        $validated = Validator::make(
            ['location_id' => $request->query('location_id')],
            ['location_id' => ['required', 'bail', 'uuid']],
        )->validate();

        $locationId = $validated['location_id'];

        $location = Location::query()->forCompany($companyId)->find($locationId);

        if ($location === null) {
            return response()->json([
                'error' => [
                    'code' => 'LOCATION_NOT_FOUND',
                    'message' => 'Location not found for the current company.',
                ],
            ], 404);
        }

        $countedProductIds = DB::table('inventory_counting_items')
            ->join('inventory_countings', 'inventory_countings.id', '=', 'inventory_counting_items.counting_id')
            ->where('inventory_counting_items.location_id', $locationId)
            ->whereNotIn('inventory_countings.status', [
                CountingStatus::Draft->value,
                CountingStatus::Scheduled->value,
                CountingStatus::Cancelled->value,
            ])
            ->where(function ($query): void {
                $query->whereNotNull('inventory_counting_items.count_1_qty')
                    ->orWhereNotNull('inventory_counting_items.count_2_qty')
                    ->orWhereNotNull('inventory_counting_items.count_3_qty');
            })
            ->select('inventory_counting_items.product_id');

        $query = DB::table('products')
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->leftJoin('stock_levels', function ($join) use ($locationId): void {
                $join->on('stock_levels.product_id', '=', 'products.id')
                    ->where('stock_levels.location_id', $locationId)
                    ->whereNull('stock_levels.variant_id');
            })
            ->where(function ($q): void {
                $q->where('stock_levels.quantity', '<', 0)
                    ->orWhereNull('stock_levels.id');
            })
            ->whereNotIn('products.id', $countedProductIds)
            ->select([
                'products.id as product_id',
                'products.name as name',
                'products.sku as sku',
                'stock_levels.quantity as on_hand',
            ])
            ->orderBy('products.name');

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $products = $query->paginate($perPage);

        /** @var Collection<int, \stdClass> $productRows */
        $productRows = $products->getCollection();

        /** @var list<string> $productIds */
        $productIds = $productRows->pluck('product_id')->all();

        /** @var array<string, string> $lastSoldByProduct */
        $lastSoldByProduct = DB::table('stock_movements')
            ->where('location_id', $locationId)
            ->where('reason', MovementReason::POSSale->value)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, MAX(occurred_at) as last_sold_at')
            ->groupBy('product_id')
            ->pluck('last_sold_at', 'product_id')
            ->all();

        $data = $productRows->map(function (\stdClass $row) use ($lastSoldByProduct): array {
            /** @var string $productId */
            $productId = $row->product_id;
            /** @var string|null $lastSoldAt */
            $lastSoldAt = $lastSoldByProduct[$productId] ?? null;

            /** @var numeric-string $onHandRaw */
            $onHandRaw = (string) ($row->on_hand ?? '0');
            $onHand = bcadd($onHandRaw, '0', InventoryScale::QUANTITY_SCALE);

            return [
                'product_id' => $productId,
                'name' => $row->name,
                'sku' => $row->sku,
                'on_hand' => $onHand,
                'last_sold_at' => $lastSoldAt !== null ? Carbon::parse($lastSoldAt)->toIso8601String() : null,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Dashboard summary.
     */
    public function dashboard(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $active = InventoryCounting::forCompany($companyId)->active()->count();
        $pendingReview = InventoryCounting::forCompany($companyId)->pendingReview()->count();
        $completedThisMonth = InventoryCounting::forCompany($companyId)
            ->where('status', CountingStatus::Finalized)
            ->whereNotNull('finalized_at')
            ->whereMonth('finalized_at', now()->month)
            ->count();
        $overdue = InventoryCounting::forCompany($companyId)
            ->active()
            ->where('scheduled_end', '<', now())
            ->count();

        $activeCounts = InventoryCounting::forCompany($companyId)
            ->active()
            ->with(['count1User', 'count2User', 'count3User', 'assignments'])
            ->orderBy('scheduled_end')
            ->take(5)
            ->get();

        $pendingReviewCounts = InventoryCounting::forCompany($companyId)
            ->pendingReview()
            ->with(['items'])
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'data' => [
                'summary' => [
                    'active' => $active,
                    'pending_review' => $pendingReview,
                    'completed_this_month' => $completedThisMonth,
                    'overdue' => $overdue,
                ],
                'active_counts' => $this->transformCountings($activeCounts),
                'pending_review' => $this->transformCountings($pendingReviewCounts),
            ],
        ]);
    }

    /**
     * List counting operations.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = InventoryCounting::forCompany($companyId)
            ->with(['count1User', 'count2User', 'count3User', 'createdBy']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('id', 'ilike', "%{$search}%");
        }

        // Apply sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $countings = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $this->transformCountings($countings->getCollection()),
            'meta' => [
                'current_page' => $countings->currentPage(),
                'last_page' => $countings->lastPage(),
                'per_page' => $countings->perPage(),
                'total' => $countings->total(),
            ],
        ]);
    }

    /**
     * Show counting details (admin view - includes all data).
     */
    public function show(string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $counting = InventoryCounting::forCompany($companyId)
            ->with([
                'count1User',
                'count2User',
                'count3User',
                'createdBy',
                'assignments.user',
                'items.product',
                'items.location',
            ])
            ->findOrFail($countingId);

        return response()->json([
            'data' => $this->transformCounting($counting, true),
        ]);
    }

    public function report(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = InventoryCounting::forCompany($companyId)
            ->with([
                'count1User',
                'count2User',
                'count3User',
                'createdBy',
                'assignments.user',
                'company',
            ])
            ->findOrFail($countingId);

        if (! in_array($counting->status, [CountingStatus::PendingReview, CountingStatus::Finalized], true)) {
            return response()->json([
                'error' => [
                    'code' => 'COUNTING_REPORT_UNAVAILABLE',
                    'message' => 'Counting report is only available for pending review or finalized countings.',
                ],
            ], 422);
        }

        return response()->json([
            'data' => $this->reportService->build(
                $counting,
                $user,
                $this->transformCounting($counting, false),
            ),
        ]);
    }

    /**
     * Counter view (BLIND - no theoretical quantities!).
     *
     * CRITICAL: This endpoint must NEVER return theoretical_qty or other counters' results.
     */
    public function counterView(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);
        $userId = (string) $user->id;

        // Verify user is assigned
        if (! $counting->isUserAssigned($userId)) {
            abort(403, 'You are not assigned to this counting');
        }

        $countNumber = $counting->getUserCountNumber($userId);
        $items = $this->countingService->getItemsForCounter($counting, $user);

        // Transform items - NEVER include theoretical_qty
        $transformedItems = $items->map(function ($item) use ($countNumber) {
            $myCountColumn = "count_{$countNumber}_qty";
            $myCountAtColumn = "count_{$countNumber}_at";

            return [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'barcode' => $item->product->barcode ?? null,
                ],
                'location' => [
                    'id' => $item->location->id,
                    'code' => $item->location->code ?? null,
                    'name' => $item->location->name,
                ],
                'is_counted' => $item->$myCountColumn !== null,
                'my_count' => $item->$myCountColumn,
                'my_count_at' => $item->$myCountAtColumn,
                // NEVER INCLUDE: theoretical_qty, count_1_qty, count_2_qty, count_3_qty
            ];
        });

        return response()->json([
            'data' => [
                'counting' => [
                    'id' => $counting->id,
                    'status' => $counting->status->value,
                    'instructions' => $counting->instructions,
                    'deadline' => $counting->scheduled_end?->toIso8601String(),
                    'block_sales' => $counting->block_sales,
                    'ambiguity_window_minutes' => $counting->ambiguity_window_minutes,
                    'includes_zero_stock' => $counting->includes_zero_stock,
                ],
                'my_count_number' => $countNumber,
                'items' => $transformedItems,
                'progress' => [
                    'counted' => $items->filter(fn ($i) => $i->{"count_{$countNumber}_qty"} !== null)->count(),
                    'total' => $items->count(),
                ],
            ],
        ]);
    }

    /**
     * Create counting operation.
     */
    public function store(CreateCountingRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = $this->countingService->create(
            $request->validated(),
            $user,
            $companyId
        );

        return response()->json([
            'data' => $this->transformCounting($counting),
        ], 201);
    }

    /**
     * Activate counting.
     */
    public function activate(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);

        $this->countingService->activate($counting, $user);

        return response()->json([
            'message' => 'Counting activated successfully',
            'data' => $this->transformCounting($counting->fresh() ?? $counting),
        ]);
    }

    /**
     * Cancel counting.
     */
    public function cancel(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);

        $this->countingService->cancel(
            $counting,
            (string) $request->input('reason'),
            $user
        );

        return response()->json([
            'message' => 'Counting cancelled',
        ]);
    }

    /**
     * Finalize counting.
     */
    public function finalize(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        /** @var array{acknowledge_terminal_sync_risk?: bool, terminal_sync_health_signature?: string|null} $validated */
        $validated = Validator::make($request->all(), [
            'acknowledge_terminal_sync_risk' => ['sometimes', 'boolean'],
            'terminal_sync_health_signature' => ['nullable', 'string', 'size:64'],
        ])->validate();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);

        try {
            $this->countingService->finalize(
                $counting,
                $user,
                $validated['acknowledge_terminal_sync_risk'] ?? false,
                $validated['terminal_sync_health_signature'] ?? null,
            );
        } catch (TerminalSyncAcknowledgementRequiredException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED',
                    'message' => $exception->getMessage(),
                    'terminal_sync_health' => $this->terminalSyncHealthService->forCounting($counting),
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Counting finalized successfully',
            'data' => $this->transformCounting($counting->fresh() ?? $counting),
        ]);
    }

    /**
     * Receive device-local pending-receipt truth after a POS sync tick.
     */
    public function reportTerminalSyncHealth(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var array{terminal_id: string, hardware_identifier: string, pending_receipt_count: int, last_sync_at: string} $validated */
        $validated = Validator::make($request->all(), [
            'terminal_id' => ['required', 'uuid'],
            'hardware_identifier' => ['required', 'string', 'max:255'],
            'pending_receipt_count' => ['required', 'integer', 'min:0'],
            'last_sync_at' => ['required', 'date'],
        ])->validate();

        $terminal = $this->terminalSyncHealthSource->physicalForDevice(
            $companyId,
            $validated['terminal_id'],
            $validated['hardware_identifier'],
        );

        if ($terminal === null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_DEVICE_MISMATCH',
                    'message' => 'The physical terminal is not claimed by this device.',
                ],
            ], 422);
        }

        $this->terminalSyncHealthService->record(
            $companyId,
            $terminal['id'],
            $validated['pending_receipt_count'],
            CarbonImmutable::parse($validated['last_sync_at']),
        );

        return response()->json(['data' => ['recorded' => true]]);
    }

    /**
     * Get my assigned tasks.
     */
    public function myTasks(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $countings = InventoryCounting::forCompany($companyId)
            ->where(function ($query) use ($userId): void {
                $query->where('count_1_user_id', $userId)
                    ->orWhere('count_2_user_id', $userId)
                    ->orWhere('count_3_user_id', $userId);
            })
            ->whereIn('status', [
                CountingStatus::Count1InProgress,
                CountingStatus::Count2InProgress,
                CountingStatus::Count3InProgress,
            ])
            ->with(['assignments'])
            ->get();

        return response()->json([
            'data' => $countings->map(fn ($counting) => [
                'id' => $counting->id,
                'status' => $counting->status->value,
                'my_count_number' => $counting->getUserCountNumber($userId),
                'instructions' => $counting->instructions,
                'deadline' => $counting->scheduled_end?->toIso8601String(),
                'progress' => $counting->getProgress(),
            ]),
        ]);
    }

    /**
     * Transform a collection of countings for response.
     *
     * @param  Collection<int, InventoryCounting>  $countings
     * @return array<int, array<string, mixed>>
     */
    private function transformCountings($countings): array
    {
        return $countings->map(fn ($counting) => $this->transformCounting($counting))->all();
    }

    /**
     * Transform a counting for response.
     *
     * @return array<string, mixed>
     */
    private function transformCounting(InventoryCounting $counting, bool $includeItems = false): array
    {
        $data = [
            'id' => $counting->id,
            'counting_number' => $counting->counting_number,
            'company_id' => $counting->company_id,
            'scope_type' => $counting->scope_type->value,
            'scope_filters' => $counting->scope_filters,
            'execution_mode' => $counting->execution_mode->value,
            'status' => $counting->status->value,
            'scheduled_start' => $counting->scheduled_start?->toIso8601String(),
            'scheduled_end' => $counting->scheduled_end?->toIso8601String(),
            'requires_count_2' => $counting->requires_count_2,
            'requires_count_3' => $counting->requires_count_3,
            'allow_unexpected_items' => $counting->allow_unexpected_items,
            'instructions' => $counting->instructions,
            'block_sales' => $counting->block_sales,
            'ambiguity_window_minutes' => $counting->ambiguity_window_minutes,
            'includes_zero_stock' => $counting->includes_zero_stock,
            'created_at' => $counting->created_at?->toIso8601String(),
            'activated_at' => $counting->activated_at?->toIso8601String(),
            'finalized_at' => $counting->finalized_at?->toIso8601String(),
            'cancelled_at' => $counting->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $counting->cancellation_reason,
            'progress' => $counting->getProgress(),
            'count_1_user' => $counting->count1User ? [
                'id' => $counting->count1User->id,
                'name' => $counting->count1User->name,
            ] : null,
            'count_2_user' => $counting->count2User ? [
                'id' => $counting->count2User->id,
                'name' => $counting->count2User->name,
            ] : null,
            'count_3_user' => $counting->count3User ? [
                'id' => $counting->count3User->id,
                'name' => $counting->count3User->name,
            ] : null,
            'created_by' => $counting->createdBy ? [
                'id' => $counting->createdBy->id,
                'name' => $counting->createdBy->name,
            ] : null,
        ];

        if ($counting->relationLoaded('assignments')) {
            $data['assignments'] = $counting->assignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'user' => [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->name,
                ],
                'count_number' => $assignment->count_number,
                'status' => $assignment->status->value,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'started_at' => $assignment->started_at?->toIso8601String(),
                'completed_at' => $assignment->completed_at?->toIso8601String(),
                'deadline' => $assignment->deadline?->toIso8601String(),
                'total_items' => $assignment->total_items,
                'counted_items' => $assignment->counted_items,
                'progress_percentage' => $assignment->total_items > 0
                    ? (int) round($assignment->counted_items / $assignment->total_items * 100)
                    : 0,
            ])->all();
        }

        if ($includeItems && $counting->relationLoaded('items')) {
            $data['items'] = $counting->items->map(fn ($item) => [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                ],
                'location' => [
                    'id' => $item->location->id,
                    'name' => $item->location->name,
                ],
                'theoretical_qty' => $item->theoretical_qty,
                'count_1_qty' => $item->count_1_qty,
                'count_2_qty' => $item->count_2_qty,
                'count_3_qty' => $item->count_3_qty,
                'final_qty' => $item->final_qty,
                'variance' => $item->getVariance(),
                'resolution_method' => $item->resolution_method->value,
                'is_flagged' => $item->is_flagged,
                'flag_reason' => $item->flag_reason,
            ])->all();
        }

        return $data;
    }

    /**
     * Create a draft counting operation (mobile-initiated).
     *
     * Allows managers to start a count with minimal validation.
     * Products can be added incrementally via addProduct endpoint.
     */
    public function createDraft(CreateDraftCountingRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $counting = new InventoryCounting;
        $counting->id = (string) Str::uuid();
        $counting->tenant_id = $user->tenant_id;
        $counting->company_id = $companyId;
        $counting->created_by_user_id = $userId;
        $counting->created_on_mobile = $request->input('created_on_mobile', true);
        $counting->title = $request->input('title');
        $counting->scope_type = $request->input('scope_type');
        $counting->status = CountingStatus::Draft;
        $counting->execution_mode = $request->input('execution_mode', 'sequential');
        $counting->requires_count_2 = $request->input('requires_count_2', false);
        $counting->requires_count_3 = $request->input('requires_count_3', false);
        $counting->allow_unexpected_items = $request->input('allow_unexpected_items', true);
        $counting->instructions = $request->input('instructions');
        $counting->scope_filters = $request->input('scope_filters', []);
        $counting->count_1_user_id = $request->input('count_1_user_id');
        $counting->count_2_user_id = $request->input('count_2_user_id');
        $counting->count_3_user_id = $request->input('count_3_user_id');
        $counting->scheduled_start = $request->input('scheduled_start');
        $counting->scheduled_end = $request->input('scheduled_end');
        $counting->last_modified_at = now()->toDateTimeString();
        $counting->last_modified_by_user_id = $userId;

        $counting->save();

        return response()->json([
            'data' => $this->transformCounting($counting, false),
        ], 201);
    }

    /**
     * Get user's draft counting operations.
     */
    public function myDrafts(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $drafts = InventoryCounting::forCompany($companyId)
            ->where('status', CountingStatus::Draft)
            ->where('created_by_user_id', $userId)
            ->with(['count1User', 'count2User', 'count3User'])
            ->orderBy('last_modified_at', 'desc')
            ->get();

        return response()->json([
            'data' => $drafts->map(fn (InventoryCounting $counting): array => [
                'id' => $counting->id,
                'uuid' => $counting->id,
                'title' => $counting->title,
                'status' => $counting->status->value,
                'scope_type' => $counting->scope_type->value,
                'product_count' => count($counting->scope_filters['product_ids'] ?? []),
                'created_at' => $counting->created_at?->toIso8601String(),
                'last_modified_at' => $counting->last_modified_at,
            ])->all(),
        ]);
    }

    /**
     * Add a product to draft counting by barcode or product_id.
     */
    public function addProduct(string $id, AddProductToCountingRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($id);

        // Only drafts can have products added incrementally
        if ($counting->status !== CountingStatus::Draft) {
            return response()->json([
                'error' => 'Can only add products to draft counts',
            ], 422);
        }

        // Check authorization - must be creator or admin
        /** @var User $authUser */
        $authUser = $request->user();
        if ($counting->created_by_user_id !== (string) $authUser->id && ! $authUser->hasRole('admin')) {
            return response()->json([
                'error' => 'Unauthorized to modify this counting operation',
            ], 403);
        }

        // Lookup product by barcode or use provided product_id
        $productId = $request->input('product_id');

        if (! $productId && $request->has('barcode')) {
            $product = Product::where('barcode', $request->input('barcode'))
                ->where('company_id', $companyId)
                ->first();

            if (! $product) {
                return response()->json([
                    'error' => 'Product not found with barcode: '.$request->input('barcode'),
                ], 404);
            }

            $productId = $product->id;
        }

        // Check if product already in count
        $scopeFilters = $counting->scope_filters;
        $productIds = $scopeFilters['product_ids'] ?? [];

        if (in_array($productId, $productIds, true)) {
            return response()->json([
                'error' => 'Product already added to this count',
            ], 409);
        }

        // Add product to scope_filters
        $productIds[] = $productId;
        $scopeFilters['product_ids'] = $productIds;
        $counting->scope_filters = $scopeFilters;
        $counting->last_modified_at = now()->toDateTimeString();
        $counting->last_modified_by_user_id = (string) $authUser->id;
        $counting->save();

        // Load product details for response — scoped by the caller's
        // tenant + company so a forged barcode-resolved or body-supplied
        // productId cannot leak a foreign product's name/sku/barcode.
        /** @var Product $product */
        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $companyId)
            ->findOrFail($productId);

        return response()->json([
            'data' => [
                'product_id' => $productId,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                ],
                'added_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Remove a product from draft counting.
     */
    public function removeProduct(string $id, string $productId, Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($id);

        // Only drafts can have products removed
        if ($counting->status !== CountingStatus::Draft) {
            return response()->json([
                'error' => 'Can only remove products from draft counts',
            ], 422);
        }

        // Check authorization
        /** @var User $authUser */
        $authUser = $request->user();
        if ($counting->created_by_user_id !== (string) $authUser->id && ! $authUser->hasRole('admin')) {
            return response()->json([
                'error' => 'Unauthorized to modify this counting operation',
            ], 403);
        }

        // Remove product from scope_filters
        $scopeFilters = $counting->scope_filters;
        $productIds = $scopeFilters['product_ids'] ?? [];
        $productIds = array_values(array_filter($productIds, fn ($id) => $id !== $productId));
        $scopeFilters['product_ids'] = $productIds;
        $counting->scope_filters = $scopeFilters;
        $counting->last_modified_at = now()->toDateTimeString();
        $counting->last_modified_by_user_id = (string) $authUser->id;
        $counting->save();

        return response()->json(null, 204);
    }

    /**
     * Update draft counting operation.
     */
    public function updateDraft(string $id, UpdateDraftCountingRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($id);

        // Only drafts can be updated via this endpoint
        if ($counting->status !== CountingStatus::Draft) {
            return response()->json([
                'error' => 'Can only update draft counts via this endpoint',
            ], 422);
        }

        // Check authorization
        /** @var User $authUser */
        $authUser = $request->user();
        if ($counting->created_by_user_id !== (string) $authUser->id && ! $authUser->hasRole('admin')) {
            return response()->json([
                'error' => 'Unauthorized to modify this counting operation',
            ], 403);
        }

        // Update fields
        if ($request->has('title')) {
            $counting->title = $request->input('title');
        }
        if ($request->has('instructions')) {
            $counting->instructions = $request->input('instructions');
        }
        if ($request->has('execution_mode')) {
            $counting->execution_mode = $request->input('execution_mode');
        }
        if ($request->has('requires_count_2')) {
            $counting->requires_count_2 = $request->input('requires_count_2');
        }
        if ($request->has('requires_count_3')) {
            $counting->requires_count_3 = $request->input('requires_count_3');
        }
        if ($request->has('allow_unexpected_items')) {
            $counting->allow_unexpected_items = $request->input('allow_unexpected_items');
        }
        if ($request->has('count_1_user_id')) {
            $counting->count_1_user_id = $request->input('count_1_user_id');
        }
        if ($request->has('count_2_user_id')) {
            $counting->count_2_user_id = $request->input('count_2_user_id');
        }
        if ($request->has('count_3_user_id')) {
            $counting->count_3_user_id = $request->input('count_3_user_id');
        }
        if ($request->has('scheduled_start')) {
            $counting->scheduled_start = $request->input('scheduled_start');
        }
        if ($request->has('scheduled_end')) {
            $counting->scheduled_end = $request->input('scheduled_end');
        }

        $counting->last_modified_at = now()->toDateTimeString();
        $counting->last_modified_by_user_id = (string) $authUser->id;
        $counting->save();

        return response()->json([
            'data' => $this->transformCounting($counting, false),
        ]);
    }

    /**
     * Activate a draft counting operation (transition to active status).
     */
    public function activateDraft(string $id, ActivateCountingRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($id);

        // Only drafts can be activated
        if ($counting->status !== CountingStatus::Draft) {
            return response()->json([
                'error' => 'Can only activate draft counts',
            ], 422);
        }

        // Check authorization
        /** @var User $authUser */
        $authUser = $request->user();
        if ($counting->created_by_user_id !== (string) $authUser->id && ! $authUser->hasRole('admin')) {
            return response()->json([
                'error' => 'Unauthorized to activate this counting operation',
            ], 403);
        }

        // Validate activation requirements. Only scopes whose item generation
        // actually consumes scope_filters.product_ids (see
        // InventoryCountingService::resolveCountingItemSeeds /
        // getStockLevelsForScope) require it here — zone-scoped and
        // catalog-sourced (location/category/full_inventory) countings
        // generate items from product_placements or the active catalog
        // and carry no product_ids at all.
        if (in_array($counting->scope_type, [CountingScopeType::Product, CountingScopeType::ProductLocation], true)) {
            $productIds = $counting->scope_filters['product_ids'] ?? [];
            if (count($productIds) === 0) {
                return response()->json([
                    'error' => 'At least one product must be added before activation',
                ], 422);
            }
        } elseif ($counting->scope_type === CountingScopeType::Zone) {
            // Mirrors CreateCountingRequest's zone scope rule: zone_ids required.
            $zoneIds = $counting->scope_filters['zone_ids'] ?? [];
            if (count($zoneIds) === 0) {
                return response()->json([
                    'error' => 'At least one zone must be selected before activation',
                ], 422);
            }
        }

        if (! $counting->count_1_user_id) {
            return response()->json([
                'error' => 'Primary counter must be assigned before activation',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $activateImmediately = (bool) $request->input('activate_immediately', true);

        $counting = $this->countingService->activateDraft(
            $counting,
            $companyId,
            $user,
            $activateImmediately,
        );

        return response()->json([
            'data' => $this->transformCounting($counting, false),
        ]);
    }

    /**
     * Batch create draft counting operations (offline sync).
     *
     * Allows mobile app to sync multiple drafts created offline.
     * Maximum 50 drafts per request to prevent memory issues.
     */
    public function batchCreateDrafts(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $request->validate([
            'drafts' => 'required|array|max:50',
            'drafts.*.localId' => 'required|string',
            'drafts.*.title' => 'nullable|string|max:255',
            'drafts.*.scopeType' => 'required|string',
            'drafts.*.instructions' => 'nullable|string',
            'drafts.*.executionMode' => 'nullable|string|in:parallel,sequential',
            'drafts.*.requiresCount2' => 'nullable|boolean',
            'drafts.*.requiresCount3' => 'nullable|boolean',
            'drafts.*.allowUnexpectedItems' => 'nullable|boolean',
            'drafts.*.scopeFilters' => 'nullable|array',
            'drafts.*.count1UserId' => 'nullable|string',
            'drafts.*.count2UserId' => 'nullable|string',
            'drafts.*.count3UserId' => 'nullable|string',
            'drafts.*.scheduledStart' => 'nullable|date',
            'drafts.*.scheduledEnd' => 'nullable|date',
        ]);

        $results = [];
        $errors = [];

        \DB::transaction(function () use ($request, $companyId, $userId, &$results, &$errors): void {
            foreach ($request->input('drafts') as $draftData) {
                try {
                    $counting = new InventoryCounting;
                    $counting->id = (string) Str::uuid();
                    $counting->company_id = $companyId;
                    $counting->created_by_user_id = $userId;
                    $counting->created_on_mobile = true;
                    $counting->title = $draftData['title'] ?? null;
                    $counting->scope_type = $draftData['scopeType'];
                    $counting->status = CountingStatus::Draft;
                    $counting->execution_mode = $draftData['executionMode'] ?? 'sequential';
                    $counting->requires_count_2 = $draftData['requiresCount2'] ?? false;
                    $counting->requires_count_3 = $draftData['requiresCount3'] ?? false;
                    $counting->allow_unexpected_items = $draftData['allowUnexpectedItems'] ?? true;
                    $counting->instructions = $draftData['instructions'] ?? null;
                    $counting->scope_filters = $draftData['scopeFilters'] ?? [];
                    $counting->count_1_user_id = $draftData['count1UserId'] ?? null;
                    $counting->count_2_user_id = $draftData['count2UserId'] ?? null;
                    $counting->count_3_user_id = $draftData['count3UserId'] ?? null;
                    $counting->scheduled_start = isset($draftData['scheduledStart']) ? Carbon::parse($draftData['scheduledStart']) : null;
                    $counting->scheduled_end = isset($draftData['scheduledEnd']) ? Carbon::parse($draftData['scheduledEnd']) : null;
                    $counting->last_modified_at = now()->toDateTimeString();
                    $counting->last_modified_by_user_id = $userId;

                    $counting->save();

                    $results[] = [
                        'localId' => $draftData['localId'],
                        'serverId' => $counting->id,
                        'status' => 'success',
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'localId' => $draftData['localId'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return response()->json([
            'data' => [
                'success' => $results,
                'errors' => $errors,
            ],
        ], 201);
    }

    /**
     * Batch add products to a draft counting (offline sync).
     *
     * Allows mobile app to sync multiple products added offline.
     * Maximum 100 products per request.
     */
    public function batchAddProducts(string $id, Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $request->validate([
            'products' => 'required|array|max:100',
            'products.*.barcode' => 'required_without:products.*.productId',
            'products.*.productId' => 'required_without:products.*.barcode',
        ]);

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::forCompany($companyId)->findOrFail($id);

        // Only drafts can have products added incrementally
        if ($counting->status !== CountingStatus::Draft) {
            return response()->json([
                'error' => 'Can only add products to draft counts',
            ], 422);
        }

        // Check authorization
        if ($counting->created_by_user_id !== $userId && ! $user->hasRole('admin')) {
            return response()->json([
                'error' => 'Unauthorized to modify this counting operation',
            ], 403);
        }

        $results = [];
        $errors = [];
        $scopeFilters = $counting->scope_filters;
        $productIds = $scopeFilters['product_ids'] ?? [];
        $productsInput = $request->input('products');
        $products = is_array($productsInput) ? $productsInput : [];
        $submittedProductIds = [];

        foreach ($products as $productData) {
            if (! is_array($productData)) {
                continue;
            }

            $productId = $productData['productId'] ?? null;

            if (is_string($productId) && Str::isUuid($productId)) {
                $submittedProductIds[strtolower($productId)] = true;
            }
        }

        $validProductIds = [];
        $companyProductIds = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($submittedProductIds))
            ->pluck('id');

        foreach ($companyProductIds as $companyProductId) {
            $canonicalProductId = (string) $companyProductId;
            $validProductIds[strtolower($canonicalProductId)] = $canonicalProductId;
        }

        foreach ($products as $productData) {
            if (! is_array($productData)) {
                continue;
            }

            try {
                $productId = $productData['productId'] ?? null;
                $productId = $productId === '' ? null : $productId;

                if ($productId !== null) {
                    if (! is_string($productId) || ! Str::isUuid($productId)) {
                        $errors[] = [
                            'data' => $productData,
                            'code' => self::BATCH_ERROR_PRODUCT_NOT_FOUND,
                            'error' => 'Invalid product ID; expected a UUID',
                        ];

                        continue;
                    }

                    $normalizedProductId = strtolower($productId);

                    if (! isset($validProductIds[$normalizedProductId])) {
                        $errors[] = [
                            'data' => $productData,
                            'code' => self::BATCH_ERROR_PRODUCT_NOT_FOUND,
                            'error' => 'Product not found for current company',
                        ];

                        continue;
                    }

                    $productId = $validProductIds[$normalizedProductId];
                }

                // Lookup by barcode if product_id not provided
                $barcode = $productData['barcode'] ?? null;

                if (! $productId && ! is_string($barcode)) {
                    $errors[] = [
                        'data' => $productData,
                        'code' => self::BATCH_ERROR_INVALID_BARCODE,
                        'error' => 'Invalid barcode; expected a string',
                    ];

                    continue;
                }

                if (! $productId && is_string($barcode)) {
                    $product = Product::where('barcode', $barcode)
                        ->where('company_id', $companyId)
                        ->first();

                    if (! $product) {
                        $errors[] = [
                            'data' => $productData,
                            'code' => self::BATCH_ERROR_PRODUCT_NOT_FOUND,
                            'error' => 'Product not found with barcode: '.$barcode,
                        ];

                        continue;
                    }

                    $productId = $product->id;
                }

                // Check if already added
                if (in_array($productId, $productIds, true)) {
                    $errors[] = [
                        'data' => $productData,
                        'code' => self::BATCH_ERROR_PRODUCT_ALREADY_IN_COUNT,
                        'error' => 'Product already added to this count',
                    ];

                    continue;
                }

                // Add to list
                $productIds[] = $productId;

                $results[] = [
                    'productId' => $productId,
                    'status' => 'success',
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'data' => $productData,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Update counting with new product list
        $scopeFilters['product_ids'] = $productIds;
        $counting->scope_filters = $scopeFilters;
        $counting->last_modified_at = now()->toDateTimeString();
        $counting->last_modified_by_user_id = $userId;
        $counting->save();

        return response()->json([
            'data' => [
                'success' => $results,
                'errors' => $errors,
                'total_products' => count($productIds),
            ],
        ]);
    }

    /**
     * Batch update draft counting operations (offline sync).
     *
     * Allows mobile app to sync multiple draft updates made offline.
     * Maximum 50 drafts per request.
     */
    public function batchUpdateDrafts(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();
        $userId = (string) $user->id;

        $request->validate([
            'updates' => 'required|array|max:50',
            'updates.*.id' => 'required|string',
            'updates.*.localId' => 'nullable|string',
            'updates.*.data' => 'required|array',
            'updates.*.data.title' => 'nullable|string|max:255',
            'updates.*.data.instructions' => 'nullable|string',
            'updates.*.data.executionMode' => 'nullable|string|in:parallel,sequential',
            'updates.*.data.requiresCount2' => 'nullable|boolean',
            'updates.*.data.requiresCount3' => 'nullable|boolean',
            'updates.*.data.allowUnexpectedItems' => 'nullable|boolean',
            'updates.*.data.count1UserId' => 'nullable|string',
            'updates.*.data.count2UserId' => 'nullable|string',
            'updates.*.data.count3UserId' => 'nullable|string',
            'updates.*.data.scheduledStart' => 'nullable|date',
            'updates.*.data.scheduledEnd' => 'nullable|date',
        ]);

        $results = [];
        $errors = [];

        \DB::transaction(function () use ($user, $companyId, $userId, $request, &$results, &$errors): void {
            foreach ($request->input('updates') as $updateData) {
                try {
                    /** @var InventoryCounting $counting */
                    $counting = InventoryCounting::forCompany($companyId)->findOrFail($updateData['id']);

                    // Only drafts can be updated
                    if ($counting->status !== CountingStatus::Draft) {
                        $errors[] = [
                            'id' => $updateData['id'],
                            'localId' => $updateData['localId'] ?? null,
                            'error' => 'Can only update draft counts',
                        ];

                        continue;
                    }

                    // Check authorization
                    if ($counting->created_by_user_id !== $userId && ! $user->hasRole('admin')) {
                        $errors[] = [
                            'id' => $updateData['id'],
                            'localId' => $updateData['localId'] ?? null,
                            'error' => 'Unauthorized to modify this counting operation',
                        ];

                        continue;
                    }

                    $data = $updateData['data'];

                    // Update fields
                    if (isset($data['title'])) {
                        $counting->title = $data['title'];
                    }
                    if (isset($data['instructions'])) {
                        $counting->instructions = $data['instructions'];
                    }
                    if (isset($data['executionMode'])) {
                        $counting->execution_mode = $data['executionMode'];
                    }
                    if (isset($data['requiresCount2'])) {
                        $counting->requires_count_2 = $data['requiresCount2'];
                    }
                    if (isset($data['requiresCount3'])) {
                        $counting->requires_count_3 = $data['requiresCount3'];
                    }
                    if (isset($data['allowUnexpectedItems'])) {
                        $counting->allow_unexpected_items = $data['allowUnexpectedItems'];
                    }
                    if (isset($data['count1UserId'])) {
                        $counting->count_1_user_id = $data['count1UserId'];
                    }
                    if (isset($data['count2UserId'])) {
                        $counting->count_2_user_id = $data['count2UserId'];
                    }
                    if (isset($data['count3UserId'])) {
                        $counting->count_3_user_id = $data['count3UserId'];
                    }
                    if (isset($data['scheduledStart'])) {
                        $counting->scheduled_start = Carbon::parse($data['scheduledStart']);
                    }
                    if (isset($data['scheduledEnd'])) {
                        $counting->scheduled_end = Carbon::parse($data['scheduledEnd']);
                    }

                    $counting->last_modified_at = now()->toDateTimeString();
                    $counting->last_modified_by_user_id = $userId;
                    $counting->save();

                    $results[] = [
                        'id' => $updateData['id'],
                        'localId' => $updateData['localId'] ?? null,
                        'status' => 'success',
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'id' => $updateData['id'],
                        'localId' => $updateData['localId'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return response()->json([
            'data' => [
                'success' => $results,
                'errors' => $errors,
            ],
        ]);
    }
}
