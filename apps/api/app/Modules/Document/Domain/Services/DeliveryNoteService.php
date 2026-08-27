<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for delivery note operations with fiscal hash chain compliance.
 *
 * Key differences from DocumentPostingService:
 * - DN is hashed on CONFIRM (when stock moves), not on POST
 * - DN does not create GL entries (not an accounting document)
 * - DN has its own separate hash chain per company
 * - Required for Tunisia fiscal compliance (tamper-proof delivery documents)
 *
 * Stock Reservations:
 * - When DN is confirmed, releases reservations from source sales order
 * - Only releases reservations for lines actually delivered
 *
 * Hash Chain Format: SHA256(previous_hash | document_number | date | total | currency)
 */
final class DeliveryNoteService
{
    /**
     * Quantities cross the WAC seam at the canonical quantity scale, as numeric
     * STRINGS. A `(float)` cast here silently dropped the 4th decimal on ordinary
     * magnitudes (DPA Wave 3 T3, house rule 19).
     */
    private const QUANTITY_SCALE = 4;

    public function __construct(
        private readonly FiscalHashService $hashService,
        private readonly StockReservationService $stockReservationService,
        private readonly WeightedAverageCostService $wacService,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly BatchStockService $batchStockService,
        private readonly FEFOInventoryService $fefoService,
        private readonly InventoryGlPostingBuffer $glBuffer,
        private readonly DocumentStatusService $documentStatusService,
    ) {}

    /**
     * Confirm a delivery note, adding it to the fiscal hash chain.
     *
     * This is the key moment when:
     * - Stock is moved (outbound for sales, inbound for purchases)
     * - The DN becomes fiscally sealed (tamper-proof)
     * - The DN is added to the company's DN hash chain
     *
     * @throws \DomainException If delivery note cannot be confirmed
     */
    public function confirm(Document $deliveryNote, ?InventoryGlPostingBuffer $rootBuffer = null): Document
    {
        if ($deliveryNote->type !== DocumentType::DeliveryNote) {
            throw new \DomainException(
                'Only delivery notes can be confirmed with this service. Use DocumentPostingService for other document types.'
            );
        }

        if (! $deliveryNote->isDraft()) {
            throw new \DomainException(
                'Only draft delivery notes can be confirmed. Current status: '.$deliveryNote->status->value
            );
        }

        if ($deliveryNote->location_id === null) {
            throw new \DomainException(
                'Delivery note must have a location before confirmation. Document: '.$deliveryNote->document_number
            );
        }

        return DB::transaction(function () use ($deliveryNote, $rootBuffer): Document {
            $this->confirmWithFiscalChain($deliveryNote, $rootBuffer ?? $this->glBuffer);

            /** @var Document */
            return $deliveryNote->fresh(['lines']);
        });
    }

