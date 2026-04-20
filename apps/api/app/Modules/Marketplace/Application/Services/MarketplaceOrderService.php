<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Marketplace\Domain\Enums\MarketplaceOrderStatus;
use App\Modules\Marketplace\Domain\Models\BuyerSellerMapping;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrderLine;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceOrderService
{
    public function __construct(
        private readonly StockReservationService $stockReservationService,
        private readonly DocumentNumberingService $documentNumberingService,
    ) {}

    /**
     * Create a stock reservation for a marketplace cart item.
     *
     * @throws \RuntimeException If insufficient stock
     */
    public function reserveForCart(
        MarketplaceListing $listing,
        string $quantity,
        string $cartItemId,
    ): StockReservation {
        $seller = $listing->seller;

        if ($seller->company_id === null) {
            throw new \DomainException('Cannot reserve stock for external sellers');
        }

        $sellerCompany = Company::findOrFail($seller->company_id);

        // Find the first stock level location for this product
        $stockLevel = StockLevel::where('product_id', $listing->source_product_id)
            ->where('company_id', $sellerCompany->id)
            ->where('quantity', '>', 0)
            ->first();

        if ($stockLevel === null) {
            throw new \RuntimeException('No stock available for this listing');
        }

        return $this->stockReservationService->reserve(
            company: $sellerCompany,
            productId: (string) $listing->source_product_id,
            locationId: $stockLevel->location_id,
            quantity: $quantity,
            sourceType: ReservationSource::MarketplaceOrder,
            sourceId: $cartItemId,
            notes: 'Marketplace cart reservation for listing '.$listing->id,
        );
    }

    /**
     * Create a marketplace order with PO in buyer system and SO in seller system.
     *
     * @param  array<int, array{listing_id: string, quantity: string}>  $items
     */
    public function createOrder(array $items, Company $buyerCompany): MarketplaceOrder
    {
        return DB::transaction(function () use ($items, $buyerCompany): MarketplaceOrder {
            // Group items by seller
            $listingIds = array_column($items, 'listing_id');
            $listings = MarketplaceListing::with('seller')
                ->whereIn('id', $listingIds)
                ->get()
                ->keyBy('id');

            // Validate all listings exist and are from same seller (for now single-seller orders)
            $sellers = $listings->pluck('seller')->unique('id');
            if ($sellers->count() !== 1) {
                throw new \DomainException('All items in a marketplace order must be from the same seller');
            }

            /** @var MarketplaceSeller $seller */
            $seller = $sellers->first();

            // Calculate totals
            $subtotal = '0.000';
            $orderLines = [];

            foreach ($items as $item) {
                $listing = $listings->get($item['listing_id']);
                if ($listing === null) {
                    throw new \DomainException('Listing not found: '.$item['listing_id']);
                }

                /** @var numeric-string $price */
                $price = (string) $listing->price;
                /** @var numeric-string $qty */
                $qty = $item['quantity'];
                $lineTotal = bcmul($price, $qty, 3);
                $subtotal = bcadd($subtotal, $lineTotal, 3);

                $orderLines[] = [
                    'listing' => $listing,
                    'quantity' => $item['quantity'],
                    'unit_price' => (string) $listing->price,
                    'line_total' => $lineTotal,
                ];
            }

            $commissionRate = (string) $seller->commission_rate;
            $commissionAmount = bcmul($subtotal, bcdiv($commissionRate, '100', 5), 3);
            $total = bcadd($subtotal, $commissionAmount, 3);

            // Ensure partner mappings exist (auto-create on first order)
            $mapping = $this->ensureBuyerSellerMapping($seller, $buyerCompany);

            // Create the marketplace order
            $order = MarketplaceOrder::create([
                'seller_id' => $seller->id,
                'buyer_tenant_id' => $buyerCompany->tenant_id,
                'buyer_company_id' => $buyerCompany->id,
                'order_number' => 'MKT-'.date('Y').'-'.Str::upper(Str::random(6)),
                'order_status' => MarketplaceOrderStatus::Pending,
                'country_code' => $seller->country_code,
                'currency' => $seller->currency,
                'subtotal' => $subtotal,
                'commission_amount' => $commissionAmount,
                'commission_rate' => $commissionRate,
                'total' => $total,
            ]);

            // Create order lines
            foreach ($orderLines as $lineData) {
                MarketplaceOrderLine::create([
                    'order_id' => $order->id,
                    'listing_id' => $lineData['listing']->id,
                    'article_number' => $lineData['listing']->article_number,
                    'article_name' => $lineData['listing']->product_name,
                    'supplier_brand' => $lineData['listing']->supplier_brand,
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                    'line_total' => $lineData['line_total'],
                ]);
            }

            // Create PO in buyer's system
            $buyerPO = $this->createBuyerPurchaseOrder($order, $buyerCompany, $mapping, $orderLines);
            $order->update(['buyer_document_id' => $buyerPO->id]);

            // Create SO in seller's system (if seller is ERP tenant)
            if ($seller->company_id !== null) {
                $sellerCompany = Company::findOrFail($seller->company_id);
                $sellerSO = $this->createSellerSalesOrder($order, $sellerCompany, $mapping, $orderLines);
                $order->update(['seller_document_id' => $sellerSO->id]);
            }

            // Update seller volume counters
            $this->updateSellerVolume($seller, $subtotal, count($items));

            /** @var MarketplaceOrder */
            return $order->fresh();
        });
    }

    /**
     * Cancel a marketplace order and release all reservations.
     */
    public function cancelOrder(MarketplaceOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order->update([
                'order_status' => MarketplaceOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Order cancelled',
            ]);

            // Release any reservations tied to this order
            $this->stockReservationService->releaseBySource(
                sourceType: ReservationSource::MarketplaceOrder,
                sourceId: $order->id,
                reason: ReleaseReason::Cancelled,
            );
        });
    }

    /**
     * Ensure BuyerSellerMapping exists, auto-creating partner records on first order.
     */
    private function ensureBuyerSellerMapping(
        MarketplaceSeller $seller,
        Company $buyerCompany,
    ): BuyerSellerMapping {
        $mapping = BuyerSellerMapping::where('seller_id', $seller->id)
            ->where('buyer_tenant_id', $buyerCompany->tenant_id)
            ->where('buyer_company_id', $buyerCompany->id)
            ->first();

        if ($mapping !== null) {
            return $mapping;
        }

        // Auto-create supplier partner in buyer's system
        $supplierPartner = Partner::create([
            'tenant_id' => $buyerCompany->tenant_id,
            'company_id' => $buyerCompany->id,
            'name' => $seller->display_name.' (Marketplace)',
            'type' => PartnerType::Supplier,
            'country_code' => $seller->country_code,
        ]);

        // Auto-create customer partner in seller's system (if ERP tenant)
        $customerPartner = null;
        if ($seller->company_id !== null) {
            $sellerCompany = Company::findOrFail($seller->company_id);
            $customerPartner = Partner::create([
                'tenant_id' => $sellerCompany->tenant_id,
                'company_id' => $sellerCompany->id,
                'name' => $buyerCompany->name.' (Marketplace)',
                'type' => PartnerType::Customer,
                'country_code' => $buyerCompany->country_code,
            ]);
        }

        return BuyerSellerMapping::create([
            'seller_id' => $seller->id,
            'buyer_tenant_id' => $buyerCompany->tenant_id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_partner_id' => $supplierPartner->id,
            'seller_partner_id' => $customerPartner?->id,
        ]);
    }

    /**
     * Create a PurchaseOrder in the buyer's system.
     *
     * @param  array<int, array{listing: MarketplaceListing, quantity: string, unit_price: string, line_total: string}>  $orderLines
     */
    private function createBuyerPurchaseOrder(
        MarketplaceOrder $order,
        Company $buyerCompany,
        BuyerSellerMapping $mapping,
        array $orderLines,
    ): Document {
        $documentNumber = $this->documentNumberingService->generateNumber(
            $buyerCompany->tenant_id,
            $buyerCompany->id,
            DocumentType::PurchaseOrder,
        );

        $document = Document::create([
            'tenant_id' => $buyerCompany->tenant_id,
            'company_id' => $buyerCompany->id,
            'partner_id' => $mapping->buyer_partner_id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'total' => $order->subtotal,
            'balance_due' => $order->subtotal,
            'reference' => 'Marketplace Order: '.$order->order_number,
        ]);

        $lineNumber = 1;
        foreach ($orderLines as $lineData) {
            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => $lineNumber,
                'description' => $lineData['listing']->product_name,
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'line_total' => $lineData['line_total'],
            ]);
            $lineNumber++;
        }

        return $document;
    }

    /**
     * Create a SalesOrder in the seller's system.
     *
     * @param  array<int, array{listing: MarketplaceListing, quantity: string, unit_price: string, line_total: string}>  $orderLines
     */
    private function createSellerSalesOrder(
        MarketplaceOrder $order,
        Company $sellerCompany,
        BuyerSellerMapping $mapping,
        array $orderLines,
    ): Document {
        $documentNumber = $this->documentNumberingService->generateNumber(
            $sellerCompany->tenant_id,
            $sellerCompany->id,
            DocumentType::SalesOrder,
        );

        $document = Document::create([
            'tenant_id' => $sellerCompany->tenant_id,
            'company_id' => $sellerCompany->id,
            'partner_id' => $mapping->seller_partner_id,
            'type' => DocumentType::SalesOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'total' => $order->subtotal,
            'balance_due' => $order->subtotal,
            'reference' => 'Marketplace Order: '.$order->order_number,
        ]);

        $lineNumber = 1;
        foreach ($orderLines as $lineData) {
            DocumentLine::create([
                'document_id' => $document->id,
                'product_id' => $lineData['listing']->source_product_id,
                'line_number' => $lineNumber,
                'description' => $lineData['listing']->product_name,
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'line_total' => $lineData['line_total'],
            ]);
            $lineNumber++;
        }

        return $document;
    }

    /**
     * Update seller volume tracking counters.
     */
    private function updateSellerVolume(
        MarketplaceSeller $seller,
        string $subtotal,
        int $itemCount,
    ): void {
        /** @var numeric-string $currentGmv */
        $currentGmv = (string) $seller->total_gmv;
        /** @var numeric-string $currentMonthGmv */
        $currentMonthGmv = (string) $seller->gmv_current_month;
        /** @var numeric-string $sub */
        $sub = $subtotal;

        $seller->update([
            'total_gmv' => bcadd($currentGmv, $sub, 3),
            'total_orders' => $seller->total_orders + 1,
            'total_items_sold' => $seller->total_items_sold + $itemCount,
            'gmv_current_month' => bcadd($currentMonthGmv, $sub, 3),
            'orders_current_month' => $seller->orders_current_month + 1,
        ]);
    }
}
