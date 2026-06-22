<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\Log;

/**
 * Listener that posts COGS (Cost of Goods Sold) journal entries
 * when an invoice is posted.
 *
 * For each physical product line in the invoice, this calculates:
 * - COGS = Weighted Average Cost (WAC) × quantity
 *
 * And creates a journal entry:
 * - Debit: Cost of Goods Sold (601)
 * - Credit: Inventory (37)
 *
 * Services (non-physical products) are skipped as they have no inventory cost.
 */
final class PostCOGSOnInvoice
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Handle the InvoicePosted event.
     */
    public function handle(InvoicePosted $event): void
    {
        // Only process sales invoices (not credit notes or other types)
        if ($event->documentType !== DocumentType::Invoice->value) {
            return;
        }

        try {
            $invoice = Document::with(['lines.product'])->find($event->invoiceId);

            if ($invoice === null) {
                Log::warning('PostCOGSOnInvoice: Invoice not found', [
                    'invoice_id' => $event->invoiceId,
                ]);

                return;
            }

            $lineItems = $this->extractPhysicalProductLines($invoice);

            // Skip if no physical products
            if (count($lineItems) === 0) {
                Log::debug('PostCOGSOnInvoice: No physical products in invoice', [
                    'invoice_id' => $event->invoiceId,
                    'document_number' => $event->documentNumber,
                ]);

                return;
            }

            $entry = $this->glService->createCOGSEntry(
                companyId: $event->companyId,
                invoiceId: $event->invoiceId,
                documentNumber: $event->documentNumber,
                lineItems: $lineItems,
                date: new \DateTimeImmutable($event->postedAt),
                currencyCode: $event->currency,
            );

            if ($entry !== null) {
                // Calculate total COGS for logging. unit_cost carries the WAC at
                // higher internal precision; accumulate at a high working scale and
                // round HALF-UP once at the boundary so the logged figure matches
                // the journal entry (see GeneralLedgerService::createCOGSEntry).
                $scale = $this->scale();
                $working = $scale + 6;
                $totalCogsPrecise = '0';
                foreach ($lineItems as $item) {
                    /** @var numeric-string $qty */
                    $qty = $item['quantity'];
                    /** @var numeric-string $cost */
                    $cost = $item['unit_cost'];
                    $totalCogsPrecise = bcadd($totalCogsPrecise, bcmul($qty, $cost, $working), $working);
                }
                $totalCogs = CurrencyScale::bcround($totalCogsPrecise, $scale);

                Log::info('PostCOGSOnInvoice: COGS entry created', [
                    'invoice_id' => $event->invoiceId,
                    'document_number' => $event->documentNumber,
                    'entry_number' => $entry->entry_number,
                    'total_cogs' => $totalCogs,
                ]);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the invoice posting
            // COGS posting is important but should not block the sale
            Log::error('PostCOGSOnInvoice: Failed to create COGS entry', [
                'invoice_id' => $event->invoiceId,
                'document_number' => $event->documentNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract line items that have physical products with cost.
     *
     * @return array<int, array{product_id: string, quantity: string, unit_cost: string}>
     */
    private function extractPhysicalProductLines(Document $invoice): array
    {
        $lineItems = [];

        foreach ($invoice->lines as $line) {
            // Skip lines without a product (service lines, description-only lines)
            if ($line->product === null) {
                continue;
            }

            $product = $line->product;

            // Skip non-physical products (services)
            if (! $product->isPhysical()) {
                continue;
            }

            // Get the cost price (WAC - Weighted Average Cost)
            /** @var numeric-string $unitCost */
            $unitCost = $product->cost_price ?? '0.00';

            // Skip if no cost (shouldn't happen but safety check)
            if (bccomp($unitCost, '0.00', $this->scale()) <= 0) {
                Log::debug('PostCOGSOnInvoice: Skipping product with zero cost', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ]);

                continue;
            }

            $lineItems[] = [
                'product_id' => $product->id,
                'quantity' => $line->quantity,
                'unit_cost' => $unitCost,
            ];
        }

        return $lineItems;
    }
}
