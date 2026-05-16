<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 10 — `fiscal_event_quarantine` non-admissible-envelope partition.
 *
 * Spec §8 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
 * `sequence_conflict` envelopes physically cannot occupy an already-claimed
 * (tenant, terminal, sequence) slot in `fiscal_events`, so they go here.
 * The row is self-contained — full canonical bytes + typed envelope + mirrored
 * metadata — so verifier (§15) and JET export (§16) can render the incident
 * with the same fidelity as an admitted `fiscal_events` row.
 *
 * The table IS mutable (resolution flow writes `resolved_at` / `resolved_by`)
 * and carries no immutability trigger. In Phase 1 the only valid
 * `integrity_exception_class` is `'sequence_conflict'`.
 */
final class FiscalEventQuarantineTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_quarantine_table_has_envelope_and_metadata_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_event_quarantine'));
        foreach ([
            // Identity
            'id',
            // Tenancy + actors
            'tenant_id',
            'company_id',
            'terminal_id',
            'operator_id',
            // Envelope-mirrored metadata
            'envelope_event_id',
            'event_type',
            'event_version',
            'signature_version',
            'claimed_sequence_number',
            'event_time_device',
            'business_date',
            'last_server_time_seen',
            // Cross-event references
            'reference_event_id',
            'reference_document_id',
            'source_event_class',
            'source_event_id',
            // Chain coordinates
            'previous_hash',
            'current_hash',
            // Conserved envelope
            'canonical_bytes',
            'raw_envelope',
            'payload_parse_status',
            // Incident metadata
            'integrity_exception_class',
            'integrity_exception_reason',
            'conflicting_event_id',
            'server_received_at',
            'resolved_at',
            'resolved_by',
            // Server-controlled
            'created_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('fiscal_event_quarantine', $col),
                "missing {$col}",
            );
        }
    }

    public function test_model_casts_raw_envelope_to_array_and_class_to_enum(): void
    {
        $id = $this->insertQuarantineRow([
            'raw_envelope' => json_encode(['event_type' => 'SALE_RECEIPT', 'sequence' => 42]),
            'integrity_exception_class' => 'sequence_conflict',
        ]);

        /** @var FiscalEventQuarantine $row */
        $row = FiscalEventQuarantine::query()->findOrFail($id);

        $this->assertSame(
            ['event_type' => 'SALE_RECEIPT', 'sequence' => 42],
            $row->raw_envelope,
        );
        $this->assertSame(IntegrityExceptionClass::SequenceConflict, $row->integrity_exception_class);
    }

    public function test_integrity_exception_class_check_rejects_non_phase1_value_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $this->expectException(QueryException::class);
        $this->insertQuarantineRow(['integrity_exception_class' => 'time_anomaly']);
    }

    public function test_resolved_columns_default_to_null_on_insert(): void
    {
        $id = $this->insertQuarantineRow();
        $row = DB::table('fiscal_event_quarantine')->where('id', $id)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->resolved_at);
        $this->assertNull($row->resolved_by);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertQuarantineRow(array $overrides = []): string
    {
        $defaults = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => Str::uuid()->toString(),
            'operator_id' => Str::uuid()->toString(),
            'envelope_event_id' => Str::uuid()->toString(),
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'claimed_sequence_number' => 7,
            // Driver-portable literals.
            'event_time_device' => now()->toDateTimeString(),
            'business_date' => now()->toDateString(),
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
            'canonical_bytes' => '{}',
            'raw_envelope' => json_encode(['stub' => true]),
            'integrity_exception_class' => 'sequence_conflict',
            'integrity_exception_reason' => 'duplicate sequence slot',
            'conflicting_event_id' => Str::uuid()->toString(),
            'server_received_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];

        $row = array_merge($defaults, $overrides);
        DB::table('fiscal_event_quarantine')->insert($row);

        return (string) $row['id'];
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint only enforced on PostgreSQL');
        }
    }
}
