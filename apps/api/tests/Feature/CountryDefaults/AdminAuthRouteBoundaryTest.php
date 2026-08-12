<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAuthRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_is_reachable_without_authenticated_middleware(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [])
            ->assertUnprocessable();
    }

    #[DataProvider('centralAdminRoles')]
    public function test_every_active_central_admin_role_can_read_profile_and_logout(string $role): void
    {
        if ($role === 'defaults_editor') {
            config(['country_defaults.external_editors_enabled' => true]);
        }
        $admin = $this->admin($role);
        $token = $admin->createToken('route-boundary', ['super-admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', $role);
        $this->withToken($token)->postJson('/api/v1/admin/auth/logout')->assertOk();
        self::assertCount(0, $admin->tokens()->get());
    }

    public function test_inactive_central_admin_cannot_use_profile_routes(): void
    {
        $admin = $this->admin('defaults_editor', false);

        $this->actingAs($admin, 'sanctum-admin')->getJson('/api/v1/admin/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_support_approver_cannot_enter_country_defaults_capability_routes(): void
    {
        $approver = $this->admin('support_approver');

        $this->actingAs($approver, 'sanctum-admin')
            ->getJson('/api/v1/admin/country-defaults/templates?domain=chart_of_accounts')
            ->assertForbidden();
    }

    private function admin(string $role, bool $active = true): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Central administrator',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function centralAdminRoles(): iterable
    {
        yield 'super admin' => ['super_admin'];
        yield 'support approver' => ['support_approver'];
        yield 'defaults editor' => ['defaults_editor'];
    }
}
