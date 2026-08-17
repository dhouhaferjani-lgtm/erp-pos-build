<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Support\Carbon;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class RefundRegisterPaginationTest extends ReceiptReportingTestCase
{
    public function test_refund_and_void_union_is_server_paginated_once_in_posted_order(): void
    {
        $expectedIds = [];
        for ($index = 0; $index < 30; $index++) {
            $receipt = $this->createReceipt(
                invoiceTypeCode: $index % 2 === 0 ? 'REFUND' : 'VOID',
                postedAt: Carbon::parse('2026-08-17 12:00:00 UTC')->subMinutes($index)->toDateTimeString(),
            );
            $expectedIds[] = $receipt->id;
        }

        $pageOne = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID&per_page=10&page=1');
        $pageTwo = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID&per_page=10&page=2');

        $pageOne->assertOk()->assertJsonPath('data.meta.total', 30)->assertJsonPath('data.meta.last_page', 3);
        $pageTwo->assertOk()->assertJsonCount(10, 'data.data');
        $this->assertEqualsCanonicalizing(
            ['REFUND', 'VOID'],
            array_values(array_unique(array_column($pageTwo->json('data.data'), 'invoice_type_code'))),
        );
        $this->assertSame(array_slice($expectedIds, 10, 10), array_column($pageTwo->json('data.data'), 'id'));
        $this->assertSame(
            [],
            array_values(array_intersect(
                array_column($pageOne->json('data.data'), 'id'),
                array_column($pageTwo->json('data.data'), 'id'),
            )),
        );
    }
}
