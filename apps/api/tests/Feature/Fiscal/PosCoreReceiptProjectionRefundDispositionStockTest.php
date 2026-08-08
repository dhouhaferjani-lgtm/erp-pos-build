<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §10 — disposition-aware stock restore
 * for a v4 REFUND.
 *
 * `restock` restores stock (unless the product's own `RestockPolicyResolver`
 * resolves `RestockPolicy::Never` — "regulated never-restock honored");
 * `scrap` / `not_received` do not.
 *
 * Rule 20 — every `apply()` call clears `CompanyContext` first.
 */
final class PosCoreReceiptProjectionRefundDispositionStockTest extends TestCase
{
    use RefreshDatabase;

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

        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
            'fiscal_schema_version' => 3,
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
    }

    public function test_restock_disposition_restores_stock(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);
        $stockLevel = $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);
        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity, 'sale must decrement first');

        $refund = $this->v4RefundEvent($sale, $product->id, '2.000', 'restock', sequenceNumber: 2);
        $this->project($refund);

        $stockLevel->refresh();
        self::assertSame('7.0000', $stockLevel->quantity);
    }

    /**
     * DPA V10 — the v4 device-refund projection must produce the SAME two-leg
     * SCRAP outcome as the interactive server return path: restore (+qty,
     * `pos_return`) then a COST-BEARING write-off (−qty, `write_off`) carrying
     * a movement-keyed Dr COGS / Cr Inventory journal entry. Net sellable
     * quantity is unchanged — what changes is that the destruction is now
     * recorded and costed instead of silently skipped.
     */
    public function test_scrap_disposition_records_costed_two_leg_write_off(): void
    {
        $this->seedWriteOffAccounts();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'cost_price' => '3.000000',
        ]);
        $stockLevel = $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);
        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity);

        $refund = $this->v4RefundEvent($sale, $product->id, '2.000', 'scrap', sequenceNumber: 2);
        $this->project($refund);

        // Net sellable quantity unchanged — the two legs cancel.
        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity, 'scrap nets to zero sellable change');

        // Leg 1 — restore.
        self::assertSame(1, StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::POSReturn->value)
            ->count());

        // Leg 2 — costed write-off carrying the S0 document linkage.
        $writeOff = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->sole();

        self::assertSame('3.000000', (string) $writeOff->unit_cost);
        self::assertSame('6.000000', (string) $writeOff->total_cost);
        self::assertSame(
            StockMovementReferenceType::PosReceiptReturnScrap->value,
            $writeOff->reference_type,
        );

        // occurred_at = DEVICE event time of the refund event (same rule the
        // restore leg follows), not server wall-clock.
        self::assertNotNull($writeOff->occurred_at);
        self::assertSame(
            $refund->event_time_device->toIso8601String(),
            $writeOff->occurred_at->toIso8601String(),
        );

        // Movement-keyed Dr COGS / Cr Inventory. EUR scale 2: 2.000 × 3 = 6.00
        $entry = JournalEntry::query()
            ->where('company_id', $this->companyId)
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $writeOff->id)
            ->with('lines')
            ->sole();

        self::assertSame('6.00', (string) $entry->lines->firstWhere('debit', '>', '0')->debit);
        self::assertSame('6.00', (string) $entry->lines->firstWhere('credit', '>', '0')->credit);
    }

    /**
     * Replay safety: `apply()` is guarded by the `pos_receipts.fiscal_event_id`
     * idempotency probe, so re-projecting the SAME refund event must not write
     * a second write-off movement (and therefore not a second journal entry —
     * the entry is keyed on the movement id).
     */
    public function test_scrap_disposition_write_off_is_idempotent_on_replay(): void
    {
        $this->seedWriteOffAccounts();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'cost_price' => '3.000000',
        ]);
        $stockLevel = $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);

        $refund = $this->v4RefundEvent($sale, $product->id, '2.000', 'scrap', sequenceNumber: 2);
        $this->project($refund);
        $this->project($refund);
        $this->project($refund);

        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity);

        self::assertSame(1, StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->count());
        self::assertSame(1, StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::POSReturn->value)
            ->count());
        self::assertSame(1, JournalEntry::query()
            ->where('company_id', $this->companyId)
            ->where('source_type', 'batch_write_off')
            ->count());
    }

    public function test_not_received_disposition_does_not_restock(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);
        $stockLevel = $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);
        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity);

        $refund = $this->v4RefundEvent($sale, $product->id, '2.000', 'not_received', sequenceNumber: 2);
        $this->project($refund);

        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity, 'not_received must NOT restore stock');
    }

    public function test_regulated_never_restock_product_is_not_restocked_even_with_restock_disposition(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'restock_policy' => RestockPolicy::Never,
        ]);
        $stockLevel = $this->seedStockLevel($product->id, '10.0000');

        $sale = $this->v4SaleEvent($product->id, '5.000', sequenceNumber: 1);
        $this->project($sale);
        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity);

        // Disposition says restock, but the product is regulated never-restock.
        $refund = $this->v4RefundEvent($sale, $product->id, '2.000', 'restock', sequenceNumber: 2);
        $this->project($refund);

        $stockLevel->refresh();
        self::assertSame('5.0000', $stockLevel->quantity, 'regulated never-restock policy must be honored even when disposition=restock');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }

    private function seedWriteOffAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => '311',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);
    }

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

    /**
     * @param  numeric-string  $quantity
     */
    private function v4SaleEvent(string $productId, string $quantity, int $sequenceNumber): FiscalEvent
    {
        return $this->buildEvent(
            invoiceTypeCode: 'SALE',
            eventVersion: 3,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: '00000000-0000-4000-8000-000000000001',
            originalLineReferences: null,
            originalReceiptReference: null,
        );
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function v4RefundEvent(
        FiscalEvent $original,
        string $productId,
        string $quantity,
        string $disposition,
        int $sequenceNumber,
    ): FiscalEvent {
        return $this->buildEvent(
            invoiceTypeCode: 'REFUND',
            eventVersion: 4,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: '00000000-0000-4000-8000-00000000000'.($sequenceNumber + 1),
            originalLineReferences: [[
                'disposition' => $disposition,
                'original_line_index' => 0,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]],
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'customer return',
            ],
        );
    }

    /**
     * @param  numeric-string  $quantity
     * @param  list<array<string, mixed>>|null  $originalLineReferences
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function buildEvent(
        string $invoiceTypeCode,
        int $eventVersion,
        string $productId,
        string $quantity,
        int $sequenceNumber,
        string $receiptUuid,
        ?array $originalLineReferences,
        ?array $originalReceiptReference,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();

        $unitPrice = '10.00';
        $lineTotal = bcmul($unitPrice, $quantity, 2);

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => $lineTotal,
            'line_vat' => '0.00',
            'name' => 'Disposition Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sku' => 'SKU-DISP',
            'tax_category_code' => 'Z',
            'unit_price' => $unitPrice,
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
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $lineTotal,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $lineTotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $lineTotal,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $lineTotal,
                'net_amount' => $lineTotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        if ($originalLineReferences !== null) {
            $payload['original_line_references'] = $originalLineReferences;
            $payload['refund_destination'] = 'cash';
            $payload['settlement_allocation'] = null;
        }

        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes.(string) $sequenceNumber);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
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
            'previous_hash' => str_repeat('0', 64),
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
}
