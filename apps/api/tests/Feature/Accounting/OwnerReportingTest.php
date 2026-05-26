<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OwnerReportingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $childCompany;

    private Company $otherCompany;

    private User $owner;

    private User $userWithoutPermission;

    private Location $locationA;

    private Location $locationB;

    private Location $childLocation;

    private Terminal $terminalA;

    private Terminal $terminalB;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parent Company',
        ]);
        $this->childCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Company',
            'parent_company_id' => $this->company->id,
            'is_headquarters' => false,
        ]);
        $this->otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outside Company',
        ]);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('dashboard.owner', 'sanctum');
        $this->owner->givePermissionTo('dashboard.owner');

        $this->locationA = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Downtown',
        ]);
        $this->locationB = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Airport',
        ]);
        $this->childLocation = Location::factory()->create([
            'company_id' => $this->childCompany->id,
            'name' => 'Child Shop',
        ]);

        $this->terminalA = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->locationA->id,
        ]);
        $this->terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->locationB->id,
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

    public function test_owner_dashboard_permission_is_required(): void
    {
        Sanctum::actingAs($this->userWithoutPermission);

        $response = $this->getJson(
            '/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31',
            $this->companyHeaders(),
        );

        $response->assertForbidden();
    }

    public function test_sales_by_location_groups_receipts_by_location_and_day(): void
    {
        Sanctum::actingAs($this->owner);

        $this->seedReceipt($this->locationA, $this->terminalA, '2026-05-01 10:00:00', '120.000');
        $this->seedReceipt($this->locationB, $this->terminalB, '2026-05-01 11:00:00', '80.000');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-01 10:00:00', '999.000');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-05-01 12:00:00', '777.000', trainingFlag: true);

        $response = $this->getJson(
            '/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31&granularity=day',
            $this->companyHeaders(),
        );

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => [
                    'period',
                    'company_id',
                    'company_name',
                    'location_id',
                    'location_name',
                    'gross_sales',
                    'receipt_count',
                ],
            ],
        ]);

        $rows = collect($response->json('data'));
        $this->assertSame('120', $rows->firstWhere('location_id', $this->locationA->id)['gross_sales']);
        $this->assertSame('80', $rows->firstWhere('location_id', $this->locationB->id)['gross_sales']);
        $this->assertNull($rows->firstWhere('gross_sales', '999'));
        $this->assertNull($rows->firstWhere('gross_sales', '777'));
    }

    public function test_sales_by_location_filters_requested_locations(): void
    {
        Sanctum::actingAs($this->owner);

        $this->seedReceipt($this->locationA, $this->terminalA, '2026-05-01 10:00:00', '120.000');
        $this->seedReceipt($this->locationB, $this->terminalB, '2026-05-01 11:00:00', '80.000');

        $response = $this->getJson(
            "/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31&location_ids[]={$this->locationB->id}",
            $this->companyHeaders(),
        );

        $response->assertOk();
        $this->assertSame([$this->locationB->id], collect($response->json('data'))->pluck('location_id')->unique()->values()->all());
    }

    public function test_location_filter_rejects_locations_outside_user_membership_scope(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => [$this->locationA->id],
        ]);
        $manager->givePermissionTo('dashboard.owner');
        Sanctum::actingAs($manager);

        $this->seedReceipt($this->locationA, $this->terminalA, '2026-05-01 10:00:00', '120.000');
        $this->seedReceipt($this->locationB, $this->terminalB, '2026-05-01 11:00:00', '80.000');

        $allowedResponse = $this->getJson(
            '/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31',
            $this->companyHeaders(),
        );
        $allowedResponse->assertOk();
        $this->assertSame([$this->locationA->id], collect($allowedResponse->json('data'))->pluck('location_id')->unique()->values()->all());

        $blockedResponse = $this->getJson(
            "/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31&location_ids[]={$this->locationB->id}",
            $this->companyHeaders(),
        );
        $blockedResponse->assertForbidden();
    }

    public function test_company_filter_rejects_companies_outside_owner_scope(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson(
            "/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31&company_ids[]={$this->otherCompany->id}",
            $this->companyHeaders(),
        );

        $response->assertForbidden();
    }

    public function test_sales_by_location_can_include_child_company_scope(): void
    {
        Sanctum::actingAs($this->owner);

        $childTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->childCompany->id,
            'location_id' => $this->childLocation->id,
        ]);
        $this->seedReceipt($this->childLocation, $childTerminal, '2026-05-02 10:00:00', '150.000');

        $response = $this->getJson(
            "/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31&company_ids[]={$this->childCompany->id}",
            $this->companyHeaders(),
        );

        $response->assertOk();
        $this->assertSame([$this->childCompany->id], collect($response->json('data'))->pluck('company_id')->unique()->values()->all());
    }

    public function test_top_skus_returns_limit_ordered_by_revenue(): void
    {
        Sanctum::actingAs($this->owner);

        $productA = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Brake Pads', 'sku' => 'BRAKE']);
        $productB = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Oil Filter', 'sku' => 'OIL']);
        $this->seedReceiptWithLine($productA, $this->locationA, $this->terminalA, '2026-05-03 10:00:00', '200.000', '2.000');
        $this->seedReceiptWithLine($productB, $this->locationA, $this->terminalA, '2026-05-03 11:00:00', '50.000', '5.000');

        $response = $this->getJson(
            '/api/v1/reports/sales/top-skus?from=2026-05-01&to=2026-05-31&limit=1&sort_by=revenue',
            $this->companyHeaders(),
        );

        $response->assertOk()->assertJsonPath('data.0.product_id', $productA->id);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_revenue_by_category_groups_uncategorized_products(): void
    {
        Sanctum::actingAs($this->owner);

        $category = Category::factory()->create(['company_id' => $this->company->id, 'name' => 'Parts']);
        $categorized = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'category_id' => $category->id, 'name' => 'Pad Set']);
        $uncategorized = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'category_id' => null, 'name' => 'Loose Item']);
        $this->seedReceiptWithLine($categorized, $this->locationA, $this->terminalA, '2026-05-04 10:00:00', '70.000', '1.000');
        $this->seedReceiptWithLine($uncategorized, $this->locationA, $this->terminalA, '2026-05-04 11:00:00', '30.000', '1.000');

        $response = $this->getJson(
            '/api/v1/reports/sales/revenue-by-category?from=2026-05-01&to=2026-05-31',
            $this->companyHeaders(),
        );

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['category_id', 'category_name', 'revenue', 'percentage', 'quantity'],
            ],
        ]);

        $this->assertNotNull(collect($response->json('data'))->firstWhere('category_name', 'Parts'));
        $this->assertNotNull(collect($response->json('data'))->firstWhere('category_name', 'Uncategorized'));
    }

    public function test_payment_method_breakdown_returns_amount_and_percentage(): void
    {
        Sanctum::actingAs($this->owner);

        $receipt = $this->seedReceipt($this->locationA, $this->terminalA, '2026-05-05 10:00:00', '100.000');
        ReceiptPayment::query()->where('receipt_id', $receipt->id)->delete();
        $this->seedPayment($receipt, $this->cashMethod, 'cash', '60.000');
        $this->seedPayment($receipt, $this->cardMethod, 'card', '40.000');

        $response = $this->getJson(
            '/api/v1/reports/sales/payment-method-breakdown?from=2026-05-01&to=2026-05-31',
            $this->companyHeaders(),
        );

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['payment_type', 'payment_method_name', 'amount', 'percentage', 'transaction_count'],
            ],
        ]);

        $cash = collect($response->json('data'))->firstWhere('payment_type', 'cash');
        $this->assertSame('60', $cash['amount']);
        $this->assertSame('60.00', $cash['percentage']);
    }

    public function test_stock_alerts_returns_below_minimum_with_severity(): void
    {
        Sanctum::actingAs($this->owner);

        $out = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'No Stock']);
        $low = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Low Stock']);
        $ok = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Enough Stock']);

        $this->seedStockLevel($out, '0.00', '10.00');
        $this->seedStockLevel($low, '4.00', '10.00');
        $this->seedStockLevel($ok, '12.00', '10.00');

        $response = $this->getJson('/api/v1/reports/stock/alerts?threshold_pct=100', $this->companyHeaders());

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['product_id', 'product_name', 'location_id', 'location_name', 'quantity', 'min_quantity', 'threshold_pct', 'severity'],
            ],
        ]);

        $rows = collect($response->json('data'));
        $this->assertSame('out_of_stock', $rows->firstWhere('product_id', $out->id)['severity']);
        $this->assertSame('critical', $rows->firstWhere('product_id', $low->id)['severity']);
        $this->assertNull($rows->firstWhere('product_id', $ok->id));
    }

    public function test_cash_register_reconciliation_returns_shift_variance(): void
    {
        Sanctum::actingAs($this->owner);

        CompanyFraudSettings::query()->updateOrCreate(
            ['company_id' => $this->company->id],
            [...CompanyFraudSettings::getDefaults(), 'cash_variance_under_hard' => '4.0000'],
        );

        Shift::create([
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->owner->id,
            'shift_number' => 7,
            'opening_cash' => '100.00',
            'expected_cash' => '200.00',
            'actual_cash' => '195.00',
            'variance' => '-5.00',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-05-06 08:00:00',
            'closed_at' => '2026-05-06 17:00:00',
            'closed_by' => $this->owner->id,
        ]);

        $response = $this->getJson(
            '/api/v1/reports/cash-register/reconciliation?from=2026-05-01&to=2026-05-31',
            $this->companyHeaders(),
        );

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => [
                    'date',
                    'location_id',
                    'location_name',
                    'terminal_id',
                    'terminal_name',
                    'shift_id',
                    'expected_cash',
                    'counted_cash',
                    'variance',
                    'variance_severity',
                ],
            ],
        ]);
        $response->assertJsonPath('data.0.variance', '-5');
        $response->assertJsonPath('data.0.variance_severity', 'critical');
    }

    /**
     * @return array<string, string>
     */
    private function companyHeaders(): array
    {
        return ['X-Company-Id' => $this->company->id];
    }

    private function seedReceipt(Location $location, Terminal $terminal, string $postedAt, string $total, bool $trainingFlag = false): Receipt
    {
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => $postedAt,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'training_flag' => $trainingFlag,
        ]);

        $this->seedPayment($receipt, $this->cashMethod, 'cash', $total);

        return $receipt;
    }

    private function seedReceiptWithLine(Product $product, Location $location, Terminal $terminal, string $postedAt, string $lineTotal, string $quantity): Receipt
    {
        $receipt = $this->seedReceipt($location, $terminal, $postedAt, $lineTotal);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => $lineTotal,
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => $lineTotal,
            'discount_amount' => '0.00',
        ]);

        return $receipt;
    }

    private function seedPayment(Receipt $receipt, PaymentMethod $method, string $paymentType, string $amount): void
    {
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $method->id,
            'payment_type' => $paymentType,
            'amount' => $amount,
        ]);
    }

    private function seedStockLevel(Product $product, string $quantity, string $minQuantity): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->locationA->id,
            'quantity' => $quantity,
            'reserved' => '0.00',
            'min_quantity' => $minQuantity,
        ]);
    }
}
