<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Application\Services\TenantLinkSigner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * P1-1 (Codex 2026-05-25): password-reset redemption MUST be tenant-bound.
 *
 * `forgotPassword()` issues a per-tenant, tenant-qualified link, but in Phase 0a
 * `password_reset_tokens` is shared and email-keyed. If redemption ignores the
 * signed `tenant` qualifier and uses Laravel's email-only broker, a multi-tenant
 * email can have whichever `users` row the default provider returns first updated
 * — a live wrong-tenant password reset. These tests pin the tenant-bound flow.
 */
class TenantBoundPasswordResetTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    public function test_reset_updates_only_the_targeted_tenants_user(): void
    {
        [$tenantA, $userA] = $this->makeIndexedUser('reset-a', 'multi@example.com');
        [$tenantB, $userB] = $this->makeIndexedUser('reset-b', 'multi@example.com');

        // A token is created for the tenant-A user. With a shared, email-keyed
        // token table this is the live token for the email.
        $token = Password::createToken($userA);

        $signedTenantA = app(TenantLinkSigner::class)->sign($tenantA->id);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'multi@example.com',
            'password' => 'BrandNew9!Pass',
            'password_confirmation' => 'BrandNew9!Pass',
            'tenant' => $signedTenantA,
        ]);

        $response->assertOk();

        // ONLY tenant A's user must have the new password; tenant B is untouched.
        $this->assertTrue(
            Hash::check('BrandNew9!Pass', $userA->fresh()->password),
            'Targeted tenant user password must be updated.'
        );
        $this->assertFalse(
            Hash::check('BrandNew9!Pass', $userB->fresh()->password),
            'Non-targeted tenant user password must NOT change (no wrong-tenant reset).'
        );
    }

    public function test_missing_tenant_qualifier_is_rejected(): void
    {
        [$tenant, $user] = $this->makeIndexedUser('reset-noqual', 'noqual@example.com');
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'noqual@example.com',
            'password' => 'BrandNew9!Pass',
            'password_confirmation' => 'BrandNew9!Pass',
        ]);

        $this->assertApiValidationErrors($response, ['tenant']);
        $this->assertFalse(
            Hash::check('BrandNew9!Pass', $user->fresh()->password),
            'A reset without a tenant qualifier must not change any password.'
        );
    }

    public function test_undecryptable_tenant_qualifier_is_rejected(): void
    {
        [$tenant, $user] = $this->makeIndexedUser('reset-bad', 'bad@example.com');
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'bad@example.com',
            'password' => 'BrandNew9!Pass',
            'password_confirmation' => 'BrandNew9!Pass',
            'tenant' => 'not-a-valid-encrypted-blob',
        ]);

        $this->assertApiValidationErrors($response, ['tenant']);
        $this->assertFalse(Hash::check('BrandNew9!Pass', $user->fresh()->password));
    }

    public function test_reset_is_confined_to_the_tenant_named_in_the_link(): void
    {
        // The reset always stays inside the tenant the LINK was qualified for —
        // even when the same email exists in another tenant. Here a tenant-A link
        // resets tenant A's user and never touches tenant B's identically-emailed
        // user. This is the core wrong-tenant-reset guarantee from P1-1.
        [$tenantA, $userA] = $this->makeIndexedUser('reset-confa', 'conf@example.com');
        [$tenantB, $userB] = $this->makeIndexedUser('reset-confb', 'conf@example.com');

        $token = Password::createToken($userA);
        $signedTenantA = app(TenantLinkSigner::class)->sign($tenantA->id);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'conf@example.com',
            'password' => 'BrandNew9!Pass',
            'password_confirmation' => 'BrandNew9!Pass',
            'tenant' => $signedTenantA,
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('BrandNew9!Pass', $userA->fresh()->password));
        $this->assertFalse(
            Hash::check('BrandNew9!Pass', $userB->fresh()->password),
            'A reset must never cross into another tenant sharing the email.'
        );
    }

    public function test_reset_for_a_tenant_with_no_matching_user_is_rejected(): void
    {
        // Link is qualified for a tenant that has NO user with this email. The
        // (tenant_id, email) scope finds nobody → rejected, nobody reset.
        [$tenantA, $userA] = $this->makeIndexedUser('reset-nm', 'nm@example.com');
        $emptyTenant = Tenant::create([
            'name' => 'Empty Org',
            'slug' => 'reset-empty',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $token = Password::createToken($userA);
        $signedEmpty = app(TenantLinkSigner::class)->sign($emptyTenant->id);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'nm@example.com',
            'password' => 'BrandNew9!Pass',
            'password_confirmation' => 'BrandNew9!Pass',
            'tenant' => $signedEmpty,
        ]);

        $this->assertApiValidationErrors($response, ['email']);
        $this->assertFalse(Hash::check('BrandNew9!Pass', $userA->fresh()->password));
    }

    /**
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
