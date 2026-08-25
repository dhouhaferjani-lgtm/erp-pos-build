<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slice D batch 1 — LIVENESS pin for the 13 money/fiscal enum CHECK constraints
 * added by the five `2026_08_25_1301xx_add_enum_check_constraints_to_*`
 * migrations (`vouchers`, `journal_entries`, `payments`, `documents`,
 * `instrument_events`).
 *
 * WHY THIS EXISTS SEPARATELY FROM THE PARITY GATE.
 * `EnumCheckParityTest` reads `pg_constraint` and compares admitted sets against
 * enum cases: it proves the constraint is DECLARED with the right value set. It
 * cannot prove PostgreSQL actually REJECTS a write, nor that `VALIDATE
 * CONSTRAINT` ran (a `NOT VALID` constraint is enforced on new rows but silently
 * leaves pre-existing rows unchecked, which is exactly the state a half-applied
 * rollout leaves behind). This file asserts both against the live engine:
 *
 *   1. an out-of-set value raises SQLSTATE 23514 naming `chk_{table}_{column}_enum`;
 *   2. NULL is still accepted on the four genuinely nullable columns
 *      (`journal_entries.journal_code`, `payments.origin`,
 *      `instrument_events.from_status`, `instrument_events.to_status`) — the
 *      null-guarded CHECK form must not turn an optional column into a required
 *      one;
 *   3. `pg_constraint.convalidated` is true for all 13, i.e. the second
 *      `ALTER TABLE … VALIDATE CONSTRAINT` statement really ran.
 *
 * PG-ONLY: the constraints are created behind a `pgsql` driver guard in the
 * migrations, so every method self-skips on the SQLite default suite.
 *
 * `instrument_events` is append-only (`instrument_events_immutability_update`),
 * so its arms drive INSERT rather than UPDATE; the other four tables mutate a
 * legitimately-created row so that no NOT NULL / FK column has to be faked.
 *
 * The sentinel bad value is `'ZZ'`: no case of any of the eight governing enums
 * has that value, and it fits the narrowest column in the batch
 * (`journal_entries.journal_code varchar(4)`), so the write is rejected by the
 * CHECK and never by a `22001 string_data_right_truncation`.
 */
