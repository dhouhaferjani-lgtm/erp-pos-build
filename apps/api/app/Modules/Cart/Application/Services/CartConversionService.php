<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Services;

use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Support\Facades\DB;

class CartConversionService
{
    public function __construct(
        private readonly DocumentNumberingService $documentNumberingService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Convert catalog items to purchase orders, grouped by supplier.
     *
     * @param  array<int, string>  $itemIds
     * @return array<int, Document>
     */
    public function convertToPurchaseOrder(CatalogCart $cart, array $itemIds): array
    {
        return DB::transaction(function () use ($cart, $itemIds): array {
            $company = $this->companyContext->requireCompany();
            $items = CatalogCartItem::whereIn('id', $itemIds)
                ->where('cart_id', $cart->id)
                ->get();

            // Group items by supplier (preferred_supplier_partner_id)
            $grouped = $items->groupBy(fn (CatalogCartItem $item): string => $item->preferred_supplier_partner_id ?? 'no_supplier');

            $documents = [];

            foreach ($grouped as $partnerId => $groupItems) {
                $partner = $partnerId !== 'no_supplier' ? Partner::find($partnerId) : null;

                // If no partner, create a generic one
                if ($partner === null) {
                    $supplierBrand = $groupItems->first()->supplier_brand ?? 'Unknown Supplier';
                    $partner = Partner::create([
                        'tenant_id' => $company->tenant_id,
                        'company_id' => $company->id,
                        'name' => $supplierBrand,
                        'type' => PartnerType::Supplier,
                        'country_code' => $company->country_code,
                    ]);
                }

                $documentNumber = $this->documentNumberingService->generateNumber(
                    $company->tenant_id,
                    $company->id,
                    DocumentType::PurchaseOrder,
                );

                $subtotal = '0.000';
                foreach ($groupItems as $item) {
                    if ($item->unit_price !== null) {
                        /** @var numeric-string $qty */
                        $qty = (string) $item->quantity;
                        /** @var numeric-string $unitPrice */
                        $unitPrice = (string) $item->unit_price;
                        $lineTotal = bcmul($qty, $unitPrice, 3);
                        $subtotal = bcadd($subtotal, $lineTotal, 3);
                    }
                }

                $document = Document::create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'partner_id' => $partner->id,
                    'type' => DocumentType::PurchaseOrder,
                    'fiscal_category' => FiscalCategory::NonFiscal,
                    'fiscal_status' => FiscalStatus::Draft,
                    'status' => DocumentStatus::Draft,
                    'document_number' => $documentNumber,
                    'document_date' => now(),
                    'currency' => $company->currency,
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                    'balance_due' => $subtotal,
                    'reference' => 'From Cart: '.($cart->name ?? $cart->id),
                ]);

                $lineNumber = 1;
                foreach ($groupItems as $item) {
                    /** @var numeric-string $itemQty */
                    $itemQty = (string) $item->quantity;
                    /** @var numeric-string $itemUnitPrice */
                    $itemUnitPrice = (string) ($item->unit_price ?? '0.000');
                    $lineTotal = $item->unit_price !== null
                        ? bcmul($itemQty, $itemUnitPrice, 3)
                        : '0.000';

                    DocumentLine::create([
                        'document_id' => $document->id,
                        'product_id' => $item->product_id,
                        'line_number' => $lineNumber,
                        'description' => $item->article_name,
                        'quantity' => (string) $item->quantity,
                        'unit_price' => $item->unit_price ?? '0.000',
                        'line_total' => $lineTotal,
                    ]);
                    $lineNumber++;
                }

                $documents[] = $document;
            }

            // Update cart status
            $this->updateCartStatusAfterConversion($cart, $itemIds);

            return $documents;
        });
    }

    /**
     * Convert cart items to a sales order for a customer.
     *
     * @param  array<int, string>  $itemIds
     */
    public function convertToSalesOrder(CatalogCart $cart, array $itemIds, string $customerId): Document
    {
        return DB::transaction(function () use ($cart, $itemIds, $customerId): Document {
            $company = $this->companyContext->requireCompany();
            $customer = Partner::findOrFail($customerId);
            $items = CatalogCartItem::whereIn('id', $itemIds)
                ->where('cart_id', $cart->id)
                ->get();

            $documentNumber = $this->documentNumberingService->generateNumber(
                $company->tenant_id,
                $company->id,
                DocumentType::SalesOrder,
            );

            $subtotal = '0.000';
            foreach ($items as $item) {
                if ($item->unit_price !== null) {
                    /** @var numeric-string $soQty */
                    $soQty = (string) $item->quantity;
                    /** @var numeric-string $soPrice */
                    $soPrice = (string) $item->unit_price;
                    $lineTotal = bcmul($soQty, $soPrice, 3);
                    $subtotal = bcadd($subtotal, $lineTotal, 3);
                }
            }

            $document = Document::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'partner_id' => $customer->id,
                'type' => DocumentType::SalesOrder,
                'fiscal_category' => FiscalCategory::NonFiscal,
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $documentNumber,
                'document_date' => now(),
                'currency' => $company->currency,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'balance_due' => $subtotal,
                'reference' => 'From Cart: '.($cart->name ?? $cart->id),
            ]);

            $lineNumber = 1;
            foreach ($items as $item) {
                /** @var numeric-string $soLineQty */
                $soLineQty = (string) $item->quantity;
                /** @var numeric-string $soLinePrice */
                $soLinePrice = (string) ($item->unit_price ?? '0.000');
                $lineTotal = $item->unit_price !== null
                    ? bcmul($soLineQty, $soLinePrice, 3)
                    : '0.000';

                DocumentLine::create([
                    'document_id' => $document->id,
                    'product_id' => $item->product_id,
                    'line_number' => $lineNumber,
                    'description' => $item->article_name,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => $item->unit_price ?? '0.000',
                    'line_total' => $lineTotal,
                ]);
                $lineNumber++;
            }

            $this->updateCartStatusAfterConversion($cart, $itemIds);

            return $document;
        });
    }

    /**
     * Update cart status after conversion.
     *
     * If all items are converted, mark as Converted.
     * If some marketplace items remain, mark as Partial.
     *
     * @param  array<int, string>  $convertedItemIds
     */
    private function updateCartStatusAfterConversion(CatalogCart $cart, array $convertedItemIds): void
    {
        $remainingItems = CatalogCartItem::where('cart_id', $cart->id)
            ->whereNotIn('id', $convertedItemIds)
            ->count();

        if ($remainingItems === 0) {
            $cart->update(['status' => CartStatus::Converted]);
        } else {
            $cart->update(['status' => CartStatus::Partial]);
        }
    }
}
