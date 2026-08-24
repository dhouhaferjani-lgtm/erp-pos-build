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
     */
    private function receiptWithSealedVat(string $total, string $taxAmount, array $sealed): Receipt
    {
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

        // Every FK is passed explicitly: ReceiptFactory's defaults would spin up
        // their own tenant-less Company through the nested factories.
        $receipt = Receipt::factory()
            ->withTotal($total, $taxAmount)
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'terminal_id' => $terminal->id,
                'cashier_id' => $cashier->id,
                'currency' => 'TND',
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
