<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Pricing\Domain\PartnerPriceList;
use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Pricing\Domain\Services\PricingService;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PricingController extends Controller
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly MarginService $marginService,
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * List all price lists
     */
    public function index(Request $request): JsonResponse
    {
        $query = PriceList::with(['company', 'items']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->input('currency'));
        }

        $priceLists = $query->latest()->paginate(20);

        return response()->json($priceLists);
    }

    /**
     * Get single price list
     */
    public function show(string $id): JsonResponse
    {
        $priceList = PriceList::with(['company', 'items.product', 'partnerPriceLists.partner'])
            ->findOrFail($id);

        return response()->json(['data' => $priceList]);
    }

    /**
     * Create price list
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:price_lists,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'currency' => 'required|string|size:3',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $user = $request->user();

        $priceList = PriceList::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $user->tenant_id,
            'company_id' => $this->companyContext->requireCompanyId(),
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'currency' => $request->input('currency'),
            'is_active' => $request->boolean('is_active', true),
            'is_default' => $request->boolean('is_default', false),
            'valid_from' => $request->input('valid_from'),
            'valid_until' => $request->input('valid_until'),
        ]);

        return response()->json([
            'data' => $priceList->load('company'),
            'message' => 'Price list created successfully',
        ], 201);
    }

    /**
     * Update price list
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'code' => 'sometimes|string|max:50|unique:price_lists,code,'.$id,
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'currency' => 'sometimes|string|size:3',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $priceList = PriceList::findOrFail($id);
        $priceList->update($request->only([
            'code', 'name', 'description', 'currency',
            'is_active', 'is_default', 'valid_from', 'valid_until',
        ]));

        return response()->json([
            'data' => $priceList->fresh('company'),
            'message' => 'Price list updated successfully',
        ]);
    }

    /**
     * Delete price list
     */
    public function destroy(string $id): JsonResponse
    {
        $priceList = PriceList::findOrFail($id);
        $priceList->delete();

        return response()->json([
            'message' => 'Price list deleted successfully',
        ]);
    }

    /**
     * Add item to price list
     */
    public function addItem(Request $request, string $priceListId): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'price' => 'required|numeric|min:0',
            'min_quantity' => 'required|numeric|min:0',
            'max_quantity' => 'nullable|numeric|gt:min_quantity',
        ]);

        $priceList = PriceList::findOrFail($priceListId);

        $item = PriceListItem::create([
            'id' => Str::uuid()->toString(),
            'price_list_id' => $priceList->id,
            'product_id' => $request->input('product_id'),
            'price' => $request->input('price'),
            'min_quantity' => $request->input('min_quantity'),
            'max_quantity' => $request->input('max_quantity'),
        ]);

        return response()->json([
            'data' => $item->load('product'),
            'message' => 'Price list item added successfully',
        ], 201);
    }

    /**
     * Update price list item
     */
    public function updateItem(Request $request, string $priceListId, string $itemId): JsonResponse
    {
        $request->validate([
            'price' => 'sometimes|numeric|min:0',
            'min_quantity' => 'sometimes|numeric|min:0',
            'max_quantity' => 'nullable|numeric',
        ]);

        $item = PriceListItem::where('price_list_id', $priceListId)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->update($request->only(['price', 'min_quantity', 'max_quantity']));

        return response()->json([
            'data' => $item->fresh('product'),
            'message' => 'Price list item updated successfully',
        ]);
    }

    /**
     * Remove item from price list
     */
    public function removeItem(string $priceListId, string $itemId): JsonResponse
    {
        $item = PriceListItem::where('price_list_id', $priceListId)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->delete();

        return response()->json([
            'message' => 'Price list item removed successfully',
        ]);
    }

    /**
     * Assign price list to partner
     */
    public function assignToPartner(Request $request, string $priceListId): JsonResponse
    {
        $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'priority' => 'integer|min:0',
        ]);

        $priceList = PriceList::findOrFail($priceListId);

        $assignment = PartnerPriceList::create([
            'id' => Str::uuid()->toString(),
            'partner_id' => $request->input('partner_id'),
            'price_list_id' => $priceList->id,
            'valid_from' => $request->input('valid_from'),
            'valid_until' => $request->input('valid_until'),
            'is_active' => true,
            'priority' => $request->input('priority', 0),
        ]);

        return response()->json([
            'data' => $assignment->load(['partner', 'priceList']),
            'message' => 'Price list assigned to partner successfully',
        ], 201);
    }

    /**
     * Remove price list assignment from partner
     */
    public function removeFromPartner(string $priceListId, string $partnerId): JsonResponse
    {
        $assignment = PartnerPriceList::where('price_list_id', $priceListId)
            ->where('partner_id', $partnerId)
            ->firstOrFail();

        $assignment->delete();

        return response()->json([
            'message' => 'Price list removed from partner successfully',
        ]);
    }

    /**
     * Get price for product
     */
    public function getPrice(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'partner_id' => 'nullable|exists:partners,id',
            'quantity' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'date' => 'nullable|date',
        ]);

        try {
            $date = $request->has('date')
                ? new \DateTime($request->input('date'))
                : now();

            $result = $this->pricingService->getPrice(
                $request->input('product_id'),
                $request->input('partner_id'),
                (string) $request->input('quantity'),
                $request->input('currency'),
                $date
            );

            return response()->json(['data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get quantity breaks for product
     */
    public function getQuantityBreaks(Request $request): JsonResponse
    {
        $request->validate([
            'price_list_id' => 'required|exists:price_lists,id',
            'product_id' => 'required|exists:products,id',
        ]);

        $breaks = $this->pricingService->getQuantityBreaks(
            $request->input('price_list_id'),
            $request->input('product_id')
        );

        return response()->json(['data' => $breaks]);
    }

    /**
     * Calculate line total with discounts
     */
    public function calculateLineTotal(Request $request): JsonResponse
    {
        $request->validate([
            'unit_price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.01',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $result = $this->pricingService->calculateLineTotal(
            (string) $request->input('unit_price'),
            (string) $request->input('quantity'),
            $request->has('discount_percent') ? (string) $request->input('discount_percent') : null,
            $request->has('discount_amount') ? (string) $request->input('discount_amount') : null
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Bulk price lookup
     */
    public function getBulkPrices(Request $request): JsonResponse
    {
        $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'partner_id' => 'nullable|exists:partners,id',
            'currency' => 'required|string|size:3',
            'date' => 'nullable|date',
        ]);

        try {
            $date = $request->has('date')
                ? new \DateTime($request->input('date'))
                : now();

            $prices = $this->pricingService->getBulkPrices(
                $request->input('product_ids'),
                $request->input('partner_id'),
                $request->input('currency'),
                $date
            );

            return response()->json(['data' => $prices]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check margin for a given product and sell price
     */
    public function checkMargin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:products,id',
            'sell_price' => 'required|numeric|min:0',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $sellPrice = (float) $validated['sell_price'];

        $marginLevel = $this->marginService->getMarginLevel($product, $sellPrice);
        $canSell = $this->marginService->canSellAtPrice($product, $sellPrice, $request->user());
        $suggestedPrice = $this->marginService->getSuggestedPrice($product);

        return response()->json([
            'data' => [
                'cost_price' => $product->cost_price,
                'sell_price' => $sellPrice,
                'margin_level' => $marginLevel,
                'can_sell' => $canSell,
                'suggested_price' => $suggestedPrice,
                'margins' => $this->marginService->getEffectiveMargins($product),
            ],
        ]);
    }
}
