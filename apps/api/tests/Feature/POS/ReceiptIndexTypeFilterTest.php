<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptIndexTypeFilterTest extends ReceiptReportingTestCase
{
    public function test_default_and_explicit_type_sets_are_disjoint(): void
    {
        $this->createReceipt('SALE');
        $this->createReceipt('REFUND');
        $this->createReceipt('VOID');

        $this->getJson('/api/v1/pos/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.invoice_type_code', 'SALE');

        $refunds = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID');
        $refunds->assertOk()->assertJsonCount(2, 'data.data');
        $this->assertEqualsCanonicalizing(['REFUND', 'VOID'], array_column($refunds->json('data.data'), 'invoice_type_code'));
    }

    public function test_legacy_return_axis_matches_the_refund_void_union(): void
    {
        $sale = $this->createReceipt('SALE');
        $this->createReceipt('REFUND', attributes: [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => ReturnReason::Other,
        ]);
        $this->createReceipt('VOID', attributes: [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => ReturnReason::Other,
        ]);

        $newAxis = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID')
            ->assertOk()
            ->json('data.data');
        $legacyAxis = $this->getJson('/api/v1/pos/receipts?receipt_type=return')
            ->assertOk()
            ->json('data.data');

        $this->assertEqualsCanonicalizing(array_column($newAxis, 'id'), array_column($legacyAxis, 'id'));
        $this->assertSame(['return', 'return'], array_column($legacyAxis, 'receipt_type'));
    }

    public function test_explicit_invoice_codes_take_precedence_over_the_legacy_axis(): void
    {
        $sale = $this->createReceipt('SALE');
        $this->createReceipt('TRAINING', true);

        $response = $this->getJson(
            '/api/v1/pos/receipts?receipt_type=sale&include_training=true&invoice_type_codes[]=SALE',
        );

        $response->assertOk();
        $this->assertSame([$sale->id], array_column($response->json('data.data'), 'id'));
    }

    public function test_training_toggle_does_not_widen_the_legacy_axis_by_itself(): void
    {
        $sale = $this->createReceipt('SALE');
        $this->createReceipt('TRAINING', true);

        $response = $this->getJson('/api/v1/pos/receipts?receipt_type=sale&include_training=true');

        $response->assertOk();
        $this->assertSame([$sale->id], array_column($response->json('data.data'), 'id'));
    }

    public function test_pre_fiscal_return_is_excluded_from_sales_and_projected_into_refunds(): void
    {
        $sale = $this->createReceipt('SALE');
        $legacyReturn = $this->createReceipt('SALE', attributes: [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => 'defective',
            'fiscal_event_id' => null,
            'currency' => 'TND',
            'subtotal' => '-5.250',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'cash_rounding_adjustment' => null,
            'total' => '-5.250',
        ]);

        $sales = $this->getJson('/api/v1/pos/receipts')->assertOk()->json('data.data');
        $refunds = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID')
            ->assertOk()
            ->json('data.data');

        $this->assertSame([$sale->id], array_column($sales, 'id'));
        $this->assertSame([$legacyReturn->id], array_column($refunds, 'id'));
        $this->assertSame('REFUND', $refunds[0]['invoice_type_code']);
        $this->assertSame('5.250', $refunds[0]['total']);
        $this->assertSame('return', $refunds[0]['receipt_type']);
    }

    public function test_receipt_number_search_treats_percent_and_underscore_as_literals(): void
    {
        $matching = $this->createReceipt(attributes: ['receipt_number' => 'TN-%_SPECIAL']);
        $this->createReceipt(attributes: ['receipt_number' => 'TN-XXSPECIAL']);

        $rows = $this->getJson('/api/v1/pos/receipts?receipt_number=%25_')
            ->assertOk()
            ->json('data.data');

        $this->assertSame([$matching->id], array_column($rows, 'id'));
    }

    public function test_voided_filter_emits_the_visible_voided_state(): void
    {
        $live = $this->createReceipt('SALE');
        $voided = $this->createReceipt('SALE', attributes: [
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by' => $this->user->id,
        ]);

        $this->getJson('/api/v1/pos/receipts?is_voided=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $voided->id)
            ->assertJsonPath('data.data.0.is_voided', true);

        $this->getJson('/api/v1/pos/receipts?is_voided=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $live->id)
            ->assertJsonPath('data.data.0.is_voided', false);
    }
}