final class SliceDBatch1CheckConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private const BAD_VALUE = 'ZZ';

    /**
     * The 13 columns this batch constrains, table by table.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function constrainedColumns(): array
    {
        return [
            'vouchers.status' => ['vouchers', 'status'],
            'vouchers.source' => ['vouchers', 'source'],
            'vouchers.voucher_kind' => ['vouchers', 'voucher_kind'],
            'vouchers.redemption_mode' => ['vouchers', 'redemption_mode'],
            'journal_entries.status' => ['journal_entries', 'status'],
            'journal_entries.journal_code' => ['journal_entries', 'journal_code'],
            'payments.status' => ['payments', 'status'],
            'payments.origin' => ['payments', 'origin'],
            'payments.payment_type' => ['payments', 'payment_type'],
            'documents.type' => ['documents', 'type'],
            'instrument_events.event_type' => ['instrument_events', 'event_type'],
            'instrument_events.from_status' => ['instrument_events', 'from_status'],
            'instrument_events.to_status' => ['instrument_events', 'to_status'],
        ];
    }

    /**
     * The subset that is NULLABLE in the live schema — asserted straight from
     * `information_schema` in `test_nullable_set_matches_the_live_schema()` so
     * this list cannot silently drift from the columns it claims to describe.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nullableColumns(): array
    {
        return [
            'journal_entries.journal_code' => ['journal_entries', 'journal_code'],
            'payments.origin' => ['payments', 'origin'],
            'instrument_events.from_status' => ['instrument_events', 'from_status'],
            'instrument_events.to_status' => ['instrument_events', 'to_status'],
        ];
    }

    #[DataProvider('constrainedColumns')]
    public function test_out_of_set_value_is_rejected_by_the_check(string $table, string $column): void
    {
        $this->requirePostgres();

        $constraint = "chk_{$table}_{$column}_enum";

        try {
            $this->writeValue($table, $column, self::BAD_VALUE);
            $this->fail("{$table}.{$column} accepted the out-of-set value '".self::BAD_VALUE."'; {$constraint} is absent or not enforcing.");
        } catch (QueryException $exception) {
            $this->assertSame(
                '23514',
                (string) $exception->getCode(),
                "{$table}.{$column} was rejected, but not by a CHECK: ".$exception->getMessage()
            );
            $this->assertStringContainsString(
                $constraint,
                $exception->getMessage(),
                "{$table}.{$column} was rejected by a CHECK other than {$constraint}."
            );
        }
    }

    #[DataProvider('nullableColumns')]
    public function test_null_is_still_accepted_on_nullable_columns(string $table, string $column): void
    {
        $this->requirePostgres();

        $id = $this->writeValue($table, $column, null);

        $this->assertNull(
            DB::table($table)->where('id', $id)->value($column),
            "{$table}.{$column} is nullable in the schema but the CHECK refused NULL."
        );
    }

    public function test_nullable_set_matches_the_live_schema(): void
    {
        $this->requirePostgres();

        $declaredNullable = array_keys(self::nullableColumns());
        sort($declaredNullable);

        $actual = [];
        foreach (self::constrainedColumns() as $key => [$table, $column]) {
            $isNullable = DB::selectOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                [$table, $column]
            );
            $this->assertNotNull($isNullable, "{$table}.{$column} is missing from the live schema.");
            if ($isNullable->is_nullable === 'YES') {
                $actual[] = $key;
            }
        }
        sort($actual);

        $this->assertSame($declaredNullable, $actual);
    }

    public function test_every_constraint_exists_and_was_validated(): void
    {
        $this->requirePostgres();

        foreach (self::constrainedColumns() as [$table, $column]) {
            $constraint = "chk_{$table}_{$column}_enum";

            $row = DB::selectOne(
                "SELECT con.convalidated
                   FROM pg_constraint con
                   JOIN pg_class rel ON rel.oid = con.conrelid
                  WHERE con.contype = 'c' AND con.conname = ? AND rel.relname = ?",
                [$constraint, $table]
            );

            $this->assertNotNull($row, "{$constraint} does not exist on {$table}.");
            $this->assertTrue(
                (bool) $row->convalidated,
                "{$constraint} exists but is still NOT VALID — the VALIDATE CONSTRAINT statement did not run."
            );
        }
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Slice D batch 1 CHECK constraints are PostgreSQL-only.');
        }
    }

    /**
     * Drives one column to `$value` through the table's only writable path and
     * returns the id of the affected row.
     */
    private function writeValue(string $table, string $column, ?string $value): string
    {
        if ($table === 'instrument_events') {
            return $this->insertInstrumentEvent([$column => $value]);
        }

        $id = match ($table) {
            'vouchers' => (string) Voucher::factory()->create()->id,
            'payments' => (string) Payment::factory()->create()->id,
            'documents' => (string) Document::factory()->create()->id,
            'journal_entries' => $this->insertJournalEntry(),
            default => throw new \LogicException("No row builder for {$table}."),
        };

        DB::table($table)->where('id', $id)->update([$column => $value]);

        return $id;
    }

    private function insertJournalEntry(): string
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $id = (string) Str::uuid();

        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'JE-'.Str::upper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'status' => 'draft',
            'journal_code' => 'OD',
            'is_historical' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function insertInstrumentEvent(array $overrides): string
    {
        $instrument = $this->instrument();
        $id = (string) Str::uuid();

        DB::table('instrument_events')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $instrument->tenant_id,
            'company_id' => $instrument->company_id,
            'instrument_id' => $instrument->id,
            'event_type' => InstrumentEventType::Created->value,
            'from_status' => InstrumentStatus::Received->value,
            'to_status' => InstrumentStatus::Deposited->value,
            'payload' => '{}',
            'occurred_at' => now(),
        ], $overrides));

        return $id;
    }

    private function instrument(): PaymentInstrument
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $method = PaymentMethod::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CHECK-'.Str::lower(Str::random(8)),
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
            'is_active' => true,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'CHK-'.Str::upper(Str::random(8)),
            'amount' => '100.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
        ]);
    }
}
