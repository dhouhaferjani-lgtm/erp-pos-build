<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DTOs\DeliveredQuantityTuple;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of an invoice has physically LEFT the building, and from where.
 *
 * Plan CF T15 / CF-D6 / CF-D11. The guided cancel flow needs this because
 * **posting an invoice moves no stock units**: the only `InvoicePosted` listeners
 * are `InvoicePostedListener` (GL) and `PostCOGSOnInvoice` (which injects only
 * `GeneralLedgerService`), and `DocumentPostingService` mentions stock nowhere at
 * all. Units move on delivery notes and return notes. Meanwhile the pre-existing
 * over-return cap (`ReturnNoteService::assertWithinReturnableQuantities()`) caps on
 * **invoiced** quantity, never delivered.
 *
 * So a catalogue-shaped predicate — "this invoice has product lines, therefore a
 * return restocks them" — would create inventory that never existed: one click on
 * an invoice that was billed but never shipped, and the warehouse gains units.
 * That is the failure this resolver exists to prevent, and it is why `goods_issued`
 * gates options 1 and 2 of the modal AND why the server refuses independently
 * (a disabled radio is affordance, not enforcement).
 *
 * TWO LINKAGE SHAPES, BOTH REQUIRED (fiscal gate N-I2). Reading only one silently
 * disables the whole flow for tenants using the other:
 *   (a) `DeliveryNoteToInvoiceConverter` writes
 *       `invoice.payload.source_delivery_note_ids` — its only two writers in `app/`.
 *   (b) `SalesOrderToInvoiceConverter` gives the invoice
 *       `source_document_id = $order->id` (via
 *       `CopiesDocumentData::createTargetDocument()`), and the ORDER's payload
 *       carries `delivery_note_ids`.
 * An SO-driven tenant resolves to ZERO delivered under (a) alone — fail-closed, so
 * no phantom stock, but silently unusable for genuinely delivered goods.
 *
 * FAIL CLOSED throughout: no linkage, an unconfirmed delivery note, or an
 * unresolvable location all yield less delivered quantity, never more.
 */
final class DeliveredQuantityResolver
{
    private const QUANTITY_SCALE = 4;

    /**
     * Delivered-remaining per `(product_id, location_id)` tuple, in the mandated
     * stable sort order (`product_id`, then `location_id`).
     *
     * The order is not cosmetic: CF-D11 has the caller emit one return-note line per
     * tuple, the draft total sums PER-LINE `bcmul` truncations, and the return note's
     * `total` is a fiscal hash input. An unstable order would move the sealed byte
     * between two otherwise identical returns.
     *
     * Tuples whose remaining is zero are RETAINED here — callers need to tell "this
     * tuple is exhausted" from "this tuple does not exist" (a fully-returned invoice
     * must report zero remaining, not an empty map). Dropping them is the caller's
     * job, immediately before building lines (CF-D7).
     *
     * @return list<DeliveredQuantityTuple>
     */
    public function resolve(Document $invoice): array
    {
        $deliveryNotes = $this->confirmedDeliveryNotesFor($invoice);

        if ($deliveryNotes === []) {
            return [];
        }

        /** @var array<string, numeric-string> $delivered keyed "productId|locationId" */
        $delivered = [];

        foreach ($deliveryNotes as $deliveryNote) {
            foreach ($deliveryNote->lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }

                // The location the units actually left from. `issueStock()` resolves
                // `$line->location ?? $deliveryNote->location` per line, so this
                // mirrors the movement rather than guessing at it. A confirmed DN is
                // guaranteed to have a document location — `DeliveryNoteService::confirm()`
                // refuses to confirm without one — so for any invoice that passes the
                // `goods_issued` test every tuple has a location.
                $locationId = $line->location_id ?? $deliveryNote->location_id;

                if ($locationId === null) {
                    // Unreachable for a confirmed DN, but fail closed rather than
                    // inventing a location: skipping shrinks delivered quantity, and
                    // the caller's typed `RETURN_LOCATION_UNRESOLVED` refusal is a
                    // better outcome than a restock into a guessed warehouse.
                    continue;
                }

                $key = (string) $line->product_id.'|'.$locationId;

                $delivered[$key] = bcadd(
                    $delivered[$key] ?? '0',
                    (string) $line->quantity,
                    self::QUANTITY_SCALE,
                );
            }
        }

