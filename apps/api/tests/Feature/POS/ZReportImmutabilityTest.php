<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZReportProjection;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M1 (2026-06-09 Z-report audit) — pos_z_reports append-only trigger.
 *
 * PG-only: the prevent_z_report_modification() trigger does not exist on
 * SQLite, so every test here skips there. Runs under the backend-test-pgsql
 * CI gate (this class name is in the job's --filter list).
 *
 * Writer inventory the trigger must keep working (verified 2026-06-11):
 *  - ReportGenerationService::generateZReport — builds the model in memory,
 *    sets fiscal_hash, then save() → single INSERT (never UPDATEs).
 *  - ZReportSyncController — insert-only (duplicate push → early return / 409).
 *  - ZReportProjection::apply — INSERT for new rows; the ONE legitimate UPDATE
 *    is the one-time legacy→canonical upgrade (fiscal_event_id NULL → value),
 *    which rewrites the mirror fields (fiscal_hash, report_data, grand_totals,
 *    canonical_bytes, …) from the verified fiscal event.
 *
 * Everything else is tampering: DELETE always blocked; any UPDATE on a
 * canonical row (fiscal_event_id NOT NULL) blocked; any UPDATE on a legacy
 * row that does not perform the canonical upgrade blocked; id / shift_id /
 * terminal_id immutable in every transition.
 */
final class ZReportImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private User $cashier;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pos_z_reports append-only trigger is PostgreSQL-only.');
        }

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Immutability Cashier',
        ]);
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'code' => 'T-IMM',
        ]);
        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => '2026-06-11 08:00:00',
        ]);
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    public function test_delete_is_blocked_on_legacy_row(): void
    {
        $report = $this->createLegacyZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete fiscal Z-report');

        DB::table('pos_z_reports')->where('id', $report->id)->delete();
    }

    public function test_delete_is_blocked_on_canonical_row(): void
    {
        $report = $this->createCanonicalZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete fiscal Z-report');

        DB::table('pos_z_reports')->where('id', $report->id)->delete();
    }

    // ─── Canonical rows are fully sealed ─────────────────────────────────────

    public function test_canonical_row_rejects_report_data_update(): void
    {
        $report = $this->createCanonicalZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sealed');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['report_data' => json_encode(['schema_version' => 3, 'gross_sales' => '999.000'])]);
    }

    public function test_canonical_row_rejects_fiscal_hash_update(): void
    {
        $report = $this->createCanonicalZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sealed');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['fiscal_hash' => str_repeat('e', 64)]);
    }

    public function test_canonical_row_rejects_fiscal_event_id_change(): void
    {
        $report = $this->createCanonicalZReport();
        $otherEvent = $this->storeZReportFiscalEvent(
            zReportUuid: Str::uuid()->toString(),
            sequenceNumber: 9,
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sealed');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['fiscal_event_id' => $otherEvent->id]);
    }

    // ─── Legacy rows: only the canonical upgrade may modify them ─────────────

    public function test_legacy_row_rejects_report_data_tamper(): void
    {
        $report = $this->createLegacyZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('canonical projection upgrade');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['report_data' => json_encode(['schema_version' => 2, 'gross_sales' => '0.000'])]);
    }

    public function test_legacy_row_rejects_fiscal_hash_tamper(): void
    {
        $report = $this->createLegacyZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('canonical projection upgrade');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['fiscal_hash' => str_repeat('d', 64)]);
    }

    public function test_legacy_row_rejects_z_number_tamper(): void
    {
        $report = $this->createLegacyZReport();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('canonical projection upgrade');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update(['z_number' => 99]);
    }

    // ─── Identity columns are immutable in every transition ──────────────────

    public function test_shift_id_is_immutable_even_during_canonical_upgrade(): void
    {
        $report = $this->createLegacyZReport();
        $otherShift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 2,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-06-11 19:00:00',
            'closed_at' => '2026-06-11 20:00:00',
            'closed_by' => $this->cashier->id,
        ]);
        $event = $this->storeZReportFiscalEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('identity');

        DB::table('pos_z_reports')
            ->where('id', $report->id)
            ->update([
                'shift_id' => $otherShift->id,
                'fiscal_event_id' => $event->id,
            ]);
    }

    // ─── Legitimate flows keep working ───────────────────────────────────────

    public function test_insert_with_fiscal_hash_set_succeeds(): void
    {
        // ReportGenerationService / ZReportSyncController write shape: a single
        // INSERT with fiscal_hash already computed. A BEFORE UPDATE OR DELETE
        // trigger must never interfere with it.
        $report = $this->createLegacyZReport();

        $this->assertDatabaseHas('pos_z_reports', ['id' => $report->id]);
    }

    public function test_legacy_to_canonical_projection_upgrade_is_allowed(): void
    {
        // The documented ZReportProjection flow: a legacy mirror row for the
        // shift is adopted by the verified canonical Z_REPORT fiscal event,
        // rewriting the mirror fields and stamping fiscal_event_id exactly once.
        $report = $this->createLegacyZReport();
        $event = $this->storeZReportFiscalEvent();

        $this->app->make(ZReportProjection::class)->apply($event);

        $this->assertSame(1, ZReport::query()->count());
        $upgraded = ZReport::query()->firstOrFail();
        $this->assertSame($report->id, $upgraded->id);
        $this->assertSame($event->id, $upgraded->fiscal_event_id);
        $this->assertSame($event->current_hash, $upgraded->fiscal_hash);
        $this->assertSame(3, $upgraded->z_number);
    }

    public function test_canonical_projection_insert_and_idempotent_reapply_succeed(): void
    {
        $event = $this->storeZReportFiscalEvent();
        $projector = $this->app->make(ZReportProjection::class);

        $projector->apply($event);
        $projector->apply($event);

        $this->assertSame(1, ZReport::query()->count());
        $report = ZReport::query()->firstOrFail();
        $this->assertSame($event->id, $report->fiscal_event_id);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createLegacyZReport(): ZReport
    {
        return ZReport::query()->create([
            'id' => Str::uuid()->toString(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'z_number' => 2,
            'fiscal_hash' => str_repeat('c', 64),
            'previous_z_hash' => null,
            'report_data' => ['schema_version' => 2, 'gross_sales' => '50.000'],
            'receipt_snapshots' => [],
            'grand_totals' => [],
            'generated_by' => $this->cashier->id,
            'generated_at' => '2026-06-11 18:00:00',
        ]);
    }

    private function createCanonicalZReport(): ZReport
    {
        $event = $this->storeZReportFiscalEvent();
        $this->app->make(ZReportProjection::class)->apply($event);

        return ZReport::query()->where('fiscal_event_id', $event->id)->firstOrFail();
    }

    private function storeZReportFiscalEvent(
        ?string $zReportUuid = null,
        int $sequenceNumber = 4,
    ): FiscalEvent {
        $payload = $this->zReportPayload($zReportUuid ?? '66666666-6666-4666-8666-666666666666');
        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::Z_REPORT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => '2026-06-11 18:00:00',
            'business_date' => '2026-06-11',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-06-11 18:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'z_report',
            'source_event_id' => $payload['z_report_uuid'],
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * Minimal Z_REPORT payload — only the keys ZReportProjection reads.
     *
     * @return array<string, mixed>
     */
    private function zReportPayload(string $zReportUuid): array
    {
        return [
            'business_date' => '2026-06-11',
            'cash_count' => [
                'counted_cash' => '150.000',
                'expected_cash' => '150.000',
                'variance_amount' => '0.000',
            ],
            'cash_drawer_totals' => ['opening_cash' => '100.000'],
            'closed_at_device' => '2026-06-11T18:00:00.000Z',
            'formatted_z_number' => 'Z0003',
            'grand_totals_after' => [
                'cumulative_refunds' => '0.000',
                'cumulative_sales' => '550.000',
                'cumulative_tax' => '88.000',
                'perpetual_grand_total' => '550.000',
                'receipt_count_lifetime' => 11,
            ],
            'operator_id' => $this->cashier->id,
            'operator_name' => 'Immutability Cashier',
            'payment_method_totals' => [
                ['payment_type' => 'CASH', 'total_amount' => '50.000', 'transaction_count' => 1],
            ],
            'period_end' => '2026-06-11T18:00:00.000Z',
            'period_start' => '2026-06-11T08:00:00.000Z',
            'receipt_totals' => ['count' => 1, 'gross_sales' => '50.000', 'net_sales' => '42.000', 'tax_amount' => '8.000'],
            'refunds_totals' => ['amount' => '0.000', 'count' => 0],
            'shift_id' => $this->shift->id,
            'terminal_id' => $this->terminal->id,
            'tolerance_summary' => null,
            'vat_breakdown' => [],
            'voids_totals' => ['count' => 0],
            'z_number' => 3,
            'z_report_uuid' => $zReportUuid,
        ];
    }
}
