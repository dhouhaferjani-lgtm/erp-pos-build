<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\RefundVatDisclosureData;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B-6(ii) / Option A2 — the DERIVED refund-VAT disclosure line.
 *
 * The signed Z payload cannot be decomposed back into "sales VAT" and "refund
 * VAT": `refunds_totals` carries `{count, amount}` only, and `vat_breakdown` is
 * already net. So the disclosure is derived server-side from
 * `pos_receipt_vat_details` × `receipt_type = 'return'` over the Z's window,
 * using the IDENTICAL per-row `-ABS` normalisation the VAT declaration arm uses
 * (`EloquentVatDataRepository.php:112-113`).
 *
 * These tests pin (a) the derivation itself, (b) that both refund sign eras
 * normalise to the same positive magnitude, (c) that the three figures satisfy
 * `sales_vat - refund_vat == net_vat`, and (d) that the window is `posted_at`
 * scoped so a neighbouring shift's refund never leaks in.
 */
class ZReportRefundVatDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private ReportGenerationService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private int $receiptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ReportGenerationService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_no_refunds_produces_an_empty_reconciled_disclosure(): void
    {
        $sale = $this->createSale('100.000', '19.000');
        $this->createVatDetail($sale, '19.00', '100.000', '19.000');

        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '100.000', 'vat_amount' => '19.000', 'gross_amount' => '119.000'],
        ], salesVat: '19.000'));

        $this->assertSame([], $disclosure->rows);
        $this->assertFalse($disclosure->has_refund_vat);
        $this->assertSame('0.000', $disclosure->refund_vat);
        $this->assertSame('19.000', $disclosure->sales_vat);
        $this->assertSame('19.000', $disclosure->net_vat);
        $this->assertTrue($disclosure->is_reconciled);
    }

    public function test_refund_vat_is_derived_per_rate_as_a_positive_magnitude(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $return = $this->createReturn($sale, '50.000', '9.500');
        $this->createVatDetail($return, '19.00', '50.000', '9.500');

        // Signed Z table is already NET: 57.000 − 9.500.
        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '250.000', 'vat_amount' => '47.500', 'gross_amount' => '297.500'],
        ], salesVat: '57.000'));

        $this->assertTrue($disclosure->has_refund_vat);
        $this->assertCount(1, $disclosure->rows);
        $this->assertSame('19.00', $disclosure->rows[0]->tax_rate);
        $this->assertSame('9.500', $disclosure->rows[0]->vat_amount);
        $this->assertSame('50.000', $disclosure->rows[0]->net_amount);
        $this->assertSame('59.500', $disclosure->rows[0]->gross_amount);

        $this->assertSame('57.000', $disclosure->sales_vat);
        $this->assertSame('9.500', $disclosure->refund_vat);
        $this->assertSame('47.500', $disclosure->net_vat);
        $this->assertTrue($disclosure->is_reconciled, 'sales_vat − refund_vat must equal net_vat');
    }

    /**
     * The `-ABS` normalisation is load-bearing, not decorative: the canonical
     * projection writes POSITIVE return rows and the legacy
     * `ReceiptReturnService` writes NEGATIVE ones. A bare `-` would flip the
     * legacy era back to positive and re-inflate the disclosure.
     */
    public function test_both_refund_sign_eras_normalise_to_the_same_magnitude(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $positiveEra = $this->createReturn($sale, '50.000', '9.500');
        $this->createVatDetail($positiveEra, '19.00', '50.000', '9.500');

        $legacyEra = $this->createReturn($sale, '-20.000', '-3.800');
        $this->createVatDetail($legacyEra, '19.00', '-20.000', '-3.800');

        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '230.000', 'vat_amount' => '43.700', 'gross_amount' => '273.700'],
        ], salesVat: '57.000'));

        $this->assertSame('13.300', $disclosure->refund_vat, '9.500 + 3.800, both as magnitudes');
        $this->assertSame('43.700', $disclosure->net_vat);
        $this->assertTrue($disclosure->is_reconciled);
    }

    public function test_voided_and_training_refunds_are_excluded(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $training = $this->createReturn($sale, '50.000', '9.500', ['is_training' => true]);
        $this->createVatDetail($training, '19.00', '50.000', '9.500');

        $voided = $this->createReturn($sale, '40.000', '7.600', [
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by' => $this->cashier->id,
            'void_reason' => 'test void',
        ]);
        $this->createVatDetail($voided, '19.00', '40.000', '7.600');

        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '300.000', 'vat_amount' => '57.000', 'gross_amount' => '357.000'],
        ], salesVat: '57.000'));

        $this->assertSame('0.000', $disclosure->refund_vat);
        $this->assertFalse($disclosure->has_refund_vat);
    }

    /**
     * `posted_at` is the canonical shift-window column (REALIGNMENT-LOG
     * 2026-04-26). A refund posted after the Z's `period_end` belongs to the
     * NEXT shift and must not appear on this Z's disclosure.
     */
    public function test_disclosure_window_is_scoped_by_posted_at(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $outOfWindow = $this->createReturn($sale, '50.000', '9.500', ['posted_at' => now()->addHours(4)]);
        $this->createVatDetail($outOfWindow, '19.00', '50.000', '9.500');

        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '300.000', 'vat_amount' => '57.000', 'gross_amount' => '357.000'],
        ], salesVat: '57.000'));

        $this->assertSame('0.000', $disclosure->refund_vat);
    }

    /**
     * `is_reconciled` is an honest flag, not decoration: when the projected
     * refund rows and the signed table disagree the report must say so rather
     * than print three numbers that do not add up.
     */
    public function test_unreconciled_window_is_flagged_rather_than_hidden(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $return = $this->createReturn($sale, '50.000', '9.500');
        $this->createVatDetail($return, '19.00', '50.000', '9.500');

        // Signed table claims NO netting happened — the contradiction A2 exists to surface.
        $disclosure = $this->service->refundVatDisclosureFor($this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '300.000', 'vat_amount' => '57.000', 'gross_amount' => '357.000'],
        ], salesVat: '57.000'));

        $this->assertSame('9.500', $disclosure->refund_vat);
        $this->assertSame('57.000', $disclosure->net_vat);
        $this->assertFalse($disclosure->is_reconciled);
    }

    /**
     * The blade must render the three-line disclosure; the sale-only headline
     * must no longer stand alone next to a net per-rate table.
     */
    public function test_pdf_view_data_carries_the_disclosure_and_renders_it(): void
    {
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        $return = $this->createReturn($sale, '50.000', '9.500');
        $this->createVatDetail($return, '19.00', '50.000', '9.500');

        $zReport = $this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '250.000', 'vat_amount' => '47.500', 'gross_amount' => '297.500'],
        ], salesVat: '57.000');

        $viewData = $this->service->viewDataFor($zReport);

        $this->assertArrayHasKey('refundVatDisclosure', $viewData);
        $this->assertInstanceOf(
            RefundVatDisclosureData::class,
            $viewData['refundVatDisclosure'],
        );

        $html = view('pos.z-report', $viewData)->render();

        $this->assertStringContainsString(__('pos.z_report_vat_on_sales'), $html);
        $this->assertStringContainsString(__('pos.z_report_vat_on_refunds'), $html);
        $this->assertStringContainsString(__('pos.z_report_net_vat'), $html);
        $this->assertStringContainsString(__('pos.z_report_refund_vat_breakdown'), $html);

        // No raw i18n key may reach a fiscal document.
        $this->assertStringNotContainsString('pos.z_report_', $html);
    }

    /**
     * A refund-free Z must render EXACTLY as before: one `tax_amount` line, no
     * disclosure block. The three-line shape is reserved for the shifts where
     * the headline and the table actually disagree.
     */
    public function test_refund_free_z_keeps_the_single_vat_line(): void
    {
        $sale = $this->createSale('100.000', '19.000');
        $this->createVatDetail($sale, '19.00', '100.000', '19.000');

        $zReport = $this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '100.000', 'vat_amount' => '19.000', 'gross_amount' => '119.000'],
        ], salesVat: '19.000');

        $html = view('pos.z-report', $this->service->viewDataFor($zReport))->render();

        $this->assertStringNotContainsString(__('pos.z_report_vat_on_refunds'), $html);
        $this->assertStringNotContainsString(__('pos.z_report_refund_vat_breakdown'), $html);
        $this->assertStringContainsString(__('pos.z_report_tax_amount'), $html);
    }

    /**
     * GATE r1 F-2 — THE SYNC-LAG CASE, the most likely real-world unreconciled
     * state and the one the blade could not report.
     *
     * A v3 device Z whose refund receipts have not yet synced/projected:
     * projections return no return rows (`has_refund_vat = false`) while the
     * SIGNED table is already net (`net_vat < sales_vat`), so `is_reconciled`
     * is false. The warning used to be nested INSIDE the `has_refund_vat`
     * branch, so the PDF printed the sale-only headline directly beneath a
     * table this lane had just labelled "net of refunds" — two disagreeing
     * figures, one of them newly mislabelled, and no warning.
     */
    public function test_sync_lag_z_prints_the_unreconciled_warning(): void
    {
        // A sale is projected; the refund that the signed table already nets is NOT.
        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');

        // Signed table is NET (47.500) while the headline is sale-only (57.000).
        $zReport = $this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '250.000', 'vat_amount' => '47.500', 'gross_amount' => '297.500'],
        ], salesVat: '57.000');

        $disclosure = $this->service->refundVatDisclosureFor($zReport);
        $this->assertFalse($disclosure->has_refund_vat, 'No return rows are projected yet');
        $this->assertFalse($disclosure->is_reconciled, '57.000 - 0 != 47.500');

        $html = view('pos.z-report', $this->service->viewDataFor($zReport))->render();

        $this->assertStringContainsString(
            __('pos.z_report_vat_unreconciled'),
            $html,
            'The warning must render independently of has_refund_vat',
        );
    }

    /**
     * The web Z-report detail page reads the disclosure off the DETAIL endpoint.
     * The list endpoint deliberately does not carry it (one aggregate query per
     * row would be an N+1 for a figure no list row renders).
     */
    public function test_detail_endpoint_exposes_the_disclosure(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->cashier->givePermissionTo('pos.view_reports');
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $sale = $this->createSale('300.000', '57.000');
        $this->createVatDetail($sale, '19.00', '300.000', '57.000');
        $return = $this->createReturn($sale, '50.000', '9.500');
        $this->createVatDetail($return, '19.00', '50.000', '9.500');

        $zReport = $this->createZReport([
            ['tax_rate' => '19.00', 'net_amount' => '250.000', 'vat_amount' => '47.500', 'gross_amount' => '297.500'],
        ], salesVat: '57.000');

        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/v1/pos/reports/z/{$zReport->z_number}?terminal_id={$this->terminal->id}");

        $response->assertOk();
        $response->assertJsonPath('data.refund_vat_disclosure.sales_vat', '57.000');
        $response->assertJsonPath('data.refund_vat_disclosure.refund_vat', '9.500');
        $response->assertJsonPath('data.refund_vat_disclosure.net_vat', '47.500');
        $response->assertJsonPath('data.refund_vat_disclosure.has_refund_vat', true);
        $response->assertJsonPath('data.refund_vat_disclosure.is_reconciled', true);
        $response->assertJsonPath('data.refund_vat_disclosure.rows.0.vat_amount', '9.500');
    }

    /**
     * @param  list<array<string, string>>  $vatBreakdown
     */
    private function createZReport(array $vatBreakdown, string $salesVat): ZReport
    {
        // `closed_at` + `closed_by` are NOT decoration: the PostgreSQL
        // `pos_shifts_closed_logic` CHECK requires both on a CLOSED shift.
        $shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opened_at' => now()->subHours(8),
            'opening_cash' => '100.000',
            'status' => 'CLOSED',
            'closed_at' => now(),
            'closed_by' => $this->cashier->id,
        ]);

        return ZReport::create([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => hash('sha256', 'z-refund-vat-disclosure'),
            'previous_z_hash' => null,
            'report_data' => [
                'schema_version' => 3,
                'period_start' => now()->subHours(8)->toIso8601String(),
                'period_end' => now()->addHour()->toIso8601String(),
                'sales_count' => 1,
                'gross_sales' => '357.000',
                'net_sales' => '300.000',
                'tax_amount' => $salesVat,
                'refunds_count' => 0,
                'refunds_amount' => '0.000',
                'voided_count' => 0,
                'opening_cash' => '100.000',
                'expected_cash' => '457.000',
                'actual_cash' => '457.000',
                'variance' => '0.000',
                'vat_breakdown' => $vatBreakdown,
                'payment_methods' => [],
            ],
            'generated_by' => $this->cashier->id,
            'generated_at' => now(),
        ]);
    }

    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $taxAmount
     */
    private function createSale(string $subtotal, string $taxAmount): Receipt
    {
        return $this->createReceipt([
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => bcadd($subtotal, $taxAmount, 3),
        ]);
    }

    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $taxAmount
     * @param  array<string, mixed>  $overrides
     */
    private function createReturn(Receipt $original, string $subtotal, string $taxAmount, array $overrides = []): Receipt
    {
        return $this->createReceipt(array_merge([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::Defective,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => bcadd($subtotal, $taxAmount, 3),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createReceipt(array $attributes): Receipt
    {
        $this->receiptSequence++;

        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'posted_at' => now(),
            'currency' => 'TND',
            'discount_amount' => '0.000',
            'is_training' => false,
            'is_voided' => false,
        ], $attributes));
    }

    /**
     * @param  numeric-string  $taxRate
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $vatAmount
     */
    private function createVatDetail(Receipt $receipt, string $taxRate, string $netAmount, string $vatAmount): ReceiptVatDetail
    {
        return ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => $taxRate,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'gross_amount' => bcadd($netAmount, $vatAmount, 3),
        ]);
    }
}
