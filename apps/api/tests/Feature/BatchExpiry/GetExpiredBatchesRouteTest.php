<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Enums\Vertical;
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
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TDD — Task B1: GET /api/v1/batches/expired route.
 *
 * Models the auth setup pattern of BatchExpiryModuleAccessControlTest
 * (Pharmacy vertical + admin user) and the assertion pattern of the
 * sibling `expiring` tests.
 */
class GetExpiredBatchesRouteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Expired Route Test Tenant',
            'slug' => 'expired-route-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Route Test Company',
            'legal_name' => 'Expired Route Test Company LLC',
            'tax_id' => 'TAXEXPIREDROUTE',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Route Test User',
            'email' => 'user@expired-route-test.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth / module guard
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/batches/expired')
            ->assertUnauthorized();
    }

    public function test_non_batch_expiry_vertical_returns_403(): void
    {
        // Retail does NOT have the BatchExpiry module — must be blocked.
        $retailTenant = Tenant::create([
            'name' => 'Retail Tenant Expired',
            'slug' => 'retail-expired',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $retailCompany = Company::create([
            'tenant_id' => $retailTenant->id,
            'name' => 'Retail Company Expired',
            'legal_name' => 'Retail Company Expired LLC',
            'tax_id' => 'TAXRETAILEXP',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($retailTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $retailUser = User::create([
            'tenant_id' => $retailTenant->id,
            'name' => 'Retail User Expired',
            'email' => 'user@retail-expired.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $retailUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $retailUser->id,
            'company_id' => $retailCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($retailCompany->id);

        $this->actingAs($retailUser, 'sanctum')
            ->getJson('/api/v1/batches/expired')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Happy-path: returns expired lots with available stock
    // -------------------------------------------------------------------------

    public function test_returns_expired_lots_with_available_stock(): void
    {
        // Expired batch with available stock — must appear
        $expiredBatch = $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-01',
            quantity: '5.0000',
            reservedQuantity: '0.0000',
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'batch_number',
                        'expiry_date',
                        'expiry_status',
                        'batch_stock' => [
                            '*' => [
                                'location_id',
                                'quantity',
                                'reserved_quantity',
                                'available_quantity',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($expiredBatch->uuid, $response->json('data.0.uuid'));
    }

    public function test_excludes_fully_reserved_expired_lots(): void
    {
        // Expired batch — fully reserved (available = 0) → must be excluded
        $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-RESERVED',
            quantity: '5.0000',
            reservedQuantity: '5.0000',
        );

        // Expired batch with available stock → must appear
        $availableBatch = $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-AVAIL',
            quantity: '3.0000',
            reservedQuantity: '0.0000',
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($availableBatch->uuid, $response->json('data.0.uuid'));
    }

    public function test_location_id_filter_scopes_results(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);

        // Expired lot at the main location
        $batchAtMain = $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-LOC-MAIN',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $this->location->id,
        );

        // Expired lot at another location — should be filtered out
        $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-LOC-OTHER',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $otherLocation->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired?location_id='.$this->location->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($batchAtMain->uuid, $response->json('data.0.uuid'));
    }

    public function test_location_ids_filter_scopes_results_to_multiple_locations(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);

        $batchAtMain = $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-LOC-LIST-MAIN',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $this->location->id,
        );
        $batchAtOther = $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-LOC-LIST-OTHER',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $otherLocation->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            '/api/v1/batches/expired?location_ids[]='.$this->location->id.'&location_ids[]='.$otherLocation->id,
        );

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$batchAtMain->uuid, $batchAtOther->uuid],
            array_column($response->json('data') ?? [], 'uuid'),
        );
    }

    public function test_location_ids_outside_membership_scope_are_forbidden(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$this->location->id]]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired?location_ids[]='.$otherLocation->id)
            ->assertForbidden();
    }

    public function test_returns_empty_when_no_expired_lots_with_available_stock(): void
    {
        // No batches at all → empty data array
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired');

        $response->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_batch_stock_includes_reserved_quantity_field(): void
    {
        $this->createExpiredBatch(
            batchNumber: 'EXP-ROUTE-FIELDS',
            quantity: '8.0000',
            reservedQuantity: '3.0000', // available = 5
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/batches/expired');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $stockEntry = $response->json('data.0.batch_stock.0');
        $this->assertNotNull($stockEntry);
        $this->assertArrayHasKey('reserved_quantity', $stockEntry);
        $this->assertEquals('3.0000', $stockEntry['reserved_quantity']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createExpiredBatch(
        string $batchNumber,
        string $quantity,
        string $reservedQuantity,
        ?string $locationId = null,
    ): Batch {
        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->subDays(5),
            'is_active' => true,
            'is_expired' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $locationId ?? $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => $reservedQuantity,
        ]);

        return $batch;
    }
}
