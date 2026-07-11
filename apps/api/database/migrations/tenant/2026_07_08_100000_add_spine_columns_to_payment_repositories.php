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
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable()->after('balance');
            $table->timestampTz('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();
            $table->unsignedBigInteger('next_movement_ordinal')->default(0);
        });

        // Backfill currency from the owning company (single-currency today).
        // pgsql-only: this "UPDATE ... FROM" syntax is Postgres-specific, and
        // on sqlite test runs (RefreshDatabase) the table is created empty by
        // this very migration, so there is nothing to backfill anyway.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE payment_repositories pr
                SET currency = c.currency
                FROM companies c
                WHERE c.id = pr.company_id AND pr.currency IS NULL
            SQL);

            // MED-11: currency is a hard guard — must be non-null. Assert
            // backfill completeness, then enforce at the schema level.
            $nulls = DB::table('payment_repositories')->whereNull('currency')->count();
            if ($nulls > 0) {
                throw new RuntimeException("Cannot enforce NOT NULL: {$nulls} payment_repositories have null currency after backfill.");
            }
            DB::statement('ALTER TABLE payment_repositories ALTER COLUMN currency SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'frozen_at', 'frozen_reason', 'next_movement_ordinal']);
        });
    }
};
