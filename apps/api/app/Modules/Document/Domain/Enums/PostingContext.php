<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * WHO is asking `DocumentPostingService::post()` to post, stated by the caller.
 *
 * ── WHY THIS EXISTS (Wave 3 fix round 1, fiscal F-1; ORCHESTRATOR RULING S3 (b)) ──
 * T25b put the delivery-compliance refusal on the posting chokepoint, which is
 * correct: every caller must pass it. But the sweep missed the second production
 * caller — {@see \App\Modules\Workshop\WorkOrder\Infrastructure\Adapters\DocumentGenerationAdapter}
 * — and the Workshop work-order → invoice flow has **no stock-issuance lane at
 * all**: WO parts move no stock, produce no delivery note and produce no
 * movement, anywhere, today. So `hasEverIssuedGoods()` is false for every
 * WO-generated invoice **by construction**, and there is no reachable compliant
 * path: the composite `create-delivery-and-post` needs a Confirmed invoice over
 * HTTP, while the WO transition creates → confirms → posts inside one service
 * transaction.
 *
 * Refusing there would block a real, delivered repair job on a fact the system
 * has no way to record; auto-creating a delivery note there would fabricate a
 * goods movement that never happened. The ruling is therefore a **recorded,
 * tested exemption**, plus a ticket for the real gap
 * (`docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`).
 *
 * ── WHY A TYPED CALLER CONTEXT AND NOT A DOCUMENT-SHAPE TEST ──
 * A shape test (`type === Invoice && work_order_id !== null`) is a rule any
 * FUTURE path could ride simply by stamping `work_order_id` on a document it
 * built — including an HTTP path that has a perfectly good delivery lane. The
 * exemption must be something a caller has to ASK for, in code, at the call
 * site, so that adding a new exempt path is a deliberate edit to this enum and
 * not an emergent property of a column. The document shape is still checked, as
 * a second condition — see
 * {@see \App\Modules\Document\Domain\Services\DocumentPostingService::isExemptFromDeliveryRequirement()}
 * — so the context alone cannot exempt an unrelated document either.
 */
enum PostingContext: string
{
    /**
     * Every ordinary caller: the HTTP posting endpoints, the converters, the
     * composite. The full delivery-compliance gate applies.
     */
    case Standard = 'standard';

    /**
     * The Workshop work-order → invoice adapter, and nothing else.
     *
     * Exempt from the *pre-delivery* refusal ONLY (
     * {@see DeliveryComplianceCode::DeliveryRequiredBeforeInvoice}). Every other
     * verdict the gate can return — draft delivery notes, an incomplete
     * delivery, an order with no notes — still refuses, because those describe a
     * delivery lane that exists and is in the wrong state, which is a different
     * fact from "this module has no delivery lane".
     */
    case WorkOrderGeneratedInvoice = 'work_order_generated_invoice';

    /**
     * Does this caller claim the pre-delivery exemption?
     *
     * Deliberately a method on the enum rather than a `match` at the call site:
     * the set of exempt contexts is a fiscal fact and belongs in one place.
     */
    public function claimsPreDeliveryExemption(): bool
    {
        return $this === self::WorkOrderGeneratedInvoice;
    }
}
