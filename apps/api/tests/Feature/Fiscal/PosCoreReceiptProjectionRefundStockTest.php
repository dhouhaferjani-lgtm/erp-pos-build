<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Refund/void stock DIRECTION in `PosCoreReceiptProjection` (follow-up to the
 * variant-stock fix — see `docs/handoff/HANDOVER-refund-void-stock-decrement.md`).
 *
 * **The defect this suite pins.** `apply()` calls `decrementStockForLines()`
 * UNCONDITIONALLY for every `SALE_RECEIPT` fiscal event — including events whose
 * `invoice_type_code` is `REFUND` / `VOID` (which `resolveReceiptType()` maps to
 * `ReceiptType::Return`). A refund/void returns goods to the shelf, so its stock
 * effect must be a **restock (increment)**, not a decrement. Pre-fix the
 * original sale decremented stock and the refund decremented it AGAIN — a
 * double-removal that drives inventory negative.
 *
 * **Canonical sign convention (pinned by `FiscalPayloadConstraintValidator`).**
 * `line_items[].quantity` is validated by `moneyRegex(QUANTITY_SCALE=3)` =
 * `/^(0|[1-9]\d*)\.\d{3}$/D`, which forbids a leading `-`. So refund/void line
 * quantities arrive as POSITIVE MAGNITUDES; direction is implied entirely by
 * `invoice_type_code`. The fix restocks by ADDING that magnitude back to the
 * same grain the sale decremented.
 *
 * **Rule 20 — projections run with NO CompanyContext.** Each test
 * `app(CompanyContext::class)->clear()`s before every `apply()` to mirror the
 * `ApplyFiscalEventProjectionJob` worker reality (binding it in setUp would mask
 * a regression that reintroduced a context dependency).
 *
 * The refund/void-via-SALE_RECEIPT path is Phase-2 reserved (the device only
 * authors SALE/TRAINING today); this suite is the forward-looking guard that
 * the projection's Return branch restocks instead of decrements.
 */
