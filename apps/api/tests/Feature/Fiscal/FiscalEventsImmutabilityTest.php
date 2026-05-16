<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 8 — `fiscal_events` immutability triggers (server PostgreSQL).
 *
 * Spec §3.3:
 *   - BEFORE UPDATE — `RAISE EXCEPTION` unless only the allowed columns changed
 *     (`payload`, `payload_parse_status`, `integrity_status`,
 *     `integrity_exception_class`, `integrity_exception_reason`,
 *     `integrity_resolved_at`, `integrity_resolved_by`), AND the allowed-set
 *     transitions match the spec.
 *   - BEFORE DELETE — always `RAISE EXCEPTION`.
 *   - BEFORE TRUNCATE (statement-level) — always `RAISE EXCEPTION`.
 *
 * Plan §Task 8 (the v1-plan-review BLOCKER fix):
 *   - Named `failed → parsed` parse-failure resume transition is allowed only when
 *     a quarantined `canonical_parse_failure` is being resolved (payload appears,
 *     integrity flips to `verified`, resolution stamps populated). Without that
 *     named transition Task 24 cannot pass; with it, Task 24's resolver UPDATE
 *     is one atomic transition.
 *
 * Triggers only exist on pgsql; tests that exercise them skip on SQLite.
 */
final class FiscalEventsImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_column_update_succeeds(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent(['payload_parse_status' => 'pending']);

        DB::table('fiscal_events')->where('id', $e)->update([
            'payload' => json_encode(['ok' => true]),
            'payload_parse_status' => 'parsed',
        ]); // no exception

        $this->assertSame(
            'parsed',
            DB::table('fiscal_events')->where('id', $e)->value('payload_parse_status'),
        );
    }

    public function test_forbidden_column_update_raises(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent();

        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update([
            'current_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_payload_is_write_once(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent(['payload_parse_status' => 'pending']);

        DB::table('fiscal_events')->where('id', $e)->update([
            'payload' => json_encode(['v' => 1]),
            'payload_parse_status' => 'parsed',
        ]);

        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update([
            'payload' => json_encode(['v' => 2]),
        ]);
    }

    /**
     * The parse-failure resume path (spec §7.5, Task 24).
     *
     * A quarantined canonical_parse_failure event must be resolvable via one
     * atomic UPDATE that flips payload_parse_status `failed → parsed`, writes
     * the now-parseable payload, flips integrity_status `quarantined → verified`,
     * and stamps `integrity_resolved_at` / `integrity_resolved_by`. Built here
     * so the trigger supports the resume contract from the start.
     */
    public function test_parse_failure_resume_transition_failed_to_parsed_is_allowed(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent([
            'payload' => null,
            'payload_parse_status' => 'failed',
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_parse_failure',
        ]);

        DB::table('fiscal_events')->where('id', $e)->update([
            'payload' => json_encode(['resolved' => true]),
            'payload_parse_status' => 'parsed',
            'integrity_status' => 'verified',
            'integrity_resolved_at' => now(),
            'integrity_resolved_by' => Str::uuid()->toString(),
        ]); // no exception — the resolution update is one atomic update

        $this->assertSame(
            'parsed',
            DB::table('fiscal_events')->where('id', $e)->value('payload_parse_status'),
        );
    }

    public function test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution(): void
    {
        $this->skipUnlessPostgres();

        // `failed -> parsed` is allowed ONLY for a quarantined canonical_parse_failure
        // being resolved to verified. Here integrity_status is still 'verified' (no
        // quarantine), so the same status flip must raise.
        $e = $this->insertEvent([
            'payload' => null,
            'payload_parse_status' => 'failed',
            'integrity_status' => 'verified',
        ]);

        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update([
            'payload' => json_encode(['x' => 1]),
            'payload_parse_status' => 'parsed',
        ]);
    }

    public function test_delete_and_truncate_raise(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent();

        try {
            DB::table('fiscal_events')->where('id', $e)->delete();
            $this->fail('delete allowed');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);
        DB::statement('TRUNCATE fiscal_events');
    }

    /**
     * Mirrors `FiscalEventsTableTest::insertEvent()` (deliberate duplication —
     * the table-shape contract is shared across Task 7 / Task 8; both files own
     * a copy so each can evolve independently while it is the only consumer).
     *
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
            'event_time_device' => now()->toDateTimeString(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now()->toDateTimeString(),
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
        ];

        // Re-use tenant/terminal across consecutive inserts in the same test
        // so any unique-sequence collision fires deterministically (mirrors
        // FiscalEventsTableTest::insertEvent).
        static $stickyTenant = null;
        static $stickyTerminal = null;
        if ($stickyTenant === null) {
            $stickyTenant = $defaults['tenant_id'];
            $stickyTerminal = $defaults['terminal_id'];
        }
        $defaults['tenant_id'] = $stickyTenant;
        $defaults['terminal_id'] = $stickyTerminal;

        $row = array_merge($defaults, $overrides);

        DB::table('fiscal_events')->insert($row);

        return (string) $row['id'];
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('fiscal_events immutability triggers only exist on PostgreSQL');
        }
    }
}
