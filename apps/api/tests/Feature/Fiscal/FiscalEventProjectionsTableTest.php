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
 * Task 9 — `fiscal_event_projections` server-side table.
 *
 * Spec §7.5: per-(event, projector) projection state for the worker dispatcher.
 * The table IS mutable (it is not chain truth) and carries no immutability trigger.
 * The idempotency key is the composite UNIQUE (fiscal_event_id, projector_name).
 *
 * Schema invariants checked here:
 *  - Every column the OutboxIngestor (Task 19) + ApplyFiscalEventProjectionJob (Task 23)
 *    will read/write exists.
 *  - The composite UNIQUE blocks a duplicate projector row for the same fiscal event
 *    (idempotency contract). This is portable across drivers (composite UNIQUE is
 *    declared via Laravel's Schema builder, not raw PG), so the assertion runs on
 *    both SQLite and PostgreSQL.
 */
final class FiscalEventProjectionsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_all_projection_state_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_event_projections'));
        foreach ([
            'id',
            'fiscal_event_id',
            'projector_name',
            'projection_status',
            'attempts',
            'last_error',
            'last_attempted_at',
            'applied_at',
            'dead_lettered_at',
            'created_at',
            'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('fiscal_event_projections', $col),
                "missing {$col}",
            );
        }
    }

    public function test_unique_event_projector_blocks_duplicate(): void
    {
        $eventId = $this->insertEvent();
        $this->insertProjection($eventId, 'pos_core_receipt');

        $this->expectException(QueryException::class);
        $this->insertProjection($eventId, 'pos_core_receipt'); // duplicate (fiscal_event_id, projector_name)
    }

    public function test_unique_key_allows_distinct_projectors_per_event(): void
    {
        $eventId = $this->insertEvent();
        $this->insertProjection($eventId, 'pos_core_receipt');
        $this->insertProjection($eventId, 'treasury_receipt_bridge');

        $count = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $eventId)
            ->count();

        $this->assertSame(2, $count);
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
            'server_received_at' => now()->toDateTimeString(),
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
        ];

        $row = array_merge($defaults, $overrides);
        DB::table('fiscal_events')->insert($row);

        return (string) $row['id'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertProjection(
        string $fiscalEventId,
        string $projectorName = 'pos_core_receipt',
        array $overrides = [],
    ): string {
        $defaults = [
            'id' => Str::uuid()->toString(),
            'fiscal_event_id' => $fiscalEventId,
            'projector_name' => $projectorName,
            'projection_status' => 'pending',
            'attempts' => 0,
            // Driver-portable: avoid DB::raw('NOW()') so the test runs on SQLite.
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        $row = array_merge($defaults, $overrides);
        DB::table('fiscal_event_projections')->insert($row);

        return (string) $row['id'];
    }
}
