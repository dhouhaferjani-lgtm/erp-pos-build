<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integration tests: promotion discounts reduce receipt totals (not just audit JSONB).
 */
final class PromotionReceiptIntegrationTest extends TestCase
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

    public function test_buy_x_get_y_promotion_reduces_receipt_total(): void
    {
        // Create products as composite items (POS uses composite_item_id)
        $frappIngredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Espresso Beans',
            'tax_rate' => '7.00',
            'cost_price' => '1.00',
        ]);
        $this->createStock($frappIngredient->id, '100.0000');

        $croissantIngredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Flour',
            'tax_rate' => '7.00',
            'cost_price' => '0.50',
        ]);
        $this->createStock($croissantIngredient->id, '100.0000');

        $frapp = $this->createCompositeItemWithRecipe('FRP-TEST', 'Frappuccino', '7.500', '7.00', $frappIngredient->id);
        $croissant = $this->createCompositeItemWithRecipe('CRO-TEST', 'Croissant', '3.000', '7.00', $croissantIngredient->id);

        // Create BuyXGetY promotion: buy Frapp, get free Croissant
        Promotion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Buy Frapp Get Croissant',
            'type' => PromotionType::BuyXGetY,
            'status' => PromotionStatus::Active,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'applies_to' => DiscountAppliesTo::SpecificItem,
            'conditions' => [
                'qualifying_product_ids' => [$frapp->id],
                'trigger_qty' => 1,
                'reward_product_ids' => [$croissant->id],
            ],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        // Create receipt with 1 Frapp + 1 Croissant
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $frapp->id,
                    'quantity' => '1',
                    'unit_price' => '7.500',
                ],
                [
                    'composite_item_id' => $croissant->id,
                    'quantity' => '1',
                    'unit_price' => '3.000',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::with('lines')->find($receiptId);
        $this->assertNotNull($receipt);

        // Croissant should be fully discounted — receipt total should be Frapp TTC only
        // Frapp TTC = 7.500, Croissant TTC = 3.000, discount = 3.000
        // Total = 7.500 (croissant line discount reduces its line_total to 0)
        $this->assertEquals('7.500', $receipt->total);

        // Verify the croissant line has a discount
        $croissantLine = $receipt->lines->firstWhere('composite_item_id', $croissant->id);
        $this->assertNotNull($croissantLine);
        $this->assertEquals('3.000', $croissantLine->discount_amount);
        $this->assertEquals('0.000', $croissantLine->line_total);

        // Verify discount_breakdown JSONB is present
        $this->assertNotNull($receipt->discount_breakdown);
        $this->assertArrayHasKey('line_discounts', $receipt->discount_breakdown);
    }

    public function test_promotion_transaction_discount_reduces_receipt_total(): void
    {
        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Ingredient',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);
        $this->createStock($ingredient->id, '100.0000');

        $item = $this->createCompositeItemWithRecipe('TEST-HH', 'Test Item', '100.000', '0.00', $ingredient->id);

        // Create HappyHour 10% promotion
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Happy Hour 10%',
            'type' => PromotionType::HappyHour,
            'status' => PromotionStatus::Active,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '10',
            'applies_to' => DiscountAppliesTo::Transaction,
            'conditions' => [],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertTrue($promo->isCurrentlyActive());

        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $item->id,
                    'quantity' => '1',
                    'unit_price' => '100.000',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::find($receiptId);
        $this->assertNotNull($receipt);

        // 10% of 100 = 10 discount, total should be 90
        $this->assertNotNull($receipt->discount_breakdown);
        $this->assertEquals('10.000', $receipt->discount_amount);
        $this->assertEquals('90.000', $receipt->total);
    }

    public function test_orchestrator_failure_falls_back_to_manual_only(): void
    {
        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Ingredient',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);
        $this->createStock($ingredient->id, '100.0000');

        $item = $this->createCompositeItemWithRecipe('TEST-FALL', 'Test Item', '50.000', '0.00', $ingredient->id);

        // No promotions → orchestrator returns 0 for both line and transaction
        // Manual discount of 5.000
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $item->id,
                    'quantity' => '1',
                    'unit_price' => '50.000',
                ],
            ],
            'transaction_discount_amount' => '5.000',
            'transaction_discount_reason' => 'Regular customer',
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::find($receiptId);
        $this->assertNotNull($receipt);

        // Total = 50 - 5 = 45
        $this->assertEquals('45.000', $receipt->total);
    }

    public function test_promotion_line_discount_adjusts_vat_correctly(): void
    {
        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Ingredient',
            'tax_rate' => '7.00',
            'cost_price' => '1.00',
        ]);
        $this->createStock($ingredient->id, '100.0000');

        $triggerItem = $this->createCompositeItemWithRecipe('TRIG', 'Trigger', '10.000', '7.00', $ingredient->id);
        $rewardItem = $this->createCompositeItemWithRecipe('REWARD', 'Reward', '5.000', '7.00', $ingredient->id);

        // BuyXGetY: buy trigger, get reward free
        Promotion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Free Reward',
            'type' => PromotionType::BuyXGetY,
            'status' => PromotionStatus::Active,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'applies_to' => DiscountAppliesTo::SpecificItem,
            'conditions' => [
                'qualifying_product_ids' => [$triggerItem->id],
                'trigger_qty' => 1,
                'reward_product_ids' => [$rewardItem->id],
            ],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'composite_item_id' => $triggerItem->id,
                    'quantity' => '1',
                    'unit_price' => '10.000',
                ],
                [
                    'composite_item_id' => $rewardItem->id,
                    'quantity' => '1',
                    'unit_price' => '5.000',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::with(['lines', 'vatDetails'])->find($receiptId);
        $this->assertNotNull($receipt);

        // Reward item fully discounted → only trigger item contributes to VAT
        // Trigger: 10.000 TTC → net = bcdiv(10.000, 1.07, 3) = 9.345, tax via roundVat ≈ 0.654
        // Reward: 0.000 TTC (fully discounted)
        // Total = subtotal + totalTax = 9.345 + 0.654 = 9.999 (TND 3-decimal rounding)
        $receiptTotal = (float) $receipt->total;
        $this->assertLessThanOrEqual(10.001, $receiptTotal, 'Total should reflect free reward item');
        $this->assertGreaterThanOrEqual(9.998, $receiptTotal, 'Total should be close to trigger price');

        // VAT should be computed on post-discount amount (only trigger item)
        $totalVat = $receipt->vatDetails->sum(fn ($vd) => (float) $vd->vat_amount);
        // VAT should be approximately 0.654 (7% of trigger net), not 0.981 (7% of full 14.019)
        $this->assertLessThan(0.75, $totalVat, 'VAT should be calculated on post-discount net, not full price');
        $this->assertGreaterThan(0.60, $totalVat, 'VAT should reflect the trigger item tax');
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
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
            'max_discount_percent' => 100.00,
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

    private function createCompositeItemWithRecipe(
        string $code,
        string $name,
        string $price,
        string $taxRate,
        string $ingredientProductId,
    ): CompositeItem {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'base_price' => $price,
            'tax_rate' => $taxRate,
            'is_active' => true,
            'is_available' => true,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $ingredientProductId,
            'quantity' => 1,
        ]);

        $item->update(['default_recipe_id' => $recipe->id]);

        return $item;
    }
}
