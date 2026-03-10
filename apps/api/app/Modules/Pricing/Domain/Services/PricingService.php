<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Services;

use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;

class PricingService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Get price for a product based on partner, quantity, and date.
     *
     * @return array{price: string, source: string, price_list_id: string|null}
     */
    public function getPrice(
        string $productId,
        ?string $partnerId = null,
        string $quantity = '1.00',
        string $currency = 'USD',
        ?\DateTimeInterface $date = null
    ): array {
        $date = $date ?? now();

        // 1. Try partner-specific price list
        if ($partnerId !== null) {
            $partnerPrice = $this->getPartnerPrice($partnerId, $productId, $quantity, $currency, $date);
            if ($partnerPrice !== null) {
                return [
                    'price' => $partnerPrice['price'],
                    'source' => 'partner_price_list',
                    'price_list_id' => $partnerPrice['price_list_id'],
                ];
            }
        }

        // 2. Try default price list for currency
        $defaultPrice = $this->getDefaultPriceListPrice($productId, $quantity, $currency, $date);
        if ($defaultPrice !== null) {
            return [
                'price' => $defaultPrice['price'],
                'source' => 'default_price_list',
                'price_list_id' => $defaultPrice['price_list_id'],
            ];
        }

        // 3. Fall back to product base price
        $product = Product::findOrFail($productId);

        return [
            'price' => $product->sale_price ?? '0.00',
            'source' => 'base_price',
            'price_list_id' => null,
        ];
    }

    /**
     * Get partner-specific price.
     *
     * @return array{price: string, price_list_id: string}|null
     */
    private function getPartnerPrice(
        string $partnerId,
        string $productId,
        string $quantity,
        string $currency,
        \DateTimeInterface $date
    ): ?array {
        // Get all active price lists for partner, ordered by priority
        $partnerPriceLists = DB::table('partner_price_lists')
            ->join('price_lists', 'partner_price_lists.price_list_id', '=', 'price_lists.id')
            ->where('partner_price_lists.partner_id', $partnerId)
            ->where('partner_price_lists.is_active', true)
            ->where('price_lists.is_active', true)
            ->where('price_lists.currency', $currency)
            ->where(function ($query) use ($date) {
                $query->whereNull('partner_price_lists.valid_from')
                    ->orWhere('partner_price_lists.valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('partner_price_lists.valid_until')
                    ->orWhere('partner_price_lists.valid_until', '>=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('price_lists.valid_from')
                    ->orWhere('price_lists.valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('price_lists.valid_until')
                    ->orWhere('price_lists.valid_until', '>=', $date);
            })
            ->orderBy('partner_price_lists.priority', 'desc')
            ->select('price_lists.id')
            ->pluck('id');

        // Try each price list in priority order
        foreach ($partnerPriceLists as $priceListId) {
            $price = $this->getPriceFromList((string) $priceListId, $productId, $quantity);
            if ($price !== null) {
                return [
                    'price' => $price,
                    'price_list_id' => $priceListId,
                ];
            }
        }

        return null;
    }

    /**
     * Get price from default price list.
     *
     * @return array{price: string, price_list_id: string}|null
     */
    private function getDefaultPriceListPrice(
        string $productId,
        string $quantity,
        string $currency,
        \DateTimeInterface $date
    ): ?array {
        $priceList = PriceList::where('currency', $currency)
            ->where('is_default', true)
            ->where('is_active', true)
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            })
            ->first();

        if ($priceList === null) {
            return null;
        }

        /** @var string $priceListId */
        $priceListId = $priceList->id;
        $price = $this->getPriceFromList($priceListId, $productId, $quantity);

        if ($price === null) {
            return null;
        }

        return [
            'price' => $price,
            'price_list_id' => $priceListId,
        ];
    }

    /**
     * Get price from specific price list with quantity breaks
     */
    private function getPriceFromList(string $priceListId, string $productId, string $quantity): ?string
    {
        $items = PriceListItem::where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($quantity) {
                $query->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderBy('min_quantity', 'desc')
            ->first();

        return $items?->price;
    }

    /**
     * Calculate line subtotal with discounts.
     *
     * @return array{subtotal: string, discount_amount: string, total: string}
     */
    public function calculateLineTotal(
        string $unitPrice,
        string $quantity,
        ?string $discountPercent = null,
        ?string $discountAmount = null
    ): array {
        /** @var numeric-string $unitPrice */
        /** @var numeric-string $quantity */
        $subtotal = bcmul($unitPrice, $quantity, $this->scale());

        /** @var numeric-string $totalDiscount */
        $totalDiscount = '0';

        // Apply percentage discount
        if ($discountPercent !== null) {
            /** @var numeric-string $discountPct */
            $discountPct = $discountPercent;
            if (bccomp($discountPct, '0', $this->scale()) > 0) {
                $percentDiscount = bcmul($subtotal, bcdiv($discountPct, '100', 4), $this->scale());
                $totalDiscount = bcadd($totalDiscount, $percentDiscount, $this->scale());
            }
        }

        // Apply fixed amount discount
        if ($discountAmount !== null) {
            /** @var numeric-string $discountAmt */
            $discountAmt = $discountAmount;
            if (bccomp($discountAmt, '0', $this->scale()) > 0) {
                $totalDiscount = bcadd($totalDiscount, $discountAmt, $this->scale());
            }
        }

        // Discount cannot exceed subtotal
        if (bccomp($totalDiscount, $subtotal, $this->scale()) > 0) {
            $totalDiscount = $subtotal;
        }

        $total = bcsub($subtotal, $totalDiscount, $this->scale());

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'total' => $total,
        ];
    }

    /**
     * Apply document-level discount.
     *
     * @return array{discount_amount: string, total: string}
     */
    public function applyDocumentDiscount(
        string $subtotal,
        ?string $discountPercent = null,
        ?string $discountAmount = null
    ): array {
        /** @var numeric-string $subtotal */
        /** @var numeric-string $totalDiscount */
        $totalDiscount = '0';

        if ($discountPercent !== null) {
            /** @var numeric-string $discountPct */
            $discountPct = $discountPercent;
            if (bccomp($discountPct, '0', $this->scale()) > 0) {
                $percentDiscount = bcmul($subtotal, bcdiv($discountPct, '100', 4), $this->scale());
                $totalDiscount = bcadd($totalDiscount, $percentDiscount, $this->scale());
            }
        }

        if ($discountAmount !== null) {
            /** @var numeric-string $discountAmt */
            $discountAmt = $discountAmount;
            if (bccomp($discountAmt, '0', $this->scale()) > 0) {
                $totalDiscount = bcadd($totalDiscount, $discountAmt, $this->scale());
            }
        }

        if (bccomp($totalDiscount, $subtotal, $this->scale()) > 0) {
            $totalDiscount = $subtotal;
        }

        $total = bcsub($subtotal, $totalDiscount, $this->scale());

        return [
            'discount_amount' => $totalDiscount,
            'total' => $total,
        ];
    }

    /**
     * Get all quantity breaks for a product in a price list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PriceListItem>
     */
    public function getQuantityBreaks(string $priceListId, string $productId): \Illuminate\Database\Eloquent\Collection
    {
        return PriceListItem::where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->orderBy('min_quantity')
            ->get();
    }

    /**
     * Bulk price lookup for multiple products.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, array{price: string, source: string, price_list_id: string|null}>
     */
    public function getBulkPrices(
        array $productIds,
        ?string $partnerId = null,
        string $currency = 'USD',
        ?\DateTimeInterface $date = null
    ): array {
        $prices = [];

        foreach ($productIds as $productId) {
            $prices[$productId] = $this->getPrice($productId, $partnerId, '1.00', $currency, $date);
        }

        return $prices;
    }
}
