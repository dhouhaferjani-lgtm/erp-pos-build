<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    private User $userWithoutPermission;

    private Location $location;

    private Terminal $terminal;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-01-01&to=2026-01-31');

        $response->assertStatus(401);
    }

    public function test_requires_view_reports_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-01-01&to=2026-01-31');

        $response->assertStatus(403);
    }

    public function test_validates_required_date_range(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/summary');
        $response->assertStatus(422);
        $this->assertArrayHasKey('from', $response->json('error.errors'));
        $this->assertArrayHasKey('to', $response->json('error.errors'));
    }

    public function test_validates_date_range_order(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-15&to=2026-03-01');
        $response->assertStatus(422);
        $this->assertArrayHasKey('to', $response->json('error.errors'));
    }

    public function test_validates_max_90_day_range(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-01-01&to=2026-06-01');
        $response->assertStatus(422);
        $this->assertArrayHasKey('to', $response->json('error.errors'));
    }

    public function test_summary_returns_correct_aggregations(): void
    {
        Sanctum::actingAs($this->user);
        $this->seedReceipts();

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'receipt_count',
                'gross_sales',
                'net_sales',
                'tax_total',
                'average_ticket',
                'refund_count',
                'refund_total',
                'voided_count',
                'payment_breakdown',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['receipt_count']);
        $this->assertEquals(0, $data['voided_count']);
    }

    public function test_summary_excludes_voided_receipts(): void
    {
        Sanctum::actingAs($this->user);

        // Create a voided receipt
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'is_voided' => true,
            'voided_at' => now(),
            // voided rows need voided_by (pos_receipts_void_logic, PostgreSQL).
            'voided_by' => $this->user->id,
            'fiscal_status' => FiscalStatus::Voided,
            'posted_at' => '2026-03-15 10:00:00',
            'subtotal' => '42.000',
            'tax_amount' => '8.000',
            'total' => '50.000',
        ]);

        // Create a normal receipt
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-15 11:00:00',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, $data['receipt_count']);
        $this->assertEquals(1, $data['voided_count']);
    }

    public function test_summary_location_filter_narrows_receipts(): void
    {
        Sanctum::actingAs($this->user);
        $this->seedReceipts();
        $secondLocation = Location::factory()->create(['company_id' => $this->company->id]);
        $secondTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $secondLocation->id,
        ]);
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $secondLocation->id,
            'terminal_id' => $secondTerminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-17 10:00:00',
            'subtotal' => '25.000',
            'tax_amount' => '4.750',
            'total' => '29.750',
        ]);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31&location_ids[]='.$secondLocation->id);

        $response->assertOk()->assertJsonPath('data.receipt_count', 1);
    }

    public function test_summary_rejects_location_outside_company_scope(): void
    {
        Sanctum::actingAs($this->user);
        $otherLocation = Location::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31&location_ids[]='.$otherLocation->id)
            ->assertForbidden();
    }

    public function test_company_scoping_isolation(): void
    {
        Sanctum::actingAs($this->user);

        $otherLocation = Location::factory()->create([
            'company_id' => $this->otherCompany->id,
        ]);

        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'location_id' => $otherLocation->id,
            'terminal_id' => $otherTerminal->id,
            'cashier_id' => $this->user->id,
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-15 10:00:00',
            'subtotal' => '500.000',
            'tax_amount' => '95.000',
            'total' => '595.000',
        ]);

        $response = $this->getJson('/api/v1/pos/analytics/summary?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(0, $data['receipt_count']);
    }

    public function test_sales_by_category_endpoint(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/sales-by-category?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    public function test_sales_by_product_endpoint(): void
    {
        Sanctum::actingAs($this->user);
        $this->seedReceipts();

        $response = $this->getJson('/api/v1/pos/analytics/sales-by-product?from=2026-03-01&to=2026-03-31&limit=5');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['product_name', 'total', 'quantity'],
            ],
        ]);
    }

    public function test_sales_by_period_endpoint(): void
    {
        Sanctum::actingAs($this->user);
        $this->seedReceipts();

        $response = $this->getJson('/api/v1/pos/analytics/sales-by-period?from=2026-03-01&to=2026-03-31&granularity=day');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['period', 'total', 'count'],
            ],
        ]);
    }

    public function test_cashiers_endpoint(): void
    {
        Sanctum::actingAs($this->user);
        $this->seedReceipts();

        $response = $this->getJson('/api/v1/pos/analytics/cashiers?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['cashier_id', 'cashier_name', 'receipt_count', 'total_sales', 'average_ticket'],
            ],
        ]);
    }

    public function test_discounts_endpoint(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/discounts?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'total_discount_amount',
                'discount_count',
                'by_reason',
                'top_discounted_products',
            ],
        ]);
    }

    public function test_customers_endpoint(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/customers?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'unique_customers',
                'returning_count',
                'returning_rate',
                'top_customers',
            ],
        ]);
    }

    public function test_fnb_endpoint(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/fnb?from=2026-03-01&to=2026-03-31');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'avg_table_time_minutes',
                'avg_items_per_order',
                'peak_hours',
                'orders_by_mode',
            ],
        ]);
    }

    public function test_invalid_granularity_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/analytics/sales-by-period?from=2026-03-01&to=2026-03-31&granularity=invalid');

        $response->assertStatus(422);
        $this->assertArrayHasKey('granularity', $response->json('error.errors'));
    }

    private function seedReceipts(): void
    {
        $receipt1 = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Test Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-15 10:00:00',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt1->id,
            'line_number' => 1,
            'product_name' => 'Coffee',
            'product_code' => 'COF001',
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'tax_rate' => '19.00',
            'tax_amount' => '19.00',
            'line_total' => '119.00',
            'discount_amount' => '0.00',
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt1->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => '119.00',
        ]);

        $receipt2 = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Test Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => '2026-03-16 14:00:00',
            'subtotal' => '50.000',
            'tax_amount' => '9.500',
            'total' => '59.500',
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt2->id,
            'line_number' => 1,
            'product_name' => 'Tea',
            'product_code' => 'TEA001',
            'quantity' => '1.000',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'tax_rate' => '19.00',
            'tax_amount' => '9.50',
            'line_total' => '59.50',
            'discount_amount' => '0.00',
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt2->id,
            'payment_method_id' => $this->cardMethod->id,
            'payment_type' => 'card',
            'amount' => '59.50',
        ]);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->userWithoutPermission = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo('pos.view_reports');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        $this->cardMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Card',
            'code' => 'CARD',
        ]);
    }
}
