<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\DTOs\PosVatRateAllocation;
use App\Modules\Accounting\Domain\Enums\PosVatRefusalReason;
use App\Modules\Accounting\Domain\Exceptions\PosVatProjectionRefusedException;
use App\Modules\Accounting\Domain\Services\PosReceiptVatAllocator;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W4-9 — the arithmetic of apportioning a receipt's SEALED VAT across its
 * tender legs, isolated from the GL.
 *
 * The property that matters is not "each leg gets a sensible share" but
 * "the shares add back to the sealed figure EXACTLY, for every rate". A
 * millime lost per rate per split-tender receipt is a VAT declaration that
 * drifts from the ledger a little more every trading day — the slow version of
 * the defect this lane fixes.
 */
final class PosReceiptVatAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private PosReceiptVatAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = $this->app->make(PosReceiptVatAllocator::class);
    }

    public function test_single_tender_reproduces_the_sealed_numbers_verbatim(): void
    {
        $receipt = $this->receiptWithSealedVat('690.000', '90.000', [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ]);

        $split = $this->allocator->allocate($receipt, ['690.000'], 3)[0];

        $this->assertSame('690.000', $split->tenderAmount);
        $this->assertSame('600.000', $split->netRevenueAmount);
        $this->assertSame('90.000', $split->totalVat());
        $this->assertSame(
            ['7.00' => '7.000', '13.00' => '26.000', '19.00' => '57.000'],
            $this->byRate($split->vatAllocations),
        );
    }

    public function test_split_tender_shares_add_back_to_the_sealed_vat_for_every_rate(): void
    {
        // 400 / 290 of 690: no rate divides evenly at scale 3, so every rate
        // leaves a truncation residual that has to land somewhere.
        $receipt = $this->receiptWithSealedVat('690.000', '90.000', [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ]);

        $splits = $this->allocator->allocate($receipt, ['400.000', '290.000'], 3);

        $this->assertCount(2, $splits);

        // The residual lands on the LARGEST leg (400.000), deterministically.
        $this->assertSame(
            ['7.00' => '4.058', '13.00' => '15.073', '19.00' => '33.044'],
            $this->byRate($splits[0]->vatAllocations),
        );
        $this->assertSame(
            ['7.00' => '2.942', '13.00' => '10.927', '19.00' => '23.956'],
            $this->byRate($splits[1]->vatAllocations),
        );

        // The invariant, stated directly: per rate, and in total.
        foreach (['7.00' => '7.000', '13.00' => '26.000', '19.00' => '57.000'] as $rate => $sealed) {
            $this->assertSame(
                $sealed,
                bcadd(
                    $this->byRate($splits[0]->vatAllocations)[$rate],
                    $this->byRate($splits[1]->vatAllocations)[$rate],
                    3,
                ),
                'rate '.$rate.' must reconstruct the sealed VAT exactly',
            );
        }
        $this->assertSame('90.000', bcadd($splits[0]->totalVat(), $splits[1]->totalVat(), 3));
        $this->assertSame('600.000', bcadd($splits[0]->netRevenueAmount, $splits[1]->netRevenueAmount, 3));
    }

    public function test_a_fully_netted_leg_takes_no_vat_and_the_survivor_takes_it_all(): void
    {
        // Cash-rounding change can net a leg to zero; it posts nothing, so it
        // must not be handed a share of the VAT either.
        $receipt = $this->receiptWithSealedVat('119.000', '19.000', [['19.00', '100.000', '19.000']]);

        $splits = $this->allocator->allocate($receipt, ['0.000', '119.000'], 3);

        $this->assertSame('0.000', $splits[0]->totalVat());
        $this->assertSame('0.000', $splits[0]->netRevenueAmount);
        $this->assertSame('19.000', $splits[1]->totalVat());
        $this->assertSame('100.000', $splits[1]->netRevenueAmount);
    }

    public function test_a_hundred_percent_comp_is_split_not_refused(): void
    {
        // W4-9 gate r2, R2-1 — the limiting case. The whole ticket is comped, so
        // the tender is 0.000 and the discount is the entire gross. Before the
        // fix the receipt-level guard compared the sealed VAT against the bare
        // TENDER and refused, and nothing catches that refusal: the projection
        // job failed forever, while the declaration still reported 90.000 of VAT
        // from the same sealed rows.
        //
        // Asserted at the allocator rather than end-to-end on purpose: a comp
        // receipt cannot be PROJECTED at all on PostgreSQL, because its only
        // payment line is 0.000 and `pos_receipt_payments` carries
        // CHECK (amount > 0). That is a pre-existing POS-core/schema gap
        // upstream of this lane (see the handback residuals); the guard this
        // test pins is the part W4-9 owns.
        $receipt = $this->receiptWithSealedVat('0.000', '90.000', [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ], discountAmount: '690.000');

        $split = $this->allocator->allocate($receipt, ['0.000'], 3)[0];

        $this->assertSame('0.000', $split->tenderAmount);
        $this->assertSame('690.000', $split->discountAmount);
        $this->assertSame('600.000', $split->netRevenueAmount);
        $this->assertSame('90.000', $split->totalVat());
        // Dr 709 690.000 / Cr 70x 600.000 / Cr 4457 90.000 — and it balances.
        $split->assertReconciles($receipt->id, '0.000');
    }

    public function test_a_discount_larger_than_the_net_subtotal_is_split_not_refused(): void
    {
        // The general R2-1 shape: `cartTotals.ts` clamps the discount to the
        // GROSS subtotal, so 620.000 off a 690.000 gross ticket is
        // device-authorable and signed (`600 + 90 == 70 + 620`).
        $receipt = $this->receiptWithSealedVat('70.000', '90.000', [
            ['19.00', '600.000', '90.000'],
        ], discountAmount: '620.000');

        $split = $this->allocator->allocate($receipt, ['70.000'], 3)[0];

        $this->assertSame('620.000', $split->discountAmount);
        $this->assertSame('600.000', $split->netRevenueAmount);
        $split->assertReconciles($receipt->id, '70.000');
    }

    public function test_a_multi_leg_comp_still_assigns_the_whole_discount(): void
    {
        // R2-3 — with a zero tender and more than one leg the apportionment
        // cannot divide proportionally. Zeroing every share (the old behaviour)
        // let `netRevenue` fall back silently to the post-discount base while
        // `assertReconciles()` still passed, because `0 + 0 − 0 == 0`. The whole
        // discount now lands on the residual leg.
        $receipt = $this->receiptWithSealedVat('0.000', '19.000', [
            ['19.00', '100.000', '19.000'],
        ], discountAmount: '119.000');

        $splits = $this->allocator->allocate($receipt, ['0.000', '0.000'], 3);

        $this->assertSame(
            '119.000',
            bcadd($splits[0]->discountAmount, $splits[1]->discountAmount, 3),
            'the whole discount must be assigned, not silently dropped',
        );
        $this->assertSame(
            '100.000',
            bcadd($splits[0]->netRevenueAmount, $splits[1]->netRevenueAmount, 3),
            'revenue must stay on the sealed pre-discount base',
        );
    }

    public function test_a_receipt_with_no_vat_at_all_is_vat_free_not_refused(): void
    {
        $receipt = $this->receiptWithSealedVat('100.000', '0.000', []);

        $split = $this->allocator->allocate($receipt, ['100.000'], 3)[0];

        $this->assertTrue($split->isVatFree);
        $this->assertSame('100.000', $split->netRevenueAmount);
        $this->assertSame([], $split->vatAllocations);
        // …and it still passes the GL writer's preflight.
        $split->assertReconciles($receipt->id, '100.000');
    }

    public function test_missing_sealed_rows_on_a_vat_bearing_receipt_is_refused(): void
    {
        $receipt = $this->receiptWithSealedVat('119.000', '19.000', []);

        $this->expectException(PosVatProjectionRefusedException::class);
        try {
            $this->allocator->allocate($receipt, ['119.000'], 3);
        } catch (PosVatProjectionRefusedException $e) {
            $this->assertSame(PosVatRefusalReason::MissingSealedVatDetails, $e->reason);
            throw $e;
        }
    }

    public function test_sealed_rows_disagreeing_with_the_receipt_tax_amount_is_refused(): void
    {
        $receipt = $this->receiptWithSealedVat('119.000', '19.000', [['19.00', '100.000', '18.000']]);

        try {
            $this->allocator->allocate($receipt, ['119.000'], 3);
            $this->fail('two disagreeing authorities for the same VAT must refuse');
        } catch (PosVatProjectionRefusedException $e) {
            $this->assertSame(PosVatRefusalReason::SealedVatDisagreesWithReceipt, $e->reason);
        }
    }

    public function test_vat_above_the_retained_tender_is_refused_rather_than_booked_as_negative_revenue(): void
    {
        $receipt = $this->receiptWithSealedVat('119.000', '19.000', [['19.00', '100.000', '19.000']]);

        try {
            $this->allocator->allocate($receipt, ['10.000'], 3);
            $this->fail('VAT above the tender would force a negative revenue credit');
        } catch (PosVatProjectionRefusedException $e) {
            $this->assertSame(PosVatRefusalReason::VatExceedsTender, $e->reason);
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param  numeric-string  $total
     * @param  numeric-string  $taxAmount
     * @param  list<array{0: string, 1: string, 2: string}>  $sealed  [rate, net, vat]
     * @param  numeric-string  $discountAmount
     */
    private function receiptWithSealedVat(
        string $total,
        string $taxAmount,
        array $sealed,
        string $discountAmount = '0.000',
    ): Receipt {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        // `pos_receipts` carries a PG CHECK
        // `total = subtotal + tax_amount − discount_amount + rounding`, so the
        // four columns have to be set as a consistent set — `withTotal()` alone
        // derives `subtotal = total − tax` and would violate it on any discounted
        // fixture. The subtotal is the SEALED pre-discount base (Σ net_amount),
        // which is what the device seals and the declaration reports.
        $subtotal = $sealed === []
            ? bcsub($total, $taxAmount, 3)
            : array_reduce(
                $sealed,
                static fn (string $carry, array $row): string => bcadd($carry, $row[1], 3),
                '0.000',
            );

        // Every FK is passed explicitly: ReceiptFactory's defaults would spin up
        // their own tenant-less Company through the nested factories.
        $receipt = Receipt::factory()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'terminal_id' => $terminal->id,
                'cashier_id' => $cashier->id,
                'currency' => 'TND',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'discount_amount' => $discountAmount,
            ]);

        foreach ($sealed as [$rate, $net, $vat]) {
            ReceiptVatDetail::create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receipt->id,
                'tax_category' => 'S',
                'tax_rate' => $rate,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => bcadd($net, $vat, 3),
            ]);
        }

        return $receipt;
    }

    /**
     * @param  list<PosVatRateAllocation>  $allocations
     * @return array<string, string>
     */
    private function byRate(array $allocations): array
    {
        $out = [];
        foreach ($allocations as $allocation) {
            $out[$allocation->taxRate] = $allocation->vatAmount;
        }

        return $out;
    }
}
