<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Domain\Services\ConfiguredApproverSet;
use App\Modules\SupportAccess\Domain\Services\SupportAccessConfigurationValidator;
use App\Modules\SupportAccess\Providers\SupportAccessServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SupportAccessConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_sensitive_defaults_and_provider_registration_are_enabled(): void
    {
        self::assertTrue(config('support_access.four_eyes.enabled'));
        self::assertTrue(config('support_access.four_eyes.sensitive_tenants'));
        self::assertSame(60, config('support_access.session_ttl_minutes'));
        self::assertContains(
            SupportAccessServiceProvider::class,
            require base_path('bootstrap/providers.php'),
        );
    }

    public function test_malformed_hard_block_configuration_fails_closed_during_validation(): void
    {
        config()->set('support_access.write_guard.hard_block_path_patterns', [
            '#/fiscal(?:/|$)#i',
            '#[unterminated#',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('hard_block_path_patterns');

        $this->app->make(SupportAccessConfigurationValidator::class)->validate();
    }

    public function test_malformed_maximum_grant_window_fails_closed_during_validation(): void
    {
        config()->set('support_access.max_grant_window_hours', '168');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('max_grant_window_hours');

        $this->app->make(SupportAccessConfigurationValidator::class)->validate();
    }

    public function test_approver_set_fails_closed_for_empty_malformed_inactive_and_unknown_accounts(): void
    {
        $active = $this->superAdmin('partner@example.test', true, 'support_approver');
        $inactive = $this->superAdmin('inactive@example.test', false, 'support_approver');
        $approvers = $this->app->make(ConfiguredApproverSet::class);

        config()->set('support_access.four_eyes.approver_emails', []);
        self::assertFalse($approvers->allows($active));

        config()->set('support_access.four_eyes.approver_emails', ['not-an-email', 42]);
        self::assertFalse($approvers->allows($active));

        config()->set('support_access.four_eyes.approver_emails', ['partner@example.test']);
        self::assertTrue($approvers->allows($active));
        self::assertFalse($approvers->allows($inactive));
        self::assertFalse($approvers->allows($this->superAdmin('unknown@example.test', true)));
    }

    public function test_partner_super_admin_is_seeded_only_when_password_is_configured(): void
    {
        config()->set('auth.super_admin.email', 'primary@example.test');
        config()->set('auth.super_admin.password', 'Primary-password-2026!');
        config()->set('support_access.partner.name', 'Business Partner Approver');
        config()->set('support_access.partner.email', 'partner@example.test');
        config()->set('support_access.partner.password', null);

        $this->seed(SuperAdminSeeder::class);
        self::assertDatabaseMissing('super_admins', ['email' => 'partner@example.test']);

        config()->set('support_access.partner.password', 'Partner-password-2026!');
        $this->seed(SuperAdminSeeder::class);

        $partner = SuperAdmin::query()->where('email', 'partner@example.test')->firstOrFail();
        self::assertSame('Business Partner Approver', $partner->name);
        self::assertSame('support_approver', $partner->role);
        self::assertTrue($partner->is_active);
        self::assertTrue(Hash::check('Partner-password-2026!', $partner->password));
    }

    public function test_reseeding_does_not_rotate_the_primary_super_admin_password(): void
    {
        config()->set('auth.super_admin.email', 'primary@example.test');
        config()->set('auth.super_admin.password', 'Initial-password-2026!');
        config()->set('support_access.partner.password', null);
        $this->seed(SuperAdminSeeder::class);

        $primary = SuperAdmin::query()->where('email', 'primary@example.test')->firstOrFail();
        $originalHash = $primary->password;

        config()->set('auth.super_admin.password', 'Rotated-by-reseed-2026!');
        $this->seed(SuperAdminSeeder::class);

        self::assertSame($originalHash, $primary->fresh()?->password);
        self::assertTrue(Hash::check('Initial-password-2026!', (string) $primary->fresh()?->password));
    }

    public function test_support_permissions_are_seeded_and_manage_is_admin_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        self::assertNotNull(Permission::findByName('support-access.view', 'sanctum'));
        self::assertNotNull(Permission::findByName('support-access.manage', 'sanctum'));

        $admin = Role::findByName('admin', 'sanctum');
        self::assertTrue($admin->hasPermissionTo('support-access.view'));
        self::assertTrue($admin->hasPermissionTo('support-access.manage'));

        foreach (Role::query()->where('name', '!=', 'admin')->get() as $role) {
            self::assertFalse(
                $role->hasPermissionTo('support-access.manage'),
                "Role {$role->name} must not manage support access.",
            );
        }
    }

    public function test_only_the_distinct_support_approver_role_is_in_the_configured_approver_set(): void
    {
        config()->set('support_access.four_eyes.approver_emails', ['partner@example.test']);
        $ordinaryAdmin = $this->superAdmin('partner@example.test', true);
        $approvers = $this->app->make(ConfiguredApproverSet::class);

        self::assertFalse($approvers->allows($ordinaryAdmin));

        $ordinaryAdmin->update(['role' => 'support_approver']);
        self::assertTrue($approvers->allows($ordinaryAdmin));
    }

    private function superAdmin(string $email, bool $active, string $role = 'super_admin'): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => $active,
        ]);
    }
}
