<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests server-side discount enforcement for POS receipts.
 *
 * Validates:
 * - Transaction discount saved correctly and reflected in total
 * - Line discount exceeding terminal limit → 422
 * - Transaction discount > 10% without reason → 422
 * - Cashier with can_discount = false → 422
 */
final class DiscountEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Location $location;

    private Terminal $terminal;

    private Product $product;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->cashier);
    }

    public function test_transaction_discount_saved_and_reflected_in_total(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => '50.00',
                ],
            ],
            'transaction_discount_amount' => '5.00',
            'transaction_discount_reason' => 'Loyal customer',
        ]);

        $response->assertStatus(201);

        // Verify receipt has correct discount
        $receiptId = $response->json('data.id');
        $receipt = Receipt::find($receiptId);
        $this->assertNotNull($receipt);
        $this->assertEquals('5.000', $receipt->discount_amount);
        $this->assertEquals('Loyal customer', $receipt->discount_reason);

        // Total should be (subtotal + tax) - discount
        // Line total = 2 * 50 = 100, no tax on this product
        // Total = 100 - 5 = 95
        $this->assertEquals('95.000', $receipt->total);
    }

    public function test_line_discount_percentage_exceeding_limit_returns_422(): void
    {
        // Terminal max is 20%, try 30%
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'discount_type' => 'percentage',
                    'discount_percent' => '30',
                    'discount_reason' => 'Over limit',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DISCOUNT_VALIDATION_FAILED');
    }

    public function test_transaction_discount_exceeding_10_percent_without_reason_returns_422(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                ],
            ],
            'transaction_discount_amount' => '15.00', // 15% of 100
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DISCOUNT_VALIDATION_FAILED');
    }

    public function test_cashier_without_discount_permission_returns_422(): void
    {
        // Create cashier without discount permission
        $nondiscountCashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
        ]);

        UserCompanyMembership::create([
            'user_id' => $nondiscountCashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $nondiscountCashier->givePermissionTo('pos.operate_terminal');

        // Open a shift for this cashier
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $nondiscountCashier->id,
            'shift_number' => 2,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);

        // Close the previous shift first
        $this->shift->update(['status' => ShiftStatus::Closed, 'closed_at' => now()]);

        Sanctum::actingAs($nondiscountCashier);

        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'discount_type' => 'percentage',
                    'discount_percent' => '5',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DISCOUNT_VALIDATION_FAILED');
    }

    public function test_line_discount_fixed_amount_applied_correctly(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'discount_amount' => '10.00',
                    'discount_reason' => 'Small discount',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::with('lines')->find($receiptId);
        $this->assertNotNull($receipt);

        $line = $receipt->lines->first();
        $this->assertNotNull($line);
        $this->assertEquals('10.000', $line->discount_amount);
        $this->assertEquals('90.000', $line->line_total);
    }

    public function test_receipt_without_discount_works_as_before(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::find($receiptId);
        $this->assertNotNull($receipt);
        $this->assertEquals('0.000', $receipt->discount_amount);
        $this->assertNull($receipt->discount_reason);
    }

    public function test_transaction_discount_within_10_percent_without_reason_succeeds(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '100.00',
                ],
            ],
            'transaction_discount_amount' => '8.00', // 8% of 100 → under 10% threshold
        ]);

        $response->assertStatus(201);

        $receiptId = $response->json('data.id');
        $receipt = Receipt::find($receiptId);
        $this->assertNotNull($receipt);
        $this->assertEquals('8.000', $receipt->discount_amount);
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
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => '0.00',
        ]);

        // Create stock level so stock decrement doesn't block us
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '1000.00',
            'reserved_quantity' => '0.00',
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
}
