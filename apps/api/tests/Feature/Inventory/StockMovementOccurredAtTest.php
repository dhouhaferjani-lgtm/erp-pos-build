<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task A1 — every stock-movement writer stamps `occurred_at` (event time), and
 * legacy rows are backfilled to `created_at`.
 *
 * `occurred_at` is the time the stock effect physically happened. For a
 * device-authored POS sale projected later it is the DEVICE event time
 * (`FiscalEvent::event_time_device`), NOT the server insert time — the
 * live-inventory-counting replay orders movements by this column so a physical
 * count taken while sales continue reconciles against the right timeline.
 *
 * Rule 20: the projection runs with NO CompanyContext (the queue worker carries
 * none). Each `apply()` is preceded by `CompanyContext::clear()` so a regression
 * that reintroduces a context dependency surfaces here rather than being masked.
 */
final class StockMovementOccurredAtTest extends TestCase
{
    use RefreshDatabase;

    /** A fixed device event time in the past — deliberately far from now() so a
     *  movement stamped with it is provably NOT stamped with the server clock. */
    private const DEVICE_TIME = '2026-05-20 14:30:00';

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        // Bound only for factory paths during setUp; CLEARED before every
        // projection apply() (rule 20 — the projection must not depend on it).
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
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

        // GL accounts required by OpeningBalancePostingService
        Account::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => '3100',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    // =================================================================
    // (a) a pre-existing row with NULL occurred_at is backfilled to created_at
    // =================================================================

    public function test_pre_existing_row_is_backfilled_to_created_at(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);

