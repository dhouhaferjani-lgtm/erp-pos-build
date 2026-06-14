<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/variants
 *
 * Company-scoped variant catalog feed for the offline POS device sync.
 * No terminal_id required — variant catalog is company-wide.
 */
final class PosVariantFeedEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function actAsUser(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function makeActiveVariant(array $overrides = []): ProductVariant
    {
        return ProductVariant::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
            'barcode' => null,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_company_scoped_snapshot_no_terminal_required(): void
    {
        $this->actAsUser();

        // Active variant for this company — should appear.
        $this->makeActiveVariant();

        // Inactive variant for this company — must not appear.
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => false,
        ]);

        // Active variant for a different company in the same tenant — must not appear.
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/pos/variants')
            ->assertOk()
            ->assertJsonPath('data.deleted_ids', [])
            ->assertJsonCount(1, 'data.variants'); // only the active same-company variant
    }

    public function test_response_is_slim_no_tenant_company_cost_leak(): void
    {
        $this->actAsUser();

        // Need at least one active variant with an explicit barcode.
        $this->makeActiveVariant(['barcode' => 'EAN-1234567890']);

        $resp = $this->getJson('/api/v1/pos/variants')->assertOk()->json('data.variants.0');

        $this->assertArrayNotHasKey('tenant_id', $resp);
        $this->assertArrayNotHasKey('company_id', $resp);
        $this->assertArrayNotHasKey('cost_override', $resp);
        $this->assertArrayHasKey('barcode', $resp);
        $this->assertArrayHasKey('price_override', $resp);
    }

    public function test_delta_returns_tombstones_for_deactivated_and_softdeleted(): void
    {
        $this->actAsUser();

        $cursor = '2026-06-01T12:00:00+00:00';

        // Ensure at least one active, current variant exists so the feed is non-empty.
        $active = $this->makeActiveVariant();
        DB::table('product_variants')->where('id', $active->id)->update(['updated_at' => '2026-06-02 00:00:00']);

        // Deactivated AFTER the cursor → tombstone.
        $deactivated = $this->makeActiveVariant(['is_active' => false]);
        DB::table('product_variants')->where('id', $deactivated->id)->update(['updated_at' => '2026-06-02 00:00:00']);

        // Soft-deleted AFTER the cursor → tombstone.
        $softDeleted = $this->makeActiveVariant();
        $softDeleted->delete();
        DB::table('product_variants')->where('id', $softDeleted->id)->update([
            'updated_at' => '2026-06-02 00:00:00',
            'deleted_at' => '2026-06-02 00:00:00',
        ]);

        $resp = $this->getJson('/api/v1/pos/variants?updated_since='.urlencode($cursor))
            ->assertOk();

        $deletedIds = $resp->json('data.deleted_ids');
        $this->assertContains($deactivated->id, $deletedIds);
        $this->assertContains($softDeleted->id, $deletedIds);
        // The active (non-tombstoned) variant must NOT appear in deleted_ids.
        $this->assertNotContains($active->id, $deletedIds);
    }

    public function test_does_not_require_or_accept_terminal_id(): void
    {
        $this->actAsUser();

        // One active variant so the response has content.
        $this->makeActiveVariant();

        // Succeeds with NO terminal_id param (unlike /pos/stock-levels).
        $this->getJson('/api/v1/pos/variants')->assertOk();
    }

    public function test_requires_pos_operate_terminal_permission(): void
    {
        // A user WITHOUT the permission.
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($noPermUser);

        $this->getJson('/api/v1/pos/variants')->assertStatus(403);
    }

    public function test_unauthenticated_is_401(): void
    {
        // No Sanctum::actingAs() call.
        $this->getJson('/api/v1/pos/variants')->assertStatus(401);
    }

    public function test_invalid_updated_since_is_422(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pos/variants?updated_since=not-a-date')->assertStatus(422);
    }

    public function test_response_includes_pagination_meta_and_as_of(): void
    {
        $this->actAsUser();
        $this->makeActiveVariant();

        $resp = $this->getJson('/api/v1/pos/variants')->assertOk();

        $resp->assertJsonStructure([
            'data' => ['variants', 'deleted_ids', 'as_of'],
            'meta' => ['pagination' => ['current_page', 'last_page', 'total']],
        ]);

        $asOf = $resp->json('data.as_of');
        $this->assertNotNull($asOf);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $asOf);
    }
}
