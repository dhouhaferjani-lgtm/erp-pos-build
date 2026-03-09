<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integration tests for receipt return flow.
 *
 * Tests returned quantity tracking on receipt detail and receipt_type filter.
 */
final class ReceiptReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Location $location;
    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_show_receipt_includes_returned_quantity_per_line(): void
    {
        // Arrange: Create a sale receipt with 2 lines
        $saleReceipt = $this->createSaleReceipt();
        $line1 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $line2 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 2,
            'product_id' => null,
            'product_code' => 'PROD-002',
            'product_name' => 'Widget B',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '20.000',
            'line_total' => '60.000',
            'tax_rate' => '19.00',
            'tax_amount' => '11.400',
            'discount_amount' => '0.000',
        ]);

        // Create a return receipt that returned 2 of Widget A
        $returnReceipt = $this->createReturnReceipt($saleReceipt);
        ReceiptLine::create([
            'receipt_id' => $returnReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '-2.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '-20.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-3.800',
            'discount_amount' => '0.000',
        ]);

        // Act: GET receipt detail
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert
        $response->assertStatus(200);
        $lines = $response->json('data.lines');

        $this->assertCount(2, $lines);

        // Widget A: 2 returned out of 5
        $widgetA = collect($lines)->firstWhere('product_code', 'PROD-001');
        $this->assertEquals('2.000', $widgetA['returned_quantity']);

        // Widget B: 0 returned out of 3
        $widgetB = collect($lines)->firstWhere('product_code', 'PROD-002');
        $this->assertEquals('0.000', $widgetB['returned_quantity']);
    }

    public function test_show_receipt_returns_zero_returned_quantity_when_no_returns(): void
    {
        // Arrange: Create a sale receipt with no returns
        $saleReceipt = $this->createSaleReceipt();
        ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Test Product',
            'quantity' => '3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.700',
            'discount_amount' => '0.000',
        ]);

        // Act
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert
        $response->assertStatus(200);
        $lines = $response->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertEquals('0.000', $lines[0]['returned_quantity']);
    }

    public function test_show_receipt_excludes_voided_returns_from_returned_quantity(): void
    {
        // Arrange: Sale receipt with one line
        $saleReceipt = $this->createSaleReceipt();
        ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        // Create a voided return receipt (should not count)
        $voidedReturn = $this->createReturnReceipt($saleReceipt, isVoided: true);
        ReceiptLine::create([
            'receipt_id' => $voidedReturn->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '-3.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '-30.000',
            'tax_rate' => '19.00',
            'tax_amount' => '-5.700',
            'discount_amount' => '0.000',
        ]);

        // Act
        $response = $this->getJson("/api/v1/pos/receipts/{$saleReceipt->id}");

        // Assert: voided return should not count
        $response->assertStatus(200);
        $lines = $response->json('data.lines');
        $this->assertEquals('0.000', $lines[0]['returned_quantity']);
    }

    public function test_receipt_type_filter_returns_only_matching_type(): void
    {
        // Arrange: Create a sale receipt and a return receipt
        $saleReceipt = $this->createSaleReceipt();
        $returnReceipt = $this->createReturnReceipt($saleReceipt);

        // Act: Filter by return type
        $response = $this->getJson('/api/v1/pos/receipts?receipt_type=return');

        // Assert: Only return receipt in results
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('return', $data[0]['receipt_type']);

        // Act: Filter by sale type
        $response = $this->getJson('/api/v1/pos/receipts?receipt_type=sale');

        // Assert: Only sale receipt in results
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('sale', $data[0]['receipt_type']);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function createSaleReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'EUR',
        ]);
    }

    private function createReturnReceipt(Receipt $originalReceipt, bool $isVoided = false): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $originalReceipt->id,
            'return_reason' => ReturnReason::Defective,
            'subtotal' => '-20.000',
            'tax_amount' => '-3.800',
            'total' => '-23.800',
            'currency' => 'EUR',
            'is_voided' => $isVoided,
            'voided_at' => $isVoided ? now() : null,
        ]);
    }
}
