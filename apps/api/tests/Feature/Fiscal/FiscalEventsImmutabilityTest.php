<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Spec §3.3 + plan Task 8 Step 3: the gated `failed -> parsed` resume transition
     * is allowed only when ALL SEVEN of these conditions hold in one UPDATE:
     *
     *   1. OLD.payload IS NULL
     *   2. NEW.payload IS NOT NULL
     *   3. OLD.integrity_exception_class = 'canonical_parse_failure'
     *   4. OLD.integrity_status = 'quarantined'
     *   5. NEW.integrity_status = 'verified'
     *   6. NEW.integrity_resolved_at IS NOT NULL
     *   7. NEW.integrity_resolved_by IS NOT NULL
     *
     * `test_parse_failure_resume_transition_failed_to_parsed_is_allowed` covers the
     * happy path (all seven hold). `test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution`
     * covers condition 4. This data provider exercises the remaining six conditions
     * individually so a future trigger refactor that drops any single clause is
     * caught by the suite (Opus review P2-1).
     *
     * @param  array<string, mixed>  $insertOverrides
     * @param  array<string, mixed>  $updatePayload
     */
    #[DataProvider('providesGatedFailedToParsedViolations')]
    public function test_failed_to_parsed_rejects_when_any_condition_violated(
        string $violation,
        array $insertOverrides,
        array $updatePayload,
    ): void {
        $this->skipUnlessPostgres();

        $base = [
            'payload' => null,
            'payload_parse_status' => 'failed',
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_parse_failure',
        ];

        $e = $this->insertEvent(array_merge($base, $insertOverrides));

        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update($updatePayload);
        // Violation tag preserved for debugging; PHPUnit prints it on failure.
        $this->fail("expected QueryException for violation: $violation");
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function providesGatedFailedToParsedViolations(): iterable
    {
        // Condition 1 violation: OLD.payload is NOT NULL.
        // We insert a parsed event with a payload, then attempt to "resume" by
        // rewriting payload alongside a legal-looking resolution tuple. The
        // trigger's write-once payload gate (Step 4) raises before the gated
        // transition is evaluated — same root protection (no payload double-write).
        yield 'OLD.payload not null (payload write-once)' => [
            'OLD.payload IS NOT NULL',
            [
                'payload' => json_encode(['preexisting' => true]),
                'payload_parse_status' => 'parsed',
                'integrity_status' => 'quarantined',
                'integrity_exception_class' => 'canonical_parse_failure',
            ],
            [
                'payload' => json_encode(['rewrite' => true]),
                'integrity_status' => 'verified',
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => '11111111-1111-1111-1111-111111111111',
            ],
        ];

        // Condition 2 violation: NEW.payload IS NULL.
        yield 'NEW.payload null' => [
            'NEW.payload IS NULL',
            [],
            [
                'payload' => null,
                'payload_parse_status' => 'parsed',
                'integrity_status' => 'verified',
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => '22222222-2222-2222-2222-222222222222',
            ],
        ];

        // Condition 3 violation: OLD.integrity_exception_class != 'canonical_parse_failure'.
        yield 'OLD.integrity_exception_class is canonical_hash_mismatch' => [
            "OLD.integrity_exception_class != 'canonical_parse_failure'",
            [
                'integrity_exception_class' => 'canonical_hash_mismatch',
            ],
            [
                'payload' => json_encode(['x' => 1]),
                'payload_parse_status' => 'parsed',
                'integrity_status' => 'verified',
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => '33333333-3333-3333-3333-333333333333',
            ],
        ];

        // Condition 5 violation: NEW.integrity_status != 'verified' (left at
        // 'quarantined'). The flip from failed -> parsed must coincide with the
        // integrity resolution; leaving integrity quarantined makes the resume
        // half-applied and is rejected.
        yield 'NEW.integrity_status stays quarantined' => [
            "NEW.integrity_status != 'verified'",
            [],
            [
                'payload' => json_encode(['x' => 1]),
                'payload_parse_status' => 'parsed',
                // integrity_status left at 'quarantined'
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => '44444444-4444-4444-4444-444444444444',
            ],
        ];

        // Condition 6 violation: NEW.integrity_resolved_at IS NULL.
        yield 'NEW.integrity_resolved_at null' => [
            'NEW.integrity_resolved_at IS NULL',
            [],
            [
                'payload' => json_encode(['x' => 1]),
                'payload_parse_status' => 'parsed',
                'integrity_status' => 'verified',
                'integrity_resolved_at' => null,
                'integrity_resolved_by' => '55555555-5555-5555-5555-555555555555',
            ],
        ];

        // Condition 7 violation: NEW.integrity_resolved_by IS NULL.
        yield 'NEW.integrity_resolved_by null' => [
            'NEW.integrity_resolved_by IS NULL',
            [],
            [
                'payload' => json_encode(['x' => 1]),
                'payload_parse_status' => 'parsed',
                'integrity_status' => 'verified',
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => null,
            ],
        ];

        // P3 (round-2 review): partial resolver — payload is written and
        // integrity flips quarantined -> verified, but payload_parse_status
        // is left at 'failed'. This is the half-applied resume the Step 5
        // canonical_parse_failure guard rejects via `NEW.payload_parse_status
        // IS DISTINCT FROM 'parsed'`. Independent coverage so a future
        // refactor that drops that clause is caught.
        // Tuple: OLD.payload=NULL, NEW.payload NOT NULL,
        //        OLD.payload_parse_status='failed', NEW.payload_parse_status='failed',
        //        OLD.integrity_status='quarantined', NEW.integrity_status='verified'.
        yield 'partial resolver: payload written but parse_status not flipped' => [
            "NEW.payload_parse_status stays 'failed'",
            [],
            [
                'payload' => json_encode(['resolved' => true]),
                // payload_parse_status intentionally OMITTED — stays at 'failed'
                'integrity_status' => 'verified',
                'integrity_resolved_at' => '2026-05-16 12:00:00',
                'integrity_resolved_by' => '99999999-9999-9999-9999-999999999999',
            ],
        ];
    }

    /**
     * Round-2 BLOCKER fix: `integrity_exception_class` is write-once.
     *
     * Once set on INSERT or on a verified -> quarantined reclassification, the
     * class cannot change to a different non-NULL value, nor be unset. Without
     * this guard a resolver can bypass the Step 5 canonical_parse_failure
     * atomic-resume guard by (1) reclassifying the row to another class then
     * (2) verifying with stamps only, stranding a verified row with payload=NULL.
     *
     * The class describes WHY a row entered quarantine — that fact is permanent
     * forensic metadata (spec §3.3, §7.5).
     */
    public function test_integrity_exception_class_can_be_set_on_verified_to_quarantined(): void
    {
        $this->skipUnlessPostgres();

        // Insert a normal verified row with NULL class (the default-state).
        $e = $this->insertEvent([
            'integrity_status' => 'verified',
            'integrity_exception_class' => null,
        ]);

        // Legitimate first-set: the reclassifier flags this row and sets the
        // class as part of the verified -> quarantined transition. Must succeed.
        DB::table('fiscal_events')->where('id', $e)->update([
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_hash_mismatch',
            'integrity_exception_reason' => 'first-set on flag — legitimate',
        ]); // no exception

        $this->assertSame(
            'canonical_hash_mismatch',
            DB::table('fiscal_events')->where('id', $e)->value('integrity_exception_class'),
        );
    }

    public function test_integrity_exception_class_cannot_change_to_different_value(): void
    {
        $this->skipUnlessPostgres();

        // Insert a quarantined canonical_parse_failure (the bypass-attempt setup).
        $e = $this->insertEvent([
            'payload' => null,
            'payload_parse_status' => 'failed',
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_parse_failure',
        ]);

        // Reclassification attempt — Steps 2/4/5 would not fire because only
        // the class column changes. Step 1b must reject.
        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update([
            'integrity_exception_class' => 'canonical_hash_mismatch',
        ]);
    }

    public function test_integrity_exception_class_cannot_be_unset(): void
    {
        $this->skipUnlessPostgres();

        // Insert a quarantined canonical_parse_failure.
        $e = $this->insertEvent([
            'payload' => null,
            'payload_parse_status' => 'failed',
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_parse_failure',
        ]);

        // Unset attempt — the class is permanent forensic metadata.
        $this->expectException(QueryException::class);
        DB::table('fiscal_events')->where('id', $e)->update([
            'integrity_exception_class' => null,
        ]);
    }

    /**
     * Spec §3.3 names `verified -> quarantined` as the ingestor-flag path on
     * `integrity_status`. In Phase 1 the OutboxIngestor sets status at INSERT
     * (which does not fire BEFORE UPDATE), but the trigger explicitly permits
     * the named transition for Task 19 / Task 23 retry-or-flag paths and any
     * future post-ingestion reclassifier (Opus review P2-2).
     */
    public function test_verified_to_quarantined_with_exception_metadata_succeeds(): void
    {
        $this->skipUnlessPostgres();

        $e = $this->insertEvent([
            'integrity_status' => 'verified',
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
        ]);

        DB::table('fiscal_events')->where('id', $e)->update([
            'integrity_status' => 'quarantined',
            'integrity_exception_class' => 'canonical_hash_mismatch',
            'integrity_exception_reason' => 'test reason — post-insert reclassification',
        ]); // no exception

        $this->assertSame(
            'quarantined',
            DB::table('fiscal_events')->where('id', $e)->value('integrity_status'),
        );
        $this->assertSame(
            'canonical_hash_mismatch',
            DB::table('fiscal_events')->where('id', $e)->value('integrity_exception_class'),
        );
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
