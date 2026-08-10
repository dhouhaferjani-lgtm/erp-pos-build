<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\PostingContext;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Document\Domain\Services\DeliveryComplianceGate;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E, fix round 1 — **fiscal F-1**, ORCHESTRATOR RULING (b).
 *
 * The Workshop work-order → invoice adapter is the second production caller of
 * `DocumentPostingService::post()`. It was never swept by T25b, and the Workshop
 * module has **no stock-issuance lane at all**: a WO part moves no stock and
 * produces no delivery note, so `hasEverIssuedGoods()` is false for every
 * WO-generated invoice by construction and NO compliant path is reachable from
 * inside the transition's own transaction.
 *
 * The ruling is a recorded, TESTED exemption. This class is that record. It
 * exists to pin the exemption's **boundaries**, because an exemption whose edges
 * are untested is indistinguishable from a hole:
 *
 *   1. a WO-generated invoice posts;
 *   2. a NON-WO standalone invoice still refuses — the exemption is not a
 *      widening of the gate;
 *   3. claiming the WO context for a document that is not WO-generated does NOT
 *      exempt it — the typed context and the document shape must BOTH hold;
 *   4. the exemption is written onto the audit stamp, so an auditor can find
 *      every invoice that used it.
 *
 * ── 🚩 DO NOT READ THE WORKSHOP SUITE AS THE VERDICT ON THIS EXEMPTION ──
 * (corrected in fix round 2, fiscal N-3; the earlier wording here said those
 * tests "must be green" and would send a future reader hunting a bug that is not
 * in this code.)
 *
 * The three Workshop adapter classes
 * (`DocumentGenerationAdapterStripsSubToleranceDiscountTest`,
 * `DocumentLineWorkOrderLineIdRetentionTest`,
 * `DocumentGenerationAdapterDesignationTest`) sit at **4 passed / 5 failed**, and
 * that residue is NOT the exemption failing. The 5 fail on
 * `UnpostableDocumentGlException [negative_residual]`, which was **proven red at
 * the lane base `6626cb373`** by replacing `DocumentPostingService` with its base
 * content — a version containing zero references to the delivery gate — and
 * observing the identical failures.
 *
 * Root cause is a separate, ticketed CRITICAL: the WO adapter persists a
 * tax-INCLUSIVE `line_total`
 * (`docs/superpowers/tickets/2026-08-07-wo-quote-tax-inclusive-line-total.md`;
 * its one-line fix turns 4 of the 5 green, and the 5th is a second WO totals
 * defect folded into
 * `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`).
 *
 * Full reasoning and the probe transcript: task report **DEVIATION 6**.
 * THIS class is the exemption's own evidence and is expected fully green.
 */
