<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteData;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteLineData;
use App\Modules\Document\Domain\DTOs\DeliveredQuantityTuple;
use App\Modules\Document\Domain\DTOs\ReturnDecisionData;
use App\Modules\Document\Domain\Enums\CancelBlockReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionConflictException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionForbiddenException;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionMismatchesGoodsException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationAmbiguousException;
use App\Modules\Document\Domain\Exceptions\ReturnLocationUnresolvedException;
use App\Modules\Document\Domain\Exceptions\ReturnNothingDeliveredException;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Shared\Contracts\AbilityAuthorizerInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    /**
     * Quantities compare at the canonical quantity scale throughout.
     */
    private const QUANTITY_SCALE = 4;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DocumentPostingService $documentPostingService,
        private readonly DocumentPeriodLockInterface $periodLock,
        private readonly ReturnNoteService $returnNoteService,
        private readonly DeliveredQuantityResolver $deliveredQuantityResolver,
        private readonly ProductCostLock $costLock,
        private readonly AbilityAuthorizerInterface $authorizer,
    ) {}

    /**
     * Cancel an invoice, optionally recording an explicit decision about the goods.
     *
     * Plan CF CF-D1. The guided cancel flow is ONE composite server call, not three
     * front-end calls: a crash between a cancel, a return-note create and a
     * return-note confirm would leave a cancelled invoice with no return note and no
     * record of what the user chose — the silent outcome the owner ruling forbids,
     * arrived at by accident.
     *
     * `$decision === null` KEEPS TODAY'S BEHAVIOUR EXACTLY. That is this lane's
     * regression contract: `DocumentCancelConsolidationTest` and
     * `CancelRefusedOnNonOpenVatPeriodTest` stay green unmodified, and every existing
     * caller is untouched.
     *
     * @throws ReturnDecisionConflictException Re-thrown after the rejected decision is
     *                                         appended (CF-D5's commit-then-refuse).
     */
    public function cancelInvoice(
        Document $invoice,
        string $reason,
        ?string $actorId = null,
        ?ReturnDecisionData $decision = null,
    ): Document {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Document must be an invoice');
        }

        if ($decision === null) {
            return $this->cancelInvoiceWithoutDecision($invoice, $reason, $actorId);
        }

        try {
            return DB::transaction(fn (): Document => $this->cancelInvoiceWithDecision(
                $invoice,
                $reason,
                $actorId,
                $decision,
            ));
        } catch (ReturnDecisionConflictException $conflict) {
            // CF-D5 COMMIT-THEN-REFUSE (fiscal gate N2-I1). The conflict was detected
            // INSIDE the transaction above, under the step-0 lock, so the append could
            // not have happened there: throwing rolls it back, landing in the very
            // "nothing written, both decisions visible to support" state that is
            // self-contradictory — visible to nobody.
            //
            // The rollback is correct in itself; nothing else on this path was
            // written. The audit record is re-attempted here, in its own short
            // transaction that RE-READS the payload (the rollback discarded the first
            // read, and re-reading is what stops two concurrent conflicting replays
            // from losing an append).
            //
            // `DB::afterCommit` is NOT usable — it does not fire on rollback.
            $this->appendRejectedDecision($conflict);

            // ALWAYS re-thrown, including when the append above failed. Letting a
            // plumbing exception replace this would turn a typed
            // RETURN_DECISION_ALREADY_RECORDED 422 into a 500 and leave the client
            // unable to tell "your decision conflicts" from "the server broke" — the
            // fallback-becomes-primary failure rule 20 warns about. The audit record
            // is the nice-to-have; the correct refusal is the contract.
            throw $conflict;
        }
    }

    /**
     * Today's behaviour, byte for byte. Do not "unify" this with the decision path:
     * the two differ in their transaction shape and in which branches take a lock,
     * and this one is pinned by tests that predate the lane.
     */
    private function cancelInvoiceWithoutDecision(Document $invoice, string $reason, ?string $actorId): Document
    {
        if ($invoice->status === DocumentStatus::Posted) {
            return $this->documentPostingService->cancel($invoice, $reason, $actorId);
        }

        if ($invoice->status === DocumentStatus::Paid) {
            throw new \DomainException('DOCUMENT_HAS_PAYMENTS');
        }

        return DB::transaction(function () use ($invoice, $reason): Document {
            $invoice->update([
                'status' => DocumentStatus::Cancelled,
                'payload' => array_merge($invoice->payload ?? [], [
                    'cancelled_at' => now()->toDateTimeString(),
                    'cancellation_reason' => $reason,
                ]),
            ]);

            return $invoice;
        });
    }

    /**
     * The composite: cancel + goods decision, as ONE atomic act.
     *
     * ── CF-D4 LOCK ORDER (do not reorder without redoing the deadlock analysis) ──
     *   0. I — the invoice row FOR UPDATE, on EVERY branch, BEFORE reading
     *      `payload.return_decisions`                                    (here)
     *   1. I already held                       DocumentPostingService.php:162
     *   2. VAT period guard — takes no lock      DocumentPostingService.php:193
     *   3. J — the GL reversal — TAKES NO LOCK.  AccountingService.php:929-931
     *   4. I already held — source-document lock (the over-return cap)
     *   5. N — the `document_sequences` row FOR UPDATE
     *          DocumentNumberingService::generateForKeyOnce():47-51
     *   6. P — ALL product advisory locks, ONE sorted call    ProductCostLock:40-52
     *   7. R — previous confirmed RN row FOR UPDATE  ReturnNoteService:chain read
     *   8. `stock_levels` rows, nested inside P    WeightedAverageCostService
     *
     * STEP 0 IS MANDATORY ON EVERY BRANCH, including the already-cancelled replay.
     * `DocumentPostingService::cancel()`'s own idempotent early return sits OUTSIDE
     * its transaction and BEFORE its `lockForUpdate()`, and an already-cancelled
     * invoice matches neither the Posted nor the Paid arm here — it falls to the
     * plain-update branch, which historically took no lock at all. Without step 0,
     * two concurrent composites against an already-cancelled invoice would both read
     * "no decision", both create a return note and both restock.
     *
     * "J IS NOT A LOCK", and that premise is load-bearing: it is why J-before-P here
     * does not cycle against the one P-before-J path
     * (`OpeningBalancePostingService.php:75` → `:232`). IF ANY FUTURE LANE ADDS A LOCK
     * TO THE GL CHAIN ALLOCATOR, THIS COMPOSITE MUST BE RE-CHECKED.
     *
     * N-before-P here versus P-before-N in `GoodsReceiptService::post()` (which
     * acquires P and then generates a number inside it) is a textbook AB-BA SHAPE. It
     * does not cycle ONLY because the two paths lock DIFFERENT `document_sequences`
     * rows (`return_note` vs `goods_receipt`) — an accidental, undocumented safety
     * property. Recorded here because nothing else records it.
     */
    private function cancelInvoiceWithDecision(
        Document $invoice,
        string $reason,
        ?string $actorId,
        ReturnDecisionData $decision,
    ): Document {
        // ── step 0 ────────────────────────────────────────────────────────────────
        /** @var Document $locked */
        $locked = Document::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $existing = $this->acceptedDecisionOn($locked);

        if ($existing !== null) {
            if ($this->decisionMatches($existing, $decision)) {
                // Identical replay — the network-timeout path, and a real one: the
                // modal stays open on network error so the user WILL retry. It must
                // not refuse, and it must not create a second return note or a second
                // stock movement.
                return $locked->load(['lines']);
            }

            throw new ReturnDecisionConflictException(
                invoiceId: $locked->id,
                invoiceNumber: (string) $locked->document_number,
                rejectedDecision: $decision,
                existingDecision: $existing,
            );
        }

        // ── The goods decision must match what the invoice actually is ───────────
        // Gate CF round 1 / FE B3. `not_applicable` and `no_goods_issued` are the
        // SERVER's reading of the invoice, not user opinions — the modal only reports
        // them. Enforcing that here is what makes "explicit, never silent" a property of
        // the system rather than of the UI: a stale client, a direct API call, or a modal
        // rendered before `/can-cancel` resolved could otherwise record a false statement
        // about physical reality on a fiscal document.
        //
        // This is the mirror of `RETURN_NOTHING_DELIVERED` (which refuses a goods
        // decision when there are no goods); together they close both directions.
        $this->assertDecisionMatchesGoods($locked, $decision);

        // ── CF-D8: authorize each leg the composite is about to perform ───────────
        $this->authorizeDecisionLegs($decision);

        // ── the cancel branch ─────────────────────────────────────────────────────
        $cancelled = $this->performCancel($locked, $reason, $actorId);

        // ── the goods leg ─────────────────────────────────────────────────────────
        $returnNoteId = null;
        if ($decision->mode->bearsGoods()) {
            $returnNote = $this->createReturnNoteForDecision($cancelled, $decision, $actorId);
            $returnNoteId = $returnNote->id;

            if ($decision->mode->sealsTheReturnNote()) {
                // Step 6: ONE sorted acquire over the whole product set, so the
                // per-line re-acquires inside `receiveStockBack()` are re-entrant on
                // locks already held and cannot AB-BA against a concurrent confirm.
                $this->costLock->acquire(
                    $cancelled->tenant_id,
                    $cancelled->company_id,
                    $this->returnNoteService->lineProductIds($returnNote),
                    fn (): Document => $this->returnNoteService->confirmWithin($returnNote, $actorId),
                );
            }
        }

        $this->appendDecision($cancelled, $decision->toAuditRecord(true, $returnNoteId));

        /** @var Document */
        return $cancelled->refresh()->load(['lines']);
    }

    /**
     * Run the right cancel for the invoice's current state, WITHOUT clobbering an
     * existing cancellation record.
     *
     * The replay branch is the subtle one. An already-cancelled invoice matches
     * neither Posted nor Paid, and the historical else-branch re-merged `payload` with
     * a fresh `cancelled_at` + `cancellation_reason` — overwriting the ORIGINAL
     * cancellation record with the replay's. That is an audit-trail loss on a path
     * whose entire purpose is to be replay-safe.
     */
    private function performCancel(Document $invoice, string $reason, ?string $actorId): Document
    {
        if ($invoice->status === DocumentStatus::Cancelled) {
            return $invoice;
        }

        if ($invoice->status === DocumentStatus::Posted) {
            return $this->documentPostingService->cancel($invoice, $reason, $actorId);
        }

        if ($invoice->status === DocumentStatus::Paid) {
            throw new \DomainException('DOCUMENT_HAS_PAYMENTS');
        }

        $invoice->update([
            'status' => DocumentStatus::Cancelled,
            'payload' => array_merge($invoice->payload ?? [], [
                'cancelled_at' => now()->toDateTimeString(),
                'cancellation_reason' => $reason,
            ]),
        ]);

        return $invoice;
    }

    /**
     * Refuse a "there are no goods" decision for an invoice that has them.
     *
     * `not_applicable` claims the invoice has no physical lines; `no_goods_issued` claims
     * nothing was ever delivered. Both are checkable, and both are the server's to
     * decide — so both are checked here rather than trusted from the request.
     *
     * The permissive direction is deliberate: a caller posting `no_return` (goods stayed
     * out) for an invoice with nothing delivered is merely over-cautious, and
     * `RETURN_NOTHING_DELIVERED` already governs the goods-bearing modes. Only the two
     * claims that ASSERT AN ABSENCE are refused.
     */
    private function assertDecisionMatchesGoods(Document $invoice, ReturnDecisionData $decision): void
    {
        if ($decision->mode === ReturnDecisionMode::NotApplicable && $this->requiresReturnDecision($invoice)) {
            throw new ReturnDecisionMismatchesGoodsException(
                $invoice->id,
                (string) $invoice->document_number,
                $decision->mode->value,
                true,
                $this->hasGoodsIssued($invoice),
            );
        }

        if ($decision->mode === ReturnDecisionMode::NoGoodsIssued && $this->hasGoodsIssued($invoice)) {
            throw new ReturnDecisionMismatchesGoodsException(
                $invoice->id,
                (string) $invoice->document_number,
                $decision->mode->value,
                true,
                true,
            );
        }
    }

    /**
     * CF-D8. The route carries `can:invoices.cancel`; these are the abilities the
     * STANDALONE routes require for the legs the composite is about to perform on the
     * caller's behalf. Default roles make this a no-op, but roles are tenant-editable
     * and a composite silently performing a leg the caller could not perform
     * standalone is a privilege-escalation seam.
     */
    private function authorizeDecisionLegs(ReturnDecisionData $decision): void
    {
        if (! $decision->mode->bearsGoods()) {
            return;
        }

        if (! $this->authorizer->allows('deliveries.create')) {
            throw new ReturnDecisionForbiddenException('deliveries.create');
        }

        if ($decision->mode->sealsTheReturnNote() && ! $this->authorizer->allows('deliveries.confirm')) {
            throw new ReturnDecisionForbiddenException('deliveries.confirm');
        }
    }

    /**
     * Build the return note for a goods-bearing decision.
     *
     * CF-D7 + CF-D11. The return note covers every physical invoice line at the
     * DELIVERED-remaining quantity, one line per `(product, location)` tuple, with the
     * location written EXPLICITLY onto the line so `getEffectiveLocationId()` never
     * falls through to a possibly-null document location.
     */
    private function createReturnNoteForDecision(
        Document $invoice,
        ReturnDecisionData $decision,
        ?string $actorId,
    ): Document {
        $tuples = $this->deliveredQuantityResolver->resolve($invoice);
        $unresolved = $this->deliveredQuantityResolver->unresolvedLocationProductIds($invoice);

        // CF-D7: drop tuples capped to zero BEFORE the note is built. The operative
        // reason is not that they are pointless — it is that a zero line would still
        // enter the SEALED `total`: `receiveStockBack()` skips it, but the totals pass
        // does not.
        $live = array_values(array_filter(
            $tuples,
            static fn (DeliveredQuantityTuple $tuple): bool => bccomp($tuple->remaining, '0', self::QUANTITY_SCALE) > 0,
        ));

        if ($live === []) {
            // Nothing survives. Distinguish "nothing left the building" from "we
            // cannot tell where it went" — different facts, different remedies.
            if ($unresolved !== []) {
                throw new ReturnLocationUnresolvedException($invoice->id, (string) $invoice->document_number);
            }

            // CF-D6's enforcement half: typed, and raised regardless of what the UI
            // allowed. A disabled radio is affordance; a direct API call or a stale
            // client bypasses it entirely.
            throw new ReturnNothingDeliveredException($invoice->id, (string) $invoice->document_number);
        }

        if ($unresolved !== []) {
            // Some tuples resolve and some do not: part of the quantity would restock
            // somewhere it never left, and a location DOES resolve for the rest — the
            // narrow case CF-D11 reserves this code for. NEVER used to break a tie
            // between two locations; that is what the tuple split is for.
            throw new ReturnLocationAmbiguousException(
                $invoice->id,
                (string) $invoice->document_number,
                $unresolved,
            );
        }

        $company = $invoice->company;

        return $this->returnNoteService->createDraft(
            new CreateReturnNoteData(
                partnerId: (string) $invoice->partner_id,
                documentDate: $decision->returnedOn ?? now(),
                lines: $this->returnNoteLinesFor($invoice, $live, $tuples),
                currency: $invoice->currency,
                sourceDocumentId: $invoice->id,
                // Deliberately NULL: every line carries its own location (CF-D11), and
                // a document-level fallback is exactly the guess that would restock
                // goods where they never left.
                locationId: null,
                notes: 'Goods return recorded when invoice '.$invoice->document_number.' was cancelled.',
            ),
            $company,
        );
    }

    /**
     * One return-note line per surviving `(SOURCE INVOICE LINE, location)` pair.
     *
     * ── WHY THE KEY IS THE INVOICE LINE, NOT THE PRODUCT (gate CF round 1, Critical 1) ──
     * The first cut keyed pricing by PRODUCT: `$sourceLineByProduct[$productId] ??= $line`,
     * first line wins. But `DeliveredQuantityResolver` aggregates delivered quantity
     * across ALL of that product's invoice lines, so the whole delivered quantity was
     * priced at the FIRST line's `unit_price`, carried the FIRST line's
     * `discount_amount`, and was prorated against the FIRST line's `quantity`.
     *
     * Two invoice lines for one product is an ORDINARY invoice —
     * `CreateDocumentRequest` places no `distinct` rule on `lines.*.product_id`, and
     * CF-D11 point 2 leans on exactly that fact to make the tuple split expressible. So
     * this was reachable in one click, with no refusal, and it produced wrong money on a
     * SEALED document whose `total` is a hash input:
     *   - 3 @ 100.000 (disc 3.000) + 2 @ 50.000, all 5 delivered ⇒ sale net 397.000 but
     *     a sealed return net of 497.000 — the return overstating the sale by 25 %;
     *   - 1 @ 100.000 (disc 5.000) + 4 @ 100.000 split 3 + 2 ⇒ `qtyRatio` above 1 and a
     *     residue sink of **−5.000**: a negative flat discount, i.e. one that INCREASES
     *     the line net, persisted on a sealed fiscal line.
     * The sink was doing its job in both cases; its INPUT was wrong. CF-D11 forbids
     * picking one location for a multi-location quantity and gives that a typed refusal —
     * it never wrote the price analogue, and picking one price for a multi-line quantity
     * is the same defect through a different door.
     *
     * ── THE ALLOCATION ──
     * Each tuple's quantity is allocated across that product's invoice lines in
     * `line_number` order, consuming each line's capacity before moving on, and one
     * return-note line is emitted per allocation. NEVER divide by a quantity that is not
     * the quantity being split.
     *
     * The capacity ledger is seeded from the invoiced quantity MINUS what prior returns
     * already took (NEW-1) — without that, "each priced from its own source line" holds
     * only until the first partial return, after which the surviving units get re-priced
     * from lines that were already consumed.
     *
     * Order is deterministic — tuples arrive sorted by `(product_id, location_id)` and
     * lines are consumed in `line_number` order — which matters because the draft total
     * sums PER-LINE `bcmul` truncations and that total is a hash input.
     *
     * ── FIELD CLASSIFICATION (CF-D11; copying everything verbatim is WRONG) ──
     *   verbatim   `product_id`, `description`, `unit_price`, `tax_rate`,
     *              `discount_percent` — per-unit and scale-free, taken from THAT
     *              allocation's own source line. Copying `unit_price`/`tax_rate`
     *              verbatim is what keeps the return note mirroring the sale's VAT by
     *              construction (CF-D2): line tax comes from the line's OWN stored rate,
     *              not from the effective `TaxConfiguration`.
     *   allocation's `quantity`; tuple's `location_id` — the point of the split.
     *   PRORATED   `discount_amount` — a flat MONEY amount per line, not a rate.
     *
     * The proration is not a nicety. `DocumentLine::computeLineTotal()` subtracts a flat
     * `discount_amount` WHOLE from `qty × unit_price` and then floors the line at zero,
     * so a qty-5 line carrying `discount_amount = 5.000` split 3 + 2 would subtract
     * 5.000 TWICE: the sealed net and its VAT base understated by the whole duplicated
     * discount, silently clamped to zero on a small split line.
     *
     * `unit_price` here is B2B net/HT — the `documents` lane, not the POS
     * `SALE_RECEIPT` lane where it is tax-inclusive (rule 19). Money and quantity are
     * strings end to end.
     *
     * @param  list<DeliveredQuantityTuple>  $tuples  Live tuples, sorted by (product_id, location_id).
     * @param  list<DeliveredQuantityTuple>  $allTuples  Including zero-remaining ones, whose
     *                                                   `alreadyReturned` is what the capacity
     *                                                   ledger has to consume.
     * @return list<CreateReturnNoteLineData>
     */
    private function returnNoteLinesFor(Document $invoice, array $tuples, array $allTuples): array
    {
        $scale = $this->scaleResolver->getScale($invoice->currency);

        // Fix round 1 · inv gate F-1: the SAME predicate `requiresReturnDecision()`
        // and `ReturnNoteService::receiveStockBack()` use. A line this builder
        // priced but the restock skipped is a return-note line that moves no goods
        // — the divergence the gate surfaced.
        //
        // ── ERRATUM (fix round 2, fiscal gate NEW-1) ──
        // Fix round 1 claimed here that "a non-physical product has no delivered
        // tuple to price against either, so the exclusion changes no priced line
        // today". That was FALSE, and it was the P1 this filter introduced:
        // `DeliveredQuantityResolver::resolve()` still keyed `product_id !== null`,
        // so a non-physical product line delivered on a confirmed DN DID produce a
        // tuple. The tuple survived `createReturnNoteForDecision()`'s `$live` gate
        // and then priced to nothing here (`$productLines === []` → continue),
        // yielding a return note with ZERO lines and a 200. Fix round 2 adopts the
        // predicate in the resolver too, which is what makes the claim true.
        $invoice->loadMissing('lines.product');

        /** @var array<string, list<DocumentLine>> $linesByProduct */
        $linesByProduct = [];
        foreach ($invoice->lines->sortBy('line_number') as $line) {
            if (! PhysicalLinePredicate::forLine($line)) {
                continue;
            }
            $linesByProduct[(string) $line->product_id][] = $line;
        }

        /** @var array<string, numeric-string> $capacity remaining invoiced qty per source line id */
        $capacity = [];
        foreach ($linesByProduct as $productLines) {
            foreach ($productLines as $line) {
                $capacity[(string) $line->id] = (string) $line->quantity;
            }
        }

        // ── NET PRIOR RETURNS OFF THE LEDGER FIRST (gate CF round 2, NEW-1) ──
        //
        // The ledger used to be seeded from the GROSS invoiced quantity and never reduced,
        // so after a partial return the SURVIVING units were re-priced from lines that had
        // already been consumed. Reproduced by the gate: `3 @ 100.000` + `2 @ 50.000`
        // (sale net 400.000), 3 returned, then the guided cancel priced the remaining 2 at
        // 100.000 — a returned value of 500.000 against a sale of 400.000, sealed, with no
        // refusal. `assertWithinReturnableQuantities()` nets per PRODUCT (3 + 2 ≤ 5
        // passes), so the cap cannot see WHICH LINE the units came from. C1's own defect
        // class, surviving through this door.
        //
        // Prior returns are consumed in `line_number` order — the same order a prior
        // guided return would have consumed them in — so the ledger reconstructs which
        // lines are actually still outstanding.
        foreach ($this->priorReturnedByProduct($allTuples) as $productId => $priorReturned) {
            $outstanding = $priorReturned;

            foreach ($linesByProduct[$productId] ?? [] as $line) {
                if (bccomp($outstanding, '0', self::QUANTITY_SCALE) <= 0) {
                    break;
                }

                $lineId = (string) $line->id;
                $available = $capacity[$lineId];
                $consumed = bccomp($outstanding, $available, self::QUANTITY_SCALE) > 0
                    ? $available
                    : $outstanding;

                $capacity[$lineId] = bcsub($available, $consumed, self::QUANTITY_SCALE);
                $outstanding = bcsub($outstanding, $consumed, self::QUANTITY_SCALE);
            }
        }

        /** @var list<array{line: DocumentLine, tuple: DeliveredQuantityTuple, quantity: numeric-string}> $allocations */
        $allocations = [];

        foreach ($tuples as $tuple) {
            $productLines = $linesByProduct[$tuple->productId] ?? [];

            if ($productLines === []) {
                // Delivered but never invoiced. The over-return cap in `createDraft()`
                // owns that refusal; inventing a price here would pre-empt it.
                continue;
            }

            /** @var numeric-string $outstanding */
            $outstanding = $tuple->remaining;

            foreach ($productLines as $index => $line) {
                if (bccomp($outstanding, '0', self::QUANTITY_SCALE) <= 0) {
                    break;
                }

                $lineId = (string) $line->id;
                $available = $capacity[$lineId];
                $isLastLine = $index === count($productLines) - 1;

                // On the LAST line, take whatever is left even if it exceeds the line's
                // remaining capacity. Silently dropping the excess would hide an
                // over-return from `assertWithinReturnableQuantities()`, which compares
                // requested-per-product against invoiced-per-product — the refusal has to
                // still fire.
                $take = $isLastLine
                    ? $outstanding
                    : (bccomp($outstanding, $available, self::QUANTITY_SCALE) > 0 ? $available : $outstanding);

                if (bccomp($take, '0', self::QUANTITY_SCALE) <= 0) {
                    continue;
                }

                $allocations[] = ['line' => $line, 'tuple' => $tuple, 'quantity' => $take];

                $capacity[$lineId] = bcsub($available, $take, self::QUANTITY_SCALE);
                $outstanding = bcsub($outstanding, $take, self::QUANTITY_SCALE);
            }
        }

        $discounts = $this->prorateFlatDiscounts($allocations, $scale);

        $lines = [];
        foreach ($allocations as $index => $allocation) {
            $sourceLine = $allocation['line'];
            /** @var numeric-string $quantity */
            $quantity = $allocation['quantity'];
            /** @var numeric-string $unitPrice */
            $unitPrice = (string) $sourceLine->unit_price;
            /** @var numeric-string|null $taxRate */
            $taxRate = $sourceLine->tax_rate === null ? null : (string) $sourceLine->tax_rate;
            /** @var numeric-string|null $discountPercent */
            $discountPercent = $sourceLine->discount_percent === null ? null : (string) $sourceLine->discount_percent;

            $lines[] = new CreateReturnNoteLineData(
                productId: (string) $sourceLine->product_id,
                description: (string) $sourceLine->description,
                quantity: $quantity,
                unitPrice: $unitPrice,
                taxRate: $taxRate,
                discountPercent: $discountPercent,
                discountAmount: $discounts[$index],
                locationId: $allocation['tuple']->locationId,
            );
        }

        return $lines;
    }

    /**
     * Total already-returned quantity per product, across EVERY tuple.
     *
     * Read from the unfiltered tuple list on purpose: a fully-returned tuple has
     * `remaining = 0` and is dropped before pricing, but its `alreadyReturned` is exactly
     * the quantity the capacity ledger must consume. Taking this from the live tuples
     * would miss the very lines a prior return exhausted.
     *
     * @param  list<DeliveredQuantityTuple>  $allTuples
     * @return array<string, numeric-string>
     */
    private function priorReturnedByProduct(array $allTuples): array
    {
        /** @var array<string, numeric-string> $byProduct */
        $byProduct = [];

        foreach ($allTuples as $tuple) {
            $byProduct[$tuple->productId] = bcadd(
                $byProduct[$tuple->productId] ?? '0',
                $tuple->alreadyReturned,
                self::QUANTITY_SCALE,
            );
        }

        return $byProduct;
    }

    /**
     * Each source line's flat discount, split across ITS OWN allocations.
     *
     * Two properties, both load-bearing:
     *
     * 1. **Scaled to what is actually coming back.** The target for a source line is
     *    `discount × (Σ allocated ÷ line quantity)`, so a partial return of a discounted
     *    line carries a partial discount. Putting the whole line discount onto a smaller
     *    quantity would understate the sealed net.
     * 2. **Summing exactly, via a residue sink.** `bcdiv`/`bcmul` truncate, so every
     *    allocation except the FIRST for that line is prorated and the first takes
     *    `target − Σ(others)`. `Σ == target` then holds BY CONSTRUCTION at currency
     *    scale, with one named sink, no largest-remainder pass and nothing left to
     *    implementer choice. For a full return `Σ allocated == line quantity`, so the
     *    target is the source discount exactly.
     *
     * Because every ratio is now `allocated ÷ that same line's quantity`, it can never
     * exceed 1 and the sink can never go negative — the Critical-1 defect is structurally
     * unreachable rather than merely untested.
     *
     * Prorated whenever `discount_amount` is non-null, even when `discount_percent`
     * dominates `computeLineTotal()`'s precedence: harmless for the totals, and it keeps
     * the stored line data honest rather than carrying a misleading flat amount.
     *
     * @param  list<array{line: DocumentLine, tuple: DeliveredQuantityTuple, quantity: numeric-string}>  $allocations
     * @return array<int, numeric-string|null> Indexed to match $allocations.
     */
    private function prorateFlatDiscounts(array $allocations, int $scale): array
    {
        /** @var array<int, numeric-string|null> $discounts */
        $discounts = array_fill(0, count($allocations), null);

        /** @var array<string, list<int>> $indexesByLine */
        $indexesByLine = [];
        foreach ($allocations as $index => $allocation) {
            $indexesByLine[(string) $allocation['line']->id][] = $index;
        }

        foreach ($indexesByLine as $indexes) {
            $sourceLine = $allocations[$indexes[0]]['line'];

            if ($sourceLine->discount_amount === null) {
                continue;
            }

            /** @var numeric-string $sourceDiscount */
            $sourceDiscount = (string) $sourceLine->discount_amount;
            /** @var numeric-string $lineQuantity */
            $lineQuantity = (string) $sourceLine->quantity;

            if (bccomp($lineQuantity, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $allocated = '0';
            foreach ($indexes as $index) {
                $allocated = bcadd($allocated, $allocations[$index]['quantity'], self::QUANTITY_SCALE);
            }

            // The share of the line discount that belongs to the returned quantity.
            // precision-ok: a quantity RATIO, not a monetary value — 4 is the canonical
            // quantity scale; the money truncation is the surrounding bcmul at $scale.
            $returnedRatio = bcdiv($allocated, $lineQuantity, self::QUANTITY_SCALE);
            /** @var numeric-string $target */
            $target = bcmul($sourceDiscount, $returnedRatio, $scale);

            $others = '0';
            foreach ($indexes as $position => $index) {
                if ($position === 0) {
                    continue;
                }

                // precision-ok: a quantity RATIO, not a monetary value (see above).
                $qtyRatio = bcdiv($allocations[$index]['quantity'], $lineQuantity, self::QUANTITY_SCALE);
                /** @var numeric-string $prorated */
                $prorated = bcmul($sourceDiscount, $qtyRatio, $scale);

                $discounts[$index] = $prorated;
                $others = bcadd($others, $prorated, $scale);
            }

            /** @var numeric-string $residue */
            $residue = bcsub($target, $others, $scale);
            $discounts[$indexes[0]] = $residue;
        }

        return $discounts;
    }

    /**
     * The decision that TOOK EFFECT, if any. Rejected entries are skipped — they are
     * an audit trail, not state.
     *
     * @return array<string, mixed>|null
     */
    private function acceptedDecisionOn(Document $invoice): ?array
    {
        $recorded = $invoice->payload['return_decisions'] ?? null;

        if (! is_array($recorded)) {
            return null;
        }

        foreach (array_reverse($recorded) as $entry) {
            if (is_array($entry) && ($entry['accepted'] ?? false) === true) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function decisionMatches(array $existing, ReturnDecisionData $decision): bool
    {
        return ($existing['mode'] ?? null) === $decision->mode->value
            && ($existing['returned_on'] ?? null) === $decision->returnedOn?->toDateString();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function appendDecision(Document $invoice, array $record): void
    {
        $payload = $invoice->payload ?? [];
        $recorded = is_array($payload['return_decisions'] ?? null) ? $payload['return_decisions'] : [];
        $recorded[] = $record;
        $payload['return_decisions'] = $recorded;

        // Trigger-safe: `payload` is absent from the immutable column list, and after
        // a cancel `fiscal_status` is VOIDED, so `enforce_document_immutability()`
        // takes its `OLD.fiscal_status != 'SEALED'` early return either way.
        $invoice->update(['payload' => $payload]);
    }

    /**
     * CF-D5's best-effort audit append for a REFUSED decision, in its own short
     * transaction (the caller's has already rolled back).
     *
     * The skip predicate is `mode` + `returned_on` + `decided_by` + `accepted: false`,
     * matched ANYWHERE in the list — not merely against the last entry, and not
     * without the actor. Both edges matter: a DIFFERENT user's identically-shaped
     * rejection must append (support has to see that two people decided, which is the
     * audit claim this record exists to make), while two clients retry-looping with
     * different rejected modes would otherwise alternate A/B/A/B and grow `payload`
     * without bound.
     */
    private function appendRejectedDecision(ReturnDecisionConflictException $conflict): void
    {
        try {
            DB::transaction(function () use ($conflict): void {
                /** @var Document $invoice */
                $invoice = Document::query()->whereKey($conflict->invoiceId)->lockForUpdate()->firstOrFail();

                $payload = $invoice->payload ?? [];
                $recorded = is_array($payload['return_decisions'] ?? null) ? $payload['return_decisions'] : [];

                $rejected = $conflict->rejectedDecision;
                foreach ($recorded as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    if (($entry['accepted'] ?? true) === false
                        && ($entry['mode'] ?? null) === $rejected->mode->value
                        && ($entry['returned_on'] ?? null) === $rejected->returnedOn?->toDateString()
                        && ($entry['decided_by'] ?? null) === $rejected->decidedBy
                    ) {
                        return;
                    }
                }

                $recorded[] = $rejected->toAuditRecord(false);
                $payload['return_decisions'] = $recorded;
                $invoice->update(['payload' => $payload]);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to append a rejected return decision; the 422 is still returned.', [
                'invoice_id' => $conflict->invoiceId,
                'rejected_decision' => $conflict->rejectedDecision->toAuditRecord(false),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel a credit note (only if not posted)
     */
    public function cancelCreditNote(Document $creditNote, string $reason): Document
    {
        if ($creditNote->type !== DocumentType::CreditNote) {
            throw new \InvalidArgumentException('Document must be a credit note');
        }

        if ($creditNote->status === DocumentStatus::Posted) {
            return $this->documentPostingService->cancel($creditNote, $reason);
        }

        return DB::transaction(function () use ($creditNote, $reason): Document {
            $creditNote->update([
                'status' => DocumentStatus::Cancelled,
                'payload' => array_merge($creditNote->payload ?? [], [
                    'cancelled_at' => now()->toDateTimeString(),
                    'cancellation_reason' => $reason,
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Create a full credit note from a posted invoice
     */
    public function createFullCreditNote(
        Document $invoice,
        string $reason,
        DocumentNumberingService $numberingService
    ): Document {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Source document must be an invoice');
        }

        if ($invoice->status !== DocumentStatus::Posted && $invoice->status !== DocumentStatus::Paid) {
            throw new \RuntimeException('Can only create credit notes from posted or paid invoices');
        }

        // Check if already fully credited
        $payload = $invoice->payload ?? [];
        if (isset($payload['fully_credited']) && $payload['fully_credited'] === true) {
            throw new \RuntimeException('Invoice has already been fully credited');
        }

        return DB::transaction(function () use ($invoice, $reason, $numberingService): Document {
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'location_id' => $invoice->location_id,
                'partner_id' => $invoice->partner_id,
                'type' => DocumentType::CreditNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $numberingService->generateNumber(
                    tenantId: $invoice->tenant_id,
                    companyId: $invoice->company_id,
                    type: DocumentType::CreditNote
                ),
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'notes' => "Credit note for invoice {$invoice->document_number}: {$reason}",
                'source_document_id' => $invoice->id,
                'payload' => [
                    'credit_reason' => $reason,
                    'credit_type' => 'full',
                ],
            ]);

            // Copy vehicle context if exists
            if ($invoice->vehicleContext) {
                DocumentVehicleContext::create([
                    'document_id' => $creditNote->id,
                    'vehicle_id' => $invoice->vehicleContext->vehicle_id,
                    'vehicle_snapshot' => $invoice->vehicleContext->vehicle_snapshot,
                    'mileage_at_service' => $invoice->vehicleContext->mileage_at_service,
                    'context_data' => $invoice->vehicleContext->context_data,
                ]);
            }

            // Copy all lines
            foreach ($invoice->lines as $line) {
                $creditNote->lines()->create([
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'line_total' => $line->line_total,
                    'line_number' => $line->line_number,
                ]);
            }

            // Mark invoice as credited
            $invoice->update([
                'payload' => array_merge($invoice->payload ?? [], [
                    'credit_note_ids' => array_merge(
                        $invoice->payload['credit_note_ids'] ?? [],
                        [$creditNote->id]
                    ),
                    'fully_credited' => true,
                    'credited_at' => now()->toDateTimeString(),
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Create a partial credit note from a posted invoice.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function createPartialCreditNote(
        Document $invoice,
        array $lineItems,
        string $reason,
        DocumentNumberingService $numberingService
    ): Document {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Source document must be an invoice');
        }

        if ($invoice->status !== DocumentStatus::Posted && $invoice->status !== DocumentStatus::Paid) {
            throw new \RuntimeException('Can only create credit notes from posted or paid invoices');
        }

        if (empty($lineItems)) {
            throw new \InvalidArgumentException('Line items are required for partial credit note');
        }

        return DB::transaction(function () use ($invoice, $lineItems, $reason, $numberingService): Document {
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'location_id' => $invoice->location_id,
                'partner_id' => $invoice->partner_id,
                'type' => DocumentType::CreditNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $numberingService->generateNumber(
                    tenantId: $invoice->tenant_id,
                    companyId: $invoice->company_id,
                    type: DocumentType::CreditNote
                ),
                'document_date' => now(),
                'currency' => $invoice->currency,
                'notes' => "Partial credit note for invoice {$invoice->document_number}: {$reason}",
                'source_document_id' => $invoice->id,
                'payload' => [
                    'credit_reason' => $reason,
                    'credit_type' => 'partial',
                ],
            ]);

            // Copy vehicle context if exists
            if ($invoice->vehicleContext) {
                DocumentVehicleContext::create([
                    'document_id' => $creditNote->id,
                    'vehicle_id' => $invoice->vehicleContext->vehicle_id,
                    'vehicle_snapshot' => $invoice->vehicleContext->vehicle_snapshot,
                    'mileage_at_service' => $invoice->vehicleContext->mileage_at_service,
                    'context_data' => $invoice->vehicleContext->context_data,
                ]);
            }

            // Resolve scale from the credited invoice's own currency so this
            // never depends on a bound CompanyContext (context-safe AND
            // fiscally correct: EUR→2, TND→3).
            $scale = $this->scaleResolver->getScale($invoice->currency);
            $subtotal = '0.00';
            $taxAmount = '0.00';
            $total = '0.00';

            // Create credit note lines from line items
            foreach ($lineItems as $item) {
                $creditNote->lines()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? '0.00',
                    'discount_amount' => $item['discount_amount'] ?? '0.00',
                    'tax_rate' => $item['tax_rate'] ?? '0.00',
                    'line_total' => $item['line_total'] ?? $item['total'] ?? '0.00',
                    'line_number' => $item['line_number'] ?? $item['sort_order'] ?? 0,
                ]);

                $lineTotal = $item['line_total'] ?? $item['total'] ?? '0.00';
                $subtotal = bcadd($subtotal, $lineTotal, $scale);
                $taxAmount = bcadd($taxAmount, $item['tax_amount'] ?? '0.00', $scale);
                $total = bcadd($total, $lineTotal, $scale);
            }

            // Update credit note totals
            $creditNote->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            // Update invoice payload with credit note reference
            $invoice->update([
                'payload' => array_merge($invoice->payload ?? [], [
                    'credit_note_ids' => array_merge(
                        $invoice->payload['credit_note_ids'] ?? [],
                        [$creditNote->id]
                    ),
                    'partially_credited' => true,
                    'last_credit_at' => now()->toDateTimeString(),
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Check if invoice can be cancelled
     */
    public function canCancelInvoice(Document $invoice): bool
    {
        return $this->cancellationBlockReason($invoice) === null;
    }

    /**
     * WHY this invoice's Cancel action is unavailable, or NULL when it is.
     *
     * R2-F1 / GL gate I-3. This must agree with `DocumentPostingService::cancel()`
     * or the UI lies: before the period check was added here, an invoice sitting
     * in a FILED VAT period reported `can_cancel: true`, the front end rendered a
     * live Cancel button, and every click returned a 422. A FILED period can never
     * be reopened, so that button was a PERMANENT dead end — exactly the case the
     * read model has to pre-empt rather than discover on submit.
     *
     * Period refusals return the same codes the 422 carries, resolved through the
     * Shared contract so Document never touches Taxation internals.
     */
    public function cancellationBlockReason(Document $invoice): ?string
    {
        if ($invoice->type !== DocumentType::Invoice) {
            return CancelBlockReason::NotAnInvoice->value;
        }

        if ($invoice->status === DocumentStatus::Cancelled) {
            return CancelBlockReason::AlreadyCancelled->value;
        }

        if ($invoice->status === DocumentStatus::Posted) {
            if ($this->documentPostingService->hasBlockingAllocations($invoice)) {
                return CancelBlockReason::HasPayments->value;
            }

            // Only a POSTED invoice reaches DocumentPostingService::cancel() and
            // therefore the period guard; the draft/confirmed branch below never
            // touches the ledger.
            return $this->periodLock->cancellationRefusalCode($invoice);
        }

        if ($invoice->status === DocumentStatus::Paid) {
            return CancelBlockReason::HasPayments->value;
        }

        return null;
    }

    /**
     * Does cancelling this invoice require an explicit decision about the goods?
     *
     * Plan CF T7 / CF-D6. TRUE when the invoice carries at least one PHYSICAL line.
     *
     * The predicate is {@see PhysicalLinePredicate}, which is EXACTLY what
     * `ReturnNoteService::receiveStockBack()` keys on. That identity is the whole
     * point: the read model must not claim a return decision is needed for a line
     * that would not restock, nor the reverse.
     *
     * ── WHY THIS IS NOT `product_id !== null` (fix round 1, inv gate F-1) ──
     * It was, and the docblock claimed the two were identical. DPA Wave 3 T4 / D-19
     * made that FALSE by moving `receiveStockBack()` onto the predicate, which also
     * excludes a NON-PHYSICAL PRODUCT line (`product_id` set, `is_physical = false`)
     * — a population the phantom `is_service` guard had been letting through. For an
     * invoice whose lines are all such products the divergence was operator-facing:
     * the modal demanded a goods disposition, `assertDecisionMatchesGoods()` refused
     * `not_applicable`, and any goods-bearing mode built a return-note line that
     * `receiveStockBack()` then silently skipped. One question, one answer.
     *
     * The relation form (no tenant/company arguments) is used deliberately, matching
     * `ReturnNoteService` and `DeliveryNoteService`; scoping those three is a
     * separate, deliberate change (D-4). `loadMissing` keeps the read model off the
     * N+1 the predicate would otherwise introduce on this affordance path.
     *
     * FALSE means the modal renders WITHOUT the option group and posts
     * `not_applicable` — the modal itself always renders, because `reason` is
     * `required|max:500` and has no other UI.
     */
    public function requiresReturnDecision(Document $invoice): bool
    {
        $invoice->loadMissing('lines.product');

        foreach ($invoice->lines as $line) {
            if (PhysicalLinePredicate::forLine($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Have units actually left the building against this invoice? — CF-D6's
     * `goods_issued`.
     *
     * FAIL CLOSED: no delivery-note linkage, an unconfirmed delivery note, or an
     * unresolvable location all resolve to FALSE. That matters because posting an
     * invoice moves NO stock — a catalogue-shaped predicate here would let one click
     * restock goods that never shipped.
     *
     * This is affordance only. The server refuses independently in
     * `cancelInvoice()` (typed `RETURN_NOTHING_DELIVERED`), because a disabled radio
     * is not a safety property.
     */
    public function hasGoodsIssued(Document $invoice): bool
    {
        return $this->deliveredQuantityResolver->hasGoodsIssued($invoice);
    }

    /**
     * Delivered quantities per `(product, location)` tuple, for the modal.
     *
     * Reported per product AND per location so the modal can EXPLAIN a split rather
     * than present one opaque number — a user cancelling an invoice whose goods went
     * out from two warehouses is about to restock into both.
     *
     * @return list<array{product_id: string, location_id: string, delivered: string, already_returned: string, remaining: string}>
     */
    public function deliveredQuantities(Document $invoice): array
    {
        return array_map(
            static fn (DeliveredQuantityTuple $tuple): array => [
                'product_id' => $tuple->productId,
                'location_id' => $tuple->locationId,
                'delivered' => $tuple->delivered,
                'already_returned' => $tuple->alreadyReturned,
                'remaining' => $tuple->remaining,
            ],
            $this->deliveredQuantityResolver->resolve($invoice),
        );
    }

    /**
     * The goods decision already recorded for this invoice, if any — CF-D5's
     * readability half.
     *
     * Without this the owner's "the choice is recorded" exists only in the database:
     * the modal could not tell the user that someone already decided, and would offer
     * a choice that is going to 422.
     *
     * @return array<string, mixed>|null
     */
    public function recordedReturnDecision(Document $invoice): ?array
    {
        return $this->acceptedDecisionOn($invoice);
    }

    /**
     * Check if invoice can be credited
     */
    public function canCreditInvoice(Document $invoice): bool
    {
        if ($invoice->type !== DocumentType::Invoice) {
            return false;
        }

        if (! in_array($invoice->status, [DocumentStatus::Posted, DocumentStatus::Paid], true)) {
            return false;
        }

        // Check if already fully credited
        $payload = $invoice->payload ?? [];

        return ! (isset($payload['fully_credited']) && $payload['fully_credited'] === true);
    }

    /**
     * Get credit note summary for an invoice.
     *
     * @return array<string, mixed>
     */
    public function getCreditNoteSummary(Document $invoice): array
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Document must be an invoice');
        }

        $payload = $invoice->payload ?? [];
        $creditNoteIds = $payload['credit_note_ids'] ?? [];

        if (empty($creditNoteIds)) {
            return [
                'has_credit_notes' => false,
                'credit_note_count' => 0,
                'total_credited_amount' => '0.00',
                'fully_credited' => false,
            ];
        }

        $creditNotes = Document::whereIn('id', $creditNoteIds)
            ->where('type', DocumentType::CreditNote)
            ->get();

        $scale = $this->scaleResolver->getScale($invoice->currency);
        $totalCredited = '0.00';
        foreach ($creditNotes as $cn) {
            $totalCredited = bcadd($totalCredited, $cn->total ?? '0.00', $scale);
        }

        return [
            'has_credit_notes' => true,
            'credit_note_count' => $creditNotes->count(),
            'total_credited_amount' => $totalCredited,
            'fully_credited' => $payload['fully_credited'] ?? false,
            'partially_credited' => $payload['partially_credited'] ?? false,
        ];
    }
}
