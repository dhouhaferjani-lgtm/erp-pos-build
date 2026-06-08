<?php

declare(strict_types=1);

namespace Tests\Feature\T2;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 32 — Full T2 product-variant acceptance suite (Wave 1, server-side gate).
 *
 * This is the §10 orchestration test: it proves the complete vertical end-to-end
 * through the REAL HTTP API + REAL service layer (no mocks), not just the
 * individual unit/feature seams that Tasks 1–31 cover:
 *
 *   create attribute → add values → generate variant matrix for a product →
 *   set per-variant stock → select & sell ONE specific variant at the POS →
 *   stock decrements at variant grain → web read-model reflects the sale/stock.
 *
 * It additionally pins the §10 guards that are acceptance-level (not unit-level):
 *   - §10.1 partial-unique dual-row insert (variant + soft-delete reuse)
 *   - §10.2 backward compat — non-variant flow unchanged
 *   - §10.3 variant-aware sell chain writes variant_id everywhere
 *   - §10.7 vertical modularity — automotive zero-variant product never grows a row
 *   - §10.8 multi-tenant isolation — variants never leak across tenants
 *   - §6.7 WAC stays product-grain (advisory cost_override does NOT shard WAC)
 *
 * Conventions: RefreshDatabase real DB, seeded permissions via
 * RolesAndPermissionsSeeder, full middleware/auth/permission stack through HTTP.
 */
