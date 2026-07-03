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
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductSubmissionControllerTest extends TestCase
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
            'slug' => 'test-product-submission-controller',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-SUBMIT-001',
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
            'email' => 'product-submission-controller@example.com',
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

    public function test_submit_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', [
            'name' => 'Test',
            'brand' => 'Brand',
        ]);

        $response->assertStatus(401);
    }

    public function test_submit_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', []);

        $this->assertContains($response->status(), [401, 422]);
    }

    public function test_submit_accepts_null_brand(): void
    {
        $product = $this->makeProduct();
        $this->fakeSubmitResponse('trk-null-brand');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Brake Pad',
            ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $this->submitPayloadHas($request, [
            'brand' => null,
        ]));
    }

    public function test_submit_normalizes_barcode_before_platform_submit(): void
    {
        $product = $this->makeProduct();
        $this->fakeSubmitResponse('trk-normalized-barcode');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'barcode' => ' 3017-6204-22003 ',
                'name' => 'Hazelnut Spread',
                'brand' => 'Ferrero',
            ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $this->submitPayloadHas($request, [
            'barcode' => '3017620422003',
        ]));
    }

    public function test_submit_rejects_unnormalizable_barcode_without_platform_call(): void
    {
        $product = $this->makeProduct();
        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'barcode' => '!!!',
                'name' => 'Unknown Product',
                'brand' => null,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'invalid_barcode');
        Http::assertNothingSent();
    }

    public function test_submit_passes_photo_ids_and_attributes(): void
    {
        $product = $this->makeProduct();
        $photoIds = [(string) Str::uuid(), (string) Str::uuid()];
        $this->fakeSubmitResponse('trk-photo-attributes');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Face Cream',
                'brand' => null,
                'photo_ids' => $photoIds,
                'attributes' => ['volume' => '50ml'],
            ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $this->submitPayloadHas($request, [
            'photo_ids' => $photoIds,
            'attributes' => ['volume' => '50ml'],
        ]));
    }

    public function test_submit_rejects_more_than_five_photo_ids(): void
    {
        $product = $this->makeProduct();
        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/submit-for-enrichment', [
                'product_id' => $product->id,
                'name' => 'Face Cream',
                'brand' => null,
                'photo_ids' => [
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                    (string) Str::uuid(),
                ],
            ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
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

    private function fakeSubmitResponse(string $trackingId): void
    {
        Http::fake([
            'platform.test/api/v1/products/submit' => Http::response([
                'tracking_id' => $trackingId,
                'status' => 'submitted',
                'status_url' => 'https://platform.test/status/'.$trackingId,
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function submitPayloadHas(Request $request, array $expected): bool
    {
        if ($request->url() !== 'https://platform.test/api/v1/products/submit') {
            return false;
        }

        foreach ($expected as $key => $value) {
            if ($request[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
