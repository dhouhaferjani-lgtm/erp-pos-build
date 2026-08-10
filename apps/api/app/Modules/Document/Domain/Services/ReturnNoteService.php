<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteData;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteLineData;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\ReturnNoteConfirmed;
use App\Modules\Document\Domain\Exceptions\ReturnQuantityExceededException;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Taxation\PeriodBackdatingGuardInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for return note operations with fiscal hash chain compliance.
 *
 * Key differences from DeliveryNoteService:
 * - RN is hashed on CONFIRM (when stock returns), not on POST
 * - RN receives stock back (opposite of DN which issues stock)
 * - RN has its own separate hash chain per company
 * - Required for fiscal compliance (tamper-proof return documents)
 * - RN can be linked to credit notes but is independent
 *
 * Stock Returns:
 * - When RN is confirmed, receives stock back using WeightedAverageCostService
 * - Stock is received at original cost (from source document if available)
 * - WAC is recalculated based on returned goods
 *
 * Hash Chain Format: SHA256(previous_hash | document_number | date | total | currency)
 */
final class ReturnNoteService
{
    /**
     * Quantities are compared at the canonical quantity scale throughout.
     */
    private const QUANTITY_SCALE = 4;

    /**
     * The internal at-rest cost precision of the WAC ledger — the same constant
     * `WeightedAverageCostService::COST_SCALE` / `StockAdjustmentService::COST_SCALE`
     * carry, and the scale of `stock_movements.unit_cost` (decimal(19,6)).
     * Resolver-independent by construction: the cost columns never depend on a
     * bound company's currency scale.
     */
    private const COST_SCALE = 6;

    public function __construct(
        private readonly WeightedAverageCostService $wacService,
        private readonly FiscalHashService $hashService,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly ProductCostLock $costLock,
        private readonly DocumentNumberingService $numberingService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly PeriodBackdatingGuardInterface $periodBackdatingGuard,
        private readonly DeliveredQuantityResolver $deliveredQuantityResolver,
    ) {}

