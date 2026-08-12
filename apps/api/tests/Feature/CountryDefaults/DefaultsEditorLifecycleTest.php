<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DefaultsEditorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_list_disable_reenable_and_reset_credentials_with_audit(): void
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

        $this->actingAs($super, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/editors', [
            'editor_id' => $editor->id,
            'role' => 'support_approver',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['role']]]);
    }

    public function test_support_approver_is_not_listed_as_a_defaults_editor(): void
    {
        $actor = $this->admin('super_admin');
        $approver = $this->admin('support_approver');
        $editor = $this->admin('defaults_editor');

        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/editors')
            ->assertOk()
            ->assertJsonFragment(['id' => $editor->id])
            ->assertJsonMissing(['id' => $approver->id]);
    }

    public function test_support_approver_cannot_be_mutated_through_defaults_editor_lifecycle(): void
    {
        $actor = $this->admin('super_admin');
        $approver = $this->admin('support_approver');
        $original = [
            'name' => $approver->name,
            'role' => $approver->role,
            'is_active' => $approver->is_active,
        ];

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/editors', [
            'editor_id' => $approver->id,
            'name' => 'Compromised approver',
            'is_active' => false,
        ])->assertNotFound();

        $freshApprover = $approver->fresh();
        self::assertInstanceOf(SuperAdmin::class, $freshApprover);
        self::assertSame($original, [
            'name' => $freshApprover->name,
            'role' => $freshApprover->role,
            'is_active' => $freshApprover->is_active,
        ]);
        self::assertDatabaseMissing('admin_audit_logs', ['entity_id' => $approver->id]);
    }

    public function test_support_approver_credentials_and_tokens_are_inaccessible_to_defaults_editor_lifecycle(): void
    {
        $actor = $this->admin('super_admin');
        $approver = $this->admin('support_approver');
        $originalPassword = $approver->password;
        $approver->createToken('four-eyes-token');

        $this->actingAs($actor, 'sanctum-admin')
            ->postJson("/api/v1/admin/country-defaults/editors/{$approver->id}/reset-credentials", [])
            ->assertNotFound();

        $freshApprover = $approver->fresh();
        self::assertInstanceOf(SuperAdmin::class, $freshApprover);
        self::assertSame($originalPassword, $freshApprover->password);
        self::assertCount(1, $approver->tokens()->get());
        self::assertDatabaseMissing('admin_audit_logs', [
            'action' => 'country_defaults.editor.credentials_reset',
            'entity_id' => $approver->id,
        ]);
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

    public function test_unrelated_editor_query_exception_is_rethrown(): void
    {
        $actor = $this->admin('super_admin');
        $connection = DB::connection((new SuperAdmin)->getConnectionName());
        $this->installUnrelatedEditorFailure($connection->getDriverName());
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($actor, 'sanctum-admin')->postJson('/api/v1/admin/country-defaults/editors', [
                'name' => 'Infrastructure failure',
                'email' => 'infrastructure-failure@example.test',
            ]);
            self::fail('An unrelated editor storage failure must propagate as QueryException.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('country_defaults_editor_unrelated_failure', $exception->getMessage());
        } finally {
            $this->removeUnrelatedEditorFailure($connection->getDriverName());
        }
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

    private function installUnrelatedEditorFailure(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION country_defaults_editor_unrelated_failure() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'country_defaults_editor_unrelated_failure' USING ERRCODE = '57014';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER country_defaults_editor_unrelated_failure
                BEFORE INSERT ON super_admins
                FOR EACH ROW EXECUTE FUNCTION country_defaults_editor_unrelated_failure();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER country_defaults_editor_unrelated_failure
            BEFORE INSERT ON super_admins
            BEGIN
                SELECT RAISE(ABORT, 'country_defaults_editor_unrelated_failure');
            END;
            SQL);
    }

    private function removeUnrelatedEditorFailure(string $driver): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS country_defaults_editor_unrelated_failure'.($driver === 'pgsql' ? ' ON super_admins' : ''));
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS country_defaults_editor_unrelated_failure()');
        }
    }
}
