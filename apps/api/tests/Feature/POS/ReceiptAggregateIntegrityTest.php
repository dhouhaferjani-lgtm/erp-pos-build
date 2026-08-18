<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
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
        $receipt = $this->createReceipt(attributes: [
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'discount_amount' => '0.500',
            'cash_rounding_adjustment' => $roundingAdjustment,
            'total' => $expectedTotal,
        ]);

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

    public function test_pre_fiscal_return_detail_projects_one_consistent_magnitude_document(): void
    {
        $original = $this->createReceipt('SALE');
        $receipt = $this->createReceipt('SALE', attributes: [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::Other,
            'fiscal_event_id' => null,
            'currency' => 'TND',
            'subtotal' => '-5.000',
            'tax_amount' => '-0.250',
            'discount_amount' => '0.500',
            'cash_rounding_adjustment' => null,
            'total' => '-5.750',
        ]);
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'LEGACY-RETURN',
            'product_name' => 'Legacy return line',
            'quantity' => '-1.2500',
            'unit' => 'pc',
            'unit_price' => '4.000',
            'line_total' => '-5.000',
            'tax_rate' => '5.00',
            'tax_amount' => '-0.250',
            'discount_amount' => '0.500',
        ]);
        $this->createVatDetail($receipt, '5.00', '-5.000', '-0.250', '-5.250');

        $data = $this->getJson('/api/v1/pos/receipts/'.$receipt->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('REFUND', $data['invoice_type_code']);
        $this->assertSame('5.000', $data['subtotal']);
        $this->assertSame('0.250', $data['tax_amount']);
        $this->assertSame('-0.500', $data['discount_amount']);
        $this->assertSame('5.750', $data['total']);
        $this->assertSame('1.2500', $data['lines'][0]['quantity']);
        $this->assertSame('-0.500', $data['lines'][0]['discount_amount']);
        $this->assertSame('0.250', $data['lines'][0]['vat_amount']);
        $this->assertSame('5.000', $data['lines'][0]['line_total']);
        $this->assertSame('5.000', $data['vat_details'][0]['net_amount']);
        $this->assertSame('0.250', $data['vat_details'][0]['vat_amount']);
        $this->assertSame('5.250', $data['vat_details'][0]['gross_amount']);

        $calculatedTotal = bcsub(
            bcadd($data['subtotal'], $data['tax_amount'], 3),
            $data['discount_amount'],
            3,
        );
        $this->assertSame(0, bccomp($data['total'], $calculatedTotal, 3));
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
