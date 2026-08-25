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

    // =================================================================
    // D-1 (owner ruling 2026-08-25) — receipts sealed on the POST-remise base
    // =================================================================

    /**
     * The ruling's worked example. The device sealed base 524.547 + VAT 65.453
     * on a 640.000 ticket carrying a 50.000 remise, and the customer paid
     * 590.000.
     *
     * Revenue must be the SEALED base, and there must be NO contra-revenue leg:
     * the remise was already deducted from the base on the ticket, so booking it
     * again in `709` would deduct it twice. The pre-D-1 derivation
     * (`net = tender + discount − vat`) would book 574.547 — a revenue base that
     * is neither the sealed one (524.547) nor the pre-remise one (569.000), and
     * an entry that does not balance once the 709 debit is added.
     */
    public function test_a_post_remise_receipt_books_revenue_at_the_sealed_base_with_no_contra_leg(): void
    {
        $receipt = $this->receiptWithPostRemiseSealedVat('590.000', '65.453', '50.000', [
            ['0.00', '63.609', '0.000', '5.391'],
            ['7.00', '92.188', '6.453', '8.359'],
            ['13.00', '184.375', '23.969', '17.656'],
            ['19.00', '184.375', '35.031', '18.594'],
        ]);

        $split = $this->allocator->allocate($receipt, ['590.000'], 3)[0];

        $this->assertSame('590.000', $split->tenderAmount);
        $this->assertSame('524.547', $split->netRevenueAmount);
        $this->assertSame('65.453', $split->totalVat());
        // No 709 leg: `hasDiscount()` drives it, and the remise is already out
        // of the base.
        $this->assertSame('0.000', $split->discountAmount);
        $this->assertFalse($split->hasDiscount());
        // Balances by construction: Dr 590.000 == Cr 524.547 + Cr 65.453.
        $this->assertSame(
            0,
            bccomp(bcadd($split->netRevenueAmount, $split->totalVat(), 3), $split->tenderAmount, 3),
        );
    }

    /**
     * The pre-D-1 shape is UNCHANGED — the discriminator is the sealed rows'
     * own `discount_allocated`, so a receipt sealed before the cutover keeps
     * booking revenue on its pre-discount base with the remise as contra.
     * Forward-only in the ledger as well as on the wire.
     */
    public function test_a_pre_remise_receipt_keeps_the_pre_d1_contra_revenue_shape(): void
    {
        // Pre-D-1 arithmetic: the sealed base is the PRE-discount 600.000, so
        // `pos_receipts_totals` requires `total = subtotal + tax − discount`
        // = 600.000 + 90.000 − 50.000 = 640.000. (Only PostgreSQL carries that
        // CHECK, which is why this fixture has to be right there and not merely
        // plausible on sqlite.)
        $receipt = $this->receiptWithSealedVat('640.000', '90.000', [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ], '50.000');

        $split = $this->allocator->allocate($receipt, ['640.000'], 3)[0];

        // net = tender + discount − vat = 640.000 + 50.000 − 90.000, i.e. the
        // sealed PRE-discount base, with the remise as contra-revenue.
        $this->assertSame('600.000', $split->netRevenueAmount);
        $this->assertSame('50.000', $split->discountAmount);
        $this->assertTrue($split->hasDiscount());
    }

    /** A post-remise receipt with no remise at all behaves identically either way. */
    public function test_a_post_remise_receipt_without_a_remise_is_unchanged(): void
    {
        $receipt = $this->receiptWithPostRemiseSealedVat('690.000', '90.000', '0.000', [
            ['7.00', '100.000', '7.000', '0.000'],
            ['13.00', '200.000', '26.000', '0.000'],
            ['19.00', '300.000', '57.000', '0.000'],
        ]);

        $split = $this->allocator->allocate($receipt, ['690.000'], 3)[0];

        $this->assertSame('600.000', $split->netRevenueAmount);
        $this->assertSame('0.000', $split->discountAmount);
    }

    /** Split tender on a post-remise receipt: the shares still add back exactly. */
    public function test_a_post_remise_split_tender_adds_back_to_the_sealed_base_and_vat(): void
    {
        $receipt = $this->receiptWithPostRemiseSealedVat('590.000', '65.453', '50.000', [
            ['0.00', '63.609', '0.000', '5.391'],
            ['7.00', '92.188', '6.453', '8.359'],
            ['13.00', '184.375', '23.969', '17.656'],
            ['19.00', '184.375', '35.031', '18.594'],
        ]);

        $splits = $this->allocator->allocate($receipt, ['400.000', '190.000'], 3);

        $net = '0.000';
        $vat = '0.000';
        foreach ($splits as $split) {
            $net = bcadd($net, $split->netRevenueAmount, 3);
            $vat = bcadd($vat, $split->totalVat(), 3);
            $this->assertSame('0.000', $split->discountAmount);
        }

        $this->assertSame('524.547', $net);
        $this->assertSame('65.453', $vat);
    }

    /**
     * A 100 %-comp sealed at v5: every group is zero, nothing is tendered, and
     * the split is a legitimate all-zero one rather than a refusal.
     */
    public function test_a_post_remise_hundred_percent_comp_is_all_zero_not_refused(): void
    {
        $receipt = $this->receiptWithPostRemiseSealedVat('0.000', '0.000', '640.000', [
            ['0.00', '0.000', '0.000', '69.000'],
            ['7.00', '0.000', '0.000', '107.000'],
            ['13.00', '0.000', '0.000', '226.000'],
            ['19.00', '0.000', '0.000', '238.000'],
        ]);

        $split = $this->allocator->allocate($receipt, ['0.000'], 3)[0];

        $this->assertSame('0.000', $split->netRevenueAmount);
        $this->assertSame('0.000', $split->totalVat());
        $this->assertSame('0.000', $split->discountAmount);
    }

    /**
     * A sealed set where only SOME rows carry `discount_allocated` cannot be
     * read as either version, and a guess would book a wrong base. Refuse.
     */
    public function test_a_sealed_set_mixing_the_two_eras_is_refused(): void
    {
        $receipt = $this->receiptWithPostRemiseSealedVat('590.000', '65.453', '50.000', [
            ['0.00', '63.609', '0.000', '5.391'],
            ['7.00', '92.188', '6.453', null],
            ['13.00', '184.375', '23.969', '17.656'],
            ['19.00', '184.375', '35.031', '18.594'],
        ]);

        $this->expectException(PosVatProjectionRefusedException::class);

        $this->allocator->allocate($receipt, ['590.000'], 3);
    }

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
     * A receipt sealed at `event_version >= 5` (D-1): `pos_receipts.subtotal`
     * and every sealed row are the POST-remise taxable base, and each row
     * carries its ventilated share of the remise.
     *
     * `$sealed` rows are `[rate, net, vat, discount_allocated]`; a `null` share
     * seeds a pre-D-1 row inside an otherwise post-D-1 set, which is the mixed
     * corruption case.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null}>  $sealed
     */
    private function receiptWithPostRemiseSealedVat(
        string $total,
        string $taxAmount,
        string $discountAmount,
        array $sealed,
    ): Receipt {
        $receipt = $this->receiptWithSealedVat($total, $taxAmount, [], $discountAmount);

        // The header is the POST-remise base: `total = subtotal + tax_amount`,
        // which is the second arm of the widened `pos_receipts_totals` CHECK.
        $receipt->forceFill(['subtotal' => bcsub($total, $taxAmount, 3)])->save();

        foreach ($sealed as [$rate, $net, $vat, $allocated]) {
            ReceiptVatDetail::create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receipt->id,
                'tax_category' => 'S',
                'tax_rate' => $rate,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => bcadd($net, $vat, 3),
                'discount_allocated' => $allocated,
            ]);
        }

        return $receipt->refresh();
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
