<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Domain\ByteaBinding;
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

    /**
     * Fixed identifiers for the binary-column round-trip pin. `fiscal_events`
     * carries no FK to tenants/companies/terminals, so literal UUIDs are enough
     * and keep the three rows the pin writes inside one chain slot family.
     */
    private const PIN_TENANT_ID = '9f2b1c44-0000-4000-8000-0000000000a1';

    private const PIN_COMPANY_ID = '9f2b1c44-0000-4000-8000-0000000000b2';

    private const PIN_TERMINAL_ID = '9f2b1c44-0000-4000-8000-0000000000c3';

    private const PIN_OPERATOR_ID = '9f2b1c44-0000-4000-8000-0000000000d4';

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

    /**
     * DESIGN PIN for the `canonical_bytes` binary-column binding.
     *
     * `canonical_bytes` must be bound as `PDO::PARAM_LOB` (a stream) or
     * PostgreSQL parses it with the bytea *escape* input rules and rejects the
     * RFC 8785 `\"` / `\\` escapes that canonical JSON emits for free text.
     *
     * The obvious fix — a `setCanonicalBytesAttribute` mutator that stores a
     * stream in the model's attribute bag — is WRONG, and this test is what
     * rejects it: a stream is consumed by the first `execute()`, so
     *   (1) reading the attribute back in the same request yields '' , and
     *   (2) re-saving those in-memory attributes writes an EMPTY column.
     * The binding must therefore happen AFTER the attributes leave the model,
     * which is what `BindsBinaryColumns::getAttributesForInsert()` does — the
     * attribute bag keeps the plain string at all times.
     *
     * Runs on both drivers: SQLite proves the stream bind stays byte-identical
     * there too.
     */
    public function test_canonical_bytes_survive_create_read_and_resave_in_one_request(): void
    {
        $bytes = json_encode(
            ['name' => 'Café "Déluxe" \\ C:\\PARTS\\OIL', 'note' => 'dit « 5" »'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $this->assertStringContainsString('\\"', $bytes);
        $this->assertStringContainsString('\\\\', $bytes);

        $event = FiscalEvent::query()->create($this->eventAttributes(['canonical_bytes' => $bytes]));

        // (1) In-memory read in the SAME request — a consumed stream returns ''.
        $this->assertSame($bytes, $event->canonical_bytes, 'attribute must survive the write');

        // (2) Re-saving the in-memory attributes must write the same bytes.
        $replica = $event->replicate();
        $replica->id = Str::uuid()->toString();
        $replica->sequence_number = 2;
        $replica->save();

        // (3) A third row authored from the accessor's value.
        $third = FiscalEvent::query()->create($this->eventAttributes([
            'canonical_bytes' => $event->canonical_bytes,
            'id' => Str::uuid()->toString(),
            'sequence_number' => 3,
        ]));

        // (4) The refresh() arm. On PostgreSQL a refreshed model holds a bytea
        // STREAM in its attribute bag, not a string — TerminalRegistrySnapshotService
        // refreshes right after create(). Re-saving those attributes must re-stream
        // from the resource's contents, never rebind the (possibly consumed) resource.
        $event->refresh();
        $refreshedReplica = $event->replicate();
        $refreshedReplica->id = Str::uuid()->toString();
        $refreshedReplica->sequence_number = 4;
        $refreshedReplica->save();

        // All rows are byte-identical in the DATABASE.
        foreach ([$event->id, $replica->id, $third->id, $refreshedReplica->id] as $id) {
            $row = DB::table('fiscal_events')->where('id', $id)->first();
            $this->assertNotNull($row);
            $this->assertSame($bytes, ByteaBinding::read($row->canonical_bytes), "row {$id} bytes");
        }

        // And byte-identical when re-read through Eloquent.
        $this->assertSame($bytes, FiscalEvent::query()->findOrFail($event->id)->canonical_bytes);
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

    /**
     * Eloquent-shaped attributes for the binary-column round-trip pin. Uses the
     * same sticky tenant/company/terminal as `insertEvent()` so the composite
     * UNIQUE slot behaves predictably across the rows one test writes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventAttributes(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => self::PIN_TENANT_ID,
            'company_id' => self::PIN_COMPANY_ID,
            'terminal_id' => self::PIN_TERMINAL_ID,
            'operator_id' => self::PIN_OPERATOR_ID,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => now()->toDateTimeString(),
            'business_date' => now()->toDateString(),
            'chain_context' => 'operational',
            'server_received_at' => now()->toDateTimeString(),
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ], $overrides);
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK / partial-unique constraints only enforced on PostgreSQL');
        }
    }
}
