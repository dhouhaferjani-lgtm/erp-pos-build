<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/sync/pull
 *
 * Tests bulk data pull for offline POS terminal cache.
 */
final class SyncPullTest extends TestCase
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

    public function test_pull_returns_all_reference_data(): void
    {
        // Create some products
        Product::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/pos/sync/pull');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'products',
                'categories',
                'payment_methods',
                'payment_repositories',
                'terminals',
                'synced_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertCount(3, $data['products']);
        $this->assertGreaterThanOrEqual(1, count($data['terminals']));
        $this->assertNotNull($data['synced_at']);
    }

    public function test_pull_with_updated_since_returns_delta(): void
    {
        // Create old product
        $oldProduct = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Create new product
        $newProduct = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $updatedSince = urlencode(now()->subDays(2)->toIso8601String());
        $response = $this->getJson("/api/v1/pos/sync/pull?updated_since={$updatedSince}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should only return the new product
        $this->assertCount(1, $data['products']);
    }

    public function test_pull_returns_etag_header(): void
    {
        $response = $this->getJson('/api/v1/pos/sync/pull');

        $response->assertStatus(200);
        $response->assertHeader('ETag');
    }

    public function test_pull_returns_304_when_etag_matches(): void
    {
        // First request to get ETag
        $response = $this->getJson('/api/v1/pos/sync/pull');
        $etag = $response->headers->get('ETag');

        // Second request with matching ETag
        $response = $this->getJson('/api/v1/pos/sync/pull', [
            'If-None-Match' => $etag,
        ]);

        $response->assertStatus(304);
    }

    public function test_pull_terminal_config_includes_hash_chain_state(): void
    {
        $response = $this->getJson('/api/v1/pos/sync/pull');

        $response->assertStatus(200);
        $terminals = $response->json('data.terminals');

        $this->assertNotEmpty($terminals);
        $terminalData = $terminals[0];
        $this->assertArrayHasKey('genesis_seed', $terminalData);
        $this->assertArrayHasKey('last_hash', $terminalData);
        $this->assertArrayHasKey('current_sequence', $terminalData);
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
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }
}
