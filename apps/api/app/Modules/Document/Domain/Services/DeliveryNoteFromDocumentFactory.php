<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\GuidedDeliveryCannotBeGeneratedException;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a DRAFT delivery note from ANY source document's physical lines.
 *
 * ── WHY THIS EXISTS (Wave 3 T25c / D-30) ──
 * The only delivery-note generator in the system was
 * `SalesOrderToInvoiceConverter::createDeliveryNoteForOrder()` — **`private`**,
 * typed to a sales order, and reading `$order->partner` directly. Under
 * `require_delivery_first` a STANDALONE invoice needs the same machinery: a
 * compliance gate whose compliant path cannot be reached is worked around by
 * back-dating or by cancel-and-recreate, which is worse than the act it
 * prevents. So this is a real extraction, not a parameter change, and the sales
 * -order path calls the same factory — one generator, one FEFO behaviour, one
 * set of copied fields.
 *
 * What stays with the CALLER: the linkage write. A sales order records the new
 * delivery note in `order.payload['delivery_note_ids']`; a standalone invoice
 * records it in `invoice.payload['source_delivery_note_ids']`. Those are two
 * different shapes with two different readers, and folding them in here would
 * make the factory guess which one its caller meant.
 *
 * The delivery note is created **DRAFT**. Confirming it is a separate, explicit
 * act (`DeliveryNoteService::confirm()`) because that is what issues stock and
 * seals the fiscal chain.
 */
final class DeliveryNoteFromDocumentFactory
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly FEFOInventoryService $fefoService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Copy the source's PHYSICAL lines onto a new draft delivery note.
     *
     * Batch-tracked products are split FEFO into one line per batch. The guided
     * invoice path opts into a typed refusal because it must never silently
     * flatten a batch-tracked line. The legacy SO-to-invoice converter retains
     * its disclosed unbatched fallback until the wider redesign ticket lands.
     *
     * @param  bool  $autoCreated  marks the note batch-confirmable from the guided modal
     * @param  bool  $requireCompleteFefoAllocation  refuse rather than use the legacy unbatched fallback
     */
    public function createDraftFrom(
        Document $source,
        Partner $partner,
        Location $location,
        string $notes,
        bool $autoCreated = true,
        bool $requireCompleteFefoAllocation = false,
    ): Document {
        // Rule 19: scale comes from the DOCUMENT's currency, never from an
        // implicit CompanyContext — this factory is reachable from paths with no
        // bound company.
        $scale = $this->scaleResolver->getScaleSafe((string) $source->currency, 3);

        /** @phpstan-ignore argument.type */
        $delivery = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $source->tenant_id,
            'company_id' => $source->company_id,
            'location_id' => $location->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => $this->numberingService->generateNumber(
                $source->tenant_id,
                $source->company_id,
                DocumentType::DeliveryNote,
            ),
            'document_date' => now(),
            'partner_id' => $source->partner_id,
            'partner_name' => $partner->name,
            'partner_address' => $partner->street_address,
            'currency' => $source->currency,
            'subtotal' => $source->subtotal,
            'discount_amount' => $source->discount_amount,
            'tax_amount' => $source->tax_amount,
            'total' => $source->total,
            'notes' => $notes,
            'source_document_id' => $source->id,
            'payload' => [
                'auto_created' => $autoCreated,
            ],
        ]);

        $dnLineNumber = 0;

        foreach ($source->lines as $line) {
            $product = null;

            if ($line->product_id !== null) {
                // api.document.014/015/016: scope the Product lookup by the source
                // document's tenant + company, so a corrupted cross-tenant
                // `product_id` surfaces as null and the line is treated
                // defensively as a service.
                $product = Product::query()
                    ->where('tenant_id', $source->tenant_id)
                    ->where('company_id', $source->company_id)
                    ->find($line->product_id);

                if ($product !== null && ! $product->isPhysical()) {
                    continue;
                }
            }

            $requiresBatch = $product !== null && ($product->requires_batch_tracking ?? false);

            if ($requiresBatch) {
                $result = $this->fefoService->suggestBatchesForSale(
                    (string) $line->product_id,
                    (string) $location->id,
                    (string) $line->quantity,
                );

                if ($result->fullyFulfilled) {
                    foreach ($result->suggestions as $suggestion) {
                        $dnLineNumber++;
                        /** @var numeric-string $batchQty */
                        $batchQty = (string) $suggestion->quantity;
                        /** @var numeric-string $unitPrice */
                        $unitPrice = (string) $line->unit_price;

                        DocumentLine::create([
                            'id' => Str::uuid()->toString(),
                            'document_id' => $delivery->id,
                            'line_number' => $dnLineNumber,
                            'product_id' => $line->product_id,
                            'product_code' => $line->product_code,
                            'description' => $line->description,
                            'quantity' => $batchQty,
                            'unit_price' => $line->unit_price,
                            'discount_percent' => $line->discount_percent,
                            'discount_amount' => null,
                            'tax_rate' => $line->tax_rate,
                            'line_total' => bcmul($batchQty, $unitPrice, $scale),
                            'notes' => $line->notes,
                            'designation_default_snapshot' => $line->designation_default_snapshot,
                            'source_line_id' => $line->id,
                            'batch_id' => $suggestion->batch->id,
                            'location_id' => $line->location_id,
                        ]);
                    }
                } elseif ($requireCompleteFefoAllocation) {
                    Log::warning('FEFO allocation failed for guided delivery; refusing silent unbatched fallback.', [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'shortfall' => $result->shortfall,
                    ]);

                    throw GuidedDeliveryCannotBeGeneratedException::fefoAllocationFailed();
                } else {
                    Log::warning('FEFO allocation failed during order conversion; retaining legacy unbatched fallback.', [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'shortfall' => $result->shortfall,
                    ]);
                    $dnLineNumber++;
                    $this->copyLine($delivery, $line, $dnLineNumber);
                }
            } else {
                $dnLineNumber++;
                $this->copyLine($delivery, $line, $dnLineNumber);
            }

            // The source line is now fully covered by a delivery note.
            $line->update(['quantity_delivered' => $line->quantity]);
        }

        if ($source->vehicleContext !== null) {
            $delivery->vehicleContext()->create([
                'id' => Str::uuid()->toString(),
                'vehicle_id' => $source->vehicleContext->vehicle_id,
                'vehicle_snapshot' => $source->vehicleContext->vehicle_snapshot,
                'mileage_at_service' => $source->vehicleContext->mileage_at_service,
                'context_data' => $source->vehicleContext->context_data,
            ]);
        }

        return $delivery->refresh();
    }

    private function copyLine(Document $delivery, DocumentLine $line, int $lineNumber): void
    {
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $delivery->id,
            'line_number' => $lineNumber,
            'product_id' => $line->product_id,
            'product_code' => $line->product_code,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'discount_percent' => $line->discount_percent,
            'discount_amount' => $line->discount_amount,
            'tax_rate' => $line->tax_rate,
            'line_total' => $line->line_total ?? '0.00',
            'notes' => $line->notes,
            'designation_default_snapshot' => $line->designation_default_snapshot,
            'source_line_id' => $line->id,
            'location_id' => $line->location_id,
        ]);
    }
}
