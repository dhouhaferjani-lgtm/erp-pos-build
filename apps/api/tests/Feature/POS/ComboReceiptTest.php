<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for creating POS receipts with fixed_bundle combo items.
 *
 * Covers:
 * - Receipt creation with fixed_bundle CompositeItem
 * - VAT decomposition for combos with components at different tax rates
 * - Stock deduction cascading through sub-recipes
 * - combo_components stored on receipt line
 */
final class ComboReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->cashier);
    }

    public function test_receipt_with_fixed_bundle_composite_item_stores_combo_components(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — POST /api/v1/pos/receipts retired. '.
            'Combo receipt authoring moves to device-authority (Task 29 + §18 web-POS parity).',
        );

        // Create sub-components
        $coffee = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Coffee',
            'sale_price' => '3.00',
            'tax_rate' => '10.00',
            'cost_price' => '0.50',
        ]);

        $croissant = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Croissant',
            'sale_price' => '2.00',
            'tax_rate' => '5.50',
            'cost_price' => '0.80',
        ]);

        // Stock for both
        $this->createStock($coffee->id, '100.0000');
        $this->createStock($croissant->id, '100.0000');

        // Create combo
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BREAKFAST-COMBO',
            'name' => 'Breakfast Combo',
            'base_price' => '4.50',
            'pricing_mode' => PricingMode::FixedBundle,
            'tax_rate' => '10.00', // fallback rate
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $coffee->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $croissant->id,
            'quantity' => 1,
        ]);

        // Act: create a receipt with this combo
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $combo->id,
                    'quantity' => '1',
                    'unit_price' => '4.50',
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Verify combo_components are stored on the receipt line
        $receiptId = $response->json('data.id');
        $receipt = Receipt::with('lines')->find($receiptId);
        $this->assertNotNull($receipt);

        $line = $receipt->lines->first();
        $this->assertNotNull($line);
        $this->assertNotNull($line->combo_components);
        $this->assertIsArray($line->combo_components);
        $this->assertContains('Coffee', $line->combo_components);
        $this->assertContains('Croissant', $line->combo_components);
    }

    public function test_fixed_bundle_vat_decomposition_with_different_tax_rates(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — POST /api/v1/pos/receipts retired. '.
            'Combo VAT decomposition path moves to device-authority (Task 29 + §18). '.
            'The decomposeFixedBundleVat() service method is still exercised by '.
            'test_vat_decomposition_service_method_returns_correct_structure (service-level, no route).',
        );

        // Two products with different tax rates
        $sandwich = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sandwich',
            'sale_price' => '6.00',
            'tax_rate' => '10.00',
            'cost_price' => '2.00',
        ]);

        $soda = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Soda',
            'sale_price' => '2.00',
            'tax_rate' => '20.00',
            'cost_price' => '0.50',
        ]);

        $this->createStock($sandwich->id, '100.0000');
        $this->createStock($soda->id, '100.0000');

        // Combo price: 7.00 (vs 8.00 standalone total)
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LUNCH-COMBO',
            'name' => 'Lunch Combo',
            'base_price' => '7.00',
            'pricing_mode' => PricingMode::FixedBundle,
            'tax_rate' => '10.00',
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $sandwich->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $soda->id,
            'quantity' => 1,
        ]);

        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $combo->id,
                    'quantity' => '1',
                    'unit_price' => '7.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Verify VAT details exist with both rates
        $receiptId = $response->json('data.id');
        $receipt = Receipt::with('vatDetails')->find($receiptId);
        $this->assertNotNull($receipt);

        $vatRates = $receipt->vatDetails->pluck('tax_rate')->map(fn ($r) => (string) $r)->all();
        $this->assertContains('10.00', $vatRates, 'Should have 10% VAT from sandwich');
        $this->assertContains('20.00', $vatRates, 'Should have 20% VAT from soda');
    }

    public function test_stock_deduction_cascades_through_sub_recipes(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — POST /api/v1/pos/receipts retired. '.
            'Stock cascade through sub-recipes moves to device-authority (Task 29 + §18).',
        );

        // Create leaf products
        $beans = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Coffee Beans',
            'tax_rate' => '0.00',
            'cost_price' => '0.50',
        ]);

        $milk = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Milk',
            'tax_rate' => '0.00',
            'cost_price' => '0.30',
        ]);

        $bread = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bread',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);

        $this->createStock($beans->id, '100.0000');
        $this->createStock($milk->id, '50.0000');
        $this->createStock($bread->id, '20.0000');

        // Sub-item: Latte = 2 beans + 1 milk
        $latte = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LATTE-STOCK',
            'name' => 'Latte',
            'base_price' => '5.00',
            'tax_rate' => '0.00',
        ]);

        $latteRecipe = Recipe::create([
            'composite_item_id' => $latte->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $latte->update(['default_recipe_id' => $latteRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $beans->id,
            'quantity' => 2,
        ]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $milk->id,
            'quantity' => 1,
        ]);

        // Combo: Breakfast = 1 Latte + 1 Bread
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BRK-STOCK',
            'name' => 'Breakfast Stock',
            'base_price' => '8.00',
            'pricing_mode' => PricingMode::FixedBundle,
            'tax_rate' => '0.00',
        ]);

        $comboRecipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $comboRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'composite_item',
            'component_id' => $latte->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'product',
            'component_id' => $bread->id,
            'quantity' => 1,
        ]);

        // Sell 2 combos
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $combo->id,
                    'quantity' => '2',
                    'unit_price' => '8.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Verify stock deductions:
        // 2 combos * (2 beans + 1 milk + 1 bread) = 4 beans, 2 milk, 2 bread
        $beansStock = StockLevel::where('product_id', $beans->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('96.00', $beansStock->quantity); // 100 - 4

        $milkStock = StockLevel::where('product_id', $milk->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('48.00', $milkStock->quantity); // 50 - 2

        $breadStock = StockLevel::where('product_id', $bread->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('18.00', $breadStock->quantity); // 20 - 2
    }

    public function test_standard_pricing_mode_composite_item_does_not_store_combo_components(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — POST /api/v1/pos/receipts retired. '.
            'Standard-pricing-mode combo behaviour moves to device-authority (Task 29 + §18).',
        );

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Ingredient',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);

        $this->createStock($product->id, '100.0000');

        $standardItem = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'STANDARD',
            'name' => 'Standard Composite',
            'base_price' => '5.00',
            'pricing_mode' => PricingMode::Standard,
            'tax_rate' => '0.00',
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $standardItem->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $standardItem->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $standardItem->id,
                    'quantity' => '1',
                    'unit_price' => '5.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::with('lines')->find($receiptId);
        $line = $receipt->lines->first();

        // Standard pricing mode should NOT have combo_components
        $this->assertNull($line->combo_components);
    }

    public function test_vat_decomposition_service_method_returns_correct_structure(): void
    {
        $productA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product A',
            'sale_price' => '10.00',
            'tax_rate' => '10.00',
        ]);

        $productB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product B',
            'sale_price' => '5.00',
            'tax_rate' => '20.00',
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'VAT-COMBO',
            'name' => 'VAT Combo',
            'base_price' => '12.00', // discount vs 15.00 standalone
            'pricing_mode' => PricingMode::FixedBundle,
            'tax_rate' => '10.00',
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $productA->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $productB->id,
            'quantity' => 1,
        ]);

        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);
        $decomposition = $service->decomposeFixedBundleVat($combo, '12.00');

        $this->assertCount(2, $decomposition);

        // Verify structure
        foreach ($decomposition as $comp) {
            $this->assertArrayHasKey('name', $comp);
            $this->assertArrayHasKey('share', $comp);
            $this->assertArrayHasKey('tax_rate', $comp);
            $this->assertArrayHasKey('net_amount', $comp);
            $this->assertArrayHasKey('tax_amount', $comp);
        }

        // Verify shares sum to the combo price
        $totalShare = bcadd($decomposition[0]['share'], $decomposition[1]['share'], 3);
        $this->assertEquals('12.000', $totalShare);

        // Verify tax rates match the products. tax_rate is decimal(5,2) so
        // PostgreSQL returns "10.00"/"20.00" vs SQLite "10"/"20"; compare
        // numerically.
        $rates = array_map(static fn ($r): float => (float) $r, array_column($decomposition, 'tax_rate'));
        $this->assertContains(10.0, $rates);
        $this->assertContains(20.0, $rates);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->cashier->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);
    }

    private function createStock(string $productId, string $quantity): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $productId,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.00',
        ]);
    }
}
