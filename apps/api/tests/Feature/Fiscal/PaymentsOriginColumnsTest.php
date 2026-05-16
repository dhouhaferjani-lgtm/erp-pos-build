<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FK / column-type introspection only on PostgreSQL');
        }
    }
}
