<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReceiptType;
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
        $this->createReceipt('SALE');
        $refund = $this->createReceipt('REFUND');
        $refund->forceFill(['receipt_type' => ReceiptType::Return])->saveQuietly();
        $void = $this->createReceipt('VOID');
        $void->forceFill(['receipt_type' => ReceiptType::Return])->saveQuietly();

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
        $legacyReturn = $this->createReceipt('SALE');
        $legacyReturn->forceFill([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale->id,
            'return_reason' => 'defective',
            'fiscal_event_id' => null,
            'currency' => 'TND',
            'total' => '-5.250',
        ])->saveQuietly();

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
        $matching = $this->createReceipt();
        $matching->forceFill(['receipt_number' => 'TN-%_SPECIAL'])->saveQuietly();
        $other = $this->createReceipt();
        $other->forceFill(['receipt_number' => 'TN-XXSPECIAL'])->saveQuietly();

        $rows = $this->getJson('/api/v1/pos/receipts?receipt_number=%25_')
            ->assertOk()
            ->json('data.data');

        $this->assertSame([$matching->id], array_column($rows, 'id'));
    }
}
