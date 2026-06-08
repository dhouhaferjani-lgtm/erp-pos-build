<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 18 — ReceiptCreationService variant-aware stock decrement.
 *
 * Verifies:
 *  (a) A variant line decrements the variant-scoped stock_levels row and
 *      leaves the product-level row untouched.
 *  (b) The StockMovement row created for a variant line carries variant_id.
 *  (c) A plain-product (non-variant) line decrements the product-level
 *      (variant_id IS NULL) row — existing behaviour unchanged.
 */
final class ReceiptCreationVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        // Bind company context so ReceiptCreationService can resolve it.
        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // (a) Variant line decrements the variant-scoped stock_levels row
    // ──────────────────────────────────────────────────────────────────────────

    public function test_variant_line_decrements_variant_stock_row(): void
    {
        // Arrange — product + variant
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '20.00',
            'tax_rate' => '20.00',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        // Seed a variant-scoped stock_levels row with qty=10
        $variantStockLevel = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        // Seed a product-level stock_levels row (variant_id NULL) with qty=50
        // to verify it is NOT touched by the variant sale.
        $productStockLevel = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        // Act — finalize a receipt with ONE variant line (qty=1)
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => '1',
                    'unit_price' => '20.00',
                ],
            ],
        );

        // Assert — variant-scoped row decremented from 10 to 9
        $variantStockLevel->refresh();
        $this->assertSame(
            '9.0000',
            (string) $variantStockLevel->quantity,
            'Variant-scoped stock_levels row should be decremented by 1.',
        );

        // Assert — product-level row untouched (still 50)
        $productStockLevel->refresh();
        $this->assertSame(
            '50.0000',
            (string) $productStockLevel->quantity,
            'Product-level (variant_id IS NULL) stock_levels row must not be touched by a variant sale.',
        );

        // Assert — StockMovement carries variant_id
        $movement = StockMovement::where('product_id', $product->id)
            ->where('reference_type', 'pos_receipt')
            ->latest()
            ->first();

        $this->assertNotNull($movement, 'A StockMovement row should have been created.');
        $this->assertSame(
            $variant->id,
            $movement->variant_id,
            'StockMovement.variant_id must equal the sold variant UUID.',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // (b) StockMovement carries variant_id — see (a) above for combined assert.
    // ──────────────────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────────────────
    // (c) Plain-product line decrements the product-level (variant_id NULL) row
    // ──────────────────────────────────────────────────────────────────────────

    public function test_non_variant_line_decrements_product_row(): void
    {
        // Arrange — product WITHOUT variants
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.50',
            'tax_rate' => '20.00',
        ]);

        // Only a product-level stock row (variant_id IS NULL)
        $productStockLevel = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => '100.00',
            'reserved' => '0.00',
        ]);

        // Act — finalize a receipt WITHOUT variant_id (plain-product line)
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'quantity' => '3',
                    'unit_price' => '12.50',
                ],
            ],
        );

        // Assert — product-level row decremented from 100 to 97
        $productStockLevel->refresh();
        $this->assertSame(
            '97.0000',
            (string) $productStockLevel->quantity,
            'Product-level stock_levels row should be decremented by 3.',
        );

        // Assert — StockMovement has null variant_id
        $movement = StockMovement::where('product_id', $product->id)
            ->where('reference_type', 'pos_receipt')
            ->latest()
            ->first();

        $this->assertNotNull($movement, 'A StockMovement row should have been created.');
        $this->assertNull(
            $movement->variant_id,
            'StockMovement.variant_id must be null for a plain-product (non-variant) sale.',
        );
    }
}
