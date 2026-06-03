<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Controllers;

use App\Modules\Cart\Application\DTOs\CatalogCartData;
use App\Modules\Cart\Application\DTOs\CatalogCartItemData;
use App\Modules\Cart\Application\Services\CartConversionService;
use App\Modules\Cart\Application\Services\CartService;
use App\Modules\Cart\Application\Services\MarketplaceCheckoutService;
use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Marketplace\Application\DTOs\MarketplaceOrderData;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class CatalogCartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CartConversionService $conversionService,
        private readonly MarketplaceCheckoutService $checkoutService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * GET /api/v1/catalog-carts
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $carts = CatalogCart::query()
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->active()
            ->with('items')
            ->orderByDesc('updated_at')
            ->get();

        $data = $carts->map(fn (CatalogCart $cart): CatalogCartData => CatalogCartData::fromModel($cart));

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/catalog-carts
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'vehicle_id' => ['nullable', 'string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = $this->cartService->createCart(
            user: $user,
            company: $company,
            name: $validated['name'] ?? null,
            vehicleId: $validated['vehicle_id'] ?? null,
        );

        return response()->json([
            'data' => CatalogCartData::fromModel($cart),
        ], 201);
    }

    /**
     * GET /api/v1/catalog-carts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->with('items')
            ->findOrFail($id);

        return response()->json([
            'data' => CatalogCartData::fromModel($cart),
        ]);
    }

    /**
     * PATCH /api/v1/catalog-carts/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_shared' => ['sometimes', 'boolean'],
            'shared_with_user_ids' => ['sometimes', 'nullable', 'array'],
            'shared_with_user_ids.*' => ['string', 'uuid'],
        ]);

        $cart->update($validated);

        return response()->json([
            'data' => CatalogCartData::fromModel($cart->fresh()->load('items')), /** @phpstan-ignore-line */
        ]);
    }

    /**
     * DELETE /api/v1/catalog-carts/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->findOrFail($id);

        // Release all marketplace reservations before deleting
        foreach ($cart->items as $item) {
            if ($item->reservation_id !== null) {
                $this->cartService->removeItem($item);
            }
        }

        $cart->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/catalog-carts/{id}/items
     */
    public function addItem(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'source' => ['required', Rule::enum(CartItemSource::class)],
            'article_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'article_number' => ['nullable', 'string'],
            'supplier_brand' => ['nullable', 'string'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'product_id' => ['nullable', 'string', 'uuid'],
            'platform_article_id' => ['nullable', 'string', 'uuid'],
            'marketplace_listing_id' => ['nullable', 'string', 'uuid'],
            'preferred_supplier_partner_id' => ['nullable', 'string', 'uuid'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ], [
            'quantity.regex' => 'The quantity must have at most 4 decimal places.',
            'unit_price.regex' => 'The unit price must have at most 3 decimal places.',
        ]);

        try {
            $item = $this->cartService->addItem($cart, $validated);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => $e->getMessage(),
                    'errors' => [],
                ],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => $e->getMessage(),
                    'errors' => [],
                ],
            ], 422);
        }

        return response()->json([
            'data' => CatalogCartItemData::fromModel($item),
        ], 201);
    }

    /**
     * PATCH /api/v1/catalog-carts/{id}/items/{itemId}
     */
    public function updateItem(Request $request, string $id, string $itemId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->findOrFail($id);

        $item = CatalogCartItem::where('cart_id', $cart->id)->findOrFail($itemId);

        $validated = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer'],
        ], [
            'quantity.regex' => 'The quantity must have at most 4 decimal places.',
        ]);

        $item = $this->cartService->updateItem($item, $validated);

        return response()->json([
            'data' => CatalogCartItemData::fromModel($item),
        ]);
    }

    /**
     * DELETE /api/v1/catalog-carts/{id}/items/{itemId}
     */
    public function removeItem(Request $request, string $id, string $itemId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->findOrFail($id);

        $item = CatalogCartItem::where('cart_id', $cart->id)->findOrFail($itemId);

        $this->cartService->removeItem($item);

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/catalog-carts/{id}/convert
     */
    public function convert(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['string', 'uuid'],
            'type' => ['required', 'in:purchase_order,sales_order'],
            'customer_id' => [
                'required_if:type,sales_order',
                'nullable',
                'string',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
        ]);

        try {
            if ($validated['type'] === 'purchase_order') {
                $documents = $this->conversionService->convertToPurchaseOrder($cart, $validated['item_ids']);

                return response()->json([
                    'data' => array_map(fn ($doc): array => [
                        'id' => $doc->id,
                        'document_number' => $doc->document_number,
                        'type' => $doc->type->value,
                        'total' => (string) $doc->total,
                    ], $documents),
                ], 201);
            }

            $document = $this->conversionService->convertToSalesOrder(
                $cart,
                $validated['item_ids'],
                $validated['customer_id'],
            );

            return response()->json([
                'data' => [
                    'id' => $document->id,
                    'document_number' => $document->document_number,
                    'type' => $document->type->value,
                    'total' => (string) $document->total,
                ],
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => $e->getMessage(),
                    'errors' => [],
                ],
            ], 422);
        }
    }

    /**
     * POST /api/v1/catalog-carts/{id}/marketplace-checkout
     */
    public function marketplaceCheckout(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        $cart = CatalogCart::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->shared($user->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['string', 'uuid'],
        ]);

        try {
            $orders = $this->checkoutService->checkoutMarketplaceItems($cart, $validated['item_ids']);

            $orderData = array_map(
                fn ($order): MarketplaceOrderData => MarketplaceOrderData::fromModel($order),
                $orders,
            );

            return response()->json(['data' => $orderData], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => $e->getMessage(),
                    'errors' => [],
                ],
            ], 422);
        }
    }
}
