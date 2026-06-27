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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * T2 — variant-aware, exactly-once POS stock decrement in
 * `PosCoreReceiptProjection` (Inventory/POS T2; "P1" prerequisite for
 * variant-WAC COGS, tracked separately — this suite is inventory-only).
 *
 * **Two documented defects this suite pins (see the projection's
 * `decrementStockForLines` docblock, pre-fix):**
 *
 *  1. **Stale variant gap.** The projection decremented every line against
 *     the PRODUCT-LEVEL `stock_levels` row (`variant_id IS NULL`) even when
 *     the canonical line carried a `variant_id`. `LineItemDTO` HAS carried
 *     `variant_id` since SaleReceiptV2 (M4); `decrementStock()` already
 *     supports an optional `$variantId`. The fix threads `$line->variantId`
 *     through so a variant sale hits the VARIANT row and writes
 *     `stock_movements.variant_id`.
 *
 *  2. **Double-decrement.** The stale docblock claimed the projection's
 *     decrement was a SECOND decrement on top of the draft-creation path
 *     (`ReceiptCreationService`). Under device-SoT the projection — keyed and
 *     locked on `fiscal_event_id` — is the SINGLE authoritative server-side
 *     decrement; the draft `createReceipt` path is retired (every caller is
 *     410 Gone / inert per `scripts/saleReceipt-chokepoint-manifest.json`).
 *     These tests pin "exactly once per sold unit" and idempotent replay.
 *
 * **Rule 20 — projections run with NO CompanyContext.** The queue worker
 * (`ApplyFiscalEventProjectionJob`) carries no `CompanyContext`; the
 * projector reads tenant/company from the `FiscalEvent`. Each test
 * `app(CompanyContext::class)->clear()`s immediately before `apply()` to
 * mirror that worker reality (binding it in setUp would mask a regression
 * that reintroduced a context dependency).
 */
