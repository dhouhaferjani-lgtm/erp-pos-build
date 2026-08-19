<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ES-41 — immutability enforcement is PostgreSQL-only, and one branch of the
 * receipt trigger has no column guards.
 *
 * Register row (`ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md:174`), with
 * two claims at two confidences:
 *
 *   - **CONFIRMED (driver gate)** — *"All `fiscal_events` / `pos_receipts`
 *     immutability enforcement is PostgreSQL-only (the trigger migration returns
 *     early on other drivers) → the SQLite test/dev surface has zero immutability
 *     enforcement and a whole regression class is untestable."*
 *   - **SUSPECTED (seal branch exploitable)** — *"the `pending_seal→fiscalized`
 *     branch of `prevent_receipt_modification()` is an unconditional `RETURN NEW`
 *     with no column guards."*
 *
 * **The contract for this milestone is TRIGGER PRESENCE, scoped to this row**
 * (brief R-5). This file asserts on PG that the triggers EXIST and REFUSE, and
 * makes the non-PG driver gap VISIBLE rather than claiming to have fixed it.
 * Making the triggers run on SQLite, or redesigning immutability enforcement,
 * is explicitly out of scope (rule 4 / R-5's "do not fix the driver gate").
 *
 * **The SUSPECTED half is CONFIRMED by this milestone — verified in code first,
 * then demonstrated at runtime, and NOT fixed.** In
 * `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php` the
 * live definition of `prevent_receipt_modification()` opens its UPDATE handling
 * with:
 *
 *     IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
 *         RETURN NEW;
 *     END IF;
 *
 * — no column comparisons at all, unlike the `fiscalized → voided` branch
 * immediately below it, which enumerates seven guarded columns, and unlike the
 * two branches below that, which enumerate thirteen and fifteen. The
 * characterization test at the bottom of this file proves the consequence is
 * reachable, not merely readable: an UPDATE that flips
 * `pending_seal → fiscalized` may rewrite `total` in the SAME statement and the
 * trigger returns NEW.
 *
 * Why it is NOT fixed here: the sealing write legitimately populates
 * `fiscal_hash`, `chain_sequence` and `posted_at` during exactly this
 * transition, so guarding the branch means deciding, per column, what a seal
 * may and may not author. That is an immutability redesign against a LIVE seal
 * path, which R-5 names as scope creep for this row. It is ticketed, with this
 * test as the executable evidence, so the next lane inherits a demonstration
 * rather than a suspicion.
 */
final class ImmutabilityTriggerPresenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        // ReceiptFactory defaults location_id/terminal_id to UNOVERRIDDEN
        // factories that create orphan rows with no tenant — every create()
        // here overrides both, per the sibling suite's own warning.
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    // =================================================================
    // The CONFIRMED half — the driver gate, made VISIBLE on both drivers.
    //
    // This is the ONE test in the file that does not skip: it asserts the
    // enforcement is present on pgsql and ABSENT everywhere else. A test
    // that merely skipped on SQLite would leave the gap invisible, which
    // is the state ES-41 is complaining about.
    // =================================================================

    public function test_immutability_enforcement_exists_on_postgres_and_is_documented_as_absent_elsewhere(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->assertSame(
                ['enforce_receipt_immutability'],
                $this->triggerNamesOn('pos_receipts'),
                'ES-41: the pos_receipts immutability trigger must be PRESENT on PostgreSQL.',
            );
            $this->assertSame(
                [
                    'fiscal_events_immutability_delete',
                    'fiscal_events_immutability_truncate',
                    'fiscal_events_immutability_update',
                ],
                $this->triggerNamesOn('fiscal_events'),
                'ES-41: all three fiscal_events immutability triggers must be PRESENT on PostgreSQL.',
            );

            return;
        }

        // Non-PG: the DOCUMENTED gap, asserted rather than skipped.
        //
        // `2026_05_14_100002_create_fiscal_events_immutability.php::up()` and
        // `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php::up()`
        // both `return` immediately when the driver is not `pgsql`. On this
        // driver there is therefore NO append-only enforcement on
        // `fiscal_events` and NO seal enforcement on `pos_receipts`: a test
        // that passes here has proven nothing about production, and a whole
        // regression class (every "the trigger refuses X" case) is untestable.
        //
        // This assertion is a CHARACTERIZATION of the confirmed half of ES-41,
        // not an endorsement. It fails the day someone makes the triggers run
        // on this driver — at which point the gap is closed and this
        // expectation should be replaced by the PG branch above.
        $this->assertSame(
            [],
            $this->triggerNamesOn('pos_receipts'),
            'ES-41 (CONFIRMED half): immutability enforcement is PostgreSQL-only. If this driver has grown triggers, '
            .'the driver gate has been closed and this characterization must be replaced by a real assertion.',
        );
    }

    // =================================================================
    // [PG] The triggers do not merely EXIST — they REFUSE.
    // Presence without refusal would be the same false green ES-41 is about.
    // =================================================================

    public function test_pg_the_fiscal_events_trigger_refuses_a_delete(): void
    {
        $this->skipUnlessPostgres();

        $eventId = $this->insertMinimalFiscalEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only ledger/');

        DB::table('fiscal_events')->where('id', $eventId)->delete();
    }

    public function test_pg_the_fiscal_events_trigger_refuses_a_forbidden_column_update(): void
    {
        $this->skipUnlessPostgres();

        $eventId = $this->insertMinimalFiscalEvent();

        // The refused statement runs inside a NESTED transaction so PG's
        // "current transaction is aborted" state is rolled back to the
        // savepoint — otherwise the two-sided re-read below cannot run at all
        // under RefreshDatabase's outer transaction.
        try {
            DB::transaction(function () use ($eventId): void {
                DB::table('fiscal_events')
                    ->where('id', $eventId)
                    ->update(['current_hash' => str_repeat('f', 64)]);
            });
            $this->fail('ES-41: the fiscal_events immutability trigger must refuse an UPDATE of current_hash.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'may change (spec §3.3)',
                $e->getMessage(),
                'ES-41: the refusal must come from the immutability trigger itself, not from an unrelated constraint.',
            );
        }

        $stored = DB::table('fiscal_events')->where('id', $eventId)->value('current_hash');
        $this->assertNotSame(
            str_repeat('f', 64),
            (string) $stored,
            'ES-41: refusal must be two-sided — the exception AND the unchanged row.',
        );
    }

    public function test_pg_the_receipt_trigger_refuses_a_total_change_on_a_fiscalized_row(): void
    {
        $this->skipUnlessPostgres();

        $receipt = $this->receipt('fiscalized');
        $originalTotal = $this->decimalOf($receipt->total);
        $originalSubtotal = $this->decimalOf($receipt->subtotal);

        // Totals are moved COHERENTLY (subtotal and total together) so the
        // `pos_receipts_totals` CHECK is satisfied. The point of this test is
        // the TRIGGER, and a CHECK violation would prove nothing about it.
        try {
            DB::transaction(function () use ($receipt, $originalTotal, $originalSubtotal): void {
                DB::table('pos_receipts')
                    ->where('id', $receipt->id)
                    ->update([
                        'subtotal' => bcadd($originalSubtotal, '1.000', 3),
                        'total' => bcadd($originalTotal, '1.000', 3),
                    ]);
            });
            $this->fail('ES-41: the pos_receipts trigger must refuse a total change on a fiscalized row.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fiscally sealed', $e->getMessage());
        }

        $this->assertSame(
            0,
            bccomp($originalTotal, $this->decimalOf(DB::table('pos_receipts')->where('id', $receipt->id)->value('total')), 3),
            'ES-41: refusal must be two-sided — the exception AND the unchanged total.',
        );
    }

    // =================================================================
    // [PG] The SUSPECTED half, CONFIRMED and CHARACTERIZED — not fixed.
    // =================================================================

    public function test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization(): void
    {
        $this->skipUnlessPostgres();

        $receipt = $this->receipt('pending_seal');
        $originalTotal = $this->decimalOf($receipt->total);
        $rewrittenSubtotal = bcadd($this->decimalOf($receipt->subtotal), '999.000', 3);
        $rewrittenTotal = bcadd($originalTotal, '999.000', 3);

        // ONE statement: the legitimate seal transition PLUS an illegitimate
        // amount rewrite, moved coherently so the `pos_receipts_totals` CHECK
        // is satisfied — the CHECK is an arithmetic invariant, not an
        // immutability control, and it is happy to certify a forged pair of
        // amounts. Every other branch of prevent_receipt_modification() would
        // reject this UPDATE; this branch returns NEW before looking at a
        // single column.
        DB::table('pos_receipts')
            ->where('id', $receipt->id)
            ->update([
                'fiscal_status' => 'fiscalized',
                'subtotal' => $rewrittenSubtotal,
                'total' => $rewrittenTotal,
            ]);

        $stored = $this->decimalOf(DB::table('pos_receipts')->where('id', $receipt->id)->value('total'));

        $this->assertSame(
            0,
            bccomp($rewrittenTotal, $stored, 3),
            'ES-41 SUSPECTED half: this assertion CHARACTERIZES a confirmed gap — it does not endorse it. The '
            .'pending_seal→fiscalized branch is an unconditional RETURN NEW, so a seal may rewrite any column in the '
            .'same statement. If this assertion ever fails, the branch has grown column guards: DELETE this test and '
            .'replace it with a refusal test, and close the ES-41 ticket.',
        );
        $this->assertSame(
            'fiscalized',
            (string) DB::table('pos_receipts')->where('id', $receipt->id)->value('fiscal_status'),
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                '[PG] ES-41: immutability triggers exist ONLY on PostgreSQL — the migrations return early on every '
                .'other driver. A green run on this driver would be evidence about nothing. Run via phpunit-pgsql.xml.'
            );
        }
    }

    /**
     * Narrow a DB-returned decimal to a `numeric-string` for `bccomp`, failing
     * the test loudly rather than casting a surprise into silence — rule 19
     * forbids a float ever touching money, and a blind `(string)` cast on an
     * unexpected value would compare garbage as zero.
     *
     * @return numeric-string
     */
    private function decimalOf(mixed $value): string
    {
        $raw = is_string($value) ? $value : (string) $value;

        if (! is_numeric($raw)) {
            $this->fail(sprintf('Expected a decimal string from pos_receipts, got %s.', var_export($value, true)));
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function triggerNamesOn(string $table): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        /** @var list<object{tgname: string}> $rows */
        $rows = DB::select(
            'SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid '
            .'WHERE c.relname = ? AND NOT t.tgisinternal ORDER BY t.tgname',
            [$table],
        );

        return array_map(static fn (object $row): string => (string) $row->tgname, $rows);
    }

    private function receipt(string $fiscalStatus): Receipt
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => $fiscalStatus,
            'sealed_hash_algorithm' => null,
        ]);

        return $receipt;
    }

    private function insertMinimalFiscalEvent(): string
    {
        $eventId = (string) Str::uuid();
        $now = Carbon::now('UTC');
        $canonicalBytes = '{"es41":"trigger-presence-fixture"}';

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => (string) Str::uuid(),
            'event_type' => 'CHAIN_BREAK_DETECTED',
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => 'not_required',
            'integrity_status' => 'verified',
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => 'pending',
            'created_at' => $now,
        ]);

        return $eventId;
    }
}
