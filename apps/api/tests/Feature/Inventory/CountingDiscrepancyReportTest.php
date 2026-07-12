<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\AssignmentStatus;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CountingDiscrepancyReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $counter;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Counting Report Tenant',
            'slug' => 'counting-report-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counting Report Company',
            'legal_name' => 'Counting Report Company LLC',
            'tax_id' => 'CRT-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->user('report-admin@example.com', 'Report Admin');
        $this->admin->givePermissionTo(['inventory.view', 'inventory.adjust']);

        $this->counter = $this->user('report-counter@example.com', 'Report Counter');
        $this->counter->givePermissionTo(['inventory.view']);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'RPT-01',
            'name' => 'Report Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);
    }

    public function test_finalized_counting_report_matches_frontend_contract(): void
    {
        $counting = $this->counting(CountingStatus::Finalized, [
            'late_sales_flags' => [
                ['receipt_id' => 'receipt-late-1', 'occurred_at' => now()->subMinutes(10)->toIso8601String()],
            ],
        ]);

        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->counter->id,
            'count_number' => 1,
            'status' => AssignmentStatus::Completed,
            'assigned_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(5),
            'total_items' => 4,
            'counted_items' => 4,
        ]);

        $noVariance = $this->product('RPT-NO', 'No Variance Item', '1.000000');
        $positiveVariance = $this->product('RPT-POS', 'Positive Variance Item', '2.500000');
        $negativeVariance = $this->product('RPT-NEG', 'Negative Variance Item', '1.250000');
        $opening = $this->product('RPT-OPEN', 'Opening Item', '4.000000');

        $this->item($counting, $noVariance, [
            'theoretical_qty' => '10.0000',
            'count_1_qty' => '10.0000',
            'final_qty' => '10.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
            'is_flagged' => false,
        ]);

        $this->item($counting, $positiveVariance, [
            'theoretical_qty' => '10.0000',
            'count_1_qty' => '13.0000',
            'final_qty' => '13.0000',
            'expected_qty_at_apply' => '11.0000',
            'replay_audit' => [
                'windowFrom' => now()->subHour()->toIso8601String(),
                'windowTo' => now()->toIso8601String(),
                'replayedDelta' => '-2.0000',
                'onHandAtApply' => '9.0000',
                'expectedAtApply' => '11.0000',
            ],
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
            'is_flagged' => true,
            'flag_reason' => 'variance_from_theoretical',
            'flag_reasons' => ['normalized_agreement'],
        ]);

        $this->item($counting, $negativeVariance, [
            'theoretical_qty' => '10.0000',
            'count_1_qty' => '7.0000',
            'final_qty' => '7.0000',
            'resolution_method' => ItemResolutionMethod::ManualOverride,
            'resolution_notes' => 'Broken package found',
            'is_flagged' => true,
            'flag_reason' => 'manual_override',
        ]);

        $this->item($counting, $opening, [
            'theoretical_qty' => '0.0000',
            'count_1_qty' => '3.0000',
            'final_qty' => '3.0000',
            'opening_unit_cost' => '4.000000',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
            'is_flagged' => true,
            'flag_reason' => 'variance_from_theoretical',
        ], Location::create([
            'company_id' => $this->company->id,
            'code' => 'OPEN-01',
            'name' => 'Opening Location',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
            'onboarding_mode' => true,
        ]));

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/report");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'report_id',
                    'generated_at',
                    'generated_by' => ['id', 'name'],
                    'counting' => ['id', 'status', 'progress'],
                    'summary' => [
                        'total_items_counted',
                        'items_no_variance',
                        'items_with_variance',
                        'variance_breakdown',
                        'total_variance_value' => ['positive', 'negative', 'net', 'currency'],
                        'late_sales_corrections',
                        'opening_items',
                        'opening_value',
                    ],
                    'flagged_items',
                    'counter_performance',
                ],
            ]);

        $this->assertIsString($response->json('data.report_id'));
        $this->assertSame($this->admin->id, $response->json('data.generated_by.id'));
        $this->assertSame(4, $response->json('data.summary.total_items_counted'));
        $this->assertSame(1, $response->json('data.summary.items_no_variance'));
        $this->assertSame(3, $response->json('data.summary.items_with_variance'));
        $this->assertSame(1, $response->json('data.summary.variance_breakdown.auto_all_match'));
        $this->assertSame(2, $response->json('data.summary.variance_breakdown.auto_counters_agree'));
        $this->assertSame(0, $response->json('data.summary.variance_breakdown.third_count_decisive'));
        $this->assertSame(1, $response->json('data.summary.variance_breakdown.manual_override'));
        $this->assertSame('17.000', $response->json('data.summary.total_variance_value.positive'));
        $this->assertSame('-3.750', $response->json('data.summary.total_variance_value.negative'));
        $this->assertSame('13.250', $response->json('data.summary.total_variance_value.net'));
        $this->assertSame('TND', $response->json('data.summary.total_variance_value.currency'));
        $this->assertSame(1, $response->json('data.summary.late_sales_corrections'));
        $this->assertSame(1, $response->json('data.summary.opening_items'));
        $this->assertSame('12.000', $response->json('data.summary.opening_value'));
        $this->assertCount(3, $response->json('data.flagged_items'));
        $this->assertSame('11.0000', $response->json('data.flagged_items.0.expected_qty_at_apply'));
        $this->assertSame('-2.0000', $response->json('data.flagged_items.0.replay_audit.replayedDelta'));
        $this->assertSame($this->counter->id, $response->json('data.counter_performance.0.user.id'));
        $this->assertSame(4, $response->json('data.counter_performance.0.items_counted'));
        $this->assertSame(0, $response->json('data.counter_performance.0.matched_other_counter'));
        $this->assertSame(1, $response->json('data.counter_performance.0.matched_theoretical'));
        $this->assertSame(0, $response->json('data.counter_performance.0.times_proven_wrong_by_3rd'));
        $this->assertEqualsWithDelta(25.0, $response->json('data.counter_performance.0.accuracy_rate'), 0.0);
    }

    public function test_report_rejects_statuses_before_review(): void
    {
        foreach ([CountingStatus::Draft, CountingStatus::Count1InProgress] as $status) {
            $counting = $this->counting($status);

            $this->actingAs($this->admin)
                ->getJson("/api/v1/inventory/countings/{$counting->id}/report")
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'COUNTING_REPORT_UNAVAILABLE');
        }
    }

    public function test_report_is_company_scoped(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Counting Report Company',
            'legal_name' => 'Other Counting Report Company LLC',
            'tax_id' => 'OCR-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $foreign = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'created_by_user_id' => $this->admin->id,
            'status' => CountingStatus::Finalized,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/inventory/countings/{$foreign->id}/report")
            ->assertNotFound();
    }

    public function test_report_requires_inventory_view_permission(): void
    {
        $viewerWithoutPermission = $this->user('report-no-permission@example.com', 'No Permission');
        $counting = $this->counting(CountingStatus::Finalized);

        $this->actingAs($viewerWithoutPermission)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/report")
            ->assertForbidden();
    }

    private function user(string $email, string $name): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function counting(CountingStatus $status, array $attributes = []): InventoryCounting
    {
        return InventoryCounting::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->admin->id,
            'status' => $status,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->counter->id,
            'finalized_at' => $status === CountingStatus::Finalized ? now() : null,
        ], $attributes));
    }

    private function product(string $sku, string $name, string $costPrice): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => $costPrice,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(
        InventoryCounting $counting,
        Product $product,
        array $attributes,
        ?Location $location = null,
    ): InventoryCountingItem {
        return InventoryCountingItem::create(array_merge([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => ($location ?? $this->location)->id,
            'theoretical_qty' => '0.0000',
            'resolution_method' => ItemResolutionMethod::Pending,
            'is_flagged' => false,
            'is_unexpected_item' => false,
        ], $attributes));
    }
}
