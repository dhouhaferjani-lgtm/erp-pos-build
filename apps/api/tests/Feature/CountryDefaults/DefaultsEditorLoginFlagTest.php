<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DefaultsEditorLoginFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_off_flag_blocks_only_defaults_editor_login(): void
    {
        self::assertFalse(config('country_defaults.external_editors_enabled'));

        foreach (['super_admin' => 200, 'support_approver' => 200, 'defaults_editor' => 422] as $role => $status) {
            $admin = $this->admin($role);
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $admin->email,
                'password' => 'secret-password',
            ])->assertStatus($status);
        }
    }

    public function test_enabled_flag_permits_defaults_editor_login(): void
    {
        config(['country_defaults.external_editors_enabled' => true]);
        $admin = $this->admin('defaults_editor');

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk()->assertJsonPath('data.admin.role', 'defaults_editor');
    }

    public function test_disabling_flag_immediately_denies_existing_defaults_editor_token_at_every_central_boundary(): void
    {
        config(['country_defaults.external_editors_enabled' => true]);
        $admin = $this->admin('defaults_editor');
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk()->json('data.token');
        self::assertIsString($token);

        config(['country_defaults.external_editors_enabled' => false]);

        $this->withToken($token)->getJson('/api/v1/admin/country-defaults/templates?domain=chart_of_accounts')
            ->assertForbidden()->assertJsonPath('error.code', 'EXTERNAL_EDITORS_DISABLED');
        $this->withToken($token)->getJson('/api/v1/admin/auth/me')
            ->assertForbidden()->assertJsonPath('error.code', 'EXTERNAL_EDITORS_DISABLED');
        $this->withToken($token)->postJson('/api/v1/admin/auth/logout')
            ->assertForbidden()->assertJsonPath('error.code', 'EXTERNAL_EDITORS_DISABLED');
    }

    private function admin(string $role): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Login flag actor',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
