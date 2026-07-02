<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Enums\EnrichmentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the manual enrichment re-fetch endpoint that wires the
 * ProductForm "refresh" affordance: poll the platform for the current
 * status of a submitted product and, when completed, pull the enriched
 * result into the review queue.
 */
class EnrichmentRefreshControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-enrichment-refresh',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-REFRESH-001',
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
            'name' => 'Test User',
            'email' => 'enrichment-refresh@example.com',
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

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'platform_submission_id' => 'trk-refresh-001',
            'enrichment_status' => EnrichmentStatus::Pending,
        ], $overrides));
    }

    public function test_refresh_stores_result_when_completed(): void
    {
        $product = $this->makeProduct();

        Http::fake([
            'platform.test/api/v1/products/lookup-status/*' => Http::response([
                'tracking_id' => 'trk-refresh-001',
                'status' => 'enriched',
                'enrichment_quality' => 'full',
                'enriched_data' => [
                    'name' => 'Enriched Brake Pad',
                    'description' => 'Ceramic',
                    'assigned_barcode' => '4006381333931',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/enrichment/refresh");

        $response->assertOk();
        $response->assertJsonPath('data.enrichment_status', EnrichmentStatus::Completed->value);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        $this->assertSame(1, EnrichmentResult::where('tracking_id', 'trk-refresh-001')->count());
    }

    public function test_refresh_updates_status_without_result_when_still_enriching(): void
    {
        $product = $this->makeProduct();

        Http::fake([
            'platform.test/api/v1/products/lookup-status/*' => Http::response([
                'tracking_id' => 'trk-refresh-001',
                'status' => 'enriching',
                'enriched_data' => [],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/enrichment/refresh");

        $response->assertOk();
        $response->assertJsonPath('data.enrichment_status', EnrichmentStatus::Enriching->value);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Enriching, $product->enrichment_status);
        $this->assertSame(0, EnrichmentResult::where('tracking_id', 'trk-refresh-001')->count());
    }

    public function test_refresh_returns_422_when_no_submission(): void
    {
        $product = $this->makeProduct([
            'platform_submission_id' => null,
            'enrichment_status' => null,
        ]);

        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/enrichment/refresh");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'no_pending_submission');
        Http::assertNothingSent();
    }

    public function test_refresh_returns_404_for_product_in_another_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX-REFRESH-002',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $product = $this->makeProduct(['company_id' => $otherCompany->id]);

        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/enrichment/refresh");

        $response->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_refresh_requires_authentication(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson("/api/v1/products/{$product->id}/enrichment/refresh");

        $response->assertStatus(401);
    }
}
