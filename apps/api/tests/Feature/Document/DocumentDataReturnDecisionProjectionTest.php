<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T16 (plan CF §3) — the recorded goods decision is visible on the document.
 *
 * CF-D5's last mile. Without this projection the owner's "the choice is recorded"
 * exists only in the database: a cancelled invoice's detail page could not say what was
 * decided about the goods, nor link to the return note it produced — and the whole
 * point of the ruling is that the decision is explicit rather than inferred from an
 * absence.
 *
 * `DocumentData` emits payload-DERIVED projections (`delivery_note_ids`,
 * `converted_to_order_id`, `fully_delivered`, …) but never the raw `payload`. This
 * follows that precedent exactly and narrows the audit list to the decision that TOOK
 * EFFECT plus a count.
 */
final class DocumentDataReturnDecisionProjectionTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private string $issuedOn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-doc-data');
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
        $this->issuedOn = Carbon::today()->subDays(5)->toDateString();
    }

    public function test_no_decision_projects_null_and_a_zero_count(): void
    {
        $invoice = $this->deliveredInvoice();

        $data = DocumentData::fromModel($invoice);

        self::assertNull($data->return_decision);
        self::assertSame(0, $data->return_decision_count);
    }

    public function test_the_accepted_decision_is_projected_with_its_return_note(): void
    {
        $invoice = $this->deliveredInvoice();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])->assertOk();

        $data = DocumentData::fromModel($invoice->refresh());

        self::assertNotNull($data->return_decision);
        self::assertSame(ReturnDecisionMode::WillReturn, $data->return_decision->mode);
        self::assertNull($data->return_decision->returned_on);
        self::assertNotNull($data->return_decision->return_note_id);
        self::assertSame($this->cfUser->id, $data->return_decision->decided_by);
        self::assertNotSame('', $data->return_decision->decided_at);
        self::assertSame(1, $data->return_decision_count);
    }

    public function test_a_backdated_decision_projects_its_return_date(): void
    {
        $invoice = $this->deliveredInvoice();
        $returnedOn = Carbon::today()->subDay()->toDateString();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => [
                    'mode' => ReturnDecisionMode::AlreadyReturned->value,
                    'returned_on' => $returnedOn,
                ],
            ])->assertOk();

        $data = DocumentData::fromModel($invoice->refresh());

        self::assertNotNull($data->return_decision);
        self::assertSame(ReturnDecisionMode::AlreadyReturned, $data->return_decision->mode);
        self::assertSame($returnedOn, $data->return_decision->returned_on);
    }

    /**
     * A REJECTED entry raises the count — support needs to see that a second decision
     * was attempted — but must never be projected AS the decision, which would report a
     * goods outcome that no cancellation applied.
     */
    public function test_a_rejected_entry_raises_the_count_without_becoming_the_decision(): void
    {
        $invoice = $this->deliveredInvoice();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])->assertOk();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Changed my mind',
                'return_decision' => ['mode' => ReturnDecisionMode::NoReturn->value],
            ])->assertStatus(422);

        $data = DocumentData::fromModel($invoice->refresh());

        self::assertNotNull($data->return_decision);
        self::assertSame(ReturnDecisionMode::WillReturn, $data->return_decision->mode);
        self::assertSame(2, $data->return_decision_count);
    }

    /**
     * The projection has to survive the HTTP boundary, since that is the only way the
     * front end ever sees it.
     */
    public function test_the_projection_reaches_the_invoice_detail_endpoint(): void
    {
        $invoice = $this->deliveredInvoice();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::NoReturn->value],
            ])->assertOk();

        $this->actingAs($this->cfUser, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.return_decision.mode', ReturnDecisionMode::NoReturn->value)
            ->assertJsonPath('data.return_decision.return_note_id', null)
            ->assertJsonPath('data.return_decision_count', 1);
    }

    private function deliveredInvoice(): Document
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);

        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        return $invoice->refresh();
    }
}
