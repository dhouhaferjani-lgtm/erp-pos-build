<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\FiscalStatus;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptIndexFiscalStatusFilterTest extends ReceiptReportingTestCase
{
    public function test_fiscal_status_filters_and_is_emitted(): void
    {
        $this->createReceipt(fiscalStatus: FiscalStatus::PendingSeal);
        $failed = $this->createReceipt(fiscalStatus: FiscalStatus::SyncFailed);

        $this->getJson('/api/v1/pos/receipts?fiscal_status=sync_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $failed->id)
            ->assertJsonPath('data.data.0.fiscal_status', 'sync_failed');
    }
}
