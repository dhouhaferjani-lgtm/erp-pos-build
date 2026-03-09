<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Services;

use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Marketplace\Application\Services\MarketplaceOrderService;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use Illuminate\Support\Facades\Cache;

class CartService
{
    public function __construct(
        private readonly MarketplaceOrderService $marketplaceOrderService,
        private readonly StockReservationService $stockReservationService,
    ) {}

    public function createCart(
        User $user,
        Company $company,
        ?string $name = null,
        ?string $vehicleId = null,
    ): CatalogCart {
        return CatalogCart::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'name' => $name,
            'vehicle_id' => $vehicleId,
            'status' => CartStatus::Active,
        ]);
    }

    /**
     * Add an item to the cart.
     *
     * For marketplace items, creates a stock reservation against the seller's inventory.
     *
     * @param array{
     *   source: string,
     *   article_name: string,
     *   quantity: string,
     *   article_number?: string,
     *   supplier_brand?: string,
     *   unit_price?: string,
     *   currency?: string,
     *   product_id?: string,
     *   platform_article_id?: string,
     *   marketplace_listing_id?: string,
     *   preferred_supplier_partner_id?: string,
     *   notes?: string,
     *   sort_order?: int,
     * } $itemData
     */
    public function addItem(CatalogCart $cart, array $itemData): CatalogCartItem
    {
        $source = CartItemSource::from($itemData['source']);

        $attributes = [
            'cart_id' => $cart->id,
            'article_name' => $itemData['article_name'],
            'article_number' => $itemData['article_number'] ?? null,
            'supplier_brand' => $itemData['supplier_brand'] ?? null,
            'quantity' => $itemData['quantity'],
            'unit_price' => $itemData['unit_price'] ?? null,
            'currency' => $itemData['currency'] ?? null,
            'source' => $source,
            'product_id' => $itemData['product_id'] ?? null,
            'platform_article_id' => $itemData['platform_article_id'] ?? null,
            'marketplace_listing_id' => $itemData['marketplace_listing_id'] ?? null,
            'preferred_supplier_partner_id' => $itemData['preferred_supplier_partner_id'] ?? null,
            'notes' => $itemData['notes'] ?? null,
            'sort_order' => $itemData['sort_order'] ?? 0,
        ];

        // For marketplace items, create reservation
        if ($source === CartItemSource::Marketplace && isset($itemData['marketplace_listing_id'])) {
            $listing = MarketplaceListing::findOrFail($itemData['marketplace_listing_id']);

            // Anti-abuse: check re-reserve limit
            $this->checkReReserveLimit($listing->id, $cart->user_id);

            $reservation = $this->marketplaceOrderService->reserveForCart(
                listing: $listing,
                quantity: $itemData['quantity'],
                cartItemId: $cart->id, // Use cart ID as source
            );

            $attributes['reservation_id'] = $reservation->id;
            $attributes['reservation_expires_at'] = $reservation->expires_at;
            $attributes['unit_price'] = (string) $listing->price;
            $attributes['currency'] = $listing->currency;
        }

        return CatalogCartItem::create($attributes);
    }

    /**
     * Remove an item from the cart.
     *
     * For marketplace items, releases the stock reservation.
     */
    public function removeItem(CatalogCartItem $item): void
    {
        // Release reservation if marketplace item
        if ($item->reservation_id !== null) {
            $reservation = StockReservation::find($item->reservation_id);
            if ($reservation !== null && $reservation->isActive()) {
                $this->stockReservationService->release($reservation, ReleaseReason::Cancelled);
            }
        }

        $item->delete();
    }

    /**
     * Update a cart item's quantity or other properties.
     *
     * @param  array{quantity?: string, notes?: string, sort_order?: int}  $data
     */
    public function updateItem(CatalogCartItem $item, array $data): CatalogCartItem
    {
        $item->update($data);

        /** @var CatalogCartItem */
        return $item->fresh();
    }

    /**
     * Check re-reserve anti-abuse limit.
     *
     * Limits how many times a user can reserve the same listing within 24 hours.
     *
     * @throws \DomainException If limit exceeded
     */
    private function checkReReserveLimit(string $listingId, string $userId): void
    {
        $maxAttempts = (int) config('marketplace.anti_abuse.max_re_reserves_per_listing_per_day', 3);
        $cacheKey = "marketplace_reserve:{$userId}:{$listingId}";

        $attempts = (int) Cache::get($cacheKey, 0);

        if ($attempts >= $maxAttempts) {
            throw new \DomainException(
                "Re-reserve limit exceeded. Maximum {$maxAttempts} reserve attempts per listing per day."
            );
        }

        Cache::put($cacheKey, $attempts + 1, now()->endOfDay());
    }
}
