<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\Nf525\Nf525XmlBuilder;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEDGER C-6 item 3 (finding F-5) — the NF525 `GrandsTotaux` section reported
 * `<VentesBrutes>0.00</VentesBrutes>` / `<Taxe>0.00</Taxe>` for EVERY canonical
 * (device-authored) Z report.
 *
 * **The defect.** `Nf525XmlBuilder::addGrandTotals()` (`:335-336`) reads the
 * TOP-LEVEL keys `periodTotals['gross_sales']` and `periodTotals['tax_amount']`.
 * `Nf525DataProvider::mapCanonicalZReportGrandTotal()` built `$periodTotals` out
 * of five NESTED keys (`receipt_totals`, `refunds_totals`, `voids_totals`,
 * `vat_breakdown`, `payment_method_totals`) and no top-level money at all, so
 * both `?? '0.00'` fallbacks fired on every row.
 *
 * **Which side is the contract — settled by evidence, not preference.** The
 * FLAT shape is the contract, agreed by one producer and three readers:
 *   - producer: `GrandtotalService::calculatePeriodTotals()` returns
 *     `array{gross_sales, net_sales, tax_amount, sales_count, refunds_count,
 *     refunds_amount}` and that array is `json_encode`d into the grand-total
 *     event's own fiscal hash (`GrandtotalService.php:103,114`);
 *   - reader 1: `GrandtotalEvent::getPeriodGrossSales()` / `getPeriodTaxAmount()`
 *     (`GrandtotalEvent.php:149,157`) read `period_totals['gross_sales']` /
 *     `['tax_amount']`;
 *   - reader 2: `Nf525DataProvider::mapGrandTotal()` (`:1296`) passes
 *     `$grandtotal->period_totals` through verbatim — the LEGACY grand-total path
 *     therefore already feeds the builder the flat shape and exports correctly;
 *   - reader 3: `Nf525XmlBuilder::addGrandTotals()` itself.
 * `mapCanonicalZReportGrandTotal` is the only place that invented a nested shape,
 * and `periodTotals` has no other consumer (grep: the DTO field is read solely by
 * the XML builder). So the provider is fixed, not the builder.
 *
 * The nested detail keys are KEPT alongside the flat ones: they are the only route
 * by which a canonical Z's `vat_breakdown` / `payment_method_totals` reach a
 * grand-total consumer, and deleting them is an unrelated change.
 */
final class Nf525CanonicalZGrandTotalPeriodTotalsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->genesisSeed = str_repeat('0', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $this->terminalId = $terminal->id;

        $this->operatorId = User::factory()->create(['tenant_id' => $this->tenantId])->id;
    }

    public function test_canonical_z_grand_total_exposes_the_flat_period_money_the_xml_builder_reads(): void
    {
        $this->createCanonicalZReport();

        $snapshot = $this->app->make(Nf525DataProvider::class)->buildExportSnapshot(
            $this->companyId,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->grandTotals);
        $periodTotals = $snapshot->grandTotals[0]->periodTotals;

        // The exact keys `Nf525XmlBuilder::addGrandTotals()` reads (:335-336).
        $this->assertSame('150.00', $periodTotals['gross_sales'] ?? null);
        $this->assertSame('25.00', $periodTotals['tax_amount'] ?? null);
        // Carried for parity with the legacy `period_totals` contract, which
        // `GrandtotalService::calculatePeriodTotals()` also emits.
        $this->assertSame('125.00', $periodTotals['net_sales'] ?? null);
        $this->assertSame(3, $periodTotals['sales_count'] ?? null);
        $this->assertSame(1, $periodTotals['refunds_count'] ?? null);
        $this->assertSame('10.00', $periodTotals['refunds_amount'] ?? null);

        // The nested detail is kept, not replaced.
        $this->assertIsArray($periodTotals['vat_breakdown'] ?? null);
        $this->assertIsArray($periodTotals['payment_method_totals'] ?? null);
    }

    public function test_the_grands_totaux_xml_carries_the_real_period_money_not_zero(): void
    {
        $this->createCanonicalZReport();

        $snapshot = $this->app->make(Nf525DataProvider::class)->buildExportSnapshot(
            $this->companyId,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        // End to end through the REAL builder — the defect was only ever visible
        // in the emitted document, so that is where it is pinned.
        $xml = (new Nf525XmlBuilder)
            ->createDocument()
            ->addGrandTotals($snapshot->grandTotals)
            ->toString();

        $this->assertStringContainsString('<VentesBrutes>150.00</VentesBrutes>', $xml);
        $this->assertStringContainsString('<Taxe>25.00</Taxe>', $xml);
        $this->assertStringNotContainsString('<VentesBrutes>0.00</VentesBrutes>', $xml);
        $this->assertStringNotContainsString('<Taxe>0.00</Taxe>', $xml);
    }

    /**
     * A trusted, device-authored Z: a `Z_REPORT` fiscal event whose `current_hash`
     * matches its own canonical bytes and whose `integrity_status` is Verified —
     * `Nf525DataProvider::isTrustedZReport()` (`:1181`) rejects anything less, and
     * only trusted rows reach `mapCanonicalZReportGrandTotal` (`:285-287`).
     */
    private function createCanonicalZReport(): void
    {
        $generatedAt = Carbon::now();
        $payload = [
            'business_date' => $generatedAt->toDateString(),
            'period_start' => $generatedAt->copy()->subHours(8)->toIso8601String(),
            'period_end' => $generatedAt->toIso8601String(),
            'receipt_totals' => [
                'count' => 3,
                'gross_sales' => '150.00',
                'net_sales' => '125.00',
                'tax_amount' => '25.00',
            ],
            'refunds_totals' => ['amount' => '10.00', 'count' => 1],
            'voids_totals' => ['count' => 0],
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '125.00', 'vat_amount' => '25.00', 'gross_amount' => '150.00'],
            ],
            'payment_method_totals' => [
                ['payment_type' => 'CASH', 'total_amount' => '150.00', 'transaction_count' => 3],
            ],
            'grand_totals_after' => ['lifetime_sales' => '150.00', 'lifetime_tax' => '25.00'],
        ];

        $canonicalBytes = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::Z_REPORT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $generatedAt,
            'business_date' => $generatedAt->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $generatedAt,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $this->genesisSeed,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => PayloadParseStatus::Parsed->value,
            'created_at' => $generatedAt,
        ]);

        // A Z report is produced when a shift CLOSES, and `pos_z_reports.shift_id`
        // is a real FK — so the backing shift is created and closed here.
        $shift = Shift::create([
            'terminal_id' => $this->terminalId,
            'cashier_id' => $this->operatorId,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Closed,
            'opened_at' => $generatedAt->copy()->subHours(8),
            'closed_at' => $generatedAt,
            'closed_by' => $this->operatorId,
        ]);

        ZReport::create([
            'terminal_id' => $this->terminalId,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => $currentHash,
            'previous_z_hash' => null,
            'report_data' => ['canonical_z_report' => $payload],
            'grand_totals' => ['lifetime_sales' => '150.00', 'lifetime_tax' => '25.00'],
            'fiscal_event_id' => $eventId,
            'generated_by' => $this->operatorId,
            'generated_at' => $generatedAt,
        ]);
    }
}
