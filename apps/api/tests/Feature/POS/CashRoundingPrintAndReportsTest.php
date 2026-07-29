<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\Nf525\Nf525XmlBuilder;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cash-rounding Phase 1 / Task 12 — the three *outbound* surfaces of a rounded
 * receipt: the printed/PDF ticket, the NF525 JET export, and the shift-level
 * tolerance drill-down endpoint.
 *
 * Storage contract these tests are written against (Tasks 7 + 8):
 *
 *   - `pos_receipts.cash_rounding_adjustment` is `decimal(12,3)` NULLABLE and
 *     SIGNED (`rounded − exact`). A v3 receipt ALWAYS writes it — `'0.000'`
 *     when nothing was rounded — so NULL means "pre-v3 receipt", never
 *     "v3 receipt that happened not to round".
 *   - `pos_receipts.tolerance_writeoff` is likewise `'0.000'` (not NULL) on a
 *     v3 row with no shortfall.
 *
 * That distinction drives two different emission rules, and both are pinned
 * below:
 *
 *   - PRINT renders the rounding line only when the adjustment is non-null
 *     AND non-zero — a customer-facing ticket must not show a "Cash rounding:
 *     0.000" line.
 *   - The NF525 EXPORT emits `ArrondiEspeces` / `TotalExact` whenever the
 *     adjustment is present, zero included: presence is the v1/v2-vs-v3
 *     discriminator an auditor reads, and suppressing a zero would make a
 *     rounded-capable ticket indistinguishable from a legacy one.
 *
 * The fixture currency is TND (scale 3) throughout, because scale-3 money
 * ("9.973", "-0.023") could never have survived ingestion on a scale-2
 * currency — a EUR fixture here would be one that cannot exist in production.
 */
