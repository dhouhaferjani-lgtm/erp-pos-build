<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Inventory\Application\Services\StockTransferReceiptService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function test_inventory_view_alone_cannot_read_transfers(): void
    {
        $this->user->syncPermissions(['inventory.view']);
        $this->getJson('/api/v1/stock-transfers')->assertForbidden();
        $this->getJson('/api/v1/stock-transfers/'.$this->transfer->id)->assertForbidden();
    }

    public function test_complete_alone_cannot_read_reconciliation(): void
    {
        $this->user->syncPermissions(['inventory.transfers.complete']);
        $this->getJson('/api/v1/stock-transfers/'.$this->transfer->id.'/reconciliation')->assertForbidden();
    }

    public function test_scoped_service_rejects_another_company_transfer_without_writes(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $before = $this->denialSnapshot();
        try {
            $this->app->make(StockTransferReceiptService::class)->receive(
                $this->transfer->id, $this->user->id, $this->receiptBody(),
                $this->tenant->id, $otherCompany->id,
            );
            self::fail('A foreign company transfer must not resolve.');
        } catch (ModelNotFoundException) {
            self::assertSame($before, $this->denialSnapshot());
        }
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
