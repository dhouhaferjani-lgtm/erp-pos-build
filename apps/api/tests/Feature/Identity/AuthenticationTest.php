<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class AuthenticationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'tenantId',
                        'name',
                        'email',
                        'status',
                        'roles',
                        'permissions',
                    ],
                    'token',
                    'tokenType',
                ],
                'meta' => [
                    'timestamp',
                    'request_id',
                ],
            ]);

        $this->assertEquals($user->id, $response->json('data.user.id'));
        $this->assertEquals('Bearer', $response->json('data.tokenType'));
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'status' => UserStatus::Inactive,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
    }

    public function test_user_can_logout(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        // First login to get a token
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Then logout
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('data.message', 'Successfully logged out');

        // Verify token was deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertApiValidationErrors($response, ['email', 'password']);
    }

    public function test_login_validates_email_format(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_login_with_device_info_creates_device_record(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'iPhone 15 Pro',
            'device_id' => 'unique-device-id-123',
            'platform' => 'ios',
            'platform_version' => '17.0',
            'app_version' => '1.0.0',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('devices', [
            'user_id' => $user->id,
            'name' => 'iPhone 15 Pro',
            'device_id' => 'unique-device-id-123',
            'platform' => 'ios',
            'platform_version' => '17.0',
            'app_version' => '1.0.0',
            'type' => 'mobile',
        ]);
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'New Company',
            'country_code' => 'FR',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'tenantId',
                        'name',
                        'email',
                        'status',
                    ],
                    'token',
                    'tokenType',
                ],
                'meta' => [
                    'timestamp',
                    'request_id',
                ],
            ]);

        // Verify tenant was created
        $this->assertDatabaseHas('tenants', [
            'name' => 'New Company',
        ]);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        // Verify company was created
        $this->assertDatabaseHas('companies', [
            'name' => 'New Company',
            'country_code' => 'FR',
        ]);

        // Verify user company membership was created
        $this->assertDatabaseHas('user_company_memberships', [
            'role' => 'owner',
            'is_primary' => true,
        ]);
    }

    public function test_register_requires_all_mandatory_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $this->assertApiValidationErrors($response, ['name', 'email', 'password', 'company_name', 'country_code']);
    }

    public function test_register_requires_unique_email(): void
    {
        // Create existing user
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'New Company',
            'country_code' => 'FR',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
            'company_name' => 'New Company',
            'country_code' => 'FR',
        ]);

        $this->assertApiValidationErrors($response, ['password']);
    }

    public function test_register_sets_default_currency_for_country(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'French User',
            'email' => 'french@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'French Company',
            'country_code' => 'FR',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('companies', [
            'name' => 'French Company',
            'currency' => 'EUR',
            'locale' => 'fr',
        ]);
    }

    public function test_register_with_optional_company_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Business User',
            'email' => 'business@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Business Corp',
            'company_legal_name' => 'Business Corporation SARL',
            'country_code' => 'TN',
            'tax_id' => 'TN12345678',
            'phone' => '+21612345678',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('companies', [
            'name' => 'Business Corp',
            'legal_name' => 'Business Corporation SARL',
            'country_code' => 'TN',
            'tax_id' => 'TN12345678',
            'phone' => '+21612345678',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
        ]);
    }
}
