<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task 6 (spec §4.2) — policy-aware stock enforcement on the POS ONLINE
 * draft sale path (`ReceiptCreationService::createReceipt`).
 *
 * - `Block` (default): insufficient stock keeps today's behavior — the
 *   sale is rejected with the unchanged "Insufficient stock for ..."
 *   RuntimeException and stock is untouched (transaction rolls back).
 * - `Warn` / `Off`: a warning is logged and the decrement PROCEEDS into
 *   negative stock — same direction the fiscal-event projection path
 *   (`PosCoreReceiptProjection`) already takes.
 * - The policy is resolved ONCE per receipt at the createReceipt boundary
 *   and threaded down; composite leaf deduction respects the same policy.
 *
 * The projection-path pin (a signed fiscal event always lands, regardless
 * of policy) lives in
 * `Tests\Feature\Fiscal\PosCoreReceiptProjectionTest::test_projection_warns_and_continues_on_insufficient_stock_even_under_block_policy`.
 */
final class ReceiptStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    private Product $product;

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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.50',
            'tax_rate' => '20.00',
        ]);

        // Single unit on hand — selling 2 forces the insufficient branch.
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '1.0000',
            'reserved' => '0.0000',
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    public function test_block_policy_rejects_insufficient_stock(): void
    {
        // Default policy is Block (migration default + non-F&B verticals).
        $this->assertSame(PosStockPolicy::Block, $this->company->refresh()->pos_stock_policy);

        try {
            $this->createReceipt(quantity: '2');
            $this->fail('Expected RuntimeException for insufficient stock under Block policy');
        } catch (\RuntimeException $e) {
            // Pin the existing message — Block keeps today's throw exactly.
            $this->assertStringStartsWith('Insufficient stock', $e->getMessage());
        }

        // Transaction rolled back — stock unchanged.
        $this->assertSame('1.0000', $this->productStock()->quantity);
    }

    public function test_warn_policy_proceeds_and_goes_negative(): void
    {
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Warn]);

        Log::spy();

        $receipt = $this->createReceipt(quantity: '2');

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock()->quantity);

        $productId = $this->product->id;
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($productId): bool {
                return str_contains($message, 'insufficient stock')
                    && ($context['product_id'] ?? null) === $productId;
            });
    }

    public function test_off_policy_proceeds(): void
    {
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Off]);

        $receipt = $this->createReceipt(quantity: '2');

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock()->quantity);
    }

    public function test_composite_leaf_deduction_respects_policy(): void
    {
        // Leaf product with ZERO stock on hand.
        $leaf = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Leaf Ingredient',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $leaf->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '0.0000',
            'reserved' => '0.0000',
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'POLICY-COMBO',
            'name' => 'Policy Combo',
            'base_price' => '5.00',
            'tax_rate' => '0.00',
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
            'component_id' => $leaf->id,
            'quantity' => 1,
        ]);

        // Block (default): leaf shortfall aborts the whole sale.
        try {
            $this->createCompositeReceipt($combo->id);
            $this->fail('Expected RuntimeException for insufficient leaf stock under Block policy');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Insufficient stock', $e->getMessage());
        }

        $this->assertSame('0.0000', $this->productStock($leaf->id)->quantity);

        // Off: same sale succeeds and the leaf goes negative.
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Off]);

        $receipt = $this->createCompositeReceipt($combo->id);

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock($leaf->id)->quantity);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createReceipt(string $quantity): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $quantity,
                    'unit_price' => '12.50',
                ],
            ],
        );
    }

    private function createCompositeReceipt(string $compositeItemId): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'composite_item_id' => $compositeItemId,
                    'quantity' => '1',
                    'unit_price' => '5.00',
                ],
            ],
        );
    }

    private function productStock(?string $productId = null): StockLevel
    {
        /** @var StockLevel $stock */
        $stock = StockLevel::where('product_id', $productId ?? $this->product->id)
            ->where('location_id', $this->location->id)
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        return $stock;
    }
}
