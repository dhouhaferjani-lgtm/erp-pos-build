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
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EnrichmentReviewControllerTest extends TestCase
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
            'slug' => 'test-enrichment-ctrl',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'email' => 'enrichment-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/enrichment-results');

        $response->assertStatus(401);
    }

    public function test_index_serializes_catalog_result_with_null_tracking_id(): void
    {
        $this->user->assignRole('admin');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => null,
            'status' => EnrichmentReviewStatus::Accepted,
            'enriched_data' => new EnrichedProductData(
                name: 'Catalog Cream',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 95,
                enrichment_tier: 'catalog',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: $product->barcode,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'catalog',
            'reviewed_at' => now(),
            'reviewed_by' => null,
            'accepted_fields' => ['name' => true],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/enrichment-results');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $enrichmentResult->id);
        $response->assertJsonPath('data.0.tracking_id', null);
    }

    public function test_index_filters_by_product_id(): void
    {
        $this->user->assignRole('admin');

        $firstProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'First Product',
        ]);
        $secondProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Second Product',
        ]);

        $firstResult = $this->makePendingResult($firstProduct, (string) Str::uuid());
        $this->makePendingResult($secondProduct, (string) Str::uuid());

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/enrichment-results?product_id='.$firstProduct->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $firstResult->id);
        $response->assertJsonPath('data.0.product_id', $firstProduct->id);
    }

    public function test_index_rejects_invalid_product_id_filter(): void
    {
        $this->user->assignRole('admin');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/enrichment-results?product_id=not-a-uuid');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_accept_requires_enrichment_review_permission(): void
    {
        // Assign a role without enrichment.review permission
        $this->user->assignRole('cashier');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-perm-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: null,
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'partial',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/enrichment-results/{$enrichmentResult->id}/accept", [
                'accepted_fields' => ['name'],
            ]);

        $response->assertStatus(403);
    }

    public function test_reject_validates_reason_length(): void
    {
        $this->user->assignRole('admin');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-validate-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: null,
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'partial',
        ]);

        // With a reason exceeding max:1000 chars, expect 422 validation error
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/enrichment-results/{$enrichmentResult->id}/reject", [
                'reason' => str_repeat('A', 1001),
            ]);

        // The app uses a custom validation error format: error.errors.{field}
        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('reason', $response->json('error.errors'));
    }

    public function test_reject_with_valid_reason_succeeds(): void
    {
        $this->user->assignRole('admin');

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-valid-reject-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: null,
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'partial',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/enrichment-results/{$enrichmentResult->id}/reject", [
                'reason' => 'Quality too low for our needs',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Enrichment result rejected.');
    }

    private function makePendingResult(Product $product, string $trackingId): EnrichmentResult
    {
        return EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: null,
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'partial',
        ]);
    }
}
