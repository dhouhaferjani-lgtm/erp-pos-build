<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds gl_account_id to payment_repositories to map payment repositories
     * to General Ledger accounts for automatic journal entry creation.
     */
    public function up(): void
    {
        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->foreignUuid('gl_account_id')
                ->nullable()
                ->after('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->index('gl_account_id');
        });

        // PostgreSQL-specific comment
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('COMMENT ON COLUMN payment_repositories.gl_account_id IS \'General Ledger account for automatic journal entry creation\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->dropForeign(['gl_account_id']);
            $table->dropColumn('gl_account_id');
        });
    }
};
