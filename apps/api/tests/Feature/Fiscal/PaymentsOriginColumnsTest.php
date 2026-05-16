<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 12 — Treasury `payments` gains `origin` + `fiscal_event_id`.
 *
 * Spec §13 + §7.5 — the Treasury module integration migration. The FK
 * direction is `payments → fiscal_events`: a module depending on the
 * fiscal engine, which is the correct dependency direction per
 * [SoT §13.6 / D16] (the asymmetric bounded-modules seam).
 *
 * Phase 1 enumeration of `PaymentOrigin` — `pos | web_admin | mobile |
 * api | unknown_legacy`. Legacy rows pre-dating this migration have
 * `origin = NULL` and `fiscal_event_id = NULL`.
 *
 * No GL/allocation behavior change — purely additive schema columns.
 */
final class PaymentsOriginColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_gains_origin_and_fiscal_event_id_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'origin'));
        $this->assertTrue(Schema::hasColumn('payments', 'fiscal_event_id'));
    }

    public function test_payment_origin_enum_has_phase1_cases_in_order(): void
    {
        $values = array_map(static fn (PaymentOrigin $c): string => $c->value, PaymentOrigin::cases());

        $this->assertSame(
            ['pos', 'web_admin', 'mobile', 'api', 'unknown_legacy'],
            $values,
        );
    }

    public function test_payment_model_fillable_includes_origin_and_fiscal_event_id(): void
    {
        $fillable = (new Payment)->getFillable();

        $this->assertContains('origin', $fillable);
        $this->assertContains('fiscal_event_id', $fillable);
    }

    public function test_payment_model_casts_origin_to_enum(): void
    {
        $payment = new Payment;
        $payment->setRawAttributes(['origin' => 'pos'], true);

        $this->assertSame(PaymentOrigin::Pos, $payment->origin);
    }

    #[DataProvider('paymentOriginRoundTripCases')]
    public function test_payment_model_round_trips_every_origin_case(string $stored, PaymentOrigin $expected): void
    {
        $payment = new Payment;
        $payment->setRawAttributes(['origin' => $stored], true);

        $this->assertSame($expected, $payment->origin);
    }

    /**
     * @return array<string, array{string, PaymentOrigin}>
     */
    public static function paymentOriginRoundTripCases(): array
    {
        return [
            'pos' => ['pos', PaymentOrigin::Pos],
            'web_admin' => ['web_admin', PaymentOrigin::WebAdmin],
            'mobile' => ['mobile', PaymentOrigin::Mobile],
            'api' => ['api', PaymentOrigin::Api],
            'unknown_legacy' => ['unknown_legacy', PaymentOrigin::UnknownLegacy],
        ];
    }

    public function test_fiscal_event_id_fk_to_fiscal_events_exists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
            ['payments_fiscal_event_id_fk'],
        );

        $this->assertNotNull(
            $row,
            'FK payments_fiscal_event_id_fk → fiscal_events(id) missing on PostgreSQL',
        );
    }

    public function test_orphan_fiscal_event_id_is_rejected_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        // Prove the FK actually fires at runtime, not just exists in
        // pg_constraint. Without this, a future migration that names the
        // constraint differently or drops it would pass the metadata test
        // but break the projector idempotency-tail check.
        $this->expectException(QueryException::class);
        Payment::factory()->create([
            'fiscal_event_id' => Str::uuid()->toString(), // no matching fiscal_events row
        ]);
    }

    public function test_fiscal_event_id_partial_index_exists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            ['payments_fiscal_event_id_idx'],
        );

        $this->assertNotNull(
            $row,
            'Partial index payments_fiscal_event_id_idx missing on PostgreSQL',
        );
        // The predicate scopes the index to rows linked to a fiscal event
        // (i.e. projected by TreasuryReceiptBridge) so legacy rows stay
        // out of the projector's idempotency-tail hot scan.
        $this->assertStringContainsString('fiscal_event_id IS NOT NULL', $row->indexdef);
    }

    public function test_origin_and_fiscal_event_id_are_nullable_for_legacy_rows_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        foreach (['origin' => 'character varying', 'fiscal_event_id' => 'uuid'] as $column => $expectedType) {
            $row = DB::selectOne(
                'SELECT data_type, is_nullable FROM information_schema.columns
                 WHERE table_name = ? AND column_name = ?',
                ['payments', $column],
            );

            $this->assertNotNull($row, "column {$column} missing on PostgreSQL");
            $this->assertSame($expectedType, $row->data_type, "{$column} data_type drift");
            $this->assertSame('YES', $row->is_nullable, "{$column} must be nullable for legacy rows");
        }
    }

    public function test_origin_varchar_length_is_pinned_at_32_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        // The VARCHAR(32) width is part of the spec §13 contract. Pin it
        // here so a future migration widening to VARCHAR(64) doesn't slip
        // through the data_type test (which only proves "character varying").
        $row = DB::selectOne(
            'SELECT character_maximum_length FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            ['payments', 'origin'],
        );

        $this->assertNotNull($row);
        $this->assertSame(32, (int) $row->character_maximum_length);
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FK / column-type introspection only on PostgreSQL');
        }
    }
}
