<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that the vouchers and voucher_ledger tables have the correct
 * schema, constraints, indexes, and trigger-enforced append-only semantics.
 */
final class VoucherSchemaTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Task 10 — vouchers table
    // -------------------------------------------------------------------------

    public function test_vouchers_table_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('vouchers');

        $required = [
            'id',
            'tenant_id',
            'company_id',
            'code',
            'initial_balance',
            'current_balance',
            'currency',
            'status',
            'redemption_mode',
            'voucher_kind',
            'source',
            'issued_at',
            'expires_at',
            'partner_id',
            'issued_to_partner_id',
            'source_receipt_id',
            'source_loyalty_transaction_id',
            'source_promotional_campaign_id',
            'issued_by_user_id',
            'issued_at_terminal_id',
            'redeemable_at_terminal_id',
            'notes',
            'authorized_by_user_id',
            'override_reason',
            'policy_trigger',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        foreach ($required as $column) {
            $this->assertContains($column, $columns, "Column '{$column}' missing from vouchers table.");
        }
    }

    public function test_voucher_ledger_table_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('voucher_ledger');

        $required = [
            'id',
            'tenant_id',
            'company_id',
            'voucher_id',
            'event',
            'amount',
            'currency',
            'receipt_id',
            'terminal_id',
            'user_id',
            'gl_journal_entry_id',
            'authorized_by_user_id',
            'policy_trigger',
            'reverses_voucher_ledger_id',
            'occurred_at',
            'created_at',
        ];

        foreach ($required as $column) {
            $this->assertContains($column, $columns, "Column '{$column}' missing from voucher_ledger table.");
        }

        // Confirm updated_at is NOT present (append-only)
        $this->assertNotContains('updated_at', $columns, 'voucher_ledger must not have updated_at.');
    }

    public function test_vouchers_code_is_unique(): void
    {
        $this->expectException(QueryException::class);

        $row = $this->baseVoucherRow();
        $code = 'OTSP-UNIQUECODE1234-A';

        DB::table('vouchers')->insert(array_merge($row, ['code' => $code]));
        DB::table('vouchers')->insert(array_merge($row, [
            'id' => (string) Str::uuid(),
            'code' => $code,  // duplicate
        ]));
    }

    public function test_voucher_ledger_rejects_update(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Append-only trigger is PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        $voucherId = $this->insertVoucher();
        $ledgerId = $this->insertLedgerRow($voucherId);

        DB::table('voucher_ledger')
            ->where('id', $ledgerId)
            ->update(['amount' => '99.00000']);
    }

    public function test_voucher_ledger_rejects_delete(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Append-only trigger is PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        $voucherId = $this->insertVoucher();
        $ledgerId = $this->insertLedgerRow($voucherId);

        DB::table('voucher_ledger')->where('id', $ledgerId)->delete();
    }

    public function test_vouchers_indexes_exist(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_indexes query is PostgreSQL-only.');
        }

        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'vouchers'"
        );
        $indexNames = array_map(fn ($r) => $r->indexname, $indexes);

        $this->assertContains(
            'vouchers_tenant_source_status_idx',
            $indexNames,
            'Missing composite index vouchers_tenant_source_status_idx.'
        );

        $this->assertContains(
            'vouchers_terminal_status_idx',
            $indexNames,
            'Missing composite index vouchers_terminal_status_idx.'
        );
    }

    /**
     * Lane Q-5 — DB backstop for the void edge: a voucher may carry at most one
     * `voided` ledger row. VoucherVoidService enforces this under a row lock;
     * the partial unique index catches anything that races or bypasses it.
     */
    public function test_voucher_ledger_has_partial_unique_index_on_voided_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is PostgreSQL-only.');
        }

        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'voucher_ledger'"
        );
        $indexNames = array_map(fn ($r) => $r->indexname, $indexes);

        $this->assertContains(
            'uniq_voucher_ledger_voided_per_voucher',
            $indexNames,
            'Missing partial unique index uniq_voucher_ledger_voided_per_voucher.'
        );
    }

    public function test_voucher_ledger_rejects_a_second_voided_row_for_the_same_voucher(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is PostgreSQL-only.');
        }

        $voucherRow = $this->baseVoucherRow();
        DB::table('vouchers')->insert($voucherRow);

        $ledgerRow = [
            'id' => (string) Str::uuid(),
            'tenant_id' => $voucherRow['tenant_id'],
            'company_id' => $voucherRow['company_id'],
            'voucher_id' => $voucherRow['id'],
            'event' => VoucherEvent::Voided->value,
            'amount' => '-50.00000',
            'currency' => 'EUR',
            'user_id' => $voucherRow['issued_by_user_id'],
            'occurred_at' => now(),
        ];

        DB::table('voucher_ledger')->insert($ledgerRow);

        // A second Voided row for the same voucher must be rejected.
        $this->expectException(QueryException::class);

        DB::table('voucher_ledger')->insert(array_merge($ledgerRow, [
            'id' => (string) Str::uuid(),
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function baseVoucherRow(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'OTSP-'.strtoupper(Str::random(12)).'-A',
            'initial_balance' => '50.00000',
            'current_balance' => '50.00000',
            'currency' => 'EUR',
            'status' => VoucherStatus::Issued->value,
            'redemption_mode' => RedemptionMode::Bearer->value,
            'voucher_kind' => VoucherKind::MPV->value,
            'source' => VoucherSource::Refund->value,
            'issued_at' => now()->toDateTimeString(),
            'issued_by_user_id' => $user->id,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    private function insertVoucher(): string
    {
        $row = $this->baseVoucherRow();
        DB::table('vouchers')->insert($row);

        return $row['id'];
    }

    private function insertLedgerRow(string $voucherId): string
    {
        $id = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('voucher_ledger')->insert([
            'id' => $id,
            'tenant_id' => DB::table('vouchers')->where('id', $voucherId)->value('tenant_id'),
            'company_id' => DB::table('vouchers')->where('id', $voucherId)->value('company_id'),
            'voucher_id' => $voucherId,
            'event' => VoucherEvent::Issued->value,
            'amount' => '50.00000',
            'currency' => 'EUR',
            'user_id' => $user->id,
            'occurred_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }
}
