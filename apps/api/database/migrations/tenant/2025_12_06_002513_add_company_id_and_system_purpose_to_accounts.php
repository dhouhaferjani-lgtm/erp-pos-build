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
        // Add columns if they don't exist (handles existing databases)
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'company_id')) {
                $table->uuid('company_id')->nullable()->after('tenant_id');
            }

            if (! Schema::hasColumn('accounts', 'system_purpose')) {
                $table->string('system_purpose', 50)->nullable()->after('type');
            }
        });

        // Migrate existing data: for each tenant, assign accounts to their first company
        // SQLite-compatible syntax (no table aliases in UPDATE)
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('
                UPDATE accounts
                SET company_id = (
                    SELECT c.id
                    FROM companies c
                    WHERE c.tenant_id = accounts.tenant_id
                    ORDER BY c.created_at ASC
                    LIMIT 1
                )
                WHERE company_id IS NULL
            ');
        } else {
            DB::statement('
                UPDATE accounts a
                SET company_id = (
                    SELECT c.id
                    FROM companies c
                    WHERE c.tenant_id = a.tenant_id
                    ORDER BY c.created_at ASC
                    LIMIT 1
                )
                WHERE a.company_id IS NULL
            ');
        }

        // Add indexes if they don't exist
        // Check and add unique constraint for system_purpose per company
        if (! $this->indexExists('accounts', 'accounts_company_purpose_unique')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->unique(['company_id', 'system_purpose'], 'accounts_company_purpose_unique');
            });
        }

        // Add foreign key if not exists
        if (! $this->foreignKeyExists('accounts', 'accounts_company_id_foreign')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            });
        }

        // Add other indexes if they don't exist
        if (! $this->indexExists('accounts', 'accounts_company_purpose_idx')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->index(['company_id', 'system_purpose'], 'accounts_company_purpose_idx');
            });
        }

        if (! $this->indexExists('accounts', 'accounts_company_type_idx')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->index(['company_id', 'type'], 'accounts_company_type_idx');
            });
        }

        if (! $this->indexExists('accounts', 'accounts_company_code_unique')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->unique(['company_id', 'code'], 'accounts_company_code_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if ($this->foreignKeyExists('accounts', 'accounts_company_id_foreign')) {
                $table->dropForeign(['company_id']);
            }
            if ($this->indexExists('accounts', 'accounts_company_purpose_unique')) {
                $table->dropUnique('accounts_company_purpose_unique');
            }
            if ($this->indexExists('accounts', 'accounts_company_code_unique')) {
                $table->dropUnique('accounts_company_code_unique');
            }
            if ($this->indexExists('accounts', 'accounts_company_purpose_idx')) {
                $table->dropIndex('accounts_company_purpose_idx');
            }
            if ($this->indexExists('accounts', 'accounts_company_type_idx')) {
                $table->dropIndex('accounts_company_type_idx');
            }
            if (Schema::hasColumn('accounts', 'system_purpose')) {
                $table->dropColumn('system_purpose');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $indexes = DB::select("
                SELECT name FROM sqlite_master
                WHERE type='index' AND tbl_name = ? AND name = ?
            ", [$table, $indexName]);

            return count($indexes) > 0;
        }

        $indexes = DB::select('
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = ? AND indexname = ?
        ', [$table, $indexName]);

        return count($indexes) > 0;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite doesn't support constraint naming, so always return false
            // to let Laravel handle it through Schema
            return false;
        }

        $constraints = DB::select("
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'
        ", [$table, $constraintName]);

        return count($constraints) > 0;
    }
};