final class PosCoreReceiptProjectionVariantStockTest extends TestCase
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

        // Bind context only to satisfy factory paths that resolve the current
        // company during setUp. It is CLEARED before every apply() call below
        // (rule 20 — the projection must not depend on CompanyContext).
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

        // The projection resolves payment_method_id by code 'CASH'; the row
        // must exist in this tenant or the payment write fails closed.
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
    }

    // =================================================================
    // (a) variant sale decrements the VARIANT row exactly once
    // =================================================================

    public function test_variant_sale_decrements_the_variant_stock_row_exactly_once(): void
    {
        [$product, $variant] = $this->seedProductWithVariant();

        // Both grains exist for the same product/location. The variant sale
        // must touch ONLY the variant row — the product-level row is a decoy
        // that pins the stale-variant-gap regression (old code hit this one).
        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '10.0000');

        $event = $this->saleReceiptEvent(productId: $product->id, variantId: $variant->id, quantity: '2');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // Variant row decremented by exactly the sold qty (10 - 2 = 8).
        $variantLevel->refresh();
        $this->assertSame('8.0000', $variantLevel->quantity);

        // Product-level decoy row UNTOUCHED — no double-decrement across grains.
        $productLevel->refresh();
        $this->assertSame('10.0000', $productLevel->quantity);

        // Exactly one POS-sale stock movement, carrying the variant_id.
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());
        $movement = DB::table('stock_movements')->where('reason', 'pos_sale')->first();
        $this->assertNotNull($movement);
        $this->assertSame($variant->id, $movement->variant_id);
        $this->assertSame($product->id, $movement->product_id);
    }

    // =================================================================
    // (b) non-variant sale decrements the product-level row exactly once
    // =================================================================

    public function test_non_variant_sale_decrements_the_product_level_row_exactly_once(): void
    {
        [$product, $variant] = $this->seedProductWithVariant();

        $productLevel = $this->seedStockLevel($product->id, null, '10.0000');
        // Variant-level decoy must NOT move for a product-level sale.
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '10.0000');

        $event = $this->saleReceiptEvent(productId: $product->id, variantId: null, quantity: '3');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $productLevel->refresh();
        $this->assertSame('7.0000', $productLevel->quantity);

        $variantLevel->refresh();
        $this->assertSame('10.0000', $variantLevel->quantity);

        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());
        $movement = DB::table('stock_movements')->where('reason', 'pos_sale')->first();
        $this->assertNotNull($movement);
        $this->assertNull($movement->variant_id);
        $this->assertSame($product->id, $movement->product_id);
    }

    // =================================================================
    // (c) NO double-decrement (single authoritative projection decrement)
    // =================================================================

    public function test_variant_sale_produces_no_double_decrement(): void
    {
        // The projection is the single authoritative decrement point under
        // device-SoT; the draft-creation path is retired (410 Gone). A
        // variant sale of qty 1 must move the variant row by exactly 1 (not 2)
        // and must NOT also move the product-level row — i.e., no second
        // decrement from any source.
        [$product, $variant] = $this->seedProductWithVariant();

        $productLevel = $this->seedStockLevel($product->id, null, '5.0000');
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '5.0000');

        $event = $this->saleReceiptEvent(productId: $product->id, variantId: $variant->id, quantity: '1');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $variantLevel->refresh();
        $this->assertSame('4.0000', $variantLevel->quantity, 'variant row must drop by exactly one unit, not two');

        $productLevel->refresh();
        $this->assertSame('5.0000', $productLevel->quantity, 'product-level row must not be decremented for a variant sale');

        // Exactly one stock movement total for the sale.
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());
    }

    // =================================================================
    // (d) replay of the same fiscal event is a no-op
    // =================================================================

    public function test_replay_of_same_fiscal_event_does_not_double_decrement_variant_row(): void
    {
        [$product, $variant] = $this->seedProductWithVariant();
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '10.0000');

        $event = $this->saleReceiptEvent(productId: $product->id, variantId: $variant->id, quantity: '2');
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        app(CompanyContext::class)->clear();
        $projector->apply($event);

        $variantLevel->refresh();
        $this->assertSame('8.0000', $variantLevel->quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());

        // Replay — the fiscal_event_id idempotency guard short-circuits before
        // any stock write. No second decrement, no duplicate movement.
        app(CompanyContext::class)->clear();
        $projector->apply($event);

        $variantLevel->refresh();
        $this->assertSame('8.0000', $variantLevel->quantity, 'replay must not double-decrement');
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());
        $this->assertSame(1, DB::table('pos_receipts')->count());
    }

    // =================================================================
    // (e) quantities stay decimal(4) bcmath — no float drift
    // =================================================================

    public function test_variant_decrement_preserves_decimal4_quantity_without_float_drift(): void
    {
        [$product, $variant] = $this->seedProductWithVariant();
        // A fractional quantity that a float subtraction would smear
        // (e.g. 10.0001 - 0.3 in IEEE-754). bcsub at scale 4 must stay exact.
        $variantLevel = $this->seedStockLevel($product->id, $variant->id, '10.0001');

        $event = $this->saleReceiptEvent(productId: $product->id, variantId: $variant->id, quantity: '0.3000');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $variantLevel->refresh();
        $this->assertSame('9.7001', $variantLevel->quantity);

        $movement = DB::table('stock_movements')->where('reason', 'pos_sale')->first();
        $this->assertNotNull($movement);
        $this->assertSame('10.0001', (string) $movement->quantity_before);
        $this->assertSame('9.7001', (string) $movement->quantity_after);
    }

    // =================================================================
    // Helpers
    // =================================================================

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
     * Persist a verified SALE_RECEIPT fiscal_events row for a single product
     * (optionally variant) line — minimal 28-key canonical payload, mirrors
     * the fixture conventions in PosCoreReceiptProjectionTest.
     */
    private function saleReceiptEvent(
        string $productId,
        ?string $variantId,
        string $quantity,
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
            'name' => 'Variant Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $qtyCanonical,
            'sku' => 'SKU-VAR',
            'tax_category_code' => 'Z',
            'unit_price' => '10.00',
            'variant_id' => $variantId,
            'variant_name' => $variantId !== null ? 'Red / L' : null,
            'variant_sku' => $variantId !== null ? 'SKU-VAR-RED-L' : null,
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
            'invoice_type_code' => 'SALE',
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
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
            'sequence_number' => 1,
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
            'sequence_number' => 1,
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
