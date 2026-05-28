<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.1.8 — verify variant_id column + variant_requires_product CHECK on
 * document_lines, pos_receipt_lines, pos_order_lines, and
 * pos_receipt_line_batch_allocations.
 *
 * Migrations introduced:
 *   - 2026_06_02_100009_add_variant_id_to_document_lines
 *   - 2026_06_02_100010_add_variant_id_to_pos_receipt_lines
 *   - 2026_06_02_100011_add_variant_id_to_pos_order_lines
 *   - 2026_06_02_100012_add_variant_id_to_pos_receipt_line_batch_allocations
 *
 * CHECK semantics: variant_id IS NULL OR product_id IS NOT NULL
 *   → a line with a variant MUST reference a product.
 *   → batch allocations do NOT carry this CHECK (they derive sellable context
 *     from the parent receipt_line).
 */
class SellableLinesVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Column presence — all four tables
    // -------------------------------------------------------------------------

    public function test_all_four_tables_have_variant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('document_lines', 'variant_id'), 'document_lines.variant_id missing');
        $this->assertTrue(Schema::hasColumn('pos_receipt_lines', 'variant_id'), 'pos_receipt_lines.variant_id missing');
        $this->assertTrue(Schema::hasColumn('pos_order_lines', 'variant_id'), 'pos_order_lines.variant_id missing');
        $this->assertTrue(Schema::hasColumn('pos_receipt_line_batch_allocations', 'variant_id'), 'pos_receipt_line_batch_allocations.variant_id missing');
    }

    // -------------------------------------------------------------------------
    // CHECK rejection on pos_receipt_lines (composite-side row)
    // -------------------------------------------------------------------------

    /**
     * A pos_receipt_lines row with product_id IS NULL and composite_item_id IS NOT NULL
     * is a valid composite-item line. Adding variant_id to such a row must be rejected
     * by the pos_receipt_lines_variant_requires_product CHECK constraint.
     */
    public function test_variant_id_requires_product_id_on_pos_receipt_lines(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are enforced on PostgreSQL only.');
        }

        // Build parent rows -----------------------------------------------
        /** @var Location $location */
        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        /** @var Terminal $terminal */
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
        ]);

        $compositeItemId = (string) Str::uuid();

        DB::table('composite_items')->insert([
            'id' => $compositeItemId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CI-TEST-001',
            'name' => 'Test Composite',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        // Composite-side line: product_id IS NULL, composite_item_id IS NOT NULL,
        // variant_id IS NOT NULL  → must be rejected by variant_requires_product CHECK.
        $this->expectException(QueryException::class);

        DB::table('pos_receipt_lines')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => null,
            'composite_item_id' => $compositeItemId,
            'variant_id' => $variant->id,   // VIOLATES variant_requires_product
            'product_code' => 'CI-TEST-001',
            'product_name' => 'Test Composite',
            'quantity' => '1.000',
            'unit' => 'unit',
            'unit_price' => '10.00',
            'line_total' => '10.00',
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // CHECK rejection on document_lines (simpler — product_id nullable)
    // -------------------------------------------------------------------------

    /**
     * A document_lines row with product_id IS NULL but variant_id IS NOT NULL
     * must be rejected by the document_lines_variant_requires_product CHECK.
     */
    public function test_variant_requires_product_check_on_document_lines(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are enforced on PostgreSQL only.');
        }

        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        // product_id IS NULL but variant_id IS NOT NULL → CHECK violation
        $this->expectException(QueryException::class);

        DB::table('document_lines')->insert([
            'id' => (string) Str::uuid(),
            'document_id' => $document->id,
            'product_id' => null,
            'variant_id' => $variant->id,   // VIOLATES variant_requires_product
            'line_number' => 1,
            'description' => 'Test line',
            'quantity' => '1.0000',
            'unit_price' => '10.00',
            'line_total' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
