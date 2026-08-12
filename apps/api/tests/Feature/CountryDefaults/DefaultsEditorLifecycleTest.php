<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DefaultsEditorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_list_disable_reenable_change_role_and_reset_credentials_with_audit(): void
    {
        $actor = $this->admin('super_admin');

        $created = $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
            'name' => 'External Accountant',
            'email' => 'accountant@example.test',
        ])->assertCreated()->assertJsonPath('data.role', 'defaults_editor');
        self::assertIsString($created->json('meta.generated_credential'));

        $editor = SuperAdmin::query()->where('email', 'accountant@example.test')->firstOrFail();
        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/editors')
            ->assertOk()->assertJsonFragment(['email' => 'accountant@example.test']);

        $editor->createToken('disable-me');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/editors', [
            'editor_id' => $editor->id,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        self::assertCount(0, $editor->tokens()->get());

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/editors', [
            'editor_id' => $editor->id,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $editor->createToken('role-change-me');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/editors', [
            'editor_id' => $editor->id,
            'role' => 'support_approver',
        ])->assertOk()->assertJsonPath('data.role', 'support_approver');
        self::assertCount(0, $editor->tokens()->get());

        $editor->createToken('reset-me');
        $reset = $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/editors/{$editor->id}/reset-credentials", []);
        $reset->assertOk();
        $credential = $reset->json('meta.generated_credential');
        self::assertIsString($credential);
        $freshEditor = $editor->fresh();
        self::assertInstanceOf(SuperAdmin::class, $freshEditor);
        self::assertTrue(Hash::check($credential, $freshEditor->password));
        self::assertCount(0, $editor->tokens()->get());

        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.editor.created', 'entity_id' => $editor->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.editor.disabled', 'entity_id' => $editor->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.editor.enabled', 'entity_id' => $editor->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.editor.role_changed', 'entity_id' => $editor->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.editor.credentials_reset', 'entity_id' => $editor->id]);
    }

    public function test_editor_lifecycle_routes_reject_non_super_admins_and_creation_cannot_select_another_role(): void
    {
        $editor = $this->admin('defaults_editor');

        $this->actingAs($editor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/editors')->assertForbidden();
        $this->actingAs($editor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
            'name' => 'Forbidden',
            'email' => 'forbidden@example.test',
        ])->assertForbidden();

        $super = $this->admin('super_admin');
        $this->actingAs($super, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
            'name' => 'Wrong role',
            'email' => 'wrong-role@example.test',
            'role' => 'super_admin',
        ])->assertUnprocessable();
    }

    public function test_editor_email_validation_queries_the_central_model_connection(): void
    {
        $actor = $this->admin('super_admin');
        $editor = $this->admin('defaults_editor');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'super_admins')) {
                $queries[] = $query->connectionName;
            }
        });

        $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
            'name' => 'Duplicate editor',
            'email' => $editor->email,
        ])->assertUnprocessable();

        self::assertContains((new SuperAdmin)->getConnectionName(), $queries);
        self::assertNotContains('central.super_admins', $queries);
    }

    public function test_create_and_reset_reject_passwords_below_the_shared_privileged_policy(): void
    {
        $actor = $this->admin('super_admin');

        $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
            'name' => 'Weak password editor',
            'email' => 'weak-password@example.test',
            'password' => 'abcdefghijkl',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['password']]]);
        self::assertDatabaseMissing('super_admins', ['email' => 'weak-password@example.test']);

        $editor = $this->admin('defaults_editor');
        $originalPassword = $editor->password;
        $this->actingAs($actor, 'sanctum-admin')->postJson("/api/v1/admin/country-defaults/editors/{$editor->id}/reset-credentials", [
            'password' => 'abcdefghijkl',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['password']]]);

        $freshEditor = $editor->fresh();
        self::assertInstanceOf(SuperAdmin::class, $freshEditor);
        self::assertSame($originalPassword, $freshEditor->password);
        self::assertDatabaseMissing('admin_audit_logs', [
            'action' => 'country_defaults.editor.credentials_reset',
            'entity_id' => $editor->id,
        ]);
    }

    private function admin(string $role): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Lifecycle actor',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
