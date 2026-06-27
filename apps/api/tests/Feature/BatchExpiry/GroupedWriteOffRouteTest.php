<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B3b — HTTP wiring for the grouped write-off endpoint.
 *
 * POST /api/v1/batches/write-off-grouped
 *
 * Covers:
 *   - 401 unauthenticated
 *   - 403 no batches.write-off permission
 *   - 422 validation: missing/invalid location_id, empty lines, bad quantity
 *     (5 decimals, zero, negative), invalid reason, missing idempotency_key
 *   - Tenant-scope rejection for foreign location_id / batch_id
 *   - 201 happy path: correct result payload + one movement created per lot
 *   - Idempotent replay: same result, stock decremented only once
 */
final class GroupedWriteOffRouteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GWO Route Tenant',
            'slug' => 'gwo-route-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GWO Route Company',
            'legal_name' => 'GWO Route Company LLC',
            'tax_id' => 'GWO-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GWO Route Admin',
            'email' => 'gwo-route-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GWO-R01',
            'name' => 'GWO Route Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'GWO-R-PROD-001',
            'name' => 'GWO Route Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '50.0000',
            'reserved' => '0.0000',
        ]);

        $this->batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-GWO-R-001',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $this->batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '50.0000',
            'reserved_quantity' => '0.0000',
        ]);

        // GL accounts required by BatchWriteOffService to post journal entries.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '311',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Auth gates
    // -----------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/v1/batches/write-off-grouped', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_user_without_write_off_permission_is_forbidden(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'gwo-viewer-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $viewer->givePermissionTo('inventory.view');

        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Viewer,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $this->validPayload())
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Validation — 422 via custom envelope (error.errors)
    // -----------------------------------------------------------------------

    public function test_missing_location_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['location_id']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_id', 'error.errors');
    }

    public function test_invalid_location_id_uuid_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['location_id'] = 'not-a-uuid';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_id', 'error.errors');
    }

    public function test_empty_lines_array_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['lines'] = [];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines', 'error.errors');
    }

    public function test_missing_lines_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['lines']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines', 'error.errors');
    }

    public function test_quantity_with_five_decimals_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][0]['quantity'] = '1.12345';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity', 'error.errors');
    }

    public function test_zero_quantity_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][0]['quantity'] = '0';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity', 'error.errors');
    }

    public function test_negative_quantity_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][0]['quantity'] = '-1.0000';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity', 'error.errors');
    }

    public function test_invalid_reason_returns_422(): void
    {
        $payload = $this->validPayload();
        $payload['reason'] = 'theft';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason', 'error.errors');
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['idempotency_key']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key', 'error.errors');
    }

    // -----------------------------------------------------------------------
    // Tenant scope — cross-tenant ids rejected
    // -----------------------------------------------------------------------

    public function test_foreign_location_id_is_rejected(): void
    {
        // Create a second tenant/company with its own location.
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'OTHER-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $foreignLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'WH-OTHER-01',
            'name' => 'Other Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $payload = $this->validPayload();
        $payload['location_id'] = $foreignLocation->id;

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_id', 'error.errors');
    }

    public function test_foreign_batch_id_is_rejected(): void
    {
        // Create a second company with its own batch.
        $otherTenant = Tenant::create([
            'name' => 'Other Batch Tenant',
            'slug' => 'other-batch-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Batch Company',
            'legal_name' => 'Other Batch Company LLC',
            'tax_id' => 'OTHB-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $otherProduct = Product::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'sku' => 'OTHER-PROD-001',
            'name' => 'Other Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);

        $foreignBatch = Batch::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'batch_number' => 'BATCH-OTHER-001',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        $payload = $this->validPayload();
        $payload['lines'][0]['batch_id'] = $foreignBatch->uuid;

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.batch_id', 'error.errors');
    }

    // -----------------------------------------------------------------------
    // Happy path — 201
    // -----------------------------------------------------------------------

    public function test_valid_grouped_write_off_returns_201_with_result(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $this->validPayload('hp-key-001'));

        $response->assertCreated();

        $data = $response->json('data');
        $this->assertNotNull($data);
        $this->assertArrayHasKey('idempotency_key', $data);
        $this->assertSame('hp-key-001', $data['idempotency_key']);
        $this->assertArrayHasKey('movements', $data);
        $this->assertCount(1, $data['movements']);

        $movement = $data['movements'][0];
        $this->assertArrayHasKey('movement_id', $movement);
        $this->assertArrayHasKey('quantity', $movement);
        $this->assertSame('5.0000', $movement['quantity']);

        // Stock decremented
        $this->assertSame('45.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame('45.0000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));

        // Exactly one StockMovement created
        $this->assertSame(1, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->count());
    }

    public function test_all_three_reason_values_are_accepted(): void
    {
        // Verify both that the request succeeds and that the stored reason enum
        // matches the expected mapping (expiry→Expiry, damage→Damage, other→WriteOff).
        $expectedMappings = [
            'expiry' => MovementReason::Expiry,
            'damage' => MovementReason::Damage,
            'other' => MovementReason::WriteOff,
        ];

        foreach ($expectedMappings as $apiReason => $expectedReason) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/v1/batches/write-off-grouped', $this->validPayload("reason-key-{$apiReason}", $apiReason));

            $response->assertCreated();

            $movementId = $response->json('data.movements.0.movement_id');
            $this->assertNotNull($movementId, "No movement_id returned for reason '{$apiReason}'");

            $movement = StockMovement::query()->where('id', $movementId)->first();
            $this->assertNotNull($movement, "StockMovement {$movementId} not found for reason '{$apiReason}'");
            $this->assertSame(
                $expectedReason,
                $movement->reason,
                "Expected MovementReason::{$expectedReason->name} for API reason '{$apiReason}', got {$movement->reason?->value}",
            );
        }
    }

    // -----------------------------------------------------------------------
    // Idempotency via the endpoint
    // -----------------------------------------------------------------------

    public function test_idempotent_replay_returns_same_result_applies_stock_once(): void
    {
        $payload = $this->validPayload('idm-key-001');

        // First call — applies the write-off
        $first = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload);

        $first->assertCreated();

        $stockAfterFirst = (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity');

        // Second call — same idempotency_key: replay
        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload);

        $second->assertStatus(200);

        // Same movement id returned
        $this->assertSame(
            $first->json('data.movements.0.movement_id'),
            $second->json('data.movements.0.movement_id'),
        );

        // Stock unchanged since first call
        $stockAfterSecond = (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity');
        $this->assertSame($stockAfterFirst, $stockAfterSecond);

        // Exactly one idempotency record
        $this->assertSame(1, \DB::table('grouped_write_offs')
            ->where('idempotency_key', 'idm-key-001')
            ->count());

        // Exactly one StockMovement (not two)
        $this->assertSame(1, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->count());
    }

    // -----------------------------------------------------------------------
    // Controller parity guard
    // -----------------------------------------------------------------------

    /**
     * Two lines that share the same batch_id each pass FormRequest validation
     * independently (both reference an existing, company-owned batch UUID).
     * The controller's parity guard fires because pluck('id','uuid') collapses
     * duplicates and returns 1 entry while count($uuids) == 2 — directly
     * exercising the guard that prevents a partial resolution from silently
     * writing off fewer lots than requested.
     */
    public function test_parity_guard_rejects_duplicate_batch_ids(): void
    {
        $payload = [
            'location_id' => $this->warehouse->id,
            'lines' => [
                ['batch_id' => $this->batch->uuid, 'quantity' => '2.0000'],
                ['batch_id' => $this->batch->uuid, 'quantity' => '3.0000'],
            ],
            'reason' => 'expiry',
            'idempotency_key' => 'parity-guard-dupe-test',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/batches/write-off-grouped', $payload);

        $response->assertUnprocessable();
        $this->assertSame('BATCH_RESOLUTION_FAILED', $response->json('error.code'));

        // No stock was decremented — the guard fired before any write.
        $this->assertSame('50.0000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame(0, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->count());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $idempotencyKey = 'test-idem-key', string $reason = 'expiry'): array
    {
        return [
            'location_id' => $this->warehouse->id,
            'lines' => [
                [
                    'batch_id' => $this->batch->uuid,
                    'quantity' => '5.0000',
                ],
            ],
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