        // A legacy-shaped row: no occurred_at, a known historical created_at.
        $movement = StockMovement::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'location_id' => $this->locationId,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
        ]);
        DB::table('stock_movements')->where('id', $movement->id)->update([
            'created_at' => '2026-01-01 08:00:00',
            'occurred_at' => null,
        ]);

        // Invoke the migration's backfill contract directly (up() cannot be
        // re-run once the column exists).
        $migration = require database_path(
            'migrations/tenant/2026_07_06_200001_add_occurred_at_to_stock_movements.php'
        );
        $migration->backfillOccurredAt();

        $row = DB::table('stock_movements')->where('id', $movement->id)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->occurred_at);
        $this->assertSame(
            '2026-01-01 08:00:00',
            Carbon::parse($row->occurred_at)->format('Y-m-d H:i:s'),
            'legacy occurred_at must be backfilled to created_at'
        );
    }

    // =================================================================
    // (b) a POS sale projection movement carries the DEVICE event time
    // =================================================================

    public function test_pos_sale_projection_stamps_device_event_time(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $this->seedStockLevel($product->id, '10.0000');

        $event = $this->saleReceiptEvent($product->id, '2');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $movement = DB::table('stock_movements')->where('reason', 'pos_sale')->first();
        $this->assertNotNull($movement, 'the sale must write a pos_sale movement');
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(
            self::DEVICE_TIME,
            Carbon::parse($movement->occurred_at)->format('Y-m-d H:i:s'),
            'sale movement occurred_at must equal the device event time, not the server clock'
        );
    }

    // =================================================================
    // (c) a POS refund (restock) projection movement carries the device time
    // =================================================================

    public function test_pos_refund_projection_stamps_device_event_time_on_restock(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->saleReceiptEvent($product->id, '2');
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($sale);

        $refund = $this->refundReceiptEvent($sale, $product->id, '2');
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($refund);

        $movement = DB::table('stock_movements')->where('reason', 'pos_return')->first();
        $this->assertNotNull($movement, 'the refund must write a pos_return restock movement');
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(
            self::DEVICE_TIME,
            Carbon::parse($movement->occurred_at)->format('Y-m-d H:i:s'),
            'restock movement occurred_at must equal the refund device event time'
        );
    }

    // =================================================================
    // (d1) StockAdjustmentService::adjust() defaults occurred_at to now()
    // =================================================================

    public function test_stock_adjustment_defaults_occurred_at_to_now(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $this->seedStockLevel($product->id, '5.0000');

        $frozen = CarbonImmutable::parse('2026-06-15 09:00:00');
        $this->travelTo($frozen);

        $movement = $this->app->make(StockAdjustmentService::class)->adjust(
            productId: $product->id,
            locationId: $this->locationId,
            newQuantity: '7.0000',
            reason: 'count',
            userId: $this->operatorId,
            expectedCompanyId: $this->companyId,
        );

        $this->travelBack();

        $movement->refresh();
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(
            '2026-06-15 09:00:00',
            $movement->occurred_at->format('Y-m-d H:i:s'),
            'adjust() must default occurred_at to now()'
        );
    }

    // =================================================================
    // (d2) StockAdjustmentService::adjust() honors an explicit occurred_at
    // =================================================================

    public function test_stock_adjustment_honors_explicit_occurred_at(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $this->seedStockLevel($product->id, '5.0000');

        $explicit = CarbonImmutable::parse(self::DEVICE_TIME);

        $movement = $this->app->make(StockAdjustmentService::class)->adjust(
            productId: $product->id,
            locationId: $this->locationId,
            newQuantity: '9.0000',
            reason: 'replay-count',
            userId: $this->operatorId,
            expectedCompanyId: $this->companyId,
            occurredAt: $explicit,
        );

        $movement->refresh();
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(
            self::DEVICE_TIME,
            $movement->occurred_at->format('Y-m-d H:i:s'),
            'adjust() must honor an explicit occurred_at (replay path)'
        );
    }

    // =================================================================
    // (e) Opening balance posting stamps occurred_at with entryDate
    // =================================================================

    public function test_opening_balance_posting_stamps_entry_date(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);

        $backdatedEntryDate = CarbonImmutable::parse('2026-05-15 10:30:00');

        $posting = new OpeningBalancePosting(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            userId: $this->operatorId,
            entryDate: $backdatedEntryDate,
            isHistorical: false,
            sourceType: 'test',
            sourceId: Str::uuid()->toString(),
            reference: 'TEST-OB-001',
            notes: null,
            lines: [
                new OpeningBalanceLine(
                    productId: $product->id,
                    variantId: null,
                    locationId: $this->locationId,
                    quantity: '10.0000',
                    unitCost: '5.000',
                ),
            ],
        );

        $service = $this->app->make(OpeningBalancePostingService::class);
        $result = $service->post($posting);

        $movement = DB::table('stock_movements')->whereIn('id', $result->movementIdsInInputOrder)->first();
        $this->assertNotNull($movement, 'opening balance posting must create a movement');
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(
            '2026-05-15 10:30:00',
            Carbon::parse($movement->occurred_at)->format('Y-m-d H:i:s'),
            'opening balance movement occurred_at must equal the posting entry date'
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function seedStockLevel(string $productId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function refundReceiptEvent(FiscalEvent $original, string $productId, string $quantity): FiscalEvent
    {
        return $this->saleReceiptEvent(
            productId: $productId,
            quantity: $quantity,
            invoiceTypeCode: 'REFUND',
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'Customer changed mind',
            ],
        );
    }

    /**
     * Persist a verified SALE_RECEIPT fiscal event for a single non-variant
     * line, stamped with a FIXED past device time (self::DEVICE_TIME) so the
     * projected movement's occurred_at is provably the device time.
     *
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function saleReceiptEvent(
        string $productId,
        string $quantity,
        string $invoiceTypeCode = 'SALE',
        int $sequenceNumber = 1,
        string $receiptUuid = '00000000-0000-4000-8000-000000000001',
        ?array $originalReceiptReference = null,
    ): FiscalEvent {
        $eventTime = CarbonImmutable::parse(self::DEVICE_TIME, 'UTC');
        $businessDate = $eventTime->startOfDay();
        $previousHash = str_repeat('0', 64);

        $qtyCanonical = str_contains($quantity, '.') ? $quantity : $quantity.'.000';

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => '10.00',
            'line_vat' => '0.00',
            'name' => 'Occurred-At Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $qtyCanonical,
            'sku' => 'SKU-OA',
            'tax_category_code' => 'Z',
            'unit_price' => '10.00',
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ];

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s.v\Z'),
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [
                [
                    'amount' => '10.00',
                    'foreign_currency_amount' => null,
                    'foreign_currency_code' => null,
                    'instrument_serial' => null,
                    'instrument_type' => null,
                    'method_code' => 'CASH',
                ],
            ],
            'receipt_uuid' => $receiptUuid,
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
            'vat_breakdown' => [
                [
                    'gross_amount' => '10.00',
                    'net_amount' => '10.00',
                    'rate' => '0.00',
                    'tax_category_code' => 'Z',
                    'vat_amount' => '0.00',
                ],
            ],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 2,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 2,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