final class CashRoundingPrintAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->genesisSeed = str_repeat('0', 64);

        $this->tenant = Tenant::factory()->create();

        // `locale = en_US` + `currency = TND` makes the blade's ICU money
        // renderer emit three fraction digits with a dot separator, so the
        // assertions below can pin the RENDERED string exactly. ICU derives
        // the fraction digits from the CURRENCY, which is precisely the
        // "render at the receipt's currency scale" rule the rounding line
        // must follow.
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'en_US',
        ]);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::firstOrCreate([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
        ], ['role' => 'admin']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->cashier->givePermissionTo('pos.operate_terminal');

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // =================================================================
    // Server print / PDF template
    // =================================================================

    public function test_receipt_blade_renders_a_rounding_line_when_the_adjustment_is_non_zero(): void
    {
        $receipt = $this->seedReceiptRow(
            subtotal: '9.973',
            total: '9.950',
            cashRoundingAdjustment: '-0.023',
        );

        $html = $this->renderReceiptHtml($receipt);

        // Test RENDERED OUTPUT, never CSS class names. THREE fraction digits:
        // the value must print at the receipt currency's scale, not a
        // hardcoded one — at EUR's scale 2 this would read "-0.02" and the
        // ticket would no longer foot.
        $this->assertStringContainsString(__('pos.cash_rounding'), $html);
        $this->assertStringContainsString('-TND 0.023', $this->normalizeSpaces($html));
    }

    /**
     * A v1/v2 receipt has NULL in the column and must print byte-identically
     * to how it printed before this feature existed.
     */
    public function test_receipt_blade_omits_the_rounding_line_on_a_legacy_receipt(): void
    {
        $receipt = $this->seedReceiptRow(
            subtotal: '9.973',
            total: '9.973',
            cashRoundingAdjustment: null,
        );

        $html = $this->renderReceiptHtml($receipt);

        $this->assertStringNotContainsString(__('pos.cash_rounding'), $html);
    }

    /**
     * The other half of the print rule: a v3 receipt that rounded nothing
     * stores `'0.000'`, not NULL. A null-check alone would print a
     * "Cash rounding: TND 0.000" line on every unrounded v3 ticket.
     */
    public function test_receipt_blade_omits_the_rounding_line_when_a_v3_receipt_rounded_nothing(): void
    {
        $receipt = $this->seedReceiptRow(
            subtotal: '9.973',
            total: '9.973',
            cashRoundingAdjustment: '0.000',
        );

        $html = $this->renderReceiptHtml($receipt);

        $this->assertStringNotContainsString(__('pos.cash_rounding'), $html);
    }

    // =================================================================
    // NF525 JET export
    // =================================================================

    public function test_nf525_export_carries_the_adjustment_and_the_exact_total(): void
    {
        $this->seedFiscalEventAndReceipt(
            sequenceNumber: 1,
            eventVersion: 3,
            subtotal: '9.973',
            total: '9.950',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
        );

        $data = $this->exportedSale();

        $this->assertSame('-0.023', $data->cashRoundingAdjustment);
        $this->assertSame('9.950', $data->total, 'Total stays the SIGNED, collected amount.');

        $xml = $this->app->make(Nf525XmlBuilder::class)->createDocument()->addReceipts([$data])->toString();

        $this->assertStringContainsString('<ArrondiEspeces>-0.023</ArrondiEspeces>', $xml);
        $this->assertStringContainsString('<TotalExact>9.973</TotalExact>', $xml);
        $this->assertStringContainsString('<Total>9.950</Total>', $xml);
    }

    public function test_nf525_export_emits_no_rounding_elements_for_a_legacy_receipt(): void
    {
        $this->seedFiscalEventAndReceipt(
            sequenceNumber: 2,
            eventVersion: 2,
            subtotal: '9.973',
            total: '9.973',
            cashRoundingAdjustment: null,
            cashRoundingDenomination: null,
        );

        $data = $this->exportedSale();
        $this->assertNull($data->cashRoundingAdjustment);

        $xml = $this->app->make(Nf525XmlBuilder::class)->createDocument()->addReceipts([$data])->toString();

        $this->assertStringNotContainsString('ArrondiEspeces', $xml);
        $this->assertStringNotContainsString('TotalExact', $xml);
    }

    /**
     * Unlike the printed ticket, a ZERO adjustment IS exported: its presence
     * is what tells an auditor the ticket came off a rounding-capable (v3)
     * terminal and simply landed on a denomination boundary.
     */
    public function test_nf525_export_emits_a_zero_adjustment_for_an_unrounded_v3_receipt(): void
    {
        $this->seedFiscalEventAndReceipt(
            sequenceNumber: 3,
            eventVersion: 3,
            subtotal: '9.950',
            total: '9.950',
            cashRoundingAdjustment: '0.000',
            cashRoundingDenomination: '0.050',
        );

        $data = $this->exportedSale();

        $this->assertSame('0.000', $data->cashRoundingAdjustment);

        $xml = $this->app->make(Nf525XmlBuilder::class)->createDocument()->addReceipts([$data])->toString();

        $this->assertStringContainsString('<ArrondiEspeces>0.000</ArrondiEspeces>', $xml);
        $this->assertStringContainsString('<TotalExact>9.950</TotalExact>', $xml);
    }

    // =================================================================
    // Tolerance write-off drill-down endpoint
    // =================================================================

    public function test_tolerance_drill_down_endpoint_returns_receipt_level_rows(): void
    {
        $shift = $this->seedOpenShift($this->terminal);

        $this->seedReceiptRow(
            subtotal: '9.973',
            total: '9.950',
            cashRoundingAdjustment: '-0.023',
            extra: ['tolerance_writeoff' => '0.050', 'receipt_number' => 'SHORTFALL-1'],
        );

        // A v3 receipt with no shortfall stores '0.000', NOT NULL. The query
        // service filters `tolerance_writeoff > 0`, so this row must not
        // appear — pinning that the "always write the column" projection
        // contract composes with the "> 0" filter.
        $this->seedReceiptRow(
            subtotal: '9.973',
            total: '9.950',
            cashRoundingAdjustment: '-0.023',
            extra: ['tolerance_writeoff' => '0.000', 'receipt_number' => 'NO-SHORTFALL-1'],
        );

        $response = $this->actingAsCashier()
            ->getJson('/api/v1/pos/shifts/'.$shift->id.'/tolerance-receipts');

        $response->assertOk();
        $rows = $response->json('data');

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('SHORTFALL-1', $rows[0]['receiptNumber']);
        $this->assertSame('0.050', $rows[0]['writeoffAmount']);
        $this->assertSame('TND', $rows[0]['currencyCode']);
    }

    public function test_tolerance_drill_down_rejects_a_shift_from_another_company(): void
    {
        $foreignCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $foreignCompany->id,
            'location_id' => $foreignLocation->id,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $foreignShift = $this->seedOpenShift($foreignTerminal);

        $this->actingAsCashier()
            ->getJson('/api/v1/pos/shifts/'.$foreignShift->id.'/tolerance-receipts')
            ->assertStatus(403);
    }

    public function test_tolerance_drill_down_requires_the_operate_terminal_permission(): void
    {
        $shift = $this->seedOpenShift($this->terminal);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->cashier->revokePermissionTo('pos.operate_terminal');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsCashier()
            ->getJson('/api/v1/pos/shifts/'.$shift->id.'/tolerance-receipts')
            ->assertStatus(403);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function actingAsCashier(): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }

    /**
     * ICU separates a currency code from its amount with U+00A0 (and, in some
     * locales, U+202F). Fold those to a plain space so the money assertions
     * can be written readably without pasting invisible characters.
     */
    private function normalizeSpaces(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
    }

    private function renderReceiptHtml(Receipt $receipt): string
    {
        $service = $this->app->make(ReceiptPdfService::class);

        return view('pos.receipt', $service->viewDataFor($receipt))->render();
    }

    /**
     * The single sale entry of a freshly built export snapshot.
     */
    private function exportedSale(): Nf525ReceiptData
    {
        $snapshot = $this->app->make(Nf525DataProvider::class)->buildExportSnapshot(
            $this->company->id,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales, 'Fixture receipt did not reach the sales section.');

        return $snapshot->sales[0];
    }

    /**
     * A projected `pos_receipts` row. Every fixture satisfies the
     * `pos_receipts_totals` CHECK —
     * `total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)`
     * — so these tests are PG-meaningful by construction.
     *
     * @param  array<string, mixed>  $extra
     */
    private function seedReceiptRow(
        string $subtotal,
        string $total,
        ?string $cashRoundingAdjustment,
        array $extra = [],
    ): Receipt {
        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'posted_at' => Carbon::now('UTC'),
            'subtotal' => $subtotal,
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => $total,
            'currency' => 'TND',
            'cash_rounding_adjustment' => $cashRoundingAdjustment,
        ], $extra));
    }

    private function seedOpenShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '0.000',
            'status' => ShiftStatus::Open,
            'opened_at' => Carbon::now('UTC')->subHour(),
        ]);
    }

    /**
     * Persist a `fiscal_events` row + its linked `pos_receipts` row (bypassing
     * OutboxIngestor + PosCoreReceiptProjection), so the NF525 export runs over
     * a fiscal-event-backed receipt with a known canonical payload.
     *
     * The two rounding keys are added ONLY at v3+, exactly as
     * `SaleReceiptPayload::toArray()` emits them: a v1/v2 payload stays
     * key-for-key identical to what the device signs today.
     */
    private function seedFiscalEventAndReceipt(
        int $sequenceNumber,
        int $eventVersion,
        string $subtotal,
        string $total,
        ?string $cashRoundingAdjustment,
        ?string $cashRoundingDenomination,
    ): void {
        $eventTime = Carbon::now('UTC');

        $payload = [
            'business_date' => $eventTime->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $subtotal,
                'line_vat' => '0.000',
                'name' => 'Rounded item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-ROUND',
                'tax_category_code' => 'Z',
                'unit_price' => $subtotal,
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => $total,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-00000000000'.$sequenceNumber,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 avenue Habib Bourguiba'],
                'name' => 'Default Seller SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $subtotal,
                'net_amount' => $subtotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        if ($eventVersion >= 3) {
            $payload['cash_rounding_adjustment'] = $cashRoundingAdjustment;
            $payload['cash_rounding_denomination'] = $cashRoundingDenomination;
        }

        $canonicalBytes = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $eventTime->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
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
            'created_at' => $eventTime,
        ]);

        $this->seedReceiptRow(
            subtotal: $subtotal,
            total: $total,
            cashRoundingAdjustment: $eventVersion >= 3 ? ($cashRoundingAdjustment ?? '0.000') : null,
            extra: [
                'fiscal_event_id' => $eventId,
                'fiscal_hash' => $currentHash,
                'previous_hash' => $this->genesisSeed,
                'chain_sequence' => $sequenceNumber,
                'receipt_year' => (int) $eventTime->format('Y'),
                'canonical_bytes' => $canonicalBytes,
            ],
        );
    }
}
