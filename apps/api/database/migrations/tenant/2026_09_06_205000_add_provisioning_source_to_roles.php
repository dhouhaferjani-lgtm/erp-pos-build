<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            throw new RuntimeException('Role provisioning requires the roles table.');
        }
        $team = config('permission.column_names.team_foreign_key');
        if (! is_string($team) || ! preg_match('/^[a-z_][a-z0-9_]*$/D', $team) || ! Schema::hasColumn('roles', $team)) {
            throw new RuntimeException('Role provisioning requires a valid configured team column.');
        }
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Role provisioning supports PostgreSQL and SQLite only.');
        }
        if (! Schema::hasColumn('roles', 'provisioning_source')) {
            Schema::table('roles', fn (Blueprint $table) => $table->string('provisioning_source', 32)->nullable());
        }
        $column = collect(Schema::getColumns('roles'))->firstWhere('name', 'provisioning_source');
        if ($column === null || ! $column['nullable'] || $column['default'] !== null
            || ($driver === 'pgsql' && $column['type'] !== 'character varying(32)')
            || ($driver === 'sqlite' && ! in_array($column['type'], ['varchar', 'varchar(32)'], true))) {
            throw new RuntimeException('Incompatible roles.provisioning_source schema.');
        }
        $valid = "$team IS NOT NULL AND provisioning_source = 'w-lot-a-1a' AND name = 'general_manager' AND guard_name = 'sanctum'";
        if (DB::table('roles')->whereNotNull('provisioning_source')->whereRaw("NOT ($valid)")->exists()) {
            throw new RuntimeException('Invalid existing marked role.');
        }
        if ($driver === 'pgsql') {
            $expected = "CHECK (provisioning_source IS NULL OR ($valid))";
            $existing = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'roles'::regclass AND conname = 'roles_provisioning_source_check'");
            if ($existing === null) {
                DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_provisioning_source_check $expected");
                $existing = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'roles'::regclass AND conname = 'roles_provisioning_source_check'");
            }
            // Compare the PostgreSQL deparser form without discarding boolean grouping.
            $expected = "CHECK (((provisioning_source IS NULL) OR (($team IS NOT NULL) AND ((provisioning_source)::text = 'w-lot-a-1a'::text) AND ((name)::text = 'general_manager'::text) AND ((guard_name)::text = 'sanctum'::text))))";
            if ($existing === null || preg_replace('/\s+/', '', $existing->definition) !== preg_replace('/\s+/', '', $expected)) {
                throw new RuntimeException('Incompatible roles_provisioning_source_check.');
            }
        } else {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                $name = "roles_provisioning_source_{$suffix}_guard";
                $expected = "CREATE TRIGGER $name BEFORE $operation ON roles WHEN NEW.provisioning_source IS NOT NULL AND (NEW.$team IS NULL OR NEW.provisioning_source <> 'w-lot-a-1a' OR NEW.name <> 'general_manager' OR NEW.guard_name <> 'sanctum') BEGIN SELECT RAISE(ABORT, 'Invalid marked role'); END";
                $existing = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
                if ($existing === null) {
                    DB::unprepared($expected);
                    $existing = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
                }
                if ($existing === null || $this->normalize($existing->sql) !== $this->normalize($expected)) {
                    throw new RuntimeException("Incompatible $name.");
                }
            }
        }
        $index = 'roles_provisioning_source_team_unique';
        $expected = "CREATE UNIQUE INDEX $index ON roles ($team, provisioning_source) WHERE provisioning_source IS NOT NULL";
        if ($driver === 'pgsql') {
            $existing = DB::selectOne('SELECT indexdef AS definition FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$index]);
        } else {
            $existing = DB::selectOne("SELECT sql AS definition FROM sqlite_master WHERE type = 'index' AND name = ?", [$index]);
        }
        if ($existing === null) {
            DB::statement($expected);
        } elseif ($this->normalize($existing->definition) !== $this->normalize($expected)) {
            throw new RuntimeException('Incompatible roles_provisioning_source_team_unique.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('roles', 'provisioning_source')) {
            return;
        }
        if (DB::table('roles')->whereNotNull('provisioning_source')->exists()) {
            throw new RuntimeException('Cannot roll back while a marked role exists.');
        }
        DB::statement('DROP INDEX IF EXISTS roles_provisioning_source_team_unique');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_provisioning_source_check');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS roles_provisioning_source_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS roles_provisioning_source_update_guard');
        }
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('provisioning_source'));
    }

    private function normalize(string $sql): string
    {
        $sql = str_replace(['::text', '::character varying', ' USING btree', '"'], '', $sql);
        $sql = preg_replace('/\b[a-z_][a-z0-9_]*\.roles\b/', 'roles', $sql) ?? $sql;

        return strtolower(preg_replace('/[\s();]+/', '', $sql) ?? $sql);
    }
};