class WorkOrderInvoiceDeliveryExemptionTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    /**
     * A REAL work order — `documents.work_order_id` carries a foreign key, so a
     * bare UUID would only prove the FK exists.
     */
    private function dpWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'customer_partner_id' => $this->dpPartner->id,
        ]);
    }

    public function test_a_work_order_generated_invoice_posts_despite_no_delivery(): void
    {
        $invoice = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine('2.0000')],
            ['work_order_id' => $this->dpWorkOrder()->id],
        );

        $posted = app(DocumentPostingService::class)
            ->post($invoice, PostingContext::WorkOrderGeneratedInvoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
        $this->assertNotNull($posted->fiscal_hash);
    }

    /**
     * 🚨 THE BOUNDARY. The exemption must not become the rule: an ordinary
     * standalone goods invoice, posted through the ordinary caller, still refuses.
     */
    public function test_a_non_work_order_standalone_invoice_still_refuses(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->expectException(DeliveryRequiredBeforeInvoiceException::class);

        app(DocumentPostingService::class)->post($invoice);
    }

    /**
     * 🚨 THE SECOND BOUNDARY. The typed context is not a master key: a caller that
     * claims it for a document with no `work_order_id` gets the refusal anyway.
     * Both halves must hold, so neither a stamped column nor a mis-passed enum can
     * open the gate on its own.
     */
    public function test_claiming_the_work_order_context_for_a_non_work_order_invoice_still_refuses(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->expectException(DeliveryRequiredBeforeInvoiceException::class);

        app(DocumentPostingService::class)
            ->post($invoice, PostingContext::WorkOrderGeneratedInvoice);
    }

    /**
     * 🚨 FIX ROUND 2 / fiscal N-2, THE THIRD BOUNDARY — the exemption covers ONE
     * VERDICT.
     *
     * `isExemptFromDeliveryRequirement()` is consulted only for
     * `DeliveryRequiredBeforeInvoice`. That claim was asserted in prose and
     * nowhere in a test, which is the same as not making it: a later edit that
     * moved the check one branch outward would let a work order post over a
     * delivery lane that EXISTS and is in the wrong state.
     *
     * A DRAFT delivery note is exactly that state — the goods lane exists, it is
     * simply not confirmed — so the refusal must stand even for a work order.
     */
    public function test_the_exemption_does_not_cover_a_draft_delivery_note_verdict(): void
    {
        $invoice = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine('2.0000')],
            ['work_order_id' => $this->dpWorkOrder()->id],
        );

        $draftNote = $this->dpDraftDeliveryNote([$this->dpPhysicalLine('2.0000')]);
        $this->dpLinkConvertedShape($invoice, [$draftNote]);

        try {
            app(DocumentPostingService::class)
                ->post($invoice, PostingContext::WorkOrderGeneratedInvoice);
            $this->fail('A draft delivery note must refuse even for a work-order invoice.');
        } catch (\DomainException $e) {
            // The GENERIC refusal, not the typed pre-delivery one: this is an
            // operational state, not the statutory question the exemption answers.
            $this->assertNotInstanceOf(DeliveryRequiredBeforeInvoiceException::class, $e);
            $this->assertStringContainsString('confirmed', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
    }

    /**
     * The mirror state: the delivery lane exists, the note is CONFIRMED, but its
     * lines are not fully delivered. Still refuses.
     */
    public function test_the_exemption_does_not_cover_an_incomplete_delivery_verdict(): void
    {
        $invoice = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine('2.0000')],
            ['work_order_id' => $this->dpWorkOrder()->id],
        );

        // A note in Confirmed status whose line records LESS delivered than
        // ordered — written directly, because the real service back-fills
        // `quantity_delivered = quantity` on confirm and could not produce it.
        $partialNote = $this->dpDraftDeliveryNote(
            [$this->dpPhysicalLine('2.0000') + ['quantity_delivered' => '1.0000']],
            ['status' => DocumentStatus::Confirmed],
        );
        $this->dpLinkConvertedShape($invoice, [$partialNote]);

        try {
            app(DocumentPostingService::class)
                ->post($invoice, PostingContext::WorkOrderGeneratedInvoice);
            $this->fail('An incomplete delivery must refuse even for a work-order invoice.');
        } catch (\DomainException $e) {
            $this->assertNotInstanceOf(DeliveryRequiredBeforeInvoiceException::class, $e);
            $this->assertStringContainsString('fully delivered', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
    }

    /**
     * An exemption nobody can find afterwards is a hole. The T25e stamp records
     * the posting context, so the invoiced-not-delivered register can say WHY a
     * row is in it.
     */
    public function test_the_audit_stamp_records_that_the_exemption_was_used(): void
    {
        $invoice = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine('2.0000')],
            ['work_order_id' => $this->dpWorkOrder()->id],
        );

        $posted = app(DocumentPostingService::class)
            ->post($invoice, PostingContext::WorkOrderGeneratedInvoice);

        $stamp = $posted->payload[DeliveryComplianceGate::STAMP_KEY] ?? null;

        $this->assertIsArray($stamp);
        $this->assertSame(
            PostingContext::WorkOrderGeneratedInvoice->value,
            $stamp['posting_context'],
        );
        $this->assertTrue($stamp['delivery_requirement_exempted']);
        $this->assertFalse($stamp['has_ever_issued_goods']);
    }

    /**
     * The ordinary path stamps the ordinary context, so `posting_context` is a
     * usable filter rather than a key that only ever appears on exempt rows.
     */
    public function test_the_ordinary_path_stamps_the_standard_context_and_no_exemption(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('2.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $posted = app(DocumentPostingService::class)->post($invoice);

        $stamp = $posted->payload[DeliveryComplianceGate::STAMP_KEY] ?? null;

        $this->assertIsArray($stamp);
        $this->assertSame(PostingContext::Standard->value, $stamp['posting_context']);
        $this->assertFalse($stamp['delivery_requirement_exempted']);
    }
}
