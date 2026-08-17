<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptIndexArchivedTerminalTest extends ReceiptReportingTestCase
{
    public function test_archived_terminal_code_remains_available_on_historical_receipts(): void
    {
        $receipt = $this->createReceipt();
        $terminalCode = $this->terminal->code;
        $this->terminal->delete();

        $response = $this->getJson('/api/v1/pos/receipts');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.id', $receipt->id);
        $response->assertJsonPath('data.data.0.terminal_code', $terminalCode);
    }
}
