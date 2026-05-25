<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class EmailFirstLoginTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    public function test_single_tenant_email_logs_in_without_a_tenant_id(): void
    {
        $this->makeIndexedUser('solo', 'solo@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'solo@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tokenType', 'Bearer')
            ->assertJsonPath('data.user.email', 'solo@example.com');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_stamps_tenant_id_into_the_session(): void
    {
        [$tenant] = $this->makeIndexedUser('sess', 'sess@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'sess@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk();
        $response->assertSessionHas('tenant_id', $tenant->id);
    }

    public function test_unknown_email_returns_generic_no_organizations(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password1!',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_wrong_password_is_generic(): void
    {
        $this->makeIndexedUser('wp', 'wp@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wp@example.com',
            'password' => 'WrongPassword9!',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_multi_tenant_email_returns_the_org_picker(): void
    {
        $this->makeIndexedUser('orga', 'multi@example.com');
        $this->makeIndexedUser('orgb', 'multi@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'multi@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_org_selection', true)
            ->assertJsonCount(2, 'data.organizations');
        // No token issued at the picker step.
        $this->assertNull($response->json('data.token'));
    }

    public function test_multi_tenant_login_with_explicit_tenant_id_binds_that_org(): void
    {
        [$tenantA] = $this->makeIndexedUser('pick-a', 'multi@example.com');
        $this->makeIndexedUser('pick-b', 'multi@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'multi@example.com',
            'password' => 'Password1!',
            'tenant_id' => $tenantA->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'multi@example.com');
        $this->assertNotEmpty($response->json('data.token'));
        $response->assertSessionHas('tenant_id', $tenantA->id);
    }

    public function test_explicit_unknown_tenant_id_is_generic(): void
    {
        $this->makeIndexedUser('known', 'known@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'Password1!',
            'tenant_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_suspended_organization_returns_403_after_valid_credentials(): void
    {
        [$tenant] = $this->makeIndexedUser('susp', 'susp@example.com');
        $tenant->update(['status' => TenantStatus::Suspended]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'susp@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ORGANIZATION_UNAVAILABLE');
    }

    /**
     * Create a tenant + active user + central_identities row.
     *
     * @return array{0: Tenant, 1: User}
     */
    private function makeIndexedUser(string $slug, string $email): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug).' Org',
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User '.$slug,
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        app(IdentityIndexService::class)->record($email, $tenant->id, $user->id);

        return [$tenant, $user];
    }
}
