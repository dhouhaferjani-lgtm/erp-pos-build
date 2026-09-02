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
use Tests\Traits\AssertsApiValidation;

/**
 * N-1 / A-1 — house rule 19 scale ceiling on the counter's submit-count entry gate.
 *
 * `SubmitCountRequest::rules()` validated `quantity` as `['required','numeric','min:0']`
 * with no scale ceiling while `quantity()` hands the raw input to `bcadd($raw, '0', 4)`.
 * Identical defect pair to the one closed on the sibling `ManualOverrideRequest`
 * (commit 1706e0877):
 *
 *  - `'1e3'` passes `numeric` and then blows up inside bcadd ("not well-formed")
 *    -> catch-all renderer -> HTTP 500.
 *  - `'12.99999'` is accepted and silently TRUNCATED to `12.9999` at rest
 *    (bcadd truncates, it does not round) — the counter's number is altered and
 *    that altered value is what reconciliation and finalize() consume.
 */
class SubmitCountQuantityScaleTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant SCQ',
            'slug' => 'test-tenant-scq',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company SCQ',
            'legal_name' => 'Test Company SCQ LLC',
            'tax_id' => 'TAXSCQ',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin SCQ',
            'email' => 'admin-scq@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter SCQ',
            'email' => 'counter-scq@example.com',
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
            'code' => 'WH-SCQ',
            'name' => 'SCQ Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-SCQ-1',
            'name' => 'SCQ Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    public function test_submit_count_refuses_exponent_notation_instead_of_500ing(): void
    {
        $item = $this->createCountingItem();

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$item->counting_id}/items/{$item->id}/count", [
                'quantity' => '1e3',
            ]);

        $this->assertApiValidationErrors($response, ['quantity']);

        $this->assertNull(
            $item->fresh()?->count_1_qty,
            'A refused submission must not have written a quantity.',
        );
    }

    public function test_submit_count_refuses_a_quantity_beyond_scale_4_instead_of_truncating(): void
    {
        $item = $this->createCountingItem();

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$item->counting_id}/items/{$item->id}/count", [
                'quantity' => '12.99999',
            ]);

        $this->assertApiValidationErrors($response, ['quantity']);

        $this->assertNull(
            $item->fresh()?->count_1_qty,
            'The counter number must be refused, never silently truncated to 12.9999.',
        );
    }

    /**
     * The ceiling must not narrow the legitimate range: a 4-dp quantity, a
     * padded value, an integer and a JSON numeric literal all still pass and
     * are stored verbatim at 4 d.p.
     */
    public function test_submit_count_still_accepts_quantities_at_or_below_scale_4(): void
    {
        foreach (['12.5', '12.0000', '12', 6.75] as $index => $quantity) {
            $item = $this->createCountingItem();

            $response = $this->actingAs($this->counterUser)
                ->postJson("/api/v1/inventory/countings/{$item->counting_id}/items/{$item->id}/count", [
                    'quantity' => $quantity,
                ]);

            $response->assertStatus(200);
            $this->assertSame(
                bcadd((string) $quantity, '0', 4),
                $item->fresh()?->count_1_qty,
                "Case {$index}: a within-scale quantity must still be stored verbatim at 4 d.p.",
            );
        }
    }

    // --- Helpers ---

    private function createCountingItem(): InventoryCountingItem
    {
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
            'count_1_user_id' => $this->counterUser->id,
        ]);

        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->counterUser->id,
            'count_number' => 1,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);

        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'theoretical_qty' => '100.0000',
        ]);
    }
}
