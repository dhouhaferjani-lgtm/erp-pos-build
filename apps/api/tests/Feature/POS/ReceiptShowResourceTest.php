<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Uom\Domain\Entities\Unit;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptShowResourceTest extends ReceiptReportingTestCase
{
    public function test_show_emits_the_exact_allowlisted_detail_shape_from_historical_snapshots(): void
    {
        $receipt = $this->createReceipt();
        $receipt->forceFill([
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'discount_amount' => '0.500',
            'cash_rounding_adjustment' => '0.100',
            'cash_rounding_denomination' => '0.0500',
            'change_due' => '2.000',
            'total' => '11.500',
            'notes' => 'Historical note',
            'canonical_bytes' => '{"secret":"sealed"}',
            'refund_policy_alerts' => [],
        ])->saveQuietly();

        $line = ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'SNAPSHOT-001',
            'product_name' => 'Archived product',
            'quantity' => '1.2500',
            'unit' => 'pc',
            'unit_price' => '11.900',
            'line_total' => '10.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.900',
            'discount_amount' => '0.500',
        ]);
        ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => '19.00',
            'net_amount' => '10.000',
            'vat_amount' => '1.900',
            'gross_amount' => '11.900',
        ]);
        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'Cash',
            'payment_method_code' => 'CASH',
            'amount' => '13.500',
            'card_last_four' => null,
            'instrument_serial' => null,
            'transaction_reference' => 'TX-001',
            'authorization_code' => null,
        ]);

        $response = $this->getJson('/api/v1/pos/receipts/'.$receipt->id)->assertOk();
        $data = $response->json('data');

        $this->assertSame([
            'id', 'receipt_number', 'posted_at', 'invoice_type_code', 'training_flag', 'receipt_type',
            'fiscal_status', 'location_id', 'location_name', 'terminal_id', 'terminal_code', 'cashier_id',
            'cashier_name', 'currency', 'subtotal', 'tax_amount', 'discount_amount',
            'cash_rounding_adjustment', 'cash_rounding_denomination', 'change_due', 'total', 'notes',
            'is_voided', 'voided_at', 'fiscal_hash', 'previous_hash', 'chain_sequence', 'receipt_year',
            'fiscal_event_id', 'synced_at', 'sync_error', 'refund_policy_alerts', 'lines', 'vat_details',
            'payments', 'return_receipts', 'original_receipt_id', 'original_receipt',
        ], array_keys($data));
        $this->assertSame([
            'id', 'line_number', 'product_id', 'product_name', 'product_code', 'quantity',
            'quantity_decimals', 'unit_price', 'discount_amount', 'vat_rate', 'vat_amount',
            'line_total', 'returned_quantity',
        ], array_keys($data['lines'][0]));
        $this->assertSame([
            'id', 'payment_method', 'amount', 'card_last_four', 'instrument_serial',
            'transaction_reference', 'authorization_code',
        ], array_keys($data['payments'][0]));
        $this->assertSame(
            ['tax_rate', 'net_amount', 'vat_amount', 'gross_amount'],
            array_keys($data['vat_details'][0]),
        );

        $response->assertJsonPath('data.lines.0.id', $line->id);
        $response->assertJsonPath('data.lines.0.product_id', null);
        $response->assertJsonPath('data.lines.0.product_code', 'SNAPSHOT-001');
        $response->assertJsonPath('data.lines.0.product_name', 'Archived product');
        $response->assertJsonPath('data.lines.0.quantity_decimals', 4);
        $response->assertJsonPath('data.payments.0.id', $payment->id);
        $response->assertJsonPath('data.payments.0.payment_method', 'Cash');
        $response->assertJsonPath('data.cash_rounding_denomination', '0.050');
        $response->assertJsonPath('data.total', '11.500');
    }

    public function test_show_uses_the_current_unit_precision_without_replacing_the_historical_product_snapshot(): void
    {
        $unit = Unit::factory()->create(['decimal_places' => 2]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
            'sku' => 'CURRENT-001',
        ]);
        $receipt = $this->createReceipt();
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => 'SNAPSHOT-001',
            'product_name' => 'Historical product name',
            'quantity' => '1.2500',
            'unit' => 'kg',
            'unit_price' => '1.000',
            'line_total' => '1.250',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
        ]);
        $product->forceFill(['sku' => 'MUTATED-999', 'name' => 'Renamed product'])->saveQuietly();

        $this->getJson('/api/v1/pos/receipts/'.$receipt->id)
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '1.25')
            ->assertJsonPath('data.lines.0.quantity_decimals', 2)
            ->assertJsonPath('data.lines.0.product_code', 'SNAPSHOT-001')
            ->assertJsonPath('data.lines.0.product_name', 'Historical product name');
    }

    public function test_show_normalizes_a_pre_fiscal_return_to_a_refund_with_a_magnitude_total(): void
    {
        $receipt = $this->createReceipt('SALE');
        $receipt->forceFill([
            'receipt_type' => ReceiptType::Return,
            'fiscal_event_id' => null,
            'total' => '-5.250',
            'currency' => 'TND',
        ])->saveQuietly();

        $this->getJson('/api/v1/pos/receipts/'.$receipt->id)
            ->assertOk()
            ->assertJsonPath('data.invoice_type_code', 'REFUND')
            ->assertJsonPath('data.total', '5.250');
    }
}
