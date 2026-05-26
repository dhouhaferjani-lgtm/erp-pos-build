<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 7 — `fiscal_events` server PostgreSQL table.
 *
 * Verifies the schema laid out in spec §3.2: every chain/integrity column
 * exists, the per-terminal sequence slot is unique, and the regex/event-type
 * paired-null CHECK constraints reject malformed input.
 *
 * Constraint enforcement requires pgsql — those cases skip on SQLite, where
 * the column-existence assertion still runs to keep new contributors honest.
 */
final class FiscalEventsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_all_chain_and_integrity_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_events'));
        foreach ([
            'id', 'tenant_id', 'company_id', 'terminal_id', 'operator_id', 'event_type',
            'event_version', 'signature_version', 'sequence_number', 'event_time_device',
            'business_date', 'chain_context', 'server_received_at', 'canonical_bytes', 'previous_hash',
            'current_hash', 'signature_status', 'integrity_status', 'integrity_exception_class',
            'payload', 'payload_parse_status', 'created_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('fiscal_events', $col),
                "missing {$col}",
            );
        }
    }

    public function test_unique_sequence_key_blocks_duplicate_slot(): void
    {
        // The composite UNIQUE key is portable (Laravel schema builder, not raw PG). Run on every driver.
        $this->insertEvent(['sequence_number' => 1]);

        $this->expectException(QueryException::class);
        $this->insertEvent([
            'sequence_number' => 1,
            'id' => Str::uuid()->toString(),
        ]); // same (tenant_id, company_id, terminal_id, chain_context, sequence_number)
    }

    public function test_unique_sequence_key_allows_same_sequence_in_different_chain_context(): void
    {
        $this->insertEvent(['sequence_number' => 1, 'chain_context' => 'operational']);

        $id = $this->insertEvent([
            'sequence_number' => 1,
            'chain_context' => 'z_session',
            'id' => Str::uuid()->toString(),
        ]);

        $this->assertSame(2, DB::table('fiscal_events')->count());
        $this->assertTrue(DB::table('fiscal_events')->where('id', $id)->exists());
    }

    public function test_hash_format_check_constraint(): void
    {
        $this->skipUnlessPostgres();

        $this->expectException(QueryException::class);
        $this->insertEvent(['current_hash' => 'NOT-HEX']);
    }

    public function test_event_type_check_rejects_unknown_value(): void
    {
        $this->skipUnlessPostgres();

        $this->expectException(QueryException::class);
        $this->insertEvent(['event_type' => 'NOT_A_REAL_FISCAL_EVENT']);
    }

    public function test_source_event_paired_null_check(): void
    {
        $this->skipUnlessPostgres();

        $this->expectException(QueryException::class);
        $this->insertEvent([
            'source_event_class' => 'App\\Modules\\Foo\\Events\\Bar',
            'source_event_id' => null, // class set but id null — must violate
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertEvent(array $overrides = []): string
    {
        $defaults = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => Str::uuid()->toString(),
            'operator_id' => Str::uuid()->toString(),
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            // Driver-portable literals — NOW() / CURRENT_DATE don't exist on SQLite.
            'event_time_device' => now()->toDateTimeString(),
            'business_date' => now()->toDateString(),
            'chain_context' => 'operational',
            'server_received_at' => now()->toDateTimeString(),
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
        ];

        // Re-use tenant/terminal across consecutive inserts in the same test
        // so the unique-sequence check fires deterministically.
        static $stickyTenant = null;
        static $stickyCompany = null;
        static $stickyTerminal = null;
        if ($stickyTenant === null) {
            $stickyTenant = $defaults['tenant_id'];
            $stickyCompany = $defaults['company_id'];
            $stickyTerminal = $defaults['terminal_id'];
        }
        $defaults['tenant_id'] = $stickyTenant;
        $defaults['company_id'] = $stickyCompany;
        $defaults['terminal_id'] = $stickyTerminal;

        $row = array_merge($defaults, $overrides);

        DB::table('fiscal_events')->insert($row);

        return (string) $row['id'];
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK / partial-unique constraints only enforced on PostgreSQL');
        }
    }
}