    /**
     * Confirm a delivery note with full fiscal hash chain compliance.
     */
    private function confirmWithFiscalChain(Document $deliveryNote, InventoryGlPostingBuffer $buffer): void
    {
        // Acquire lock and get previous delivery note in chain
        // N-6 fix round r1 / fiscal gate F-1 [CRITICAL] — THE CHAIN IS A FISCAL
        // DIMENSION, SO ITS PREDECESSOR IS SELECTED ON THE FISCAL COLUMNS.
        //
        // This query used to carry `->where('status', <lifecycle>)`. A sealed
        // document that had since MOVED ON in its lifecycle — an invoice settled
        // to `Paid`, a delivery note or return note later cancelled — became
        // invisible to it, so the NEXT document of that type was treated as
        // GENESIS: `previous_hash = NULL` and `chain_sequence` restarting at 1.
        // Nothing detects that: there is no unique index on
        // (company_id, type, chain_sequence) and no chain verifier in `app/`, so
        // the fork is silent. `.claude/context/compliance.md` requires an
        // unbroken SHA-256 chain per document type.
        //
        // The bug predates this lane, but N-6 makes it DETERMINISTIC for the
        // flow it introduces: `settleIfFullyPrepaid()` moves a fully-prepaid
        // invoice to `Paid` INSIDE the sealing transaction, so such an invoice is
        // never chain-visible for a single instant under the old predicate.
        //
        // `whereNotNull('fiscal_hash')` is the correct and sufficient predicate:
        // a hash exists if and only if the document was sealed into this chain,
        // and a VOIDED (cancelled) document keeps its hash on purpose — the link
        // must stay in the chain, which is exactly why no status filter belongs
        // here.
        $previousDoc = Document::where('company_id', $deliveryNote->company_id)
            ->where('type', DocumentType::DeliveryNote)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();

        // For genesis document, use company's unique seed
        $previousHash = $previousDoc?->fiscal_hash;
        $genesisSeed = $previousHash === null ? $this->getCompanyGenesisSeed($deliveryNote) : null;

        $chainSequence = ($previousDoc !== null ? $previousDoc->chain_sequence : 0) + 1;
        $confirmedAt = now();

        // R-2 / LEDGER D-T9-1 — FISCAL INVARIANT. A delivery note authored in the
        // editor (auto-save accepts `delivery_note`) is a draft with NO
        // `document_number`, and this method hashes that column into the chain
        // input below. Allocate BEFORE the hash is serialized: a NULL in a sealed
        // hash input is unrecoverable — the immutability trigger refuses to
        // rewrite the number afterwards, so the seal could never be recomputed.
        // Same allocator as every other confirm (`DocumentStatusService`), inside
        // the `confirm()` transaction, so a failure below returns the number.
        $this->documentStatusService->assignNumberIfMissing($deliveryNote);

        // Release stock reservations if this DN is linked to a sales order
        $this->releaseSourceReservations($deliveryNote);

        // Issue stock for all product lines
        $this->issueStock($deliveryNote, $buffer);

        // Set quantity_delivered on each line to match quantity (full delivery)
        foreach ($deliveryNote->lines as $line) {
            $line->update([
                'quantity_delivered' => $line->quantity,
            ]);
        }

        // Calculate and snapshot taxes BEFORE sealing. The sealed fiscal hash
        // must cover the final, tax-adjusted total — and PostgreSQL's
        // immutability trigger rejects any tax_amount/total change once a
        // document is SEALED, so the totals must be written while the document
        // is still a draft.
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($deliveryNote);
        $deliveryNote->update([
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);
        $this->taxCalculationService->snapshotTaxDetails($deliveryNote, $taxResult);

        // Calculate fiscal hash over the finalized total using the compliance service
        $input = $this->hashService->serializeForHashing([
            // R-2 FISCAL INVARIANT: `assignNumberIfMissing()` ran above, so the number
            // exists by the time the hash input is serialized. `requireDocumentNumber()`
            // is the check that keeps a NULL out of a SEALED hash — which no later write
            // could repair, the immutability trigger refusing to rewrite the number.
            'document_number' => $deliveryNote->requireDocumentNumber(),
            'posted_at' => $confirmedAt->toDateString(), // Use 'posted_at' for consistency with serializer
            'total' => $deliveryNote->total ?? '0.00',
            'currency' => $deliveryNote->currency,
        ]);

        $fiscalHash = $this->hashService->calculateHash($input, $previousHash, $genesisSeed);

        // Update delivery note with fiscal chain data and seal it
        $deliveryNote->update([
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'chain_sequence' => $chainSequence,
            'confirmed_at' => $confirmedAt,
            'confirmed_by' => auth()->id(),
        ]);

        // Dispatch the fiscal event for audit log
        $this->dispatchConfirmedEvent($deliveryNote, $confirmedAt->toIso8601String());

        // Terminal GL phase. Nested callers defer to their registered root
        // frame; standalone confirms propagate a posting failure so stock,
        // seal and chain sequence roll back together.
        $buffer->flushIfOutermost();
    }

    /**
     * Release stock reservations for source sales order.
     *
     * When a delivery note is created from a sales order and confirmed,
     * we need to release the stock reservations that were created when
     * the sales order was confirmed.
     */
    private function releaseSourceReservations(Document $deliveryNote): void
    {
        // Check if this delivery note has a source document (sales order)
        if ($deliveryNote->source_document_id === null) {
            return; // No source document, nothing to release
        }

        // Load the source document to verify it's a sales order.
        // api.document.021: scope by delivery note's tenant + company so a
        // corrupted source_document_id pointing across tenants surfaces as
        // null (no foreign reservations released).
        $sourceDoc = Document::query()
            ->where('tenant_id', $deliveryNote->tenant_id)
            ->where('company_id', $deliveryNote->company_id)
            ->find($deliveryNote->source_document_id);
        if ($sourceDoc === null || $sourceDoc->type !== DocumentType::SalesOrder) {
            return; // Source is not a sales order
        }

        // Release all active reservations for this sales order, scoped to the
        // delivery note's own tenant + company (api.inventory.033).
        $this->stockReservationService->releaseBySource(
            sourceType: ReservationSource::SalesOrder,
            sourceId: $sourceDoc->id,
            reason: ReleaseReason::Delivered,
            releasedBy: (string) auth()->id(),
            expectedTenantId: $deliveryNote->tenant_id,
            expectedCompanyId: $deliveryNote->company_id,
        );
    }

    /**
     * Dispatch the DeliveryNoteConfirmed event for fiscal audit trail.
     */
    private function dispatchConfirmedEvent(Document $deliveryNote, string $confirmedAt): void
    {
        event(new DeliveryNoteConfirmed(
            deliveryNoteId: $deliveryNote->id,
            tenantId: $deliveryNote->tenant_id,
            companyId: $deliveryNote->company_id,
            // R-2 / LEDGER D-T9-1: this event describes a CONFIRMED document, and the
            // number is allocated on that very transition — so it exists, and
            // `requireDocumentNumber()` says so instead of letting a NULL into an
            // immutable event payload.
            documentNumber: $deliveryNote->requireDocumentNumber(),
            partnerId: $deliveryNote->partner_id,
            total: $deliveryNote->total ?? '0.00',
            currency: $deliveryNote->currency,
            fiscalHash: $deliveryNote->fiscal_hash ?? '',
            chainSequence: $deliveryNote->chain_sequence ?? 0,
            confirmedAt: $confirmedAt,
        ));
    }

    /**
     * Get the company's unique genesis seed for hash chain initialization.
     *
     * @throws \RuntimeException If company has no genesis seed
     */
    private function getCompanyGenesisSeed(Document $deliveryNote): string
    {
        /** @var Company $company */
        $company = $deliveryNote->company;

        if ($company->fiscal_chain_seed === null) {
            throw new \RuntimeException(
                'Company is missing fiscal_chain_seed. Run migration to generate seeds.'
            );
        }

        return $company->fiscal_chain_seed;
    }

    /**
     * Issue stock for all product lines in the delivery note.
     *
     * This actually decreases stock levels by calling the WAC service
     * to record sales and update inventory quantities.
     */
    private function issueStock(Document $deliveryNote, InventoryGlPostingBuffer $buffer): void
    {
        foreach ($deliveryNote->lines as $line) {
            // D-19 / T4: ONE physical predicate. The former guard read the
            // PHANTOM `is_service` (§0.15) and therefore issued stock for
            // non-physical PRODUCT lines.
            $product = PhysicalLinePredicate::physicalProductFor($line);

            if ($product === null) {
                continue; // Skip services and non-physical products
            }

            $location = $line->location ?? $deliveryNote->location;

            if ($location === null) {
                continue; // Skip if no location available
            }

            // Record stock sale with audit trail
            $movement = $this->wacService->recordSale(
                product: $product,
                location: $location,
                quantity: CurrencyScale::bcformatStrict((string) $line->quantity, self::QUANTITY_SCALE),
                reference: $deliveryNote->document_number,
                referenceType: StockMovementReferenceType::Document,
                referenceId: $deliveryNote->id
            );

            // Deduct batch-level stock. Two shapes:
            //
            //  - the line NAMES a lot          -> issue from exactly that lot;
            //  - the line names none, and the
            //    product is batch-tracked      -> consume FEFO lots.
            //
            // 🚨 Campaign wave 4, W4-5 — the second arm did not exist. An outbound
            // delivery moved `stock_levels` and left the lot ledger untouched, so
            // lot stock only ever GREW: wave 4 measured `stock_levels` 53 against a
            // lot ledger of 115 on one product after a day's trading. In a vertical
            // that forces batch tracking on every product that is fatal — dated lots
            // never deplete so FEFO ranks stale lots forever, the DEFAULT lot stops
            // backing genuinely untracked stock (the remainder clamps at 0), and
            // there is no which-lot-went-to-which-customer trail for a recall.
            //
            // `consumeBatchesAtomically()` locks each candidate row
            // (`FOR UPDATE … SKIP LOCKED`), draws in expiry order and writes one
            // `inventory_batch_movements` leg per lot keyed to the issue movement
            // above — the same document-per-action shape the POS channel uses
            // (`POS\Application\Services\ReceiptCreationService::allocateBatches()`).
            //
            // STRICT fulfilment, deliberately, and for the same reason the POS path
            // is strict (its FU-1 policy note): batch tracking exists for lot
            // traceability, so a delivery that cannot be drawn from real lots is
            // REFUSED rather than committed with a silent lot shortfall. The
            // aggregate `pos_stock_policy` relaxation never applied to lots.
            //
            // WHAT "cannot be drawn from real lots" MEANS — three shapes, all now
            // refusals, all pinned in `BatchTrackedSalesOrderConfirmFefoTest`
            // (gate r3 R3-4). `consumeBatchesAtomically()`'s candidate predicate is
            // narrower than "the tuple has lot stock":
            //
            //   * EXPIRED lots are invisible. The predicate is
            //     `expiry_date IS NULL OR expiry_date >= today` (W4-1): a lot that
            //     records NO expiry is NOT expired — nobody claimed it would go off
            //     on any particular day — so it stays deliverable and simply ranks
            //     LAST in FEFO. Excluding it would have made the entire opening
            //     catalogue of a batch-tracked tenant undeliverable on day one.
            //     Stock sitting entirely in a PASSED-expiry lot is UNDELIVERABLE.
            //     That is the policy this vertical wants — a parapharmacy must not
            //     ship expired goods, and shipping them silently is the worse
            //     failure — but it is stated here rather than left to a WHERE clause. Disposing of
            //     expired stock is a write-off document, not a delivery.
            //   * NO lots at all on a batch-tracked tuple is a refusal.
            //     `GoodsReceiptService` forces batch data on the common inbound path,
            //     so this is the shape left by a path that never minted a lot.
            //   * RESERVED lots are skipped: `available_quantity` is the GENERATED
            //     `quantity − reserved_quantity`, so a fully-held earliest lot is
            //     passed over and a LATER lot ships. FEFO here means "earliest
            //     expiry among lots that are actually free", not "earliest expiry".
            //
            // The refusal is ATOMIC: `recordSale()` has already moved the aggregate
            // for this line (and for every earlier line) when the draw fails, so the
            // exception must roll the whole confirm back. Pinned by the two
            // atomicity tests, single- and multi-line.
            if ($line->batch_id !== null) {
                $this->batchStockService->issueBatchStock(
                    tenantId: $deliveryNote->tenant_id,
                    batchId: (int) $line->batch_id,
                    locationId: (string) $location->id,
                    quantity: (string) $line->quantity,
                    movementId: $movement->id,
                );
            } elseif ($product->requires_batch_tracking) {
                $this->fefoService->consumeBatchesAtomically(
                    tenantId: $deliveryNote->tenant_id,
                    productId: (string) $product->id,
                    locationId: (string) $location->id,
                    quantity: CurrencyScale::bcformatStrict((string) $line->quantity, self::QUANTITY_SCALE),
                    movementId: $movement->id,
                    variantId: $line->variant_id,
                );
            }

            $occurredAt = $movement->occurred_at ?? $movement->created_at ?? now();
            $buffer->enqueue(new MovementGlContext(
                kind: MovementGlKind::Exit,
                movementId: $movement->id,
                companyId: $movement->company_id,
                currencyCode: (string) $deliveryNote->currency,
                reason: $movement->reason ?? throw new \LogicException('Delivery movement is missing its GL reason.'),
                quantityBefore: (string) $movement->quantity_before,
                quantityAfter: (string) $movement->quantity_after,
                unitCost: (string) ($movement->unit_cost ?? '0'),
                sourceType: $movement->reference_type,
                sourceId: $movement->reference_id,
                occurredAt: \DateTimeImmutable::createFromInterface($occurredAt),
                entryDate: new \DateTimeImmutable('now'),
                postedByUserId: auth()->id() !== null ? (string) auth()->id() : null,
                isHistorical: (bool) $movement->is_historical,
            ));
        }
    }
}
