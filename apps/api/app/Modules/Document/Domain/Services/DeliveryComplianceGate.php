<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Application\Services\PreDeliveryInvoicingPolicyResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\DeliveryComplianceStatus;
use App\Modules\Document\Domain\DTOs\ResolvedPreDeliveryInvoicingPolicy;
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

    /** The invoice-payload key the T25e audit stamp lives under. */
    public const STAMP_KEY = 'pre_delivery_invoicing';

    public function __construct(
        private readonly DeliveredQuantityResolver $resolver,
        private readonly PreDeliveryInvoicingPolicyResolver $policyResolver,
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
            // No linkage of ANY shape. Two populations land here:
            //
            //   (a) a genuinely standalone invoice — nothing was ever delivered;
            //   (b) an order-sourced invoice whose order carries an empty
            //       `delivery_note_ids` array.
            //
            // The shipped gates told them apart only because they reached the
            // linkage through the source order: (b) was refused with a specific
            // message, (a) took the "standalone, no delivery check needed" early
            // return and PASSED. (b) keeps its more actionable refusal; (a) falls
            // through to the T25b policy decision at the bottom of this method —
            // that early return is exactly what T25b replaces.
            if ($this->hasSourceSalesOrder($invoice)) {
                return new DeliveryComplianceStatus(
                    DeliveryComplianceCode::NoDeliveryNotes,
                    'Physical products must be delivered before posting invoice. No delivery notes found for the source order.',
                );
            }
        } else {
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
                    // A draft delivery note may be batch-confirmed from the modal
                    // only when the system created it — a hand-built one is the
                    // operator's to review.
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
        }

        // ── T25b — THE COMPLIANCE BOUNDARY ───────────────────────────────────
        //
        // Everything above is operational: "your delivery notes are not in a
        // postable state". This is the statutory question: were goods EVER
        // issued against this invoice, and does this jurisdiction permit a
        // definitive goods invoice that precedes them?
        //
        // The predicate is `hasEverIssuedGoods()`, NOT `hasGoodsIssued()`
        // (D-29 / fiscal N-5). The latter nets prior returns — it answers "are
        // units still out?" — so using it here would refuse an invoice that WAS
        // delivered and then fully returned, permanently, on the fiscal
        // chokepoint.
        if ($this->resolver->hasEverIssuedGoods($invoice)) {
            return DeliveryComplianceStatus::compliant();
        }

        $company = Company::query()->find($invoice->company_id);

        if ($company === null) {
            // Unreachable for a persisted document; fail CLOSED rather than
            // assume a policy, per D-27 ("no caller may invent a policy").
            $resolvedPolicy = ResolvedPreDeliveryInvoicingPolicy::fromSystemDefault();
        } else {
            // Throws PreDeliveryInvoicingNotSupportedException if any rung
            // resolves `allow` — which is unreachable by design (T25a).
            $resolvedPolicy = $this->policyResolver->resolveForCompany($company);
        }

        if (! $resolvedPolicy->requiresDeliveryFirst()) {
            return DeliveryComplianceStatus::compliant();
        }

        [$canAutoConfirm, $blockedReason] = $this->assessGuidedDeliveryFeasibility($invoice);

        return new DeliveryComplianceStatus(
            DeliveryComplianceCode::DeliveryRequiredBeforeInvoice,
            __('documents.pre_delivery_invoicing.refused'),
            // Standalone: there are no draft delivery notes to offer. The guided
            // flow CREATES one (T25c) rather than confirming an existing one.
            [],
            $canAutoConfirm,
            $resolvedPolicy,
            $blockedReason,
        );
    }

    /**
     * T25e — record, ON THE INVOICE, the policy that was in force when it was
     * posted and the delivery state that satisfied it.
     *
     * ── WHY AN AUDIT STAMP AND NOT AN ACKNOWLEDGEMENT ── (inv R-8)
     * There is no "I accept the risk" opt-out anywhere in 3E, and there must not
     * be: such a design is only reachable under `allow`, which the resolver
     * refuses. This writes no decision. It writes what was TRUE, so a later audit
     * can tell three populations apart that otherwise look identical in the
     * ledger: posted with goods issued / posted before this policy existed /
     * posted under a policy that permitted it.
     *
     * ── WHY IT IS SAFE TO WRITE ON A FISCAL DOCUMENT ──
     * The invoice fiscal hash covers exactly `document_number`, `posted_at`,
     * `total` and `currency` (`DocumentPostingService::postWithFiscalChain()`);
     * `payload` is NOT an input. And `enforce_document_immutability()` fires only
     * when `OLD.fiscal_status = 'SEALED'` and does not list `payload` among its
     * protected columns. The caller writes this BEFORE the seal anyway, so the
     * stamp and the seal are one atomic act.
     *
     * Merged into the existing payload, never assigned over it — the same idiom
     * as `RefundService::appendDecision()` — so a later append cannot clobber it
     * and it cannot clobber a later append.
     */
    public function stampDeliveryPolicyDecision(Document $invoice): void
    {
        $company = Company::query()->find($invoice->company_id);

        $resolvedPolicy = $company === null
            ? ResolvedPreDeliveryInvoicingPolicy::fromSystemDefault()
            : $this->policyResolver->resolveForCompany($company);

        $invoice->update([
            'payload' => array_merge($invoice->payload ?? [], [
                self::STAMP_KEY => [
                    'policy' => $resolvedPolicy->policy->value,
                    'policy_source' => $resolvedPolicy->source,
                    // BOTH predicates, deliberately. `has_ever_issued_goods` is
                    // what the gate enforced on; `has_goods_issued` nets prior
                    // returns. Recording only one would leave a later auditor
                    // unable to tell "never delivered" from "delivered and
                    // returned" — the exact distinction D-29 exists for.
                    'has_ever_issued_goods' => $this->resolver->hasEverIssuedGoods($invoice),
                    'has_goods_issued' => $this->resolver->hasGoodsIssued($invoice),
                    'delivery_note_ids' => $this->resolver->confirmedDeliveryNoteIdsFor($invoice),
                    'stamped_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }

    /**
     * Can the guided "create & confirm a delivery note now" path actually run for
     * this invoice?
     *
     * It can when every physical line names a product the company owns and a
     * location can be resolved for it. When it cannot, saying so with a typed
     * reason is the difference between a refusal the operator can act on and one
     * they route around.
     *
     * @return array{0: bool, 1: string|null}
     */
    public function assessGuidedDeliveryFeasibility(Document $invoice): array
    {
        $physicalLines = 0;

        foreach ($invoice->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $product = Product::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('company_id', $invoice->company_id)
                ->find($line->product_id);

            if ($product === null || ! $product->is_physical) {
                continue;
            }

            $physicalLines++;

            if (($line->location_id ?? $invoice->location_id) === null
                && ! $this->hasDefaultLocation($invoice)) {
                return [false, 'NO_RESOLVABLE_LOCATION'];
            }
        }

        if ($physicalLines === 0) {
            return [false, 'NO_PHYSICAL_LINES'];
        }

        return [true, null];
    }

    private function hasDefaultLocation(Document $invoice): bool
    {
        return Location::query()
            ->where('company_id', $invoice->company_id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->exists();
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
