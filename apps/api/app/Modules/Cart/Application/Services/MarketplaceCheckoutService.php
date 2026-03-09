<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Services;

use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Marketplace\Application\Services\MarketplaceOrderService;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;

class MarketplaceCheckoutService
{
    public function __construct(
        private readonly MarketplaceOrderService $orderService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Checkout marketplace items from the cart, creating marketplace orders.
     *
     * Groups items by seller and creates one marketplace order per seller.
     * Validates that reservations are still active before checkout.
     *
     * @param  array<int, string>  $itemIds
     * @return array<int, MarketplaceOrder>
     *
     * @throws \DomainException If reservations are invalid or prices changed significantly
     */
    public function checkoutMarketplaceItems(CatalogCart $cart, array $itemIds): array
    {
        $company = $this->companyContext->requireCompany();

        $items = CatalogCartItem::whereIn('id', $itemIds)
            ->where('cart_id', $cart->id)
            ->where('source', CartItemSource::Marketplace)
            ->get();

        if ($items->isEmpty()) {
            throw new \DomainException('No marketplace items found for checkout');
        }

        // Validate reservations
        foreach ($items as $item) {
            if ($item->reservation_id !== null) {
                $reservation = StockReservation::find($item->reservation_id);
                if ($reservation === null || ! $reservation->isActive()) {
                    throw new \DomainException(
                        "Reservation expired for item: {$item->article_name}. Please re-add to cart."
                    );
                }
            }

            // Warn on significant price changes (>10%)
            if ($item->marketplace_listing_id !== null) {
                $listing = MarketplaceListing::find($item->marketplace_listing_id);
                if ($listing !== null && $item->unit_price !== null) {
                    /** @var numeric-string $listingPrice */
                    $listingPrice = (string) $listing->price;
                    /** @var numeric-string $cartPrice */
                    $cartPrice = (string) $item->unit_price;
                    $priceDiff = bcsub($listingPrice, $cartPrice, 3);
                    $priceChangePercent = bccomp($cartPrice, '0', 3) > 0
                        ? bcmul(bcdiv($priceDiff, (string) $item->unit_price, 5), '100', 2)
                        : '0.00';

                    if (bccomp($priceChangePercent, '10', 2) > 0) {
                        throw new \DomainException(
                            "Price for '{$item->article_name}' has increased by {$priceChangePercent}% since added to cart. "
                            ."Current price: {$listing->price}, Cart price: {$item->unit_price}."
                        );
                    }
                }
            }
        }

        // Group by seller and create orders
        $groupedBySeller = $items->groupBy(function (CatalogCartItem $item): string {
            $listing = MarketplaceListing::find($item->marketplace_listing_id);

            return $listing !== null ? $listing->seller_id : 'unknown';
        });

        $orders = [];

        foreach ($groupedBySeller as $sellerId => $sellerItems) {
            if ($sellerId === 'unknown') {
                continue;
            }

            /** @var array<int, array{listing_id: string, quantity: string}> $orderItems */
            $orderItems = $sellerItems->map(fn (CatalogCartItem $item): array => [
                'listing_id' => (string) $item->marketplace_listing_id,
                'quantity' => (string) $item->quantity,
            ])->values()->all();

            $order = $this->orderService->createOrder($orderItems, $company);
            $orders[] = $order;
        }

        return $orders;
    }
}