    /**
     * Create a DRAFT return note.
     *
     * Plan CF T2 / CF-D1. This body used to live in
     * `ReturnNoteController::store()`, which meant the guided cancel flow could
     * only reach it by re-implementing it — the "side-channel writer" the owner
     * ruling forbids. It lives here so the manual `POST /return-notes` route and
     * the composite `POST /invoices/{id}/cancel` share one create path and cannot
     * drift.
     *
     * ASSUMES AN OPEN TRANSACTION. The caller owns the transaction boundary
     * because the composite needs the create, the confirm, the stock movement and
     * the invoice void to be ONE atomic act (CF-D4). Number generation runs in a
     * nested transaction (savepoint) via `generateForKeyOnce()`, so the
     * `document_sequences` row lock is held to the OUTER commit — deliberate: a
     * gap in the fiscal numbering sequence is worse than the contention.
     *
     * SCALE comes from the entity currency, never from a bare `getScale()`
     * (rule 19 context-safety): the composite runs from a controller today but the
     * service must stay usable from a console or queued context where no
     * CompanyContext is bound.
     *
     * @throws ReturnQuantityExceededException When a line exceeds what the source
     *                                         document still has available to return.
     */
    public function createDraft(CreateReturnNoteData $data, Company $company): Document
    {
        $currency = $data->currency ?? $company->currency;
        $scale = $this->scaleResolver->getScale($currency);

        // When linked to a source document, a customer cannot return more than was
        // invoiced (net of earlier returns). Race-safe: the source row is locked
        // FOR UPDATE for the rest of the caller's transaction.
        if ($data->sourceDocumentId !== null) {
            $this->assertWithinReturnableQuantities(
                $data->sourceDocumentId,
                $data->lines,
                $company->id,
            );
        }

        $documentNumber = $this->numberingService->generateNumber(
            $company->tenant_id,
            $company->id,
            DocumentType::ReturnNote,
        );

        $payload = [];
        if ($data->returnReason !== null) {
            $payload['return_reason'] = $data->returnReason;
        }
        if ($data->returnCondition !== null) {
            $payload['return_condition'] = $data->returnCondition;
        }

        $returnNote = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => $documentNumber,
            'document_date' => $data->documentDate,
            'partner_id' => $data->partnerId,
            'source_document_id' => $data->sourceDocumentId,
            'location_id' => $data->locationId,
            'currency' => $currency,
            'notes' => $data->notes,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'payload' => $payload === [] ? null : $payload,
        ]);

        $this->createDraftLines($returnNote, $data->lines, $company, $scale);

        /** @var Document */
        return $returnNote->refresh()->load(['lines']);
    }

    /**
     * @param  list<CreateReturnNoteLineData>  $lines
     */
    private function createDraftLines(Document $returnNote, array $lines, Company $company, int $scale): void
    {
        if ($lines === []) {
            return;
        }

        // Batch-fetch products for the designation snapshot (1 query).
        $productIds = array_values(array_filter(array_map(
            static fn (CreateReturnNoteLineData $line): ?string => $line->productId,
            $lines,
        )));
        $products = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $index => $line) {
            // The canonical NET line total: gross minus the line discount, floored
            // at zero. `DocumentLine::computeLineTotal()` is the single source of
            // truth for that arithmetic (`DocumentLine.php:283-303`) and it honours
            // `discount_percent`'s precedence over a flat `discount_amount` —
            // load-bearing for CF-D11's tuple split, where the flat amount is
            // PRORATED across the split lines.
            $lineTotal = DocumentLine::computeLineTotal(
                $line->quantity,
                $line->unitPrice,
                $line->discountPercent,
                $line->discountAmount,
                $scale,
            );

            // The rate FRACTION is not a monetary value, so it does not take the
            // currency scale: `tax_rate` is validated at 2 decimal places
            // (`CreateDocumentRequest.php:141`) and 4 is the precision the house
            // uses for every percent→fraction conversion
            // (`TaxCalculationService.php:140`, and the pre-T2 controller body this
            // was extracted from). The MONEY truncation is the surrounding bcmul at
            // the resolved $scale.
            $rateFraction = bcdiv($line->taxRate ?? '0.00', '100', 4); // precision-ok: percent→fraction, not money
            $lineTax = bcmul($lineTotal, $rateFraction, $scale);

            $product = $line->productId !== null ? $products->get($line->productId) : null;
            $defaultName = $product !== null ? (string) $product->name : '';

            DocumentLine::create([
                'document_id' => $returnNote->id,
                'product_id' => $line->productId,
                'location_id' => $line->locationId,
                'line_number' => $index + 1,
                'description' => $line->description,
                'designation_default_snapshot' => $defaultName !== '' ? mb_substr($defaultName, 0, 500) : null,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'discount_percent' => $line->discountPercent,
                'discount_amount' => $line->discountAmount,
                'tax_rate' => $line->taxRate ?? '0.00',
                'line_total' => $lineTotal,
                'notes' => $line->notes,
            ]);

            $subtotal = bcadd($subtotal, $lineTotal, $scale);
            $taxAmount = bcadd($taxAmount, $lineTax, $scale);
        }

        $returnNote->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => bcadd($subtotal, $taxAmount, $scale),
        ]);
    }

    /**
     * Reject a return that would exceed the source document's quantity for any
     * product, accounting for quantities already returned on prior return notes.
     *
     * Moved here from `ReturnNoteController::assertWithinReturnableQuantities()` by
     * plan CF T2 so the composite cancel flow is capped by the same code as the
     * standalone route. The refusal is now a typed domain exception
     * ({@see ReturnQuantityExceededException}) rather than a Presentation
     * `HttpResponseException` — same code, same `details`, rendered in
     * `bootstrap/app.php`.
     *
     * The source document row is locked FOR UPDATE so two concurrent returns
     * against the same invoice serialise and cannot jointly over-return. Netting
     * spans every return note on the source whose status is not Cancelled, so even
     * a DRAFT return note left behind by a `will_return` decision already counts
     * against the cap.
     *
     * ── AND OVER THE SOURCE'S DELIVERY NOTES (gate CF round 1, Critical 2) ── When the
     * source is an invoice, prior returns are netted over the invoice AND over the
     * confirmed delivery notes backing it. A return note raised against the DELIVERY
     * NOTE — the second entry point, live again as of T8 — would otherwise be invisible
     * here, so the same units could be returned twice: once against the delivery note
     * and once against the invoice, with the cap agreeing both times.
     *
     * @param  list<CreateReturnNoteLineData>  $requestLines
     *
     * @throws ReturnQuantityExceededException
     */
    public function assertWithinReturnableQuantities(string $sourceDocumentId, array $requestLines, string $companyId): void
    {
        /** @var Document|null $source */
        $source = Document::query()
            ->where('company_id', $companyId)
            ->with('lines')
            ->lockForUpdate()
            ->find($sourceDocumentId);

        // No resolvable source → nothing to cap against (other validation owns the
        // existence contract); leave the create path unchanged.
        if ($source === null) {
            return;
        }

        $qtyScale = self::QUANTITY_SCALE;

        /** @var array<string, numeric-string> $invoiced */
        $invoiced = [];
        foreach ($source->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }
            $invoiced[$line->product_id] = bcadd($invoiced[$line->product_id] ?? '0', (string) $line->quantity, $qtyScale);
        }

        // The netting set is the source document AND everything that shares its physical
        // movement, in BOTH directions. Round 1 covered invoice → delivery notes; round 2
        // adds the mirror, because covering one direction only refuses the double return
        // in one order of operations and permits it in the other.
        $sourceIds = [$sourceDocumentId];
        $backingInvoiceIds = [];

        if ($source->type === DocumentType::Invoice) {
            $sourceIds = array_merge(
                $sourceIds,
                $this->deliveredQuantityResolver->confirmedDeliveryNoteIdsFor($source),
            );
        } elseif ($source->type === DocumentType::DeliveryNote) {
            $backingInvoiceIds = $this->deliveredQuantityResolver->invoiceIdsBackedByDeliveryNote($source);
            $sourceIds = array_merge($sourceIds, $backingInvoiceIds);
        }
        $sourceIds = array_values(array_unique($sourceIds));

        // ── THE DENOMINATOR MUST SPAN THE SAME UNION AS THE RETURNS (round 3, NB-1) ──
        //
        // Round 2 widened the RETURNS set for a delivery-note source without widening the
        // INVOICED set, which is still this note's own lines. The two sides of
        // `remaining = invoiced − alreadyReturned` then span DIFFERENT document sets, and
        // legitimate returns of never-returned units were refused:
        //
        //  - CONSOLIDATED INVOICE over two delivery notes (a first-class flow —
        //    `DeliveryNoteToInvoiceConverter` exists to build it): DN1 = 5, DN2 = 5,
        //    invoice = 10. Return 5 through the invoice, and DN2's own five are refused
        //    with "invoiced 5, already returned 5" — units that belonged to DN1.
        //  - SALES-ORDER FAN-OUT: shape (b) of `invoiceIdsBackedByDeliveryNote()` matches
        //    every invoice sharing the note's `source_document_id`, so a return against an
        //    unrelated SIBLING invoice blocked this note's units.
        //
        // Narrowing the traversal is not the fix and may be unachievable: the SO shape
        // keeps its delivery-note list at the ORDER level, so an order-mate invoice is
        // indistinguishable from a backing one by traversal alone. Making the DENOMINATOR
        // symmetric is what makes it correct — take, per product, the greater of this
        // note's own quantity and the total invoiced across the backing invoices. The
        // invoice is the superset in both shapes, and `invoiced` is what the refusal code
        // (`CODE_INVOICED`) literally means.
        //
        // The mirror refusal this widening exists for is untouched: one DN of 5 backed by
        // one invoice of 5 still nets 5 − 5 = 0.
        // The own-surface denominator, snapshotted BEFORE the widening below. This is the
        // constraint the round-2 shape provided implicitly and the round-3 widening
        // removed — see the `min` at the bottom of this method.
        /** @var array<string, numeric-string> $ownInvoiced */
        $ownInvoiced = $invoiced;

        if ($backingInvoiceIds !== []) {
            $backingLines = DocumentLine::query()
                ->whereHas('document', static function (Builder $query) use ($backingInvoiceIds, $companyId): void {
                    /** @var Builder<Document> $query */
                    $query->where('company_id', $companyId)
                        ->whereIn('id', $backingInvoiceIds);
                })
                ->get();

            /** @var array<string, numeric-string> $backingInvoiced */
            $backingInvoiced = [];
            foreach ($backingLines as $line) {
                if ($line->product_id === null) {
                    continue;
                }
                $backingInvoiced[$line->product_id] = bcadd(
                    $backingInvoiced[$line->product_id] ?? '0',
                    (string) $line->quantity,
                    $qtyScale,
                );
            }

            foreach ($backingInvoiced as $productId => $quantity) {
                $own = $invoiced[$productId] ?? '0';
                $invoiced[$productId] = bccomp($quantity, $own, $qtyScale) > 0 ? $quantity : $own;
            }
        }

        /** @var array<string, numeric-string> $alreadyReturned across the whole union */
        $alreadyReturned = [];
        /** @var array<string, numeric-string> $ownReturned against THIS source document only */
        $ownReturned = [];

        $priorReturnLines = DocumentLine::query()
            ->whereHas('document', static function (Builder $query) use ($sourceIds, $companyId): void {
                /** @var Builder<Document> $query */
                $query->where('company_id', $companyId)
                    ->where('type', DocumentType::ReturnNote)
                    ->whereIn('source_document_id', $sourceIds)
                    ->where('status', '!=', DocumentStatus::Cancelled->value);
            })
            // Eager-loaded so attributing each line to its own source document is one
            // query, not one per line.
            ->with('document')
            ->get();

        foreach ($priorReturnLines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $quantity = (string) $line->quantity;
            $alreadyReturned[$line->product_id] = bcadd($alreadyReturned[$line->product_id] ?? '0', $quantity, $qtyScale);

            if ($line->document->source_document_id === $sourceDocumentId) {
                $ownReturned[$line->product_id] = bcadd($ownReturned[$line->product_id] ?? '0', $quantity, $qtyScale);
            }
        }

        /** @var array<string, numeric-string> $requested */
        $requested = [];
        foreach ($requestLines as $line) {
            if ($line->productId === null) {
                continue;
            }
            $requested[$line->productId] = bcadd($requested[$line->productId] ?? '0', $line->quantity, $qtyScale);
        }

        foreach ($requested as $productId => $qty) {
            // ── TWO CAPS, TAKE THE SMALLER (gate CF round 4) ──
            //
            // Round 3 widened the denominator to `max(own, Σ backing invoiced)` to stop
            // two false refusals (NB-1). It stopped them — and opened a strictly worse
            // hole in the opposite direction, because the netting set for a delivery-note
            // source still sees only that note's own returns plus the invoice's. With
            // DN1 = 5, DN2 = 5 and a consolidated invoice of 10, DN1's denominator became
            // 10 while only DN1's own five had been netted, so DN1 could be returned five
            // and then FIVE AGAIN: ten units restocked from a note that issued five, on
            // two SEALED return notes. Refused at the lane base and at round 2, accepted
            // at round 3 — fail-OPEN, which is the worse trade, since NB-1 was
            // fail-closed.
            //
            //   c_own   = this document's own quantity − returns raised against IT
            //   c_union = max(own, Σ backing invoiced)  − returns across the whole union
            //   remaining = min(c_own, c_union)
            //
            // `c_union` alone permits the over-return above; `c_own` alone is what
            // produced NB-1's two false refusals. Both constraints are real and neither
            // implies the other, so the bound is their minimum — the widening still lets a
            // consolidated invoice's OTHER delivery note be returned, while no single
            // surface can ever give back more than it moved.
            /** @var numeric-string $ownCap */
            $ownCap = bcsub($ownInvoiced[$productId] ?? '0', $ownReturned[$productId] ?? '0', $qtyScale);
            /** @var numeric-string $unionCap */
            $unionCap = bcsub($invoiced[$productId] ?? '0', $alreadyReturned[$productId] ?? '0', $qtyScale);

            /** @var numeric-string $remaining */
            $remaining = bccomp($ownCap, $unionCap, $qtyScale) < 0 ? $ownCap : $unionCap;

            if (bccomp($qty, $remaining, $qtyScale) > 0) {
                throw ReturnQuantityExceededException::exceedsInvoiced(
                    $productId,
                    $qty,
                    $remaining,
                    $invoiced[$productId] ?? '0',
                    $alreadyReturned[$productId] ?? '0',
                );
            }
        }
    }

    /**
     * The line products whose cost locks a confirm has to hold, sorted-safe.
     *
     * Plan CF T4(e). Public because the composite cancel flow acquires the cost
     * lock for the UNION of its own set and the return note's in ONE sorted call
     * (CF-D4 step 6) — re-deriving that set at the call site is how the two would
     * drift and reintroduce the AB-BA deadlock the up-front acquire exists to
     * prevent.
     *
     * @return list<string>
     */
    public function lineProductIds(Document $returnNote): array
    {
        /** @var list<string> $productIds */
        $productIds = $returnNote->lines
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $productIds;
    }

    /**
     * Confirm a return note, adding it to the fiscal hash chain.
     *
     * This is the key moment when:
     * - Stock is returned (inbound from customer)
     * - The RN becomes fiscally sealed (tamper-proof)
     * - The RN is added to the company's RN hash chain
     * - WAC is updated based on returned goods
     *
     * Plan CF T4(b): this method is now TRANSACTION AND COST-LOCK SCAFFOLDING ONLY.
     * Everything that decides anything lives in {@see confirmWithin()}, so the
     * composite cancel flow — which owns its own outer transaction and its own
     * union cost-lock (CF-D4) — runs the SAME domain transitions rather than a
     * side channel around them (the owner ruling's explicit constraint).
     *
     * @throws \DomainException If return note cannot be confirmed
     * @throws ReturnPeriodLockedException If `document_date` falls in a locked period
     */
    public function confirm(Document $returnNote, ?string $actorId = null): Document
    {
        // Multi-product deadlock defense: receiveStockBack() loops the return
        // lines and calls wacService->recordReturn() per physical line, and
        // recordReturn() acquires that product's advisory lock. Inside this one
        // outer transaction those nested per-line acquires accumulate in line
        // order (unsorted), so two concurrent confirms over overlapping products
        // in different line orders AB-BA deadlock. Acquire ALL the line product
        // advisory locks UP-FRONT in ONE sorted call (ProductCostLock sorts
        // internally); the nested per-line acquires are then re-entrant on the
        // already-held xact locks. Mirrors StockTransferService::complete and
        // GoodsReceiptService::receiveGoods.
        $productIds = $this->lineProductIds($returnNote);

        return DB::transaction(function () use ($returnNote, $productIds, $actorId): Document {
            return $this->costLock->acquire($returnNote->tenant_id, $returnNote->company_id, $productIds, function () use ($returnNote, $actorId): Document {
                return $this->confirmWithin($returnNote, $actorId);
            });
        });
    }

    /**
     * Confirm a return note INSIDE a transaction and cost lock the caller already
     * holds.
     *
     * Plan CF T4(b) / fiscal gate I-6. The type and status assertions live HERE, not
     * in {@see confirm()}: if they stayed in the wrapper, this method would be
     * exactly the "side-channel writer" the owner ruling forbids — a way for the
     * composite to seal a non-draft or non-return-note document with no domain
     * check at all.
     *
     * PRECONDITIONS THE CALLER OWNS: an open transaction, and the cost lock for at
     * least {@see lineProductIds()}.
     *
     * @throws \DomainException If return note cannot be confirmed
     * @throws ReturnPeriodLockedException If `document_date` falls in a locked period
     */
    public function confirmWithin(Document $returnNote, ?string $actorId = null): Document
    {
        if ($returnNote->type !== DocumentType::ReturnNote) {
            throw new \DomainException(
                'Only return notes can be confirmed with this service.'
            );
        }

        if (! $returnNote->isDraft()) {
            throw new \DomainException(
                'Only draft return notes can be confirmed. Current status: '.$returnNote->status->value
            );
        }

        // Plan CF T4(c) / CF-D3. The period guard runs FIRST — before
        // receiveStockBack() and before the chain-head lockForUpdate() below —
        // because a refusal raised after the chain read would hold the return-note
        // chain head for the rest of the caller's transaction, serialising every
        // other confirm behind a request that was always going to fail.
        //
        // Keyed on `document_date`, which for option 2 of the guided cancel flow IS
        // the user's `returned_on`. A refusal is recoverable: PATCH the draft's
        // `document_date` into an open period and confirm again.
        $this->periodBackdatingGuard->assertBackdatingPeriodIsOpen(
            $returnNote->company_id,
            $returnNote->document_date,
            (string) $returnNote->document_number,
        );

        $this->confirmWithFiscalChain($returnNote, $actorId);

        $returnNote->refresh();

        /** @var Document */
        return $returnNote->load(['lines']);
    }

    /**
     * Confirm a return note with full fiscal hash chain compliance.
     */
    private function confirmWithFiscalChain(Document $returnNote, ?string $actorId = null): void
    {
        // Acquire lock and get previous return note in chain
        $previousDoc = Document::where('company_id', $returnNote->company_id)
            ->where('type', DocumentType::ReturnNote)
            ->where('status', DocumentStatus::Confirmed)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();

        // For genesis document, use company's unique seed
        $previousHash = $previousDoc?->fiscal_hash;
        $genesisSeed = $previousHash === null ? $this->getCompanyGenesisSeed($returnNote) : null;

        $chainSequence = ($previousDoc !== null ? $previousDoc->chain_sequence : 0) + 1;
        $confirmedAt = now();

        // Receive stock back for each line
        $this->receiveStockBack($returnNote);

        // Calculate and snapshot taxes BEFORE sealing. The sealed fiscal hash
        // must cover the final, tax-adjusted total — and PostgreSQL's
        // immutability trigger rejects any tax_amount/total change once a
        // document is SEALED, so the totals must be written while the document
        // is still a draft.
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($returnNote);
        $returnNote->update([
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);
        $this->taxCalculationService->snapshotTaxDetails($returnNote, $taxResult);

        // Calculate fiscal hash over the finalized total using the compliance service
        $input = $this->hashService->serializeForHashing([
            'document_number' => $returnNote->document_number,
            'posted_at' => $confirmedAt->toDateString(), // Use 'posted_at' for consistency with serializer
            'total' => $returnNote->total ?? '0.00',
            'currency' => $returnNote->currency,
        ]);

        $fiscalHash = $this->hashService->calculateHash($input, $previousHash, $genesisSeed);

        // Update return note with fiscal chain data and seal it.
        //
        // Plan CF T4(a) / fiscal gate C-1. `confirmed_at` and `confirmed_by` USED TO
        // BE OMITTED here — unlike `DeliveryNoteService::confirmWithFiscalChain()`,
        // which stamps both — even though the columns exist. That destroyed the
        // hash's own date input at write time: the seal consumes
        // `$confirmedAt->toDateString()` (above) and nothing persisted it, so
        // `fiscal:verify-chains` had no way to recompute the input for a return note
        // whose `document_date` differs from its confirm day. Backdating (option 2 of
        // the guided cancel flow) makes exactly that the normal case.
        //
        // Persisting it is HASH-NEUTRAL — it is the very value the hash already
        // consumed — and TRIGGER-SAFE: `enforce_document_immutability()` takes its
        // `OLD.fiscal_status != 'SEALED'` early return, and at this update
        // `OLD.fiscal_status` is still DRAFT.
        //
        // `$actorId` rather than a bare `auth()->id()`: this path is reachable from
        // the composite cancel flow and from console/queued contexts where no guard
        // is bound. `auth()->id()` is the fallback so the standalone route keeps its
        // behaviour.
        $returnNote->update([
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'chain_sequence' => $chainSequence,
            'confirmed_at' => $confirmedAt,
            'confirmed_by' => $actorId ?? auth()->id(),
        ]);

        // Dispatch the fiscal event for audit log
        $this->dispatchConfirmedEvent($returnNote, $confirmedAt->toIso8601String());
    }

    /**
     * Receive stock back from customer.
     *
     * When a return note is confirmed, we need to record stock receipt
     * for all lines with physical products.
     */
    private function receiveStockBack(Document $returnNote): void
    {
        foreach ($returnNote->lines as $line) {
            // D-19 / T4: ONE physical predicate (was the phantom `is_service`).
            $product = PhysicalLinePredicate::physicalProductFor($line);

            if ($product === null) {
                continue; // Skip services and non-physical products
            }

            // Skip lines with zero or negative quantity
            if (bccomp((string) $line->quantity, '0', 4) <= 0) {
                continue;
            }

            // Determine effective location using the fallback chain:
            // 1. Line's explicit location_id
            // 2. Document's location_id
            // 3. Null (error - location required for stock receipt)
            $locationId = $line->getEffectiveLocationId();

            if ($locationId === null) {
                throw new \DomainException(
                    "Cannot receive stock: no location specified for line {$line->id}. ".
                    'Please set either line.location_id or document.location_id.'
                );
            }

            // api.document.020: scope Location lookup by return note's company.
            // Locations table is company-scoped (no tenant_id column).
            $location = Location::query()
                ->where('company_id', $returnNote->company_id)
                ->findOrFail($locationId);

            // Get original cost (from source document if available, otherwise use current cost)
            $originalCost = $this->getOriginalCost($line);

            // Receive stock back using WAC service with audit trail
            $this->wacService->recordReturn(
                product: $product,
                location: $location,
                quantity: CurrencyScale::bcformatStrict((string) $line->quantity, self::QUANTITY_SCALE),
                originalCost: $originalCost,
                reference: $returnNote->document_number,
                referenceType: StockMovementReferenceType::Document,
                referenceId: $returnNote->id
            );
        }
    }

    /**
     * Get the original cost for a returned line.
     *
     * If return note references a source document (invoice/delivery note),
     * use the cost from that document. Otherwise, use current product cost.
     *
     * @return numeric-string the unit cost at COST_SCALE — never a float
     */
    private function getOriginalCost(DocumentLine $line): string
    {
        // If return note references source document, get cost from there.
        //
        // NOTE (DPA Wave 3 §0b.3): on production data this branch is dead —
        // every writer of `document_lines.landed_unit_cost` is purchase-side, so
        // a customer return's source (a sales invoice or DN) never carries it and
        // the fallback always wins. Replacing the basis is D-24/T15a's job, NOT
        // T3's; T3 only stops the value being laundered through a float.
        if ($line->document->source_document_id !== null) {
            $sourceLine = DocumentLine::where('document_id', $line->document->source_document_id)
                ->where('product_id', $line->product_id)
                ->first();

            if ($sourceLine !== null && $sourceLine->landed_unit_cost !== null) {
                return CurrencyScale::bcformatStrict((string) $sourceLine->landed_unit_cost, self::COST_SCALE);
            }
        }

        // Fallback: use current product cost
        return CurrencyScale::bcformatStrict((string) ($line->product->cost_price ?? '0'), self::COST_SCALE);
    }

    /**
     * Dispatch the ReturnNoteConfirmed event for fiscal audit trail.
     */
    private function dispatchConfirmedEvent(Document $returnNote, string $confirmedAt): void
    {
        event(new ReturnNoteConfirmed(
            returnNoteId: $returnNote->id,
            tenantId: $returnNote->tenant_id,
            companyId: $returnNote->company_id,
            documentNumber: $returnNote->document_number,
            partnerId: $returnNote->partner_id,
            total: $returnNote->total ?? '0.00',
            currency: $returnNote->currency,
            fiscalHash: $returnNote->fiscal_hash ?? '',
            chainSequence: $returnNote->chain_sequence ?? 0,
            confirmedAt: $confirmedAt,
        ));
    }

    /**
     * Get the company's unique genesis seed for hash chain initialization.
     *
     * @throws \RuntimeException If company has no genesis seed
     */
    private function getCompanyGenesisSeed(Document $returnNote): string
    {
        /** @var Company $company */
        $company = $returnNote->company;

        if ($company->fiscal_chain_seed === null) {
            throw new \RuntimeException(
                'Company is missing fiscal_chain_seed. Run migration to generate seeds.'
            );
        }

        return $company->fiscal_chain_seed;
    }
}
