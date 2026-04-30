<?php

declare(strict_types=1);

namespace Tests\Unit\Voucher\Domain;

use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for VoucherLedger domain entity.
 *
 * Covers: casts, relationships, and DB-level append-only enforcement.
 */
final class VoucherLedgerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Cast tests
    // -------------------------------------------------------------------------

    public function test_event_is_cast_to_enum(): void
    {
        $ledger = VoucherLedger::factory()->issued()->create();
        $ledger->refresh();

        $this->assertInstanceOf(VoucherEvent::class, $ledger->event);
        $this->assertSame(VoucherEvent::Issued, $ledger->event);
    }

    public function test_amount_is_decimal_string(): void
    {
        $ledger = VoucherLedger::factory()->create(['amount' => '50.00000']);
        $ledger->refresh();

        $this->assertIsString($ledger->amount);
        $this->assertStringContainsString('.', $ledger->amount);
    }

    public function test_occurred_at_is_cast_to_datetime(): void
    {
        $ledger = VoucherLedger::factory()->create();
        $ledger->refresh();

        $this->assertInstanceOf(Carbon::class, $ledger->occurred_at);
    }

    public function test_updated_at_column_does_not_exist(): void
    {
        $ledger = VoucherLedger::factory()->create();
        $ledger->refresh();

        // The model declares UPDATED_AT = null; the column shouldn't be in attributes
        $this->assertArrayNotHasKey('updated_at', $ledger->getAttributes());
    }

    // -------------------------------------------------------------------------
    // Relationship tests
    // -------------------------------------------------------------------------

    public function test_belongs_to_voucher(): void
    {
        $voucher = Voucher::factory()->create();
        $ledger = VoucherLedger::factory()->create(['voucher_id' => $voucher->id]);

        $this->assertTrue($ledger->voucher->is($voucher));
    }

    // -------------------------------------------------------------------------
    // DB-level append-only enforcement
    // -------------------------------------------------------------------------

    public function test_voucher_ledger_blocks_update_at_db_level(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Append-only trigger is PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        $ledger = VoucherLedger::factory()->create(['amount' => '50.00000']);

        DB::table('voucher_ledger')
            ->where('id', $ledger->id)
            ->update(['amount' => '1.00000']);
    }

    public function test_voucher_ledger_blocks_delete_at_db_level(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Append-only trigger is PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        $ledger = VoucherLedger::factory()->create();

        DB::table('voucher_ledger')->where('id', $ledger->id)->delete();
    }
}
