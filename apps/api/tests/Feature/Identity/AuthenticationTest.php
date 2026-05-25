<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
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

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Email-first login resolves the tenant via central_identities, so any
        // user created in this test class needs an index row. This test-scoped
        // hook mirrors what IdentityIndexService does for every user created
        // through the production controllers (register / invite). It is NOT a
        // production observer: post-flip the central write must run outside the
        // tenant-DB transaction, which is why production uses explicit calls.
        User::created(function (User $user): void {
            app(IdentityIndexService::class)->record($user->email, $user->tenant_id, $user->id);
        });
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
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'New Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
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
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'New Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Diff',
            'company_name' => 'New Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $this->assertApiValidationErrors($response, ['password']);
    }

    public function test_register_sets_default_currency_for_country(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'French User',
            'email' => 'french@example.com',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'French Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
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
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'Business Corp',
            'company_legal_name' => 'Business Corporation SARL',
            'country_code' => 'TN',
            'tax_id' => 'TN12345678',
            'phone' => '+21612345678',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
            'vertical' => 'retail',
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

    public function test_register_rejects_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'weak@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Test Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $this->assertApiValidationErrors($response, ['password']);
    }

    public function test_register_sets_tenant_regional_fields_from_country(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Tunisian User',
            'email' => 'tunisian-regional@example.com',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'Tunisian Cafe',
            'country_code' => 'TN',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
            'locale' => 'fr',
            'vertical' => 'coffee_shop',
        ]);

        $response->assertCreated();

        // Verify tenant top-level columns are set (not just in settings JSON)
        $this->assertDatabaseHas('tenants', [
            'name' => 'Tunisian Cafe',
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'timezone' => 'Africa/Tunis',
            'locale' => 'fr',
            'date_format' => 'd/m/Y',
        ]);
    }

    public function test_register_accepts_strong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Strong User',
            'email' => 'strong@example.com',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'Strong Company',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $response->assertCreated();
    }

    public function test_login_does_not_validate_password_strength(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'weak',
            'status' => UserStatus::Active,
        ]);

        // Login should not reject based on password strength rules
        // (it should only fail because credentials are wrong, not because of validation)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'weak',
        ]);

        // Should get past validation (422) — either 200 or credential error (422 with email key)
        // The key point: no password validation error
        $this->assertNotEquals(422, $response->status(), 'Login should not validate password strength');
    }

    public function test_forgot_password_returns_success_for_existing_email(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'MyStr0ng!Pass',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.message', 'If an account exists with that email, a password reset link has been sent.');
    }

    public function test_forgot_password_returns_success_for_nonexistent_email(): void
    {
        // Should return same response to prevent email enumeration
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.message', 'If an account exists with that email, a password reset link has been sent.');
    }

    public function test_forgot_password_requires_valid_email(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_reset_password_with_invalid_token_fails(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'MyStr0ng!Pass',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => 'test@example.com',
            'password' => 'NewStr0ng!Pass',
            'password_confirmation' => 'NewStr0ng!Pass',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_reset_password_requires_strong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $this->assertApiValidationErrors($response, ['password']);
    }

    /**
     * T1.4 — POS-tauri client gets a 12-month token instead of the default
     * 30-day lifetime. Web back-office sessions stay on the default
     * (sanctum.expiration config). Distinguished by the `X-Client-Type`
     * request header set in `apps/pos/src/lib/api.ts`.
     */
    public function test_t14_pos_client_header_issues_12_month_token(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Cashier',
            'email' => 'cashier@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $beforeLogin = now();

        // T1.4 Codex round-2 P2 — the long-token gate now requires the
        // header AND a non-empty device_id AND a desktop platform.
        // Web back-office logins fail every gate; the Tauri client
        // sends all three.
        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'cashier@example.com',
                'password' => 'password123',
                'device_id' => 'pos-tauri-device-uuid-001',
                'platform' => 'macos',
            ]);

        $response->assertOk();

        // Locate the row Sanctum just created. There should be exactly one
        // personal_access_tokens row for this user post-login; assert its
        // `expires_at` is ~ now + 12 months (within a 5-minute drift band
        // to cover slow CI machines).
        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows, 'POS login should produce exactly one Sanctum token');

        $row = $tokenRows->first();
        $this->assertNotNull($row->expires_at, 'POS-issued token must carry a non-null expires_at');

        $expectedExpiry = $beforeLogin->copy()->addYear();
        $actualExpiry = Carbon::parse($row->expires_at);
        $driftSeconds = abs($actualExpiry->diffInSeconds($expectedExpiry));

        $this->assertLessThanOrEqual(
            300,
            $driftSeconds,
            "POS-issued token must expire ~12 months out (drift: {$driftSeconds}s)",
        );
    }

    /**
     * T1.4 — without the `X-Client-Type: pos-tauri` header, the token uses
     * the default sanctum.expiration policy (30 days; tracked via NULL
     * expires_at column + per-request sanctum middleware check, NOT a
     * row-level expiry).
     */
    public function test_t14_default_login_keeps_30_day_default_no_per_token_expiry(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Web User',
            'email' => 'web@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'web@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows, 'Default login should produce exactly one Sanctum token');

        // Default behaviour: per-token expires_at is NULL — the lifetime is
        // enforced by the per-request `sanctum.expiration` config, not a
        // row-level expiry. Asserting NULL here is the regression guard
        // that prevents accidentally bumping the global default to 12mo.
        $this->assertNull(
            $tokenRows->first()->expires_at,
            'Web back-office tokens must not carry a per-row expires_at — they use the global sanctum.expiration policy.',
        );
    }

    /**
     * PR #101 follow-up to T1.4 — POS-issued tokens must carry the scoped
     * `['pos:*']` ability set, not the catch-all `['*']`. Defense-in-depth
     * on top of the triple-gate: even if the gate is spoofed, the
     * resulting token cannot pass an `auth:sanctum,*` check that requires
     * a non-`pos:*` ability.
     */
    public function test_t14_pos_client_header_issues_pos_scoped_abilities_token(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Cashier',
            'email' => 'cashier-abilities@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'cashier-abilities@example.com',
                'password' => 'password123',
                'device_id' => 'pos-tauri-device-uuid-abilities',
                'platform' => 'macos',
            ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows, 'POS login should produce exactly one Sanctum token');

        $row = $tokenRows->first();
        $abilities = json_decode((string) $row->abilities, true);

        // Tenant-isolation Invariant D (master plan §15) prepends
        // `tenant:<uuid>` to every Sanctum token so EnforceTokenTenantClaim
        // can reject tokens whose tenant has changed since issuance. The
        // POS-flavoured `pos:*` ability follows, replacing the historical
        // catch-all `*` so a spoofed long token cannot reach web back-office
        // routes.
        $this->assertSame(
            ['tenant:'.$this->tenant->id, 'pos:*'],
            $abilities,
            'POS-issued token must carry [tenant:<uuid>, pos:*] abilities.',
        );

        // Cross-check via Sanctum's PersonalAccessToken model — `tokenCan`
        // is what every consumer route would call.
        $token = PersonalAccessToken::query()->find($row->id);
        $this->assertNotNull($token);
        $this->assertTrue($token->can('pos:*'), 'POS token must satisfy pos:* ability check');
        $this->assertTrue($token->can('tenant:'.$this->tenant->id), 'POS token must satisfy the tenant-claim ability check');
        $this->assertFalse($token->can('*'), 'POS token must NOT satisfy the catch-all ability check');
    }

    /**
     * PR #101 follow-up to T1.4 — web back-office logins keep the historical
     * catch-all `['*']` abilities. Narrowing them would silently break
     * unrelated endpoints since the back-office surface has no consistent
     * ability scoping yet. This test is the regression guard.
     */
    public function test_t14_default_login_keeps_catchall_abilities_for_web_backoffice(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Web User',
            'email' => 'web-abilities@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'web-abilities@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows, 'Default login should produce exactly one Sanctum token');

        $row = $tokenRows->first();
        $abilities = json_decode((string) $row->abilities, true);

        // Tenant-isolation Invariant D (master plan §15) prepends
        // `tenant:<uuid>` to every Sanctum token. The web back-office still
        // gets the catch-all `*` ability as its second element because the
        // back-office surface has no consistent ability scoping yet —
        // narrowing it here would silently break unrelated endpoints.
        $this->assertSame(
            ['tenant:'.$this->tenant->id, '*'],
            $abilities,
            'Web back-office tokens must keep [tenant:<uuid>, *] abilities until the back-office surface adopts scoped abilities.',
        );

        $token = PersonalAccessToken::query()->find($row->id);
        $this->assertNotNull($token);
        $this->assertTrue($token->can('*'), 'Web token must satisfy the catch-all ability check');
        $this->assertTrue($token->can('pos:*'), 'Catch-all `*` ability also satisfies pos:* (Sanctum semantics)');
        $this->assertTrue($token->can('tenant:'.$this->tenant->id), 'Web token must satisfy the tenant-claim ability check');
    }

    /**
     * PR #101 follow-up — verify the same scoping applies to the registration
     * endpoint. Triple-gate satisfied at register time → POS-flavoured token
     * with `['pos:*']` abilities + 12-month expiry.
     */
    public function test_t14_register_with_pos_client_header_issues_pos_scoped_abilities(): void
    {
        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/register', [
                'name' => 'Register POS Owner',
                'email' => 'register-pos-abilities@example.com',
                'password' => 'MyStr0ng!Pass',
                'password_confirmation' => 'MyStr0ng!Pass',
                'company_name' => 'POS Register Co',
                'country_code' => 'FR',
                'vertical' => 'retail',
                'device_id' => 'pos-tauri-register-uuid',
                'platform' => 'windows',
            ]);

        $response->assertCreated();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows, 'POS register should produce exactly one Sanctum token');

        $row = $tokenRows->first();
        $abilities = json_decode((string) $row->abilities, true);

        // The register endpoint creates the User and its Tenant in the same
        // transaction; the prepended tenant claim is the freshly-created
        // tenant_id. We look it up via the personal-access-token row's
        // tokenable_id rather than fixed expectation so the test stays
        // resilient to the random uuid the factory mints.
        $newTenantId = User::find($row->tokenable_id)?->tenant_id;
        $this->assertNotNull($newTenantId);
        $this->assertSame(['tenant:'.$newTenantId, 'pos:*'], $abilities);

        $this->assertNotNull(
            $row->expires_at,
            'POS-issued register token must also carry the 12-month expires_at (T1.4 wiring intact).',
        );
    }

    /**
     * T1.4 Codex round-1 P1 — close global-TTL bypass.
     *
     * Sanctum's default Guard::isValidAccessToken ANDs the global
     * SANCTUM_TOKEN_EXPIRATION with the per-token `expires_at`, so a
     * POS token issued with `expires_at = now()->addYear()` would
     * still be rejected by the global 30-day TTL. The
     * AppServiceProvider boot() registers a
     * `Sanctum::authenticateAccessTokensUsing` callback that lets the
     * per-token `expires_at` win for POS-issued tokens.
     *
     * These tests exercise the callback directly via Sanctum's
     * `$accessTokenAuthenticationCallback` static property — the
     * cleanest verification path because it bypasses the
     * stateful-vs-stateless guard ambiguity that the test client's
     * session preservation introduces.
     */
    public function test_t14_callback_overrides_global_ttl_when_per_token_expiry_is_future(): void
    {
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertNotNull($callback, 'AppServiceProvider must register the Sanctum callback');

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Cashier',
            'email' => 'cashier@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        // Simulate a token whose created_at is past the global TTL
        // (so default $isValid is false) but whose per-row expires_at
        // is still in the future. Without the callback this token
        // would be rejected; with it, the override should accept.
        $token = new PersonalAccessToken;
        $token->expires_at = now()->addYear();
        $token->setRelation('tokenable', $user);

        $result = $callback($token, false /* default $isValid (failed global TTL) */);

        $this->assertTrue($result, 'Callback must override global TTL when per-token expires_at is future.');
    }

    public function test_t14_callback_rejects_token_with_past_per_token_expiry(): void
    {
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertNotNull($callback);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Cashier',
            'email' => 'cashier@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $token = new PersonalAccessToken;
        $token->expires_at = now()->subHour();
        $token->setRelation('tokenable', $user);

        // Even if the default $isValid was true (e.g. recent created_at),
        // a past per-token expires_at must reject.
        $result = $callback($token, true);

        $this->assertFalse($result, 'Callback must reject when per-token expires_at is in the past.');
    }

    public function test_t14_callback_falls_through_to_default_when_no_per_token_expiry(): void
    {
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertNotNull($callback);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Web User',
            'email' => 'web@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $token = new PersonalAccessToken;
        $token->expires_at = null;
        $token->setRelation('tokenable', $user);

        // No per-row expiry → callback must defer to the default
        // $isValid (which encodes the global TTL + provider checks).
        // Pass true → callback returns true; pass false → returns false.
        $this->assertTrue($callback($token, true));
        $this->assertFalse($callback($token, false));
    }

    public function test_t14_callback_rejects_token_with_no_tokenable_even_if_expiry_future(): void
    {
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertNotNull($callback);

        $token = new PersonalAccessToken;
        $token->expires_at = now()->addYear();
        $token->setRelation('tokenable', null);

        // Provider check defends against orphaned tokens (user deleted).
        $result = $callback($token, true);

        $this->assertFalse($result, 'Callback must reject orphaned tokens (no tokenable) even with future expiry.');
    }

    /**
     * T1.4 Codex round-2 P2 — POS header WITHOUT device_id falls
     * through. A scripted attacker who learns of the header but not
     * the device-pairing requirement cannot opt into the longer
     * lifetime by header alone.
     */
    public function test_t14_pos_header_without_device_id_falls_through_to_default(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Header Only',
            'email' => 'headeronly@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'headeronly@example.com',
                'password' => 'password123',
                'platform' => 'macos',
                // device_id deliberately omitted
            ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows);
        $this->assertNull(
            $tokenRows->first()->expires_at,
            'POS header without device_id must not trigger the 12-month branch.',
        );
    }

    /**
     * T1.4 Codex round-2 P2 — POS header + device_id but platform=web
     * (browser) falls through. Tauri runs on desktop only.
     */
    public function test_t14_pos_header_with_browser_platform_falls_through(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Browser User',
            'email' => 'browser@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'browser@example.com',
                'password' => 'password123',
                'device_id' => 'browser-spoof-attempt-001',
                'platform' => 'web',
            ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows);
        $this->assertNull(
            $tokenRows->first()->expires_at,
            'POS header from a browser platform (Tauri runs on desktop only) must not trigger the 12-month branch.',
        );
    }

    /**
     * T1.4 Codex round-2 P2 — POS header + device_id + mobile platform
     * (ios/android) falls through. Tauri's desktop runtime targets are
     * windows/macos/linux only.
     */
    public function test_t14_pos_header_with_mobile_platform_falls_through(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Mobile User',
            'email' => 'mobile@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'mobile@example.com',
                'password' => 'password123',
                'device_id' => 'mobile-device-001',
                'platform' => 'ios',
            ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows);
        $this->assertNull(
            $tokenRows->first()->expires_at,
            'POS header from a mobile platform (ios/android) must not trigger the 12-month branch — Tauri desktop targets only.',
        );
    }

    /**
     * T1.4 Codex round-2 P2 — windows + linux are also valid Tauri
     * desktop targets. Pin both branches so a future refactor can't
     * accidentally drop one.
     */
    public function test_t14_pos_long_token_works_for_windows_and_linux_platforms(): void
    {
        foreach (['windows', 'linux'] as $platform) {
            \DB::table('personal_access_tokens')->delete();

            User::where('email', "platform-{$platform}@example.com")->delete();
            User::create([
                'tenant_id' => $this->tenant->id,
                'name' => "Platform {$platform}",
                'email' => "platform-{$platform}@example.com",
                'password' => 'password123',
                'status' => UserStatus::Active,
            ]);

            $response = $this->withHeaders(['X-Client-Type' => 'pos-tauri'])
                ->postJson('/api/v1/auth/login', [
                    'email' => "platform-{$platform}@example.com",
                    'password' => 'password123',
                    'device_id' => "device-{$platform}",
                    'platform' => $platform,
                ]);

            $response->assertOk();

            $tokenRow = \DB::table('personal_access_tokens')->first();
            $this->assertNotNull(
                $tokenRow->expires_at,
                "Platform {$platform} (Tauri desktop target) must trigger the 12-month branch.",
            );
        }
    }

    /**
     * T1.4 — a POS-client header on a non-canonical value should NOT
     * trigger the 12-month branch. The branch is keyed on the exact
     * literal `pos-tauri`; anything else falls through to the default.
     * Defends against typos / spoofing bumping the wrong branch.
     */
    public function test_t14_unknown_client_type_falls_through_to_default(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unknown Client',
            'email' => 'unknown@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Client-Type' => 'pos-android'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'password123',
            ]);

        $response->assertOk();

        $tokenRows = \DB::table('personal_access_tokens')->get();
        $this->assertCount(1, $tokenRows);
        $this->assertNull(
            $tokenRows->first()->expires_at,
            'Only the literal X-Client-Type=pos-tauri triggers the 12-month branch.',
        );
    }
}
