<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PhotoUploadUrlTest extends TestCase
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
            'slug' => 'test-photo-upload-url',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-PHOTO-001',
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
            'email' => 'photo-upload-url@example.com',
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

    public function test_upload_url_relays_platform_response(): void
    {
        Http::fake([
            'platform.test/api/v1/products/upload-url' => Http::response([
                'photo_id' => 'ph_123',
                'upload_url' => 'https://uploads.test/ph_123',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/upload-url', $this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('data.photo_id', 'ph_123');
        $response->assertJsonPath('data.upload_url', 'https://uploads.test/ph_123');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/products/upload-url'
            && $request['filename'] === 'front.jpg'
            && $request['content_type'] === 'image/jpeg'
            && $request['size_bytes'] === 5242880);
    }

    public function test_upload_url_rejects_files_over_five_mb_before_proxying(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/upload-url', $this->validPayload([
                'size_bytes' => 5242881,
            ]));

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_upload_url_rejects_non_image_content_type_before_proxying(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/upload-url', $this->validPayload([
                'content_type' => 'application/pdf',
            ]));

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_upload_url_returns_502_when_platform_returns_null(): void
    {
        Http::fake([
            'platform.test/api/v1/products/upload-url' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/platform/upload-url', $this->validPayload());

        $response->assertStatus(502);
        $response->assertJsonPath('error.code', 'platform_unavailable');
    }

    public function test_upload_url_requires_enrichment_submit_permission(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission',
            'email' => 'photo-upload-url-denied@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Viewer,
        ]);

        Http::fake();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/platform/upload-url', $this->validPayload());

        $response->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'filename' => 'front.jpg',
            'content_type' => 'image/jpeg',
            'size_bytes' => 5242880,
        ], $overrides);
    }
}
