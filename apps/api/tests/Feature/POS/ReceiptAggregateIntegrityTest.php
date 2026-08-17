<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptAggregateIntegrityTest extends ReceiptReportingTestCase
{
    #[DataProvider('roundingCases')]
    public function test_detail_preserves_receipt_and_vat_aggregate_identities(
        ?string $roundingAdjustment,
        string $expectedTotal,
    ): void {
        $receipt = $this->createReceipt();
        $receipt->forceFill([
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'discount_amount' => '0.500',
            'cash_rounding_adjustment' => $roundingAdjustment,
            'total' => $expectedTotal,
        ])->saveQuietly();

        $this->createVatDetail($receipt, '7.00', '4.000', '0.280', '4.280');
        $this->createVatDetail($receipt, '19.00', '6.000', '1.620', '7.620');

        $data = $this->getJson('/api/v1/pos/receipts/'.$receipt->id)
            ->assertOk()
            ->json('data');

        $rounding = $data['cash_rounding_adjustment'] ?? '0.000';
        $calculatedTotal = bcadd(
            bcsub(bcadd($data['subtotal'], $data['tax_amount'], 3), $data['discount_amount'], 3),
            $rounding,
            3,
        );
        $this->assertSame(0, bccomp($data['total'], $calculatedTotal, 3));

        $vatNet = '0.000';
        $vatAmount = '0.000';
        $vatGross = '0.000';
        foreach ($data['vat_details'] as $detail) {
            $this->assertSame(
                0,
                bccomp($detail['gross_amount'], bcadd($detail['net_amount'], $detail['vat_amount'], 3), 3),
            );
            $vatNet = bcadd($vatNet, $detail['net_amount'], 3);
            $vatAmount = bcadd($vatAmount, $detail['vat_amount'], 3);
            $vatGross = bcadd($vatGross, $detail['gross_amount'], 3);
        }

        $this->assertSame(0, bccomp($vatNet, $data['subtotal'], 3));
        $this->assertSame(0, bccomp($vatAmount, $data['tax_amount'], 3));
        $this->assertSame(0, bccomp($vatGross, bcadd($vatNet, $vatAmount, 3), 3));
    }

    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function roundingCases(): iterable
    {
        yield 'positive adjustment' => ['0.100', '11.500'];
        yield 'negative adjustment' => ['-0.100', '11.300'];
        yield 'legacy null adjustment' => [null, '11.400'];
    }

    private function createVatDetail(
        Receipt $receipt,
        string $rate,
        string $net,
        string $vat,
        string $gross,
    ): void {
        ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => $rate,
            'net_amount' => $net,
            'vat_amount' => $vat,
            'gross_amount' => $gross,
        ]);
    }
}
