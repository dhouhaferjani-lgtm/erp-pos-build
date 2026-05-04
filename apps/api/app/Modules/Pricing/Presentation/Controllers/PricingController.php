<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Pricing\Domain\PartnerPriceList;
use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Pricing\Domain\Services\PricingService;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use App\Shared\Presentation\Validation\ScopedExists;
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
        // api.pricing round-2 (Opus Finding 1, CRITICAL): pre-fix the listing
        // ran PriceList::with([...])->paginate() with NO tenant_id/company_id
        // predicate, leaking every tenant's price-list catalog (code, name,
        // currency, items, eager-loaded company tax_id) to any user with
        // pricing.view. Scanner missed it because Gate A only sees `exists:`
        // rules and Gate B only sees find()/findOrFail() — bare paginate()
        // listings fall through both. Fix: scope by tenant + company.
        $company = $this->companyContext->requireCompany();

        $query = PriceList::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['company', 'items']);

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
        // Hostile-grep blind spot bundled into api.pricing cluster: tenant-scope
        // PriceList route lookup. price_lists has tenant_id + company_id.
        $company = $this->companyContext->requireCompany();

        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['company', 'items.product', 'partnerPriceLists.partner'])
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

        /** @var User $user */
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

        // Hostile-grep blind spot: tenant-scope PriceList route lookup.
        $company = $this->companyContext->requireCompany();

        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
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
        // Hostile-grep blind spot: tenant-scope PriceList route lookup.
        $company = $this->companyContext->requireCompany();

        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
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
        // api.pricing.003: tenant-scope products exists validator.
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'product_id' => [
                'required',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'price' => 'required|numeric|min:0',
            'min_quantity' => 'required|numeric|min:0',
            'max_quantity' => 'nullable|numeric|gt:min_quantity',
        ]);

        // Hostile-grep blind spot: tenant-scope PriceList route lookup.
        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($priceListId);

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

        // Hostile-grep blind spot: pre-load tenant-scoped PriceList; the
        // PriceListItem lookup then anchors on a tenant-scoped price_list_id.
        $company = $this->companyContext->requireCompany();
        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($priceListId);

        $item = PriceListItem::where('price_list_id', $priceList->id)
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
        // Hostile-grep blind spot: pre-load tenant-scoped PriceList.
        $company = $this->companyContext->requireCompany();
        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($priceListId);

        $item = PriceListItem::where('price_list_id', $priceList->id)
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
        // api.pricing.004: tenant-scope partners exists validator.
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'partner_id' => [
                'required',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'priority' => 'integer|min:0',
        ]);

        // Hostile-grep blind spot: tenant-scope PriceList route lookup.
        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($priceListId);

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
        // api.pricing round-2 (Opus Finding 4): pre-load tenant-scoped
        // PriceList AND tenant-scoped Partner. Symmetric routes
        // (assignToPartner) validate partner_id at the validator tier; this
        // route gets the partner_id via URL segment so we validate by
        // pre-loading. firstOrFail will then 404 either when the priceList
        // is foreign-tenant OR when the partner is foreign-tenant OR when
        // the join row doesn't exist for this same-tenant pair.
        $company = $this->companyContext->requireCompany();
        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($priceListId);
        Partner::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($partnerId);

        $assignment = PartnerPriceList::where('price_list_id', $priceList->id)
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
        // api.pricing.005 / .006: tenant-scope products + partners validators.
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'product_id' => [
                'required',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'partner_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
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
        // api.pricing.007: tenant-scope products validator.
        // Hostile-grep blind spot bundled in: tenant-scope price_lists validator
        // (pre-fix bare exists let any tenant's price_list UUID through).
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'price_list_id' => [
                'required',
                ScopedExists::tenantAndCompany('price_lists', $company->tenant_id, $company->id),
            ],
            'product_id' => [
                'required',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
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
        // api.pricing.008 / .009: tenant-scope products + partners validators.
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => [
                'required',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'partner_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
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
        // api.pricing.010: tenant-scope products validator.
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'sell_price' => 'required|numeric|min:0',
        ]);

        // api.pricing.002: tenant-scope Product findOrFail (defense-in-depth
        // alongside the validator above, so a service-direct caller cannot
        // bypass the validator tier).
        /** @var Product $product */
        $product = Product::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($validated['product_id']);
        $sellPrice = (float) $validated['sell_price'];

        /** @var User $user */
        $user = $request->user();

        $marginLevel = $this->marginService->getMarginLevel($product, $sellPrice);
        $canSell = $this->marginService->canSellAtPrice($product, $sellPrice, $user);
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
