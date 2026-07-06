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
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task B2: skew-corrected device count timestamps.
 *
 * `count_N_at` = server receive (unchanged); `count_N_device_at` = raw device
 * claim; `count_N_at_estimate` = corrected via device skew when both device
 * fields are present. B3/B4 replay consumes `count_N_at_estimate` exclusively,
 * so this estimate/flag computation is load-bearing.
 */
class CountTimestampSkewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $counterUser;

    private Location $warehouse;

    private InventoryCounting $counting;

    private InventoryCountingItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-skew',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $adminUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $adminUser->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Counter User',
            'email' => 'counter@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counterUser->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $adminUser->id,
        );

        // requires_count_2 = true so completing count 1 transitions to
        // Count2InProgress rather than PendingReview — this test is about the
        // count-submission timestamp/skew mechanics, not variance-triggered
        // reconciliation flagging (a separate, pre-existing concern).
        $this->counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => true,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $adminUser->id,
            'count_1_user_id' => $this->counterUser->id,
        ]);

        $this->item = InventoryCountingItem::create([
            'counting_id' => $this->counting->id,
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'theoretical_qty' => '100.0000',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submit(array $payload): TestResponse
    {
        return $this->actingAs($this->counterUser)
            ->postJson(
                "/api/v1/inventory/countings/{$this->counting->id}/items/{$this->item->id}/count",
                $payload
            );
    }

    public function test_no_device_fields_estimate_is_server_receive_time_no_flag(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        $response = $this->submit(['quantity' => 98]);

        $response->assertStatus(200);

        $this->item->refresh();

        $this->assertNotNull($this->item->count_1_at);
        $this->assertTrue($this->item->count_1_at->equalTo($serverNow));
        $this->assertNull($this->item->count_1_device_at);
        $this->assertNotNull($this->item->count_1_at_estimate);
        $this->assertTrue($this->item->count_1_at_estimate->equalTo($serverNow));
        $this->assertFalse($this->item->is_flagged);
        $this->assertNull($this->item->flag_reasons);
    }

    public function test_device_clock_accurate_but_count_taken_two_hours_earlier_corrects_estimate_no_flag(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        // Device clock is accurate at submission time (device_now == server_now),
        // but the count itself was physically taken 2h earlier (offline queue drain).
        $countedAtDevice = $serverNow->copy()->subHours(2);
        $deviceNow = $serverNow->copy();

        $response = $this->submit([
            'quantity' => 98,
            'counted_at_device' => $countedAtDevice->toIso8601String(),
            'device_now' => $deviceNow->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $this->item->refresh();

        $this->assertNotNull($this->item->count_1_device_at);
        $this->assertTrue($this->item->count_1_device_at->equalTo($countedAtDevice));

        // Skew (|server_now - device_now|) is ~0, so estimate ≈ counted_at_device
        // (the true instant of the count, 2h in the past) — no correction needed.
        $this->assertNotNull($this->item->count_1_at_estimate);
        $this->assertTrue($this->item->count_1_at_estimate->equalTo($countedAtDevice));

        $this->assertFalse($this->item->is_flagged);
        $this->assertNull($this->item->flag_reasons);
    }

    public function test_device_at_without_device_now_falls_back_to_server_estimate_and_flags_clock_skew(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        $countedAtDevice = $serverNow->copy()->subMinutes(10);

        $response = $this->submit([
            'quantity' => 98,
            'counted_at_device' => $countedAtDevice->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $this->item->refresh();

        $this->assertNotNull($this->item->count_1_device_at);
        $this->assertNotNull($this->item->count_1_at_estimate);
        $this->assertTrue($this->item->count_1_at_estimate->equalTo($serverNow));

        $this->assertTrue($this->item->is_flagged);
        $this->assertIsArray($this->item->flag_reasons);
        $this->assertContains(CountingItemFlagReason::ClockSkew->value, $this->item->flag_reasons);
    }

    public function test_skew_over_five_minutes_flags_clock_skew_even_with_correction(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        // Device clock is 6 minutes behind actual time at submission (skew > 5 min).
        $deviceNow = $serverNow->copy()->subMinutes(6);
        $countedAtDevice = $deviceNow->copy()->subMinutes(1);

        $response = $this->submit([
            'quantity' => 98,
            'counted_at_device' => $countedAtDevice->toIso8601String(),
            'device_now' => $deviceNow->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $this->item->refresh();

        // Estimate is still corrected: counted_at_device + (server_now - device_now).
        $expectedEstimate = $countedAtDevice->copy()->addMinutes(6);
        $this->assertNotNull($this->item->count_1_at_estimate);
        $this->assertTrue($this->item->count_1_at_estimate->equalTo($expectedEstimate));

        $this->assertTrue($this->item->is_flagged);
        $this->assertIsArray($this->item->flag_reasons);
        $this->assertContains(CountingItemFlagReason::ClockSkew->value, $this->item->flag_reasons);
    }

    public function test_skew_under_five_minutes_does_not_flag(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        // Device clock is 2 minutes behind — within tolerance.
        $deviceNow = $serverNow->copy()->subMinutes(2);
        $countedAtDevice = $deviceNow->copy()->subMinutes(1);

        $response = $this->submit([
            'quantity' => 98,
            'counted_at_device' => $countedAtDevice->toIso8601String(),
            'device_now' => $deviceNow->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $this->item->refresh();

        $expectedEstimate = $countedAtDevice->copy()->addMinutes(2);
        $this->assertTrue($this->item->count_1_at_estimate->equalTo($expectedEstimate));
        $this->assertFalse($this->item->is_flagged);
        $this->assertNull($this->item->flag_reasons);
    }

    public function test_malformed_timestamp_format_returns_422_validation_error(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        // Test malformed non-ISO-8601 formats.
        $malformedFormats = [
            'notadate',
            '07/06/2026 10:00',
            '2026-07-06 10:00:00', // SPACE separator instead of T
            '2026-07-06T10:00:00', // No timezone/offset
        ];

        foreach ($malformedFormats as $malformed) {
            $response = $this->submit([
                'quantity' => 98,
                'counted_at_device' => $malformed,
            ]);

            $response->assertStatus(422);
            $response->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'errors' => [
                        'counted_at_device',
                    ],
                ],
            ]);
            $this->assertNotEmpty($response->json('error.errors.counted_at_device'), "Expected validation error for counted_at_device");
        }
    }

    public function test_iso8601_with_non_utc_offset_accepted_and_converts_to_utc_correctly(): void
    {
        $serverNow = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        Carbon::setTestNow($serverNow);

        // Device at +02:00 offset (e.g., CEST); the device's local time is 14:00,
        // equivalent to 12:00 UTC. The device's clock is 5 minutes behind server.
        $countedAtDeviceOffset = Carbon::parse('2026-07-06T13:55:00+02:00'); // 11:55 UTC
        $deviceNowOffset = Carbon::parse('2026-07-06T13:55:00+02:00'); // 11:55 UTC (device thinks it's accurate)

        $response = $this->submit([
            'quantity' => 98,
            'counted_at_device' => $countedAtDeviceOffset->toIso8601String(),
            'device_now' => $deviceNowOffset->toIso8601String(),
        ]);

        $response->assertStatus(200);

        $this->item->refresh();

        // Verify the device_at field was parsed and stored correctly in UTC.
        $this->assertNotNull($this->item->count_1_device_at);
        $expected = Carbon::parse('2026-07-06 11:55:00', 'UTC');
        $actual = $this->item->count_1_device_at;
        $this->assertTrue(
            $actual->equalTo($expected),
            "counted_at_device should be converted and stored as UTC. Expected: {$expected->toIso8601String()}, Got: {$actual->toIso8601String()}"
        );

        // Skew math: server_now (12:00 UTC) - device_now (11:55 UTC) = 5 min correction.
        // Estimate = counted_at_device (11:55 UTC) + 5 min = 12:00 UTC.
        $expectedEstimate = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        $this->assertNotNull($this->item->count_1_at_estimate);
        $this->assertTrue(
            $this->item->count_1_at_estimate->equalTo($expectedEstimate),
            'Skew math should apply UTC conversion correctly: estimate should be corrected to 12:00 UTC'
        );

        // Skew is exactly 5 minutes, so should not flag (threshold is > 5 min).
        $this->assertFalse($this->item->is_flagged);
        $this->assertNull($this->item->flag_reasons);
    }
}
