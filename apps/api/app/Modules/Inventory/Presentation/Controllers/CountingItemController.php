<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\OpeningCostGate;
use App\Modules\Inventory\Presentation\Requests\ManualOverrideRequest;
use App\Modules\Inventory\Presentation\Requests\SetOpeningCostRequest;
use App\Modules\Inventory\Presentation\Requests\SubmitCountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class CountingItemController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly InventoryCountingService $countingService,
        private readonly OpeningCostGate $openingCostGate,
    ) {}

    /**
     * Get items for counter (BLIND view).
     */
    public function toCount(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);

        $userId = (string) $user->id;

        if (! $counting->isUserAssigned($userId)) {
            abort(403, 'You are not assigned to this counting');
        }

        $uncountedOnly = $request->boolean('uncounted_only', true);
        $countNumber = $counting->getUserCountNumber($userId);
        $items = $this->countingService->getItemsForCounter($counting, $user, $uncountedOnly);

        // Transform without theoretical_qty
        $transformedItems = $items->map(function ($item) use ($countNumber) {
            $myCountColumn = "count_{$countNumber}_qty";

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
            ];
        });

        return response()->json([
            'data' => $transformedItems,
        ]);
    }

    /**
     * Submit count for an item.
     */
    public function submitCount(
        SubmitCountRequest $request,
        string $countingId,
        string $itemId
    ): JsonResponse {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);
        /** @var InventoryCountingItem $item */
        $item = InventoryCountingItem::where('counting_id', $counting->id)
            ->findOrFail($itemId);

        $countNumber = $counting->getUserCountNumber((string) $user->id);

        if ($countNumber === null) {
            abort(403, 'You are not assigned to this counting');
        }

        $this->countingService->submitCount(
            $item,
            $countNumber,
            $request->quantity(),
            $request->input('notes'),
            $user,
            $request->countedAtDevice(),
            $request->deviceNow(),
        );

        return response()->json([
            'message' => 'Count submitted successfully',
            'data' => [
                'item_id' => $item->id,
                'quantity' => $request->input('quantity'),
                'count_number' => $countNumber,
            ],
        ]);
    }

    /**
     * Lookup item by barcode (for mobile scanner).
     */
    public function lookupByBarcode(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);
        $userId = (string) $user->id;

        if (! $counting->isUserAssigned($userId)) {
            abort(403, 'You are not assigned to this counting');
        }

        $barcode = $request->input('barcode');

        /** @var InventoryCountingItem|null $item */
        $item = $counting->items()
            ->whereHas('product', function ($q) use ($barcode): void {
                /** @phpstan-ignore argument.type */
                $q->where('barcode', $barcode);
            })
            ->with(['product', 'location'])
            ->first();

        if ($item === null) {
            return response()->json([
                'found' => false,
                'message' => 'Product not found in this counting',
            ], 404);
        }

        $countNumber = $counting->getUserCountNumber($userId);
        $myCountColumn = "count_{$countNumber}_qty";

        return response()->json([
            'found' => true,
            'data' => [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'barcode' => $item->product->barcode,
                ],
                'location' => [
                    'id' => $item->location->id,
                    'code' => $item->location->code ?? null,
                ],
                'is_counted' => $item->$myCountColumn !== null,
                'my_count' => $item->$myCountColumn,
                // NEVER INCLUDE: theoretical_qty
            ],
        ]);
    }

    /**
     * Get reconciliation data (admin only).
     */
    public function reconciliation(string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);
        $items = $this->countingService->getItemsForAdmin($counting);

        $summary = [
            'total' => $items->count(),
            'auto_resolved' => $items->filter(fn ($i) => $i->resolution_method->isAutomatic())->count(),
            'needs_attention' => $items->filter(fn ($i) => $i->is_flagged && $i->final_qty === null)->count(),
            'manually_overridden' => $items->filter(fn ($i) => $i->resolution_method === ItemResolutionMethod::ManualOverride)->count(),
        ];

        return response()->json([
            'data' => [
                'summary' => $summary,
                'items' => $items->map(function ($item) {
                    // Pre-finalize opening-cost signal (D3). `will_post_as_opening`
                    // and `opening_cost_missing` come from OpeningCostGate — the
                    // SAME computation the finalize gate enforces — so the review
                    // page can flag/gate cost-less openings BEFORE finalize
                    // instead of relying on the post-finalize `pending_opening_cost`
                    // flag (which is inert here and unfixable after finalize).
                    $onboarding = (bool) $item->location->onboarding_mode;
                    $gate = $this->openingCostGate->evaluateItem($item, $onboarding);

                    return [
                        'id' => $item->id,
                        'product' => [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                        ],
                        'location' => [
                            'code' => $item->location->code ?? null,
                            'name' => $item->location->name,
                        ],
                        'theoretical_qty' => $item->theoretical_qty,
                        'count_1' => [
                            'qty' => $item->count_1_qty,
                            'at' => $item->count_1_at?->toIso8601String(),
                            'notes' => $item->count_1_notes,
                        ],
                        'count_2' => $item->count_2_qty !== null ? [
                            'qty' => $item->count_2_qty,
                            'at' => $item->count_2_at?->toIso8601String(),
                            'notes' => $item->count_2_notes,
                        ] : null,
                        'count_3' => $item->count_3_qty !== null ? [
                            'qty' => $item->count_3_qty,
                            'at' => $item->count_3_at?->toIso8601String(),
                            'notes' => $item->count_3_notes,
                        ] : null,
                        'final_qty' => $item->final_qty,
                        'variance' => $item->getVariance(),
                        'resolution_method' => $item->resolution_method->value,
                        'resolution_notes' => $item->resolution_notes,
                        'is_flagged' => $item->is_flagged,
                        'flag_reason' => $item->flag_reason,
                        // Replay-review fields (D3). `expected_qty_at_apply` and the
                        // `replay_audit` snapshot are stamped by the finalize
                        // listener; `flag_reasons` is the canonical jsonb array
                        // (blocking + informational). `opening_unit_cost` drives the
                        // backfill cell for onboarding opening lines.
                        'expected_qty_at_apply' => $item->expected_qty_at_apply,
                        'replay_audit' => $item->replay_audit,
                        'flag_reasons' => $item->flag_reasons,
                        'opening_unit_cost' => $item->opening_unit_cost,
                        // Pre-finalize opening-cost gate signals (D3): drive the
                        // review-page cost cell + finalize gate. Additive.
                        'will_post_as_opening' => $gate['will_post_as_opening'],
                        'opening_cost_missing' => $gate['opening_cost_missing'],
                    ];
                })->all(),
                // Late-sale flags captured on the session during the block
                // window — surfaced as a review banner.
                'late_sales_flags' => $counting->late_sales_flags ?? [],
            ],
        ]);
    }

    /**
     * Backfill / override an onboarding line's opening unit cost (D3).
     *
     * Editable only while the counting is PRE-finalize (draft through
     * pending_review). The opening-cost gate now runs before finalize
     * (InventoryCountingService::finalize), so by design no cost-less opening
     * ever reaches a finalized counting. A Finalized counting has no outgoing
     * transition and therefore NO re-post path — a cost written afterwards could
     * never post, so it would silently strand against the ledger; a Cancelled
     * counting posts nothing at all. Both are rejected with 422.
     */
    public function setOpeningCost(
        SetOpeningCostRequest $request,
        string $countingId,
        string $itemId
    ): JsonResponse {
        $companyId = $this->companyContext->requireCompanyId();

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);
        /** @var InventoryCountingItem $item */
        $item = InventoryCountingItem::where('counting_id', $counting->id)
            ->findOrFail($itemId);

        if (in_array($counting->status, [CountingStatus::Finalized, CountingStatus::Cancelled], true)) {
            abort(422, 'Cannot change opening cost: this counting is '.$counting->status->value.
                ' and has no re-post path. The opening-cost gate runs before finalize.');
        }

        $item->opening_unit_cost = $request->unitCost();
        $item->save();

        return response()->json([
            'message' => 'Opening cost updated',
            'data' => [
                'item_id' => $item->id,
                'opening_unit_cost' => $item->opening_unit_cost,
            ],
        ]);
    }

    /**
     * Trigger third count for items.
     */
    public function triggerThirdCount(Request $request, string $countingId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        // Validate item_ids before loading the counting so a forged item_id
        // from another counting (different company) is rejected upfront.
        // Rule::exists()->where() scopes the FK check to the parent counting_id
        // so an item belonging to a different counting fails validation.
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => [
                'string',
                Rule::exists('inventory_counting_items', 'id')
                    ->where('counting_id', $countingId),
            ],
        ]);

        $counting = InventoryCounting::forCompany($companyId)->findOrFail($countingId);

        $this->countingService->triggerThirdCount(
            $counting,
            $request->input('item_ids'),
            $user
        );

        return response()->json([
            'message' => 'Third count triggered',
        ]);
    }

    /**
     * Manual override.
     */
    public function override(ManualOverrideRequest $request, string $itemId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        /** @var InventoryCountingItem $item */
        $item = InventoryCountingItem::whereHas(
            'counting',
            function ($q) use ($companyId): void {
                /** @phpstan-ignore argument.type */
                $q->where('company_id', $companyId);
            }
        )->findOrFail($itemId);

        $this->countingService->manualOverride(
            $item,
            $request->quantity(),
            (string) $request->input('notes'),
            $user
        );

        return response()->json([
            'message' => 'Item overridden successfully',
        ]);
    }
}
