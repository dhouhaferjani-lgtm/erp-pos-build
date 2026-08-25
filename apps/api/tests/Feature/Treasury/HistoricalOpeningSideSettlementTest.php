<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 fix round r1 / gate F-2 (IMPORTANT) — the fail-closed refusal of
 * historical openings is narrowed to the side that actually books wrong-direction
 * GL, and the discriminator is the one that already exists on base.
 *
 * r1 shipped a blanket refusal on the stated grounds that "there is no column to
 * tell the two apart yet". The gate disproved that: `OpeningBalanceBatchService`
 * writes `opening_balance_import_rows.row_type` = `AR`/`AP` at insert
 * (`:200-218`, from `OpeningBatchType::rowType()`) and links the posted row to
 * the document it minted via `mapped_entity_id` (`markRowsPosted():636-658`).
 * Both are durable and both predate this lane.
 *
 * So refusing the AR side was a CHOICE, and an expensive one: a first-client
 * cutover imports its open receivables as historical openings, and a blanket
 * refusal means those invoices cannot be collected by ANY route — the AP branch
 * of `PaymentController::store()` requires `type === SupplierInvoice` and an
 * opening is minted as `Invoice`, so there is no third door.
 *
 * The narrowed rule (owner-sheet OQ-74, default recorded here):
 * - historical **AR** opening + posted  ⇒ `ReceivableClearing`. A real 411 exists
 *   (the opening JE created it); collecting it is an ordinary collection.
 * - historical **AP** opening           ⇒ REFUSED. Settling it is Dr 401 / Cr bank
 *   through the supplier flow, which does not accept an `Invoice`; admitting it
 *   on the AR path is the Dr bank / Cr 411 defect this lane exists to kill.
 * - side UNRESOLVABLE                   ⇒ REFUSED. Fail closed is only correct
 *   where the evidence is genuinely absent.
 */
final class HistoricalOpeningSideSettlementTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('hist-side');
    }

    public function test_a_historical_ar_opening_can_be_collected_on_the_direct_payment_endpoint(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertCreated();

        $this->assertSame(
            0,
            bccomp((string) PaymentAllocation::query()->where('document_id', $opening->id)->sum('amount'), '40.000', 3),
        );
        // A historical AR opening carries a REAL 411 from the opening JE, so the
        // money CLEARS it. Booking it as a 419 advance would leave the receivable
        // standing and invent a liability the company does not owe.
        $this->assertSame(
            0,
            PaymentAllocation::query()
                ->where('document_id', $opening->id)
                ->where('booked_as_advance', true)
                ->count(),
            'a historical AR opening settles a receivable; it is never an advance',
        );
    }

    public function test_a_historical_ar_opening_is_offered_and_collected_by_the_auto_sweep(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');
        $payment = $this->makeUnallocatedPayment('40.000', $this->customer);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertSame(
            0,
            bccomp((string) PaymentAllocation::query()->where('document_id', $opening->id)->sum('amount'), '40.000', 3),
        );
    }

    public function test_a_historical_ap_opening_is_still_refused(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'historical_opening_provenance');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }

    /**
     * Fail closed where the evidence is genuinely absent: an `is_historical`
     * document with no posted import row behind it (a legacy import, a
     * hand-corrected row) has no provable side, so it is refused.
     */
    public function test_a_historical_opening_whose_side_cannot_be_resolved_is_refused(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');

        // Break the link the resolver reads — the document keeps every marker
        // that identifies it as an opening, and loses only the AR/AP evidence.
        OpeningBalanceImportRow::query()
            ->where('mapped_entity_id', $opening->id)
            ->update(['mapped_entity_id' => null]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.reason', 'historical_opening_provenance');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }
}
