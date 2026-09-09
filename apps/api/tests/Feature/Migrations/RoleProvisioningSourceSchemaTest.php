<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RoleProvisioningSourceSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE SCHEMA wlota1a_schema_test');
            DB::statement('SET search_path TO wlota1a_schema_test');
        }
        config(['permission.column_names.team_foreign_key' => 'tenant_id']);
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
        });
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA wlota1a_schema_test CASCADE');
        } else {
            Schema::dropIfExists('roles');
        }
        parent::tearDown();
    }

    private function migrate(string $direction = 'up'): void
    {
        $path = database_path('migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php');
        self::assertFileExists($path);

        $migration = require $path;
        self::assertTrue(is_callable([$migration, $direction]));
        $migration->{$direction}();
    }

    /** @return array{tenant_id: ?string, name: string, guard_name: string, provisioning_source: string} */
    private function marked(?string $team = '11111111-1111-4111-8111-111111111111'): array
    {
        return ['tenant_id' => $team, 'name' => 'general_manager', 'guard_name' => 'sanctum', 'provisioning_source' => 'w-lot-a-1a'];
    }

    public function test_postgresql_schema_matches_exact_contract(): void
    {
        $this->migrate();
        $this->migrate();
        $column = collect(Schema::getColumns('roles'))->firstWhere('name', 'provisioning_source');
        self::assertNotNull($column);
        self::assertTrue($column['nullable']);
        self::assertNull($column['default']);
        DB::table('roles')->insert(['tenant_id' => null, 'name' => 'custom', 'guard_name' => 'sanctum']);
        $this->migrate('down');
        self::assertFalse(Schema::hasColumn('roles', 'provisioning_source'));
        self::assertSame('custom', DB::table('roles')->value('name'));
    }

    public function test_postgresql_rejects_marked_role_without_team(): void
    {
        $this->migrate();
        $this->expectException(QueryException::class);
        DB::table('roles')->insert($this->marked(null));
    }

    public function test_postgresql_partial_unique_index_enforces_one_marker_per_team(): void
    {
        $this->migrate();
        DB::table('roles')->insert($this->marked());
        DB::table('roles')->insert($this->marked('22222222-2222-4222-8222-222222222222'));
        self::assertSame(2, DB::table('roles')->count());
        $this->expectException(QueryException::class);
        DB::table('roles')->insert($this->marked());
    }

    public function test_sqlite_triggers_reject_invalid_and_null_team_markers(): void
    {
        $this->migrate();
        DB::table('roles')->insert($this->marked());
        $this->expectException(QueryException::class);
        DB::table('roles')->update(['tenant_id' => null]);
    }

    public function test_sqlite_partial_unique_index_enforces_one_marker_per_team(): void
    {
        $this->test_postgresql_partial_unique_index_enforces_one_marker_per_team();
    }

    public function test_down_refuses_while_a_marked_role_exists(): void
    {
        $this->migrate();
        DB::table('roles')->insert($this->marked());
        try {
            $this->migrate('down');
            self::fail('Rollback must refuse a marked role');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('marked', $exception->getMessage());
        }
        self::assertSame(1, DB::table('roles')->count());
        self::assertTrue(Schema::hasColumn('roles', 'provisioning_source'));
    }

    public function test_incompatible_existing_guard_is_rejected(): void
    {
        $this->migrate();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_provisioning_source_check');
            DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_provisioning_source_check CHECK ((provisioning_source IS NULL OR tenant_id IS NOT NULL) AND provisioning_source = 'w-lot-a-1a' AND name = 'general_manager' AND guard_name = 'sanctum')");
        } else {
            DB::unprepared('DROP TRIGGER roles_provisioning_source_update_guard');
            DB::unprepared('CREATE TRIGGER roles_provisioning_source_update_guard BEFORE UPDATE ON roles BEGIN SELECT 1; END');
        }
        $this->expectException(\RuntimeException::class);
        $this->migrate();
    }

    public function test_existing_incompatible_column_is_rejected(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->integer('provisioning_source')->nullable());
        $this->expectException(\RuntimeException::class);
        $this->migrate();
    }
}
