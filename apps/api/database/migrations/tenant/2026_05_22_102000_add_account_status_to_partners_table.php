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
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('account_status', 32)->default('active')->after('is_active');
            $table->unsignedInteger('account_status_version')->default(1)->after('account_status');
            $table->timestampTz('account_status_changed_at')->nullable()->after('account_status_version');
            $table->uuid('account_status_changed_by')->nullable()->after('account_status_changed_at');
            $table->text('account_status_reason')->nullable()->after('account_status_changed_by');
            $table->index(['company_id', 'account_status'], 'partners_company_account_status_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE partners
                ADD CONSTRAINT partners_account_status_check
                CHECK (account_status IN ('active', 'suspended', 'closed', 'disputed'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE partners DROP CONSTRAINT IF EXISTS partners_account_status_check');
        }

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropIndex('partners_company_account_status_idx');
            $table->dropColumn([
                'account_status',
                'account_status_version',
                'account_status_changed_at',
                'account_status_changed_by',
                'account_status_reason',
            ]);
        });
    }
};
