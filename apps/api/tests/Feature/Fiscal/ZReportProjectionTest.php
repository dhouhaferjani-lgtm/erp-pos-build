<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZReportProjection;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZReportProjectionTest extends TestCase
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

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Default Cashier',
        ]);
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'code' => 'T001',
        ]);
        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => '2026-05-24 08:00:00',
        ]);
    }

    public function test_z_report_projects_from_canonical_fiscal_event(): void
    {
        $event = $this->storeZReportFiscalEvent();

        $this->app->make(ZReportProjection::class)->apply($event);

        $report = ZReport::query()->first();
        $this->assertNotNull($report);
        $this->assertSame($event->id, $report->fiscal_event_id);
        $this->assertSame($event->payload['z_report_uuid'], $report->id);
        $this->assertSame($event->canonical_bytes, $report->canonical_bytes);
        $this->assertSame(hash('sha256', $event->canonical_bytes), $report->canonical_bytes_hash);
        $this->assertSame($event->current_hash, $report->fiscal_hash);
        $this->assertSame($event->previous_hash, $report->previous_z_hash);
        $this->assertSame(3, $report->z_number);
        $this->assertSame('Z0003', $report->report_data['canonical_z_report']['formatted_z_number']);
        $this->assertSame('550.000', $report->grand_totals['cumulative_sales']);
    }

    public function test_z_report_projection_is_idempotent_by_fiscal_event_id(): void
    {
        $event = $this->storeZReportFiscalEvent();
        $projector = $this->app->make(ZReportProjection::class);

        $projector->apply($event);
        $projector->apply($event);

        $this->assertSame(1, ZReport::query()->count());
    }

    public function test_z_report_projection_backfills_existing_legacy_row_without_replacing_row_id(): void
    {
        $legacyId = Str::uuid()->toString();
        $event = $this->storeZReportFiscalEvent();

        ZReport::query()->create([
            'id' => $legacyId,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'z_number' => 2,
            'fiscal_hash' => str_repeat('c', 64),
            'previous_z_hash' => null,
            'report_data' => ['schema_version' => 2],
            'receipt_snapshots' => [],
            'grand_totals' => [],
            'generated_by' => $this->cashier->id,
            'generated_at' => '2026-05-24 18:00:00',
        ]);

        $this->app->make(ZReportProjection::class)->apply($event);

        $this->assertSame(1, ZReport::query()->count());
        $report = ZReport::query()->firstOrFail();
        $this->assertSame($legacyId, $report->id);
        $this->assertSame($event->id, $report->fiscal_event_id);
        $this->assertSame(3, $report->z_number);
        $this->assertSame('Z0003', $report->report_data['canonical_z_report']['formatted_z_number']);
    }

    public function test_z_report_projection_is_registered_as_fiscal_event_projector_tag(): void
    {
        /** @var list<FiscalEventProjector> $tagged */
        $tagged = iterator_to_array($this->app->tagged(FiscalEventProjector::class), false);
        $names = array_map(
            static fn (FiscalEventProjector $projector): string => $projector->name(),
            $tagged,
        );

        $this->assertContains('pos_core_z_report', $names);
    }

    public function test_nf525_export_uses_canonical_z_report_for_grand_totals(): void
    {
        $event = $this->storeZReportFiscalEvent();
        $this->app->make(ZReportProjection::class)->apply($event);

        /** @var Nf525DataProvider $provider */
        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::parse('2026-05-24')->startOfDay(),
            Carbon::parse('2026-05-24')->endOfDay(),
        );

        $this->assertCount(1, $snapshot->zReports);
        $this->assertSame('Z0003', $snapshot->zReports[0]->reportData['canonical_z_report']['formatted_z_number']);

        $canonicalGrandTotal = collect($snapshot->grandTotals)
            ->first(static fn ($row): bool => $row->eventType === 'Z_REPORT');
        $this->assertNotNull($canonicalGrandTotal);
        $this->assertSame(str_repeat('b', 64), $canonicalGrandTotal->fiscalHash);
        $this->assertSame('550.000', $canonicalGrandTotal->perpetualTotals['cumulative_sales']);
    }

    private function storeZReportFiscalEvent(): FiscalEvent
    {
        $payload = $this->zReportPayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::Z_REPORT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 4,
            'event_time_device' => '2026-05-24 18:00:00',
            'business_date' => '2026-05-24',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-24 18:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'z_report',
            'source_event_id' => $payload['z_report_uuid'],
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->canonicalEncode($payload),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function zReportPayload(): array
    {
        return [
            'business_date' => '2026-05-24',
            'cash_count' => [
                'counted_cash' => '150.000',
                'expected_cash' => '150.000',
                'lines' => [],
                'variance_amount' => '0.000',
                'variance_direction' => 'balanced',
                'variance_reason' => null,
                'variance_severity' => 'balanced',
            ],
            'cash_drawer_totals' => ['opening_cash' => '100.000'],
            'closed_at_device' => '2026-05-24T18:00:00.000Z',
            'company_snapshot' => ['company_id' => $this->companyId],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'formatted_z_number' => 'Z0003',
            'grand_totals_after' => [
                'cumulative_refunds' => '0.000',
                'cumulative_sales' => '550.000',
                'cumulative_tax' => '88.000',
                'perpetual_grand_total' => '550.000',
                'receipt_count_lifetime' => 11,
            ],
            'grand_totals_before' => [
                'cumulative_refunds' => '0.000',
                'cumulative_sales' => '500.000',
                'cumulative_tax' => '80.000',
                'perpetual_grand_total' => '500.000',
                'receipt_count_lifetime' => 10,
            ],
            'legacy_report_reference' => null,
            'operational_event_range' => ['receipt_count' => 1],
            'operator_id' => $this->cashier->id,
            'operator_name' => 'Default Cashier',
            'payment_method_totals' => [['payment_type' => 'CASH', 'total_amount' => '50.000', 'transaction_count' => 1]],
            'period_end' => '2026-05-24T18:00:00.000Z',
            'period_start' => '2026-05-24T08:00:00.000Z',
            'period_type' => 'DAY',
            'receipt_totals' => ['count' => 1, 'gross_sales' => '50.000', 'net_sales' => '42.000', 'tax_amount' => '8.000'],
            'refunds_totals' => ['amount' => '0.000', 'count' => 0],
            'seller' => null,
            'session_event_range' => ['first_sequence' => 1, 'last_sequence' => 3],
            'session_id' => '44444444-4444-4444-8444-444444444444',
            'shift_id' => $this->shift->id,
            'terminal_id' => $this->terminal->id,
            'terminal_label' => 'T001',
            'tolerance_summary' => null,
            'training_flag' => false,
            'vat_breakdown' => [['gross_amount' => '50.000', 'net_amount' => '42.000', 'tax_rate' => 19, 'vat_amount' => '8.000']],
            'voids_totals' => ['count' => 0],
            'z_number' => 3,
            'z_report_uuid' => '66666666-6666-4666-8666-666666666666',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
