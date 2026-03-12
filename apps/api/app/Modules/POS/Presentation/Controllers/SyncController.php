<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\SyncReceiptPayload;
use App\Modules\POS\Application\Services\ReceiptSyncService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\SyncReceiptsRequest;
use App\Modules\POS\Presentation\Requests\SyncShiftCloseRequest;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * POS Sync Controller
 *
 * Handles data synchronization between offline Tauri POS terminals and the server.
 * Provides endpoints for pushing offline receipts and pulling reference data.
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptSyncService $receiptSyncService,
        private readonly ShiftManagementService $shiftManagementService,
    ) {}

    /**
     * Batch sync offline receipts.
     *
     * POST /api/v1/pos/receipts/sync
     *
     * Accepts a single receipt payload or an array of receipts.
     * Returns per-receipt status (synced, duplicate, failed, chain_broken).
     */
    public function syncReceipts(SyncReceiptsRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validated();

        /** @var array<int, array<string, mixed>> $receiptsData */
        $receiptsData = $validated['receipts'];

        $payloads = array_map(
            static fn (array $data): SyncReceiptPayload => SyncReceiptPayload::fromArray($data),
            $receiptsData,
        );

        $results = $this->receiptSyncService->syncBatch($payloads);

        $resultArrays = array_map(
            static fn ($result) => $result->toArray(),
            $results,
        );

        return response()->json([
            'data' => [
                'results' => $resultArrays,
                'total' => count($results),
                'synced' => count(array_filter($results, fn ($r) => $r->status === \App\Modules\POS\Domain\Enums\SyncStatus::Synced)),
                'duplicates' => count(array_filter($results, fn ($r) => $r->status === \App\Modules\POS\Domain\Enums\SyncStatus::Duplicate)),
                'failed' => count(array_filter($results, fn ($r) => $r->status === \App\Modules\POS\Domain\Enums\SyncStatus::Failed || $r->status === \App\Modules\POS\Domain\Enums\SyncStatus::ChainBroken)),
            ],
        ]);
    }

    /**
     * Pull reference data for local cache.
     *
     * GET /api/v1/pos/sync/pull
     *
     * Returns products, categories, payment methods, payment repositories,
     * and terminal configuration. Supports delta sync via `?updated_since=ISO8601`.
     * Supports ETag for efficient polling (304 when unchanged).
     */
    public function pull(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $companyId = $this->companyContext->getCompanyId();
        $updatedSince = $request->query('updated_since');
        $parsedSince = null;

        if (is_string($updatedSince) && $updatedSince !== '') {
            $parsedSince = Carbon::parse($updatedSince);
        }

        // Fetch products
        $productsQuery = Product::where('company_id', $companyId)
            ->where('is_active', true);
        if ($parsedSince !== null) {
            $productsQuery->where('updated_at', '>', $parsedSince);
        }
        $products = $productsQuery->get();

        // Fetch categories
        $categoriesQuery = Category::where('company_id', $companyId);
        if ($parsedSince !== null) {
            $categoriesQuery->where('updated_at', '>', $parsedSince);
        }
        $categories = $categoriesQuery->get();

        // Fetch payment methods
        $paymentMethodsQuery = PaymentMethod::where('company_id', $companyId)
            ->where('is_active', true);
        if ($parsedSince !== null) {
            $paymentMethodsQuery->where('updated_at', '>', $parsedSince);
        }
        $paymentMethods = $paymentMethodsQuery->get();

        // Fetch payment repositories
        $paymentReposQuery = PaymentRepository::where('company_id', $companyId)
            ->where('is_active', true);
        if ($parsedSince !== null) {
            $paymentReposQuery->where('updated_at', '>', $parsedSince);
        }
        $paymentRepos = $paymentReposQuery->get();

        // Fetch terminal config for this company
        $terminals = Terminal::where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(fn (Terminal $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'type' => $t->type->value,
                'genesis_seed' => $t->genesis_seed,
                'last_hash' => $t->last_hash,
                'current_sequence' => $t->current_sequence,
                'current_year' => $t->current_year,
                'max_discount_percent' => $t->max_discount_percent,
                'allow_line_discounts' => $t->allow_line_discounts,
                'allow_transaction_discounts' => $t->allow_transaction_discounts,
            ]);

        $payload = [
            'products' => $products,
            'categories' => $categories,
            'payment_methods' => $paymentMethods,
            'payment_repositories' => $paymentRepos,
            'terminals' => $terminals,
            'synced_at' => Carbon::now()->toIso8601String(),
        ];

        // ETag support
        $etag = '"' . md5(json_encode($payload, JSON_THROW_ON_ERROR)) . '"';
        $ifNoneMatch = $request->header('If-None-Match');

        if ($ifNoneMatch === $etag) {
            return response()->json(null, 304);
        }

        return response()->json([
            'data' => $payload,
        ])->header('ETag', $etag);
    }

    /**
     * Sync close a shift that was closed offline.
     *
     * POST /api/v1/pos/shifts/{id}/sync-close
     *
     * Accepts shift close data with an offline `closed_at` timestamp.
     */
    public function syncCloseShift(SyncShiftCloseRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.manage_shifts');

        $companyId = $this->companyContext->getCompanyId();

        $shift = Shift::with('terminal')
            ->whereHas('terminal', function (\Illuminate\Database\Eloquent\Builder $q) use ($companyId): void {
                $q->whereRaw('company_id = ?', [$companyId]);
            })
            ->findOrFail($id);

        if (! $shift->isOpen()) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_NOT_OPEN',
                    'message' => 'Shift is not open and cannot be closed.',
                ],
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        $closedAt = Carbon::parse($request->validated('closed_at'));

        $closedShift = $this->shiftManagementService->closeShift(
            shift: $shift,
            actualCash: $request->validated('actual_cash'),
            closedBy: $user,
            closedAt: $closedAt,
        );

        return response()->json([
            'data' => [
                'id' => $closedShift->id,
                'status' => $closedShift->status->value,
                'expected_cash' => $closedShift->expected_cash,
                'actual_cash' => $closedShift->actual_cash,
                'variance' => $closedShift->variance,
                'closed_at' => $closedShift->closed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Pull menu data for F&B terminals.
     *
     * GET /api/v1/pos/sync/menu
     *
     * Returns active composite items with modifier groups and modifiers.
     * Supports ETag for efficient polling.
     */
    public function menu(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $companyId = $this->companyContext->getCompanyId();

        /** @var \Illuminate\Database\Eloquent\Collection<int, CompositeItem> $compositeItemsCollection */
        $compositeItemsCollection = CompositeItem::query()
            ->whereRaw('company_id = ?', [$companyId])
            ->where('is_active', true)
            ->with([
                'modifierGroups' => function ($query): void {
                    $query->where('is_active', true)->orderBy('position');
                },
                'modifierGroups.modifiers' => function ($query): void {
                    $query->where('is_active', true)->orderBy('position');
                },
            ])
            ->get();

        $compositeItems = $compositeItemsCollection
            ->map(fn (CompositeItem $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->getSellableName(),
                'description' => null,
                'category_id' => $item->category_id,
                'base_price' => $item->base_price,
                'tax_rate' => $item->tax_rate,
                'image_url' => $item->image_url ?? null,
                'modifier_groups' => $item->modifierGroups->map(fn (ModifierGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'is_required' => $group->is_required,
                    'min_selections' => $group->min_selections,
                    'max_selections' => $group->max_selections,
                    'modifiers' => $group->modifiers->map(fn ($mod) => [
                        'id' => $mod->id,
                        'name' => $mod->name,
                        'price_adjustment' => $mod->price_adjustment,
                        'is_default' => $mod->is_default ?? false,
                    ])->values()->toArray(),
                ])->values()->toArray(),
            ]);

        $payload = [
            'composite_items' => $compositeItems,
            'synced_at' => Carbon::now()->toIso8601String(),
        ];

        // ETag support
        $etag = '"' . md5(json_encode($payload, JSON_THROW_ON_ERROR)) . '"';
        $ifNoneMatch = $request->header('If-None-Match');

        if ($ifNoneMatch === $etag) {
            return response()->json(null, 304);
        }

        return response()->json([
            'data' => $payload,
        ])->header('ETag', $etag);
    }
}
