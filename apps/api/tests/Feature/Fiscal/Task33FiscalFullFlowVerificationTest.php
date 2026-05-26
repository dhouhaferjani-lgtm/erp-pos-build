<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 33 — full-flow Phase 1 closure verification.
 *
 * Device authors + seals a SALE_RECEIPT event, syncs through
 * `/api/v1/pos/sync/fiscal-events`, the server ingests + verifies it,
 * projection jobs materialize POS/Treasury rows, and NF525 export reads the
 * fiscal-event-backed receipt from canonical payload rather than legacy
 * mirrors. The assertions pin byte-equivalence across device, ledger, and
 * POS projection plus canonical-only export fields.
 */
final class Task33FiscalFullFlowVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $user;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('0', 64);

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Task 33 Cashier',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($this->company);
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
    }

    public function test_device_sale_receipt_syncs_projects_and_exports_from_canonical_bytes(): void
    {
        Sanctum::actingAs($this->user);

        $eventId = Str::uuid()->toString();
        $eventTime = Carbon::now('UTC')->subSeconds(30);
        $eventTimeDevice = $eventTime->format('Y-m-d\TH:i:s\Z');
        $payloadEventTimeDevice = $eventTime->format('Y-m-d\TH:i:s.000\Z');
        $businessDate = $eventTime->toDateString();
        $payload = $this->saleReceiptPayload($eventId, $payloadEventTimeDevice, $businessDate);
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            payload: $payload,
            eventTimeDevice: $eventTimeDevice,
            businessDate: $businessDate,
        );
        $canonicalBytes = $envelope['payload']['canonical_bytes'];
        $currentHash = $envelope['payload']['current_hash'];

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envelope],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.fiscal_event_id', $eventId);
        $response->assertJsonPath('results.0.sequence_conflict', false);
        $response->assertJsonPath('results.0.exception_class', null);

        $event = DB::table('fiscal_events')->where('id', $eventId)->first();
        $this->assertNotNull($event);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame($this->company->id, $event->company_id);
        $this->assertSame(FiscalEventType::SALE_RECEIPT->value, $event->event_type);
        $this->assertSame(1, (int) $event->sequence_number);
        $this->assertSame($this->genesisSeed, $event->previous_hash);
        $this->assertSame($currentHash, $event->current_hash);
        $this->assertSame($currentHash, hash('sha256', $canonicalBytes));
        $this->assertSame($canonicalBytes, $event->canonical_bytes);
        $this->assertSame(IntegrityStatus::Verified->value, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed->value, $event->payload_parse_status);

        $receipt = DB::table('pos_receipts')->where('fiscal_event_id', $eventId)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($canonicalBytes, $receipt->canonical_bytes);
        $this->assertSame($currentHash, $receipt->fiscal_hash);
        $this->assertSame($this->genesisSeed, $receipt->previous_hash);
        $this->assertSame(1, (int) $receipt->chain_sequence);
        $this->assertSame('EUR', $receipt->currency);

        $this->assertDatabaseHas('pos_receipt_lines', [
            'receipt_id' => $receipt->id,
            'product_code' => 'TASK33-GTIN',
            'product_name' => 'Task 33 canonical item',
        ]);
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'payment_method_code' => 'CASH',
            'amount' => '10.000',
        ]);
        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'pos_core_receipt',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'treasury_receipt_bridge',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'fiscal_event_id' => $eventId,
            'origin' => PaymentOrigin::Pos->value,
            'amount' => '10.000',
        ]);

        $snapshot = $this->app->make(Nf525DataProvider::class)->buildExportSnapshot(
            $this->company->id,
            Carbon::parse($businessDate, 'UTC')->subDay(),
            Carbon::parse($businessDate, 'UTC')->addDay(),
        );

        $this->assertCount(1, $snapshot->sales);
        $sale = $snapshot->sales[0];
        $this->assertSame($receipt->id, $sale->id);
        $this->assertSame($currentHash, $sale->fiscalHash);
        $this->assertSame($this->genesisSeed, $sale->previousHash);
        $this->assertSame(1, $sale->chainSequence);
        $this->assertSame('10.00', $sale->subtotal);
        $this->assertSame('10.00', $sale->total);
        $this->assertSame('4006381333931', $sale->lines[0]->gtin);
        $this->assertSame('Z', $sale->lines[0]->taxCategoryCode);
        $this->assertSame('Z', $sale->vatDetails[0]->taxCategoryCode);
        $this->assertSame('11.50', $sale->payments[0]->foreignCurrencyAmount);
        $this->assertSame('USD', $sale->payments[0]->foreignCurrencyCode);
        $this->assertSame([], $snapshot->quarantineSection);
        $this->assertSame([], $snapshot->tamperedSection);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sealedEnvelope(
        string $eventId,
        array $payload,
        string $eventTimeDevice,
        string $businessDate,
    ): array {
        $base = [
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTimeDevice,
            'business_date' => $businessDate,
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => $this->genesisSeed,
        ];

        $canonicalArray = [
            'business_date' => $base['business_date'],
            'chain_context' => $base['chain_context'],
            'company_id' => $base['company_id'],
            'event_time_device' => $base['event_time_device'],
            'event_type' => $base['event_type'],
            'event_version' => $base['event_version'],
            'operator_id' => $base['operator_id'],
            'payload' => $payload,
            'previous_hash' => $base['previous_hash'],
            'reference_document_id' => $base['reference_document_id'],
            'reference_event_id' => $base['reference_event_id'],
            'sequence_number' => $base['sequence_number'],
            'signature_version' => $base['signature_version'],
            'tenant_id' => $base['tenant_id'],
            'terminal_id' => $base['terminal_id'],
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $base['canonical_bytes'] = $canonicalBytes;
        $base['current_hash'] = hash('sha256', $canonicalBytes);

        return [
            'envelope_id' => Str::uuid()->toString(),
            'type' => 'FISCAL_EVENT',
            'payload_version' => 1,
            'idempotency_key' => $this->terminal->id.':1',
            'payload' => $base,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleReceiptPayload(string $receiptUuid, string $eventTimeDevice, string $businessDate): array
    {
        return [
            'business_date' => $businessDate,
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => $eventTimeDevice,
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => '4006381333931',
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Task 33 canonical item',
                'non_collected_subtype' => null,
                'product_id' => 'task-33-canonical-snapshot',
                'quantity' => '1.000',
                'sku' => 'TASK33-GTIN',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => '11.50',
                'foreign_currency_code' => 'USD',
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => [
                    'city' => 'Paris',
                    'country_code' => 'FR',
                    'postal_code' => '75001',
                    'street' => '1 rue de la Paix',
                ],
                'name' => 'Task 33 Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => $this->terminal->id,
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

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed in Task 33 fixture');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
        }

        ksort($value);

        return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
    }
}
