<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pass 2A.PHP.2 — synthesis v5 §8.B Nf525 canonical-only round-trip tests.
 *
 * Validates that fiscal-event-backed Nf525 exports round-trip the
 * canonical-only fields (`gtin`, `tax_category_code`, `foreign_currency_*`)
 * from `fiscal_events.payload` into the NF525 DTOs (Nf525ReceiptLineData /
 * Nf525ReceiptPaymentData / Nf525ReceiptVatDetailData).
 *
 * These fields are NOT projected to `pos_receipt_*` columns — they exist
 * only on the canonical payload. The bifurcated `mapSaleReceiptFromCanonical`
 * surface reads them via `CanonicalPayloadReader::forSaleReceipt()`.
 *
 * Legacy receipts (`fiscal_event_id IS NULL`) keep using
 * `mapSaleReceiptLegacy` and source from the `pos_receipt_*` mirror —
 * regression-guarded by a dedicated test below.
 */
final class Nf525ExportCanonicalRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

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
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);
    }

    public function test_gtin_round_trips_from_canonical_payload_to_nf525_line_dto(): void
    {
        // Pass 2A.PHP.2 — `line_items[].gtin` is a canonical-only field
        // (synthesis v5 §3 — DSFinV-K future). NOT projected to
        // pos_receipt_lines columns. The fiscal-event-backed export must
        // surface it via mapLineFromCanonical.
        $payload = $this->canonicalPayload([
            'line_items' => [[
                'gtin' => '4006381333931', // canonical-only field
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '2.00',
                'name' => 'Item with GTIN',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-GTIN',
                'tax_category_code' => '',
                'unit_price' => '10.00',
                'vat_rate' => '20.00',
            ]],
        ]);

        $this->seedFiscalEventAndReceipt($payload, sequenceNumber: 1);

        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales);
        $line = $snapshot->sales[0]->lines[0];
        $this->assertSame('4006381333931', $line->gtin, 'GTIN must round-trip from canonical payload.');
    }

    public function test_tax_category_code_round_trips_at_both_line_and_breakdown_level(): void
    {
        // Pass 2A.PHP.2 — `tax_category_code` is the unified KSA BT-151 /
        // IT Natura axis. Canonical-only at both the line-item and
        // vat_breakdown levels.
        $payload = $this->canonicalPayload([
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Zero-rated item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-Z',
                'tax_category_code' => 'Z', // <-- canonical-only field
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'subtotal' => '10.00',
            'vat_total' => '0.00',
            'total' => '10.00',
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z', // <-- canonical-only field
                'vat_amount' => '0.00',
            ]],
        ]);

        $this->seedFiscalEventAndReceipt($payload, sequenceNumber: 2);

        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales);
        $entry = $snapshot->sales[0];
        $this->assertSame('Z', $entry->lines[0]->taxCategoryCode, 'tax_category_code must round-trip on line.');
        $this->assertSame('Z', $entry->vatDetails[0]->taxCategoryCode, 'tax_category_code must round-trip on vat breakdown.');
    }

    public function test_foreign_currency_amount_and_code_round_trip_on_payment_dto(): void
    {
        // Pass 2A.PHP.2 — `foreign_currency_amount` + `foreign_currency_code`
        // are canonical-only fields for FX-leg payments (synthesis v5 §3).
        $payload = $this->canonicalPayload([
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => '11.50', // canonical-only
                'foreign_currency_code' => 'USD',     // canonical-only
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
        ]);

        $this->seedFiscalEventAndReceipt($payload, sequenceNumber: 3);

        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales);
        $payment = $snapshot->sales[0]->payments[0];
        $this->assertSame('11.50', $payment->foreignCurrencyAmount);
        $this->assertSame('USD', $payment->foreignCurrencyCode);
    }

    public function test_legacy_path_still_exports_when_fiscal_event_id_is_null(): void
    {
        // Pass 2A.PHP.2 regression guard — legacy receipts
        // (fiscal_event_id IS NULL) MUST keep exporting via
        // mapSaleReceiptLegacy, sourcing from the pos_receipts mirror.
        // A regression that broke the legacy arm would silently corrupt
        // exports for pre-Phase-1 receipts.
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            // Legacy: no fiscal_event_id linkage.
            'fiscal_event_id' => null,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => $this->genesisSeed,
            'chain_sequence' => 999,
            'receipt_year' => (int) Carbon::now('UTC')->format('Y'),
            'posted_at' => Carbon::now('UTC'),
            'subtotal' => '7.00',
            'tax_amount' => '1.40',
            'discount_amount' => '0.00',
            'total' => '8.40',
            'currency' => 'EUR',
        ]);
        unset($receipt);

        $provider = $this->app->make(Nf525DataProvider::class);
        $snapshot = $provider->buildExportSnapshot(
            $this->companyId,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales, 'Legacy receipt without fiscal_event_id must still surface in the export.');
        // Legacy path sources from the mirror, NOT the canonical payload.
        // The Receipt cast normalizes monetary to `decimal:3`, so the
        // mirror surface emits '7.000' / '8.400' — distinct from the
        // canonical-path '7.00' / '8.40' (which preserve currency_scale).
        $this->assertSame('7.000', $snapshot->sales[0]->subtotal);
        $this->assertSame('EUR', $snapshot->sales[0]->currency);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function canonicalPayload(array $overrides): array
    {
        $base = [
            'business_date' => Carbon::now('UTC')->toDateString(),
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '2.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '10.00',
                'vat_rate' => '20.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '12.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '12.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '12.00',
                'net_amount' => '10.00',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '2.00',
            ]],
            'vat_total' => '2.00',
            'vouchers_redeemed' => [],
        ];

        return array_replace($base, $overrides);
    }

    /**
     * Persist a fiscal_events row + linked pos_receipts row (bypassing
     * OutboxIngestor + PosCoreReceiptProjection). Used by the Nf525
     * export round-trip tests to drive the export over a fiscal-event-
     * backed receipt with a known canonical payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function seedFiscalEventAndReceipt(array $payload, int $sequenceNumber): void
    {
        $eventTime = Carbon::now('UTC');
        $canonicalBytes = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
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

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'fiscal_event_id' => $eventId,
            'fiscal_hash' => $currentHash,
            'previous_hash' => $this->genesisSeed,
            'chain_sequence' => $sequenceNumber,
            'receipt_year' => (int) $eventTime->format('Y'),
            'posted_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'subtotal' => $payload['subtotal'],
            'tax_amount' => $payload['vat_total'],
            'discount_amount' => $payload['transaction_discount_amount'],
            'total' => $payload['total'],
            'currency' => $payload['currency_code'],
        ]);
        unset($receipt);
    }
}
