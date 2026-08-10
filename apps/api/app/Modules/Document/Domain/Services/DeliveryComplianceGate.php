<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\DeliveryComplianceStatus;
use App\Modules\Document\Domain\Enums\DeliveryComplianceCode;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Product\Domain\Product;

/**
 * THE delivery-compliance gate. One evaluation, one traversal, two callers.
 *
 * ── WHY THIS CLASS EXISTS (Wave 3 T25f; plan D-17, inv F-5 ≡ fiscal I-4) ──
 * The rule *"physical goods must be delivered before their invoice is posted"*
 * was implemented TWICE:
 *
 *   - `DocumentPostingService::validateDeliveryCompliance()` — throwing
 *     `\DomainException`s that the controller flattened to `POSTING_FAILED`;
 *   - `InvoiceController::checkDeliveryNotesDelivered()` — returning untyped
 *     arrays that produced the guided `DELIVERY_NOT_COMPLETED` modal.
 *
 * Both walked `sourceOrder.payload['delivery_note_ids']` by hand and NEITHER
 * read `invoice.payload['source_delivery_note_ids']` — the key
 * `DeliveryNoteToInvoiceConverter` writes. So a DN → invoice conversion, the
 * most compliant flow in the system, was invisible to both gates and survived
 * only because both take a "no source order ⇒ standalone ⇒ nothing to check"
 * early return. The moment that early return becomes a REFUSAL (T25b, under the
 * per-country pre-delivery invoicing policy), a one-shape traversal 422s every
 * converted invoice. Unifying first is therefore not tidying — it is the
 * precondition for the flip.
 *
 * The traversal is `DeliveredQuantityResolver`'s and nowhere else (D-17): a
 * copied traversal is how the two drift, and a drift here refuses compliant
 * documents on the fiscal chokepoint.
 *
 * ── DELIBERATELY ADDITIVE ── T25f changes no verdict for any invoice that posts
 * today. Converted invoices become VISIBLE to the gate and PASS it (their
 * delivery notes are Confirmed by construction — `DeliveryNoteToInvoiceConverter`
 * refuses Draft and Cancelled sources). Standalone invoices still pass. The
 * refusal in {@see evaluate()} is byte-for-byte the pre-existing one.
 */
final class DeliveryComplianceGate
{
    private const QUANTITY_SCALE = 4;

    public function __construct(
        private readonly DeliveredQuantityResolver $resolver,
    ) {}

    /**
     * Evaluate an invoice against the delivery requirement.
     *
     * Returns a verdict rather than throwing, because the guided modal needs the
     * draft-delivery-note detail that a thrown exception cannot carry. The
     * posting chokepoint turns the verdict into a refusal.
     */
    public function evaluate(Document $invoice): DeliveryComplianceStatus
    {
        if (! $this->hasPhysicalLines($invoice)) {
            return DeliveryComplianceStatus::compliant();
        }

        $noteIds = $this->resolver->linkedDeliveryNoteIdsFor($invoice);

        if ($noteIds === []) {
            // No linkage of ANY shape. Two populations land here and T25f keeps
            // treating them identically, exactly as the shipped gates did:
            //
            //   (a) a genuinely standalone invoice — nothing was ever delivered;
            //   (b) an order-sourced invoice whose order carries an empty
            //       `delivery_note_ids` array.
            //
            // The shipped gates told them apart only because they reached the
            // linkage through the source order: (a) took the "standalone, no
            // delivery check needed" early return and PASSED, (b) was refused.
            // That split is preserved below and is the exact seam T25b replaces
            // with the policy decision.
            return $this->hasSourceSalesOrder($invoice)
                ? new DeliveryComplianceStatus(
                    DeliveryComplianceCode::NoDeliveryNotes,
                    'Physical products must be delivered before posting invoice. No delivery notes found for the source order.',
                )
                : DeliveryComplianceStatus::compliant();
        }

        /** @var list<Document> $deliveryNotes */
        $deliveryNotes = Document::query()
            ->where('company_id', $invoice->company_id)
            ->where('type', DocumentType::DeliveryNote)
            ->whereIn('id', $noteIds)
            ->with('lines')
            ->get()
            ->all();

        $draft = array_values(array_filter(
            $deliveryNotes,
            static fn (Document $note): bool => $note->status === DocumentStatus::Draft,
        ));

        if ($draft !== []) {
            return new DeliveryComplianceStatus(
                DeliveryComplianceCode::DraftDeliveryNotes,
                'Delivery notes must be confirmed before posting invoice',
                array_map(
                    static fn (Document $note): array => [
                        'id' => (string) $note->id,
                        'number' => (string) $note->document_number,
                        'total' => (string) $note->total,
                        'line_count' => $note->lines->count(),
                    ],
                    $draft,
                ),
                // A draft delivery note may be batch-confirmed from the modal only
                // when the system created it — a hand-built one is the operator's
                // to review.
                array_reduce(
                    $draft,
                    static function (bool $carry, Document $note): bool {
                        $payload = $note->payload ?? [];

                        return $carry && ($payload['auto_created'] ?? null) === true;
                    },
                    true,
                ),
            );
        }

        foreach ($deliveryNotes as $note) {
            foreach ($note->lines as $line) {
                $delivered = (string) ($line->quantity_delivered ?? '0');

                if (bccomp($delivered, (string) $line->quantity, self::QUANTITY_SCALE) < 0) {
                    return new DeliveryComplianceStatus(
                        DeliveryComplianceCode::IncompleteDelivery,
                        sprintf(
                            'Delivery note %s must be marked as fully delivered before posting invoice. Please update the delivery quantities.',
                            $note->document_number,
                        ),
                    );
                }
            }
        }

        return DeliveryComplianceStatus::compliant();
    }

    /**
     * Does this document carry at least one PHYSICAL product line?
     *
     * Scoped by the document's tenant AND company (api.document.010) — the
     * stricter of the two shipped copies. `InvoiceController` used the
     * eager-loaded `$line->product` relation, which is unscoped; keeping the
     * scoped form means the enforcement point cannot be steered by a
     * cross-tenant `product_id`.
     *
     * 📌 Wave 3 T4 (D-19) replaces this body with the shared
     * `PhysicalLinePredicate`. T25f deliberately does not create that class —
     * it belongs to sub-wave 3A — but it does collapse the two call sites T4
     * has to adopt into this one.
     */
    public function hasPhysicalLines(Document $document): bool
    {
        foreach ($document->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $product = Product::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $document->company_id)
                ->find($line->product_id);

            if ($product !== null && $product->is_physical) {
                return true;
            }
        }

        return false;
    }

    private function hasSourceSalesOrder(Document $invoice): bool
    {
        $sourceOrder = $invoice->sourceDocument;

        return $sourceOrder !== null && $sourceOrder->type === DocumentType::SalesOrder;
    }
}
