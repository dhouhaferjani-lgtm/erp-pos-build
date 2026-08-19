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
        Schema::table('company_fraud_settings', function (Blueprint $table): void {
            $table->decimal('cash_variance_over_soft', 12, 4)->default(1.0000);
            $table->decimal('cash_variance_over_hard', 12, 4)->default(20.0000);
            $table->decimal('cash_variance_under_soft', 12, 4)->default(1.0000);
            $table->decimal('cash_variance_under_hard', 12, 4)->default(20.0000);
            // SV-9 retroactively flipped this default to true; the 2026_08_12_100000 migration converges existing false rows.
            $table->boolean('require_blind_cash_count')->default(true);
            $table->boolean('require_manager_pin_above_hard')->default(true);
            $table->string('cash_variance_email_severity', 12)->default('none');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_fraud_settings ADD CONSTRAINT cfs_cash_variance_soft_lt_hard
                CHECK (cash_variance_over_soft < cash_variance_over_hard
                   AND cash_variance_under_soft < cash_variance_under_hard)');
            DB::statement("ALTER TABLE company_fraud_settings ADD CONSTRAINT cfs_cash_variance_email_severity_enum
                CHECK (cash_variance_email_severity IN ('none','critical','warning','info'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_fraud_settings DROP CONSTRAINT IF EXISTS cfs_cash_variance_soft_lt_hard');
            DB::statement('ALTER TABLE company_fraud_settings DROP CONSTRAINT IF EXISTS cfs_cash_variance_email_severity_enum');
        }
        Schema::table('company_fraud_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'cash_variance_over_soft', 'cash_variance_over_hard',
                'cash_variance_under_soft', 'cash_variance_under_hard',
                'require_blind_cash_count', 'require_manager_pin_above_hard',
                'cash_variance_email_severity',
            ]);
        });
    }
};
