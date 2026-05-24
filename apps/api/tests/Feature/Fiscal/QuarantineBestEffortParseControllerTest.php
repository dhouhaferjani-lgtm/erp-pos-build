<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class QuarantineBestEffortParseControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Terminal $terminal;

    private User $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);

        $this->resolver = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->resolver->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->resolver->givePermissionTo('fiscal.events.resolve_quarantine');
        Queue::fake();
    }

    public function test_best_effort_parse_for_in_table_canonical_parse_failure_is_tenant_scoped(): void
    {
        $logger = new CapturingFiscalLog;
        Log::swap($logger);
        Sanctum::actingAs($this->resolver);
        $event = $this->storeParseFailedEvent($this->badTaxNumberEnvelope());

        $response = $this->postJson("/api/v1/fiscal/quarantine/{$event->id}/best-effort-parse");

        $response->assertOk();
        $response->assertJsonPath('data.source', 'fiscal_events');
        $response->assertJsonPath('data.fiscal_event_id', $event->id);
        $response->assertJsonPath('data.event_type', 'SALE_RECEIPT');
        $response->assertJsonPath('data.parsed.total', '5.350');
        $response->assertJsonPath('data.parsed.seller.tax_number', 'TN-INVALID');

        $paths = array_column($response->json('data.defects'), 'path');
        $this->assertContains('payload.seller', $paths);

        $this->assertCount(1, $logger->entries);
        $this->assertSame('fiscal.quarantine.best_effort_parse_invoked', $logger->entries[0]['message']);
        $this->assertSame($this->tenant->id, $logger->entries[0]['context']['tenant_id']);
        $this->assertSame($this->resolver->id, $logger->entries[0]['context']['user_id']);
        $this->assertSame('fiscal_events', $logger->entries[0]['context']['source']);
        $this->assertSame($event->id, $logger->entries[0]['context']['id']);
        $this->assertSame($event->id, $logger->entries[0]['context']['fiscal_event_id']);
        $this->assertSame('SALE_RECEIPT', $logger->entries[0]['context']['event_type']);
        $this->assertContains('seller', $logger->entries[0]['context']['parsed_keys']);
        $this->assertGreaterThan(0, $logger->entries[0]['context']['defect_count']);
    }

    public function test_permission_gate_blocks_unprivileged_operator(): void
    {
        $event = $this->storeParseFailedEvent($this->badTaxNumberEnvelope());
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($unprivileged);

        $this->postJson("/api/v1/fiscal/quarantine/{$event->id}/best-effort-parse")
            ->assertForbidden();
    }

    public function test_cross_tenant_quarantine_id_is_not_disclosed(): void
    {
        $event = $this->storeParseFailedEvent($this->badTaxNumberEnvelope(), tenantId: Tenant::factory()->create()->id);
        Sanctum::actingAs($this->resolver);

        $this->postJson("/api/v1/fiscal/quarantine/{$event->id}/best-effort-parse")
            ->assertNotFound();
    }

    public function test_best_effort_parse_reads_non_admissible_quarantine_table_row(): void
    {
        $logger = new CapturingFiscalLog;
        Log::swap($logger);
        Sanctum::actingAs($this->resolver);
        $fallbackBytes = $this->validEnvelope();
        $rawEnvelopeBytes = $this->badTaxNumberEnvelope();
        $id = Str::uuid()->toString();
        $envelopeEventId = Str::uuid()->toString();

        DB::table('fiscal_event_quarantine')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => Str::uuid()->toString(),
            'envelope_event_id' => $envelopeEventId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'claimed_sequence_number' => 1,
            'event_time_device' => now()->utc(),
            'business_date' => now()->toDateString(),
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $fallbackBytes),
            'canonical_bytes' => $fallbackBytes,
            'raw_envelope' => json_encode(['payload' => ['canonical_bytes' => $rawEnvelopeBytes]], JSON_THROW_ON_ERROR),
            'payload_parse_status' => PayloadParseStatus::Failed->value,
            'integrity_exception_class' => IntegrityExceptionClass::MalformedEnvelope->value,
            'integrity_exception_reason' => 'operator-assist fixture',
            'conflicting_event_id' => Str::uuid()->toString(),
            'server_received_at' => now()->utc(),
            'created_at' => now()->utc(),
        ]);

        $response = $this->postJson("/api/v1/fiscal/quarantine/{$id}/best-effort-parse");

        $response->assertOk();
        $response->assertJsonPath('data.source', 'fiscal_event_quarantine');
        $response->assertJsonPath('data.fiscal_event_id', null);
        $response->assertJsonPath('data.parsed.seller.tax_number', 'TN-INVALID');

        $this->assertCount(1, $logger->entries);
        $this->assertSame('fiscal.quarantine.best_effort_parse_invoked', $logger->entries[0]['message']);
        $this->assertSame('fiscal_event_quarantine', $logger->entries[0]['context']['source']);
        $this->assertSame($id, $logger->entries[0]['context']['id']);
        $this->assertNull($logger->entries[0]['context']['fiscal_event_id']);
        $this->assertSame($this->tenant->id, $logger->entries[0]['context']['tenant_id']);
    }

    public function test_best_effort_parse_rejects_non_parse_failure_rows(): void
    {
        Sanctum::actingAs($this->resolver);
        $event = $this->storeParseFailedEvent($this->validEnvelope());
        $event->forceFill([
            'payload_parse_status' => PayloadParseStatus::Parsed,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'payload' => $this->saleReceiptPayload(),
        ])->save();

        $this->postJson("/api/v1/fiscal/quarantine/{$event->id}/best-effort-parse")
            ->assertConflict()
            ->assertJsonPath('error.code', 'NOT_PARSE_FAILURE');
    }

    public function test_resolve_parse_failure_endpoint_submits_corrected_payload_through_existing_service(): void
    {
        Sanctum::actingAs($this->resolver);
        $event = $this->storeParseFailedEvent($this->badTaxNumberEnvelope());

        $response = $this->postJson("/api/v1/fiscal/events/{$event->id}/resolve-parse-failure", [
            'corrected_payload' => $this->validCorrectedPayload(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $event->id);
        $response->assertJsonPath('data.status', 'resolved');

        $row = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(PayloadParseStatus::Parsed->value, $row->payload_parse_status);
        $this->assertSame(IntegrityStatus::Verified->value, $row->integrity_status);
        $this->assertSame($this->resolver->id, $row->integrity_resolved_by);
    }

    public function test_resolve_parse_failure_endpoint_is_tenant_scoped(): void
    {
        $event = $this->storeParseFailedEvent($this->badTaxNumberEnvelope(), tenantId: Tenant::factory()->create()->id);
        Sanctum::actingAs($this->resolver);

        $this->postJson("/api/v1/fiscal/events/{$event->id}/resolve-parse-failure", [
            'corrected_payload' => $this->validCorrectedPayload(),
        ])->assertNotFound();
    }

    private function storeParseFailedEvent(string $canonicalBytes, ?string $tenantId = null): FiscalEvent
    {
        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => Str::uuid()->toString(),
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => now()->utc(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now()->utc(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Quarantined,
            'integrity_exception_class' => IntegrityExceptionClass::CanonicalParseFailure->value,
            'integrity_exception_reason' => 'payload_seller_tax_number_invalid',
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Failed,
        ]);
    }

    private function badTaxNumberEnvelope(): string
    {
        $payload = $this->saleReceiptPayload();
        $payload['seller']['tax_number'] = 'TN-INVALID';

        return $this->envelopeForPayload($payload);
    }

    private function validEnvelope(): string
    {
        return $this->envelopeForPayload($this->saleReceiptPayload());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelopeForPayload(array $payload): string
    {

        $fields = [
            'business_date' => '2026-05-20',
            'company_id' => $this->company->id,
            'event_time_device' => '2026-05-20T14:30:00Z',
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 1,
            'operator_id' => Str::uuid()->toString(),
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
        ];
        ksort($fields);

        return (string) json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function saleReceiptPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
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
                'line_subtotal' => '5.000',
                'line_vat' => '0.350',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '5.000',
                'vat_rate' => '7.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '5.350',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '5.000',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '5.350',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '5.350',
                'net_amount' => '5.000',
                'rate' => '7.00',
                'tax_category_code' => '',
                'vat_amount' => '0.350',
            ]],
            'vat_total' => '0.350',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validCorrectedPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
            'approval_references' => [],
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
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
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
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];
    }
}

final class CapturingFiscalLog
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $entries = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->entries[] = [
            'message' => $message,
            'context' => $context,
        ];
    }
}