        if ($delivered === []) {
            return [];
        }

        $alreadyReturned = $this->priorReturnsPerTuple($invoice, $delivered);

        $tuples = [];
        foreach ($delivered as $key => $deliveredQty) {
            [$productId, $locationId] = explode('|', $key, 2);

            /** @var numeric-string $returned */
            $returned = $alreadyReturned[$key] ?? '0';
            /** @var numeric-string $remaining */
            $remaining = bcsub($deliveredQty, $returned, self::QUANTITY_SCALE);
            if (bccomp($remaining, '0', self::QUANTITY_SCALE) < 0) {
                $remaining = bcadd('0', '0', self::QUANTITY_SCALE);
            }

            $tuples[] = new DeliveredQuantityTuple(
                productId: $productId,
                locationId: $locationId,
                delivered: $deliveredQty,
                alreadyReturned: $returned,
                remaining: $remaining,
            );
        }

        // Stable, reproducible order — see the method docblock.
        usort(
            $tuples,
            static fn (DeliveredQuantityTuple $a, DeliveredQuantityTuple $b): int => [$a->productId, $a->locationId] <=> [$b->productId, $b->locationId],
        );

        return $tuples;
    }

    /**
     * Products whose delivered units could NOT be attributed to a location.
     *
     * `resolve()` skips such lines — fail closed, so they never inflate a tuple —
     * but skipping them silently would let the caller mistake "we cannot tell where
     * these went" for "these were never delivered", and those need different
     * refusals. This is how the caller tells CF-D11's three cases apart:
     *
     *   - no tuples, nothing unresolved      ⇒ `RETURN_NOTHING_DELIVERED`
     *   - no tuples, something unresolved    ⇒ `RETURN_LOCATION_UNRESOLVED`
     *   - some tuples AND something unresolved ⇒ `RETURN_LOCATION_AMBIGUOUS`
     *     (the narrow case CF-D11 reserves it for: part of the quantity would
     *     silently restock somewhere it never left, while its siblings resolve)
     *
     * Unreachable for a confirmed delivery note in practice —
     * `DeliveryNoteService::confirm()` refuses to confirm one without a document
     * location — which is exactly why the caller must never guess instead.
     *
     * @return list<string>
     */
    public function unresolvedLocationProductIds(Document $invoice): array
    {
        $productIds = [];

        foreach ($this->confirmedDeliveryNotesFor($invoice) as $deliveryNote) {
            foreach ($deliveryNote->lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }

                if (($line->location_id ?? $deliveryNote->location_id) === null) {
                    $productIds[] = (string) $line->product_id;
                }
            }
        }

        return array_values(array_unique($productIds));
    }

    /**
     * TRUE when any tuple still has units out — CF-D6's `goods_issued`.
     *
     * Deliberately "any tuple > 0" rather than "delivered > 0": an invoice whose
     * every delivered unit has already come back has nothing left to restock, and
     * offering options 1 or 2 for it would over-return.
     */
    public function hasGoodsIssued(Document $invoice): bool
    {
        foreach ($this->resolve($invoice) as $tuple) {
            if (bccomp($tuple->remaining, '0', self::QUANTITY_SCALE) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of the confirmed delivery notes backing this invoice.
     *
     * Public because the over-return cap in `ReturnNoteService` has to net prior returns
     * over the SAME set (gate CF round 1, Critical 2) — re-deriving that traversal at the
     * call site is how the two would drift, and a drift there restocks the same units
     * twice.
     *
     * @return list<string>
     */
    public function confirmedDeliveryNoteIdsFor(Document $invoice): array
    {
        return array_map(
            static fn (Document $note): string => $note->id,
            $this->confirmedDeliveryNotesFor($invoice),
        );
    }

    /**
     * The MIRROR of {@see confirmedDeliveryNoteIdsFor()}: the invoices backed by this
     * delivery note.
     *
     * Gate CF round 2. Round 1 widened the netting invoice → delivery notes and left the
     * reverse open, so the system refused a double return in one order of operations and
     * permitted it in the other — which reads as fixed and is not. The reviewer
     * reproduced it as a three-step sequence STARTING FROM THIS LANE'S OWN COMPOSITE: a
     * guided `will_return` leaves a DRAFT return note against the invoice, the DN-side
     * cap never looked at it, a DN-sourced return for the same units was accepted, and
     * both confirms restocked — five delivered units, ten in the warehouse.
     *
     * This is not adjacent legacy exposure. At the lane's base the delivery-note entry
     * point 422'd on every submit (the T1 characterisation fence); T8 repairs it, so that
     * surface goes from dead-in-UI to one click IN THIS MERGE.
     *
     * BOUNDED — two company-scoped queries, mirroring the forward traversal's two
     * linkage shapes:
     *   (a) `DeliveryNoteToInvoiceConverter` writes the delivery note's id into the
     *       invoice's `payload.source_delivery_note_ids`, so a `whereJsonContains` on
     *       that path finds it (repo precedent:
     *       `Document::payloadLinkedSupplierInvoiceChildren()`);
     *   (b) `SalesOrderToInvoiceConverter` gives the invoice
     *       `source_document_id = order`, and the delivery note hangs off the same order
     *       — so an invoice sharing this note's `source_document_id` is backed by it.
     *
     * If neither resolves, the delivery note is uninvoiced and the DN-only net is already
     * complete: there is no legitimate flow to break and no unbounded fan-out.
     *
     * @return list<string>
     */
    public function invoiceIdsBackedByDeliveryNote(Document $deliveryNote): array
    {
        if ($deliveryNote->type !== DocumentType::DeliveryNote) {
            return [];
        }

        $ids = Document::query()
            ->where('company_id', $deliveryNote->company_id)
            ->where('type', DocumentType::Invoice)
            ->whereJsonContains('payload->source_delivery_note_ids', $deliveryNote->id)
            ->pluck('id')
            ->all();

        // (b) the sales-order shape: same order, so the note backs that invoice.
        if ($deliveryNote->source_document_id !== null) {
            $viaOrder = Document::query()
                ->where('company_id', $deliveryNote->company_id)
                ->where('type', DocumentType::Invoice)
                ->where('source_document_id', $deliveryNote->source_document_id)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $viaOrder);
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $ids)));

        return $unique;
    }

    /**
     * The confirmed delivery notes backing this invoice, through BOTH linkage shapes.
     *
     * @return list<Document>
     */
    private function confirmedDeliveryNotesFor(Document $invoice): array
    {
        $payload = $invoice->payload ?? [];

        /** @var list<string> $ids */
        $ids = [];

        // (a) DN → invoice conversion.
        if (is_array($payload['source_delivery_note_ids'] ?? null)) {
            foreach ($payload['source_delivery_note_ids'] as $id) {
                $ids[] = (string) $id;
            }
        }

        // (b) SO → invoice conversion: the ORDER holds the delivery-note ids.
        if ($invoice->source_document_id !== null) {
            $order = Document::query()
                ->where('company_id', $invoice->company_id)
                ->where('type', DocumentType::SalesOrder)
                ->find($invoice->source_document_id);

            $orderPayload = $order === null ? [] : ($order->payload ?? []);
            if (is_array($orderPayload['delivery_note_ids'] ?? null)) {
                foreach ($orderPayload['delivery_note_ids'] as $id) {
                    $ids[] = (string) $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        /** @var list<Document> $notes */
        $notes = Document::query()
            ->where('company_id', $invoice->company_id)
            ->where('type', DocumentType::DeliveryNote)
            ->where('status', DocumentStatus::Confirmed)
            ->whereIn('id', $ids)
            ->with('lines')
            ->get()
            ->all();

        return $notes;
    }

    /**
     * Quantities already returned against this invoice, attributed per tuple.
     *
     * A prior return-note line carries its own `location_id`, so the netting is per
     * tuple too — otherwise closing one location's returns would wrongly exhaust
     * another's remaining.
     *
     * UNATTRIBUTABLE PRIOR RETURNS. A manually-created return note may carry a line
     * with no line location and no document location, in which case the units cannot
     * be pinned to a tuple. Ignoring such a line would let the same units be
     * restocked TWICE, so the remainder is DRAINED across that product's tuples in
     * the mandated sort order, **capped at each tuple's own delivered quantity** and
     * carried forward to the next tuple.
     *
     * The cap is the part that matters. Dumping the whole remainder into the first
     * tuple would let the per-tuple zero-floor swallow the excess: product P
     * delivered 3 at L1 and 2 at L2 with an unattributable prior return of 4 would
     * net L1 to `max(3−4, 0) = 0` and leave L2 at 2, reporting 2 remaining where the
     * truth is 1 — an over-restock of one unit, which is precisely the class of
     * defect this resolver exists to prevent. Draining with a cap nets 3 at L1 and
     * 1 at L2, leaving 1.
     *
     * Return notes created by the guided cancel flow always carry an explicit
     * per-line location (CF-D11), so this only ever applies to hand-built ones.
     *
     * ── THE SOURCE SET IS THE INVOICE **AND** ITS DELIVERY NOTES (gate CF round 1,
     * Critical 2) ── Netting only on `source_document_id == invoice` missed every return
     * note raised against the DELIVERY NOTE. That is not a hypothetical shape: it is the
     * second entry point (`CreateReturnNoteForm`, rendered from `DeliveryNoteDetailPage`),
     * which posts the delivery note as its `source_document_id`. Before this lane it
     * 422'd on every submit, so the gap was invisible; T8 repairs it, which makes the gap
     * LIVE IN THE SAME MERGE.
     *
     * Reproduced by the gate: 5 delivered, returned once through that surface, then the
     * guided cancel — 200 OK and 10 units in the warehouse. Five delivered, returned
     * once, ten in stock. Phantom inventory, with no refusal, because both the resolver
     * and the cap agreed nothing had come back.
     *
     * @param  array<string, numeric-string>  $delivered  "productId|locationId" ⇒ delivered qty.
     * @return array<string, numeric-string>
     */
    private function priorReturnsPerTuple(Document $invoice, array $delivered): array
    {
        $sourceIds = array_values(array_unique(array_merge(
            [$invoice->id],
            $this->confirmedDeliveryNoteIdsFor($invoice),
        )));

        $priorLines = DocumentLine::query()
            ->whereHas('document', static function (Builder $query) use ($invoice, $sourceIds): void {
                /** @var Builder<Document> $query */
                $query->where('company_id', $invoice->company_id)
                    ->where('type', DocumentType::ReturnNote)
                    ->whereIn('source_document_id', $sourceIds)
                    ->where('status', '!=', DocumentStatus::Cancelled->value);
            })
            ->with('document')
            ->get();

        /** @var array<string, numeric-string> $returned */
        $returned = [];
        /** @var array<string, numeric-string> $unattributed keyed by product id */
        $unattributed = [];

        foreach ($priorLines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $productId = (string) $line->product_id;
            $locationId = $line->location_id ?? $line->document->location_id;

            if ($locationId === null) {
                $unattributed[$productId] = bcadd(
                    $unattributed[$productId] ?? '0',
                    (string) $line->quantity,
                    self::QUANTITY_SCALE,
                );

                continue;
            }

            $key = $productId.'|'.$locationId;
            $returned[$key] = bcadd($returned[$key] ?? '0', (string) $line->quantity, self::QUANTITY_SCALE);
        }

        if ($unattributed === []) {
            return $returned;
        }

        // Drain the unattributable remainder across the product's own tuples, in the
        // mandated sort order, capped per tuple and carried forward.
        $keys = array_keys($delivered);
        sort($keys);

        foreach ($keys as $key) {
            [$productId] = explode('|', $key, 2);

            $outstanding = $unattributed[$productId] ?? null;
            if ($outstanding === null || bccomp($outstanding, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            // Headroom = what this tuple delivered, minus what has already been
            // attributed to it by a located prior return line.
            $headroom = bcsub($delivered[$key], $returned[$key] ?? '0', self::QUANTITY_SCALE);
            if (bccomp($headroom, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $take = bccomp($outstanding, $headroom, self::QUANTITY_SCALE) > 0 ? $headroom : $outstanding;

            $returned[$key] = bcadd($returned[$key] ?? '0', $take, self::QUANTITY_SCALE);
            $unattributed[$productId] = bcsub($outstanding, $take, self::QUANTITY_SCALE);
        }

        return $returned;
    }
}
