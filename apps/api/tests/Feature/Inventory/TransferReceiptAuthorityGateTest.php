<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';

final class TransferReceiptAuthorityGateTest extends TransferReceiptFeatureTestCase
{
    public function test_complete_only_receiver_reaches_transfer_list_and_show(): void
    {
        $this->user->syncPermissions(['inventory.transfers.complete']);
        $this->getJson('/api/v1/stock-transfers')->assertOk();
        $this->getJson('/api/v1/stock-transfers/'.$this->transfer->id)->assertOk();
    }

    public function test_close_requires_both_reconcile_and_close_permissions(): void
    {
        foreach (['reconcile', 'close'] as $permission) {
            $this->user->syncPermissions(['inventory.transfers.'.$permission]);
            $this->close()->assertForbidden();
        }
        $this->user->syncPermissions(['inventory.transfers.reconcile', 'inventory.transfers.close']);
        $this->close('return_to_source')->assertCreated();
    }

    public function test_view_only_actor_cannot_post_a_receipt(): void
    {
        $this->user->syncPermissions(['inventory.transfers.view']);
        $this->receive()->assertForbidden();
        self::assertSame('0.0000', $this->destinationQuantity());
    }
}
