<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\UserCompanyMembership;
use Illuminate\Support\Facades\DB;

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

    public function test_destination_only_actor_cannot_return_to_an_inaccessible_source(): void
    {
        UserCompanyMembership::query()->where('user_id', $this->user->id)->where('company_id', $this->company->id)->sole()
            ->update(['allowed_location_ids' => [$this->destination->id]]);
        $before = $this->denialSnapshot();
        $this->close('return_to_source')->assertForbidden();
        self::assertSame($before, $this->denialSnapshot());
        $this->close('write_off')->assertCreated();
        self::assertSame(1, $this->journalCount());
    }

    public function test_source_only_actor_cannot_receive_or_close_at_the_destination(): void
    {
        UserCompanyMembership::query()->where('user_id', $this->user->id)->where('company_id', $this->company->id)->sole()
            ->update(['allowed_location_ids' => [$this->source->id]]);
        $before = $this->denialSnapshot();
        $this->receive()->assertForbidden();
        $this->close('write_off')->assertForbidden();
        $this->close('return_to_source')->assertForbidden();
        self::assertSame($before, $this->denialSnapshot());
    }

    /** @return array<string, string> */
    private function denialSnapshot(): array
    {
        $snapshot = [];
        foreach (['stock_levels', 'stock_movements', 'stock_transfers', 'stock_transfer_lines', 'stock_transfer_receipts', 'journal_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
