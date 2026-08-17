<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
}