final class PosCoreReceiptProjectionRefundStockTest extends TestCase
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

        // Bound only for factory paths during setUp; CLEARED before every
        // apply() below (rule 20 — the projection must not depend on it).
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
    }

    // =================================================================
    // (a) REFUND of a non-variant line RESTOCKS the product-level row
    // =================================================================

    public function test_refund_of_non_variant_line_restocks_product_level_row(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');

        // Sale of 2 decrements 10 -> 8.
        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '2');
        $this->project($sale);
        $productLevel->refresh();
        $this->assertSame('8.0000', $productLevel->quantity, 'sale must decrement first');

        // Refund of 2 must RESTOCK 8 -> 10 (not decrement to 6).
        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '2');
        $this->project($refund);

        $productLevel->refresh();
        $this->assertSame('10.0000', $productLevel->quantity, 'refund must restock to pre-sale level, not double-remove');

        // The refund wrote a restock movement: receipt / pos_return, +qty, no variant.
        $this->assertSame(1, $this->myStockMovements()->where('reason', 'pos_return')->count());
        $movement = $this->myStockMovements()->where('reason', 'pos_return')->first();
        $this->assertNotNull($movement);
        $this->assertSame('receipt', (string) $movement->movement_type);
        $this->assertNull($movement->variant_id);
        $this->assertSame($product->id, $movement->product_id);
        // Normalize at scale 4 — SQLite NUMERIC affinity drops trailing zeros
        // ('8.0000' -> '8'); the precision test below pins non-zero decimals.
        $this->assertSame('8.0000', bcadd((string) $movement->quantity_before, '0', 4));
        $this->assertSame('10.0000', bcadd((string) $movement->quantity_after, '0', 4));
        // Exactly one issue (the sale) and one receipt (the refund) — no double-issue.
        $this->assertSame(1, $this->myStockMovements()->where('reason', 'pos_sale')->count());
    }

    // =================================================================
    // (b) REFUND of a variant line RESTOCKS the variant row only
    // =================================================================

    public function test_refund_of_variant_line_restocks_variant_row_only(): void
    {
        [$product, $variant] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '10.0000');

        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: $variant->id, quantity: '2');
        $this->project($sale);
        $variantLevel->refresh();
        $this->assertSame('8.0000', $variantLevel->quantity);

        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: $variant->id, quantity: '2');
        $this->project($refund);

        // Variant row restored to 10; product-level decoy never moved.
        $variantLevel->refresh();
        $this->assertSame('10.0000', $variantLevel->quantity);
        $productLevel->refresh();
        $this->assertSame('10.0000', $productLevel->quantity, 'product-level row must not move for a variant refund');

        $movement = $this->myStockMovements()->where('reason', 'pos_return')->first();
        $this->assertNotNull($movement);
        $this->assertSame('receipt', (string) $movement->movement_type);
        $this->assertSame($variant->id, $movement->variant_id);
        $this->assertSame($product->id, $movement->product_id);
    }

    // =================================================================
    // (c) VOID behaves like REFUND for stock — restocks
    // =================================================================

    public function test_void_restocks_like_refund(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '5.0000');

        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '1');
        $this->project($sale);
        $productLevel->refresh();
        $this->assertSame('4.0000', $productLevel->quantity);

        $void = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '1', invoiceTypeCode: 'VOID');
        $this->project($void);

        $productLevel->refresh();
        $this->assertSame('5.0000', $productLevel->quantity, 'void must restock, mirroring the legacy ReceiptVoidService reversal');
        $this->assertSame(1, $this->myStockMovements()->where('reason', 'pos_return')->count());
    }

    // =================================================================
    // (d) Replay of the same REFUND event is a no-op (idempotent)
    // =================================================================

    public function test_replay_of_same_refund_event_does_not_double_restock(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');

        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '2');
        $this->project($sale);

        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '2');
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $productLevel->refresh();
        $this->assertSame('10.0000', $productLevel->quantity);
        $this->assertSame(1, $this->myStockMovements()->where('reason', 'pos_return')->count());

        // Replay — fiscal_event_id idempotency guard short-circuits before any
        // stock write. No second restock, no duplicate movement.
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $productLevel->refresh();
        $this->assertSame('10.0000', $productLevel->quantity, 'replay must not double-restock');
        $this->assertSame(1, $this->myStockMovements()->where('reason', 'pos_return')->count());
    }

    // =================================================================
    // (e) sale -q then refund +q nets to the pre-sale level (no phantom stock)
    // =================================================================

    public function test_sale_then_full_refund_nets_to_zero_stock_change(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '7.0000');

        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '3');
        $this->project($sale);

        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '3');
        $this->project($refund);

        $productLevel->refresh();
        $this->assertSame('7.0000', $productLevel->quantity, 'sale -3 then refund +3 must net to the original level');
    }

    // =================================================================
    // (f) fractional refund quantity stays decimal(4) — no float drift
    // =================================================================

    public function test_refund_keeps_full_scale4_precision(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '10.0001');

        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '0.3000');
        $this->project($sale);
        $productLevel->refresh();
        $this->assertSame('9.7001', $productLevel->quantity);

        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '0.3000');
        $this->project($refund);

        $productLevel->refresh();
        $this->assertSame('10.0001', $productLevel->quantity);

        $movement = $this->myStockMovements()->where('reason', 'pos_return')->first();
        $this->assertNotNull($movement);
        $this->assertSame('9.7001', (string) $movement->quantity_before);
        $this->assertSame('10.0001', (string) $movement->quantity_after);
    }

    // =================================================================
    // (g) partial refund restocks only the refunded quantity
    // =================================================================

    public function test_partial_refund_restocks_only_the_refunded_quantity(): void
    {
        [$product] = $this->seedProductWithVariant();
        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');

        // Sale of 5 -> 5 on hand.
        $sale = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '5');
        $this->project($sale);
        $productLevel->refresh();
        $this->assertSame('5.0000', $productLevel->quantity);

        // Partial refund of 2 -> restock 5 -> 7 (only the refunded qty).
        $refund = $this->refundReceiptEvent($sale, productId: $product->id, variantId: null, quantity: '2');
        $this->project($refund);

        $productLevel->refresh();
        $this->assertSame('7.0000', $productLevel->quantity);

        $movement = $this->myStockMovements()->where('reason', 'pos_return')->first();
        $this->assertNotNull($movement);
        $this->assertSame('2.0000', bcadd((string) $movement->quantity, '0', 4));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function seedProductWithVariant(): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function seedStockLevel(string $productId, ?string $variantId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    /**
     * A REFUND/VOID SALE_RECEIPT event referencing $original. The canonical
     * `original_receipt_reference.fiscal_event_id` points at the original
     * fiscal event id, so `PosCoreReceiptProjection::resolveOriginalReceiptId`
     * resolves the locally-projected original and the event maps to
     * ReceiptType::Return.
     */
    private function refundReceiptEvent(
        FiscalEvent $original,
        string $productId,
        ?string $variantId,
        string $quantity,
        string $invoiceTypeCode = 'REFUND',
    ): FiscalEvent {
        return $this->saleReceiptEvent(
            productId: $productId,
            variantId: $variantId,
            quantity: $quantity,
            invoiceTypeCode: $invoiceTypeCode,
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => $invoiceTypeCode === 'VOID' ? 'Operator error — voided' : 'Customer changed mind',
            ],
        );
    }

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row for a single product
     * (optionally variant) line — minimal canonical payload mirroring
     * PosCoreReceiptProjectionVariantStockTest, with refund/void linkage knobs.
     *
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function saleReceiptEvent(
        string $productId,
        ?string $variantId,
        string $quantity,
        string $invoiceTypeCode = 'SALE',
        int $sequenceNumber = 1,
        string $receiptUuid = '00000000-0000-4000-8000-000000000001',
        ?array $originalReceiptReference = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $qtyCanonical = str_contains($quantity, '.') ? $quantity : $quantity.'.000';

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => '10.00',
            'line_vat' => '0.00',
            'name' => 'Refund Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $qtyCanonical,
            'sku' => 'SKU-REF',
            'tax_category_code' => 'Z',
            'unit_price' => '10.00',
            'variant_id' => $variantId,
            'variant_name' => $variantId !== null ? 'Red / L' : null,
            'variant_sku' => $variantId !== null ? 'SKU-REF-RED-L' : null,
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

    // =================================================================
    // Helpers — scoped reads (LEDGER C-7)
    // =================================================================

    /**
     * `stock_movements` rows created by THIS test.
     *
     * `setUp()` mints a fresh `Tenant` per test, so a `tenant_id` filter is an
     * exact "the rows I created" scope. Without it these reads also see the
     * rows COMMITTED by `PosCoreReceiptProjectionRefundDispositionStockTest`,
     * which overrides `connectionsToTransact()` to `[]` (it has to: it asserts
     * real transaction-rollback semantics, which a wrapping RefreshDatabase
     * transaction would mask) and therefore leaves its rows behind for the rest
     * of the PHP process. That bleed is why this class was green standalone and
     * red in any multi-class run — the same root cause and the same fix shape
     * the C-7 lane applied to `PosCoreReceiptProjectionTest`.
     */
    private function myStockMovements(): Builder
    {
        return DB::table('stock_movements')->where('tenant_id', $this->tenantId);
    }
}
