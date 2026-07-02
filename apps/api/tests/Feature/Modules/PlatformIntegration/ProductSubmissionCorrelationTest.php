<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
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
 * Covers the correlation write that closes the enrichment round-trip:
 * the submit-for-enrichment endpoint must persist the platform tracking id
 * onto the product (platform_submission_id + enrichment_status = pending)
 * so inbound webhooks / polling can correlate the enriched result back.
 */
class ProductSubmissionCorrelationTest extends TestCase
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
            'slug' => 'test-enrichment-correlation',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-CORR-001',
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
            'email' => 'enrichment-correlation@example.com',
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
            'platform_submission_id' => null,
            'enrichment_status' => null,
        ], $overrides));
    }

    public function test_submit_persists_tracking_id_and_pending_status(): void
    {
        $product = $this->makeProduct();

        Http::fake([
            'platform.test/api/v1/products/submit' => Http::response([
                'tracking_id' => 'trk-corr-001',
                'status' => 'submitted',
                'status_url' => 'https://platform.test/status/trk-corr-001',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.trackingId', 'trk-corr-001');

        $product->refresh();
        $this->assertSame('trk-corr-001', $product->platform_submission_id);
        $this->assertSame(EnrichmentStatus::Pending, $product->enrichment_status);
    }

    public function test_submit_rejects_when_product_already_pending(): void
    {
        $product = $this->makeProduct([
            'platform_submission_id' => 'trk-existing-001',
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        Http::fake([
            'platform.test/api/v1/products/submit' => Http::response([
                'tracking_id' => 'trk-should-not-be-used',
                'status' => 'submitted',
                'status_url' => 'https://platform.test/status/x',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'enrichment_already_pending');

        // Platform must NOT be called and the existing tracking id preserved.
        Http::assertNothingSent();
        $product->refresh();
        $this->assertSame('trk-existing-001', $product->platform_submission_id);
    }

    public function test_submit_returns_404_for_unknown_product(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => '00000000-0000-4000-8000-000000000000',
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'product_not_found');
        Http::assertNothingSent();
    }

    public function test_submit_returns_404_for_product_in_another_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX-CORR-002',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $product = $this->makeProduct(['company_id' => $otherCompany->id]);

        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_submit_requires_product_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertStatus(422);
    }

    public function test_submit_does_not_write_when_platform_unavailable(): void
    {
        $product = $this->makeProduct();

        // The platform HTTP client returns null on a 404, which the
        // controller maps to a 502 platform_unavailable response. The key
        // invariant: a failed platform call must NOT write the correlation.
        Http::fake([
            'platform.test/api/v1/products/submit' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ]);

        $response->assertStatus(502);
        $product->refresh();
        $this->assertNull($product->platform_submission_id);
        $this->assertNull($product->enrichment_status);
    }

    public function test_webhook_correlates_to_product_after_submit(): void
    {
        $product = $this->makeProduct();

        Http::fake([
            'platform.test/api/v1/products/submit' => Http::response([
                'tracking_id' => 'trk-roundtrip-001',
                'status' => 'submitted',
                'status_url' => 'https://platform.test/status/trk-roundtrip-001',
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
                'brand' => 'Bosch',
            ])->assertOk();

        // An inbound webhook (status change, not yet completed) now finds
        // the product by tracking id and updates enrichment_status.
        EnrichmentWebhookReceived::dispatch(
            'trk-roundtrip-001',
            'enriching',
            null,
            false,
            'automotive',
        );

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Enriching, $product->enrichment_status);
    }
}