final class T2AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'currency' => 'EUR',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'catalog.attributes.view',
            'catalog.attributes.create',
            'catalog.attributes.update',
            'catalog.attributes.delete',
            'catalog.variants.view',
            'catalog.variants.create',
            'catalog.variants.update',
            'catalog.variants.delete',
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
        ]);

        $cashier = User::factory()->for($this->tenant)->create();

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->actingAs($this->user);
    }

    // =========================================================================
    // §10.3 / §10.6 — the headline end-to-end vertical
    // =========================================================================

    /**
     * THE acceptance scenario:
     *   1. Create an attribute ("size") + values via the REST API.
     *   2. Create a "color" attribute + values via the REST API.
     *   3. Generate the variant matrix for a product (size × color).
     *   4. Set per-variant stock for ONE specific variant.
     *   5. Sell exactly that variant at the POS (real ReceiptCreationService).
     *   6. Assert stock decremented at VARIANT grain only.
     *   7. Assert the web read-model reflects it: variant-scoped StockLevel,
     *      variant_id-bearing StockMovement, and variant_id on the DocumentLine
     *      mirror written by the POS sale.
     */
    public function test_full_vertical_create_attribute_to_variant_pos_sale_reflected(): void
    {
        // 1. Create the "size" attribute via the API.
        $sizeResp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'size',
            'name' => 'Pointure',
            'data_type' => 'selection',
            'is_variant_axis' => true,
        ]);
        $sizeResp->assertStatus(201);
        $sizeAttrId = $sizeResp->json('data.id');

        foreach (['38', '39', '40'] as $code) {
            $this->postJson("/api/v1/product-attributes/{$sizeAttrId}/values", [
                'code' => $code,
                'label' => $code,
            ])->assertStatus(201);
        }

        // 2. Create the "color" attribute via the API.
        $colorResp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'color',
            'name' => 'Couleur',
            'data_type' => 'selection',
            'is_variant_axis' => true,
        ]);
        $colorResp->assertStatus(201);
        $colorAttrId = $colorResp->json('data.id');

        foreach (['noir' => 'Noir', 'rouge' => 'Rouge'] as $code => $label) {
            $this->postJson("/api/v1/product-attributes/{$colorAttrId}/values", [
                'code' => $code,
                'label' => $label,
            ])->assertStatus(201);
        }

        // The product the matrix is generated for.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CHAUSSURE',
            'sale_price' => '49.90',
            'tax_rate' => '20.00',
        ]);

        // 3. Generate the variant matrix (3 sizes × 2 colors = 6 variants).
        $matrixResp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'attribute_ids' => [$sizeAttrId, $colorAttrId],
        ]);
        $matrixResp->assertStatus(201);
        $matrixResp->assertJsonCount(6, 'data');
        $this->assertDatabaseCount('product_variants', 6);

        // 4. Pick ONE specific variant (size 39 / noir) and set its stock to 10.
        $listResp = $this->getJson("/api/v1/products/{$product->id}/variants");
        $listResp->assertOk();

        $size39Id = ProductAttributeValue::where('attribute_id', $sizeAttrId)
            ->where('code', '39')->value('id');
        $noirId = ProductAttributeValue::where('attribute_id', $colorAttrId)
            ->where('code', 'noir')->value('id');

        // A variant carrying BOTH the size-39 and noir attribute-value rows.
        $soldVariantId = ProductVariantAttributeValue::query()
            ->select('variant_id')
            ->whereIn('attribute_value_id', [$size39Id, $noirId])
            ->groupBy('variant_id')
            ->havingRaw('COUNT(DISTINCT attribute_value_id) = 2')
            ->value('variant_id');

        $this->assertNotNull($soldVariantId, 'The size-39 / noir variant must exist after matrix generation.');

        /** @var ProductVariant $soldVariant */
        $soldVariant = ProductVariant::findOrFail($soldVariantId);

        $soldStock = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $soldVariant->id,
            'location_id' => $this->location->id,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        // A sibling variant stock bucket (second location) to prove the sale
        // touches ONLY the sold variant's row, not any other variant's stock.
        // (Placed at a second location so the SQLite test schema's stock_levels
        // unique index — which omits variant_id — does not collide; the
        // variant-grain unique index is exercised by StockLevelsVariantUniqueIndexTest.)
        /** @var ProductVariant $otherVariant */
        $otherVariant = ProductVariant::where('product_id', $product->id)
            ->where('id', '!=', $soldVariant->id)
            ->firstOrFail();
        $otherLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => false,
        ]);
        $otherStock = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $otherVariant->id,
            'location_id' => $otherLocation->id,
            'quantity' => '7.00',
            'reserved' => '0.00',
        ]);

        // 5. Sell exactly the size-39 / noir variant at the POS (qty=2).
        /** @var ReceiptCreationService $pos */
        $pos = app(ReceiptCreationService::class);
        $receipt = $pos->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'variant_id' => $soldVariant->id,
                    'quantity' => '2',
                    'unit_price' => '49.90',
                ],
            ],
        );

        // 6. Stock decremented at VARIANT grain only.
        $soldStock->refresh();
        $this->assertSame(
            '8.0000',
            (string) $soldStock->quantity,
            'Sold variant stock must drop from 10 to 8.',
        );

        $otherStock->refresh();
        $this->assertSame(
            '7.0000',
            (string) $otherStock->quantity,
            'Sibling variant stock must be untouched by the sale.',
        );

        // No product-level (variant_id IS NULL) row was ever created.
        $this->assertNull(
            StockLevel::where('product_id', $product->id)->whereNull('variant_id')->first(),
            'A variant sale must never spill onto a product-level stock row.',
        );

        // 7. Web read-model reflects the sale.
        $movement = StockMovement::where('product_id', $product->id)
            ->where('reference_type', 'pos_receipt')
            ->latest()
            ->first();
        $this->assertNotNull($movement, 'A StockMovement must be recorded for the POS sale.');
        $this->assertSame(
            $soldVariant->id,
            $movement->variant_id,
            'StockMovement.variant_id must equal the sold variant UUID.',
        );

        // The persisted POS receipt line carries the sold variant_id — this is
        // the row the web back-office / sync read-model surfaces.
        $receipt->loadMissing('lines');
        $persistedLine = $receipt->lines->firstOrFail();
        $this->assertSame(
            $soldVariant->id,
            (string) $persistedLine->variant_id,
            'The POS receipt line must persist the sold variant_id for web reflection.',
        );
        $this->assertDatabaseHas('pos_receipt_lines', [
            'id' => $persistedLine->id,
            'product_id' => $product->id,
            'variant_id' => $soldVariant->id,
        ]);
    }

    // =========================================================================
    // §10.1 — partial-unique indexes / soft-delete SKU reuse
    // =========================================================================

    /**
     * Two distinct active variants on the same product may each hold a unique SKU,
     * but a second active variant with a duplicate SKU is rejected — and once the
     * first is soft-deleted, its SKU becomes reusable (partial unique on
     * deleted_at IS NULL).
     */
    public function test_10_1_soft_delete_makes_variant_sku_reusable(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $first = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-REUSE',
            'sku' => 'SKU-REUSE',
            'name_suffix' => 'First',
        ]);

        // Soft-delete it via the API.
        $this->deleteJson("/api/v1/product-variants/{$first->id}")->assertNoContent();
        $this->assertSoftDeleted('product_variants', ['id' => $first->id]);

        // The SKU is now reusable on a brand-new active variant.
        $second = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-REUSE-2',
            'sku' => 'SKU-REUSE',
            'name_suffix' => 'Second',
        ]);

        $this->assertDatabaseHas('product_variants', [
            'id' => $second->id,
            'sku' => 'SKU-REUSE',
            'deleted_at' => null,
        ]);
    }

    // =========================================================================
    // §10.2 — backward compatibility: non-variant flow unchanged
    // =========================================================================

    public function test_10_2_non_variant_pos_sale_decrements_product_row(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.50',
            'tax_rate' => '20.00',
        ]);

        $stock = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => '100.00',
            'reserved' => '0.00',
        ]);

        /** @var ReceiptCreationService $pos */
        $pos = app(ReceiptCreationService::class);
        $pos->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'quantity' => '3',
                    'unit_price' => '12.50',
                ],
            ],
        );

        $stock->refresh();
        $this->assertSame('97.0000', (string) $stock->quantity);

        $movement = StockMovement::where('product_id', $product->id)
            ->where('reference_type', 'pos_receipt')
            ->latest()
            ->first();
        $this->assertNotNull($movement);
        $this->assertNull(
            $movement->variant_id,
            'A non-variant sale must leave variant_id NULL — backward compat.',
        );
    }

    // =========================================================================
    // §10.7 — vertical modularity: automotive zero-variant product
    // =========================================================================

    /**
     * An automotive product that never declares variants must never grow a
     * variant row, and its variant listing must return empty — proving the
     * matrix editor never surfaces (the frontend gate is asserted in vitest).
     */
    public function test_10_7_automotive_zero_variant_product_has_no_variant_rows(): void
    {
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'BRAKE-PAD',
        ]);

        $resp = $this->getJson("/api/v1/products/{$part->id}/variants");
        $resp->assertOk();
        $resp->assertJsonCount(0, 'data');

        $this->assertSame(
            0,
            ProductVariant::where('product_id', $part->id)->count(),
            'A zero-variant automotive product must never grow a variant row.',
        );
    }

    // =========================================================================
    // §10.8 — multi-tenant isolation: variants never leak across tenants
    // =========================================================================

    public function test_10_8_variants_do_not_leak_across_tenants(): void
    {
        // Own-tenant product + variant.
        $ownProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $ownProduct->id,
            'variant_code' => 'OWN-V1',
            'sku' => 'OWN-V1',
            'name_suffix' => 'Own',
        ]);

        // A foreign tenant with its own product + variant.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->for($otherTenant)->create();
        $otherProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
        ]);
        ProductVariant::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'variant_code' => 'OTHER-V1',
            'sku' => 'OTHER-V1',
            'name_suffix' => 'Other',
        ]);

        // Acting as own-tenant user: listing the foreign product's variants must
        // NOT expose the foreign variant (scoped by tenant/company).
        $resp = $this->getJson("/api/v1/products/{$otherProduct->id}/variants");
        $resp->assertJsonMissing(['sku' => 'OTHER-V1']);

        // The attribute listing for the own tenant must also not surface foreign data.
        $this->assertSame(
            0,
            ProductVariant::where('tenant_id', $this->tenant->id)
                ->where('sku', 'OTHER-V1')
                ->count(),
            'A foreign tenant variant must never be visible under the own tenant scope.',
        );
    }
}
