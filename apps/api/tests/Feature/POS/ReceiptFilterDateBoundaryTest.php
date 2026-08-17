<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptFilterDateBoundaryTest extends ReceiptReportingTestCase
{
    public function test_to_date_is_half_open_in_company_timezone(): void
    {
        $this->company->update(['timezone' => 'Europe/Paris']);
        $inside = $this->createReceipt(postedAt: '2026-03-29 21:30:00 UTC');
        $this->createReceipt(postedAt: '2026-03-29 22:15:00 UTC');

        $response = $this->getJson('/api/v1/pos/receipts?from_date=2026-03-29&to_date=2026-03-29');

        $response->assertOk()->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $inside->id);
        $response->assertJsonPath('data.meta.from', '2026-03-28T23:00:00.000000Z');
        $response->assertJsonPath('data.meta.to', '2026-03-29T22:00:00.000000Z');
    }
}
