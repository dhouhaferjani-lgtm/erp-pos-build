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
        Schema::create('pos_z_report_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('z_report_id')->constrained('pos_z_reports')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('expected_amount', 16, 4);
            $table->decimal('actual_amount', 16, 4);
            $table->decimal('variance_amount', 16, 4);
            $table->string('variance_direction', 10);
            $table->integer('transaction_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['z_report_id', 'payment_method_id'], 'pos_z_report_counts_unique_method_per_z');
            $table->index('payment_method_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_z_report_counts ADD CONSTRAINT pos_z_report_counts_variance_calc
                CHECK (variance_amount = actual_amount - expected_amount)');
            DB::statement('ALTER TABLE pos_z_report_counts ADD CONSTRAINT pos_z_report_counts_amounts_positive
                CHECK (expected_amount >= 0 AND actual_amount >= 0)');
            DB::statement("ALTER TABLE pos_z_report_counts ADD CONSTRAINT pos_z_report_counts_direction_sign
                CHECK (
                    (variance_direction = 'balanced' AND variance_amount = 0)
                    OR (variance_direction = 'over' AND variance_amount > 0)
                    OR (variance_direction = 'under' AND variance_amount < 0)
                )");
            DB::statement('ALTER TABLE pos_z_report_counts ADD CONSTRAINT pos_z_report_counts_currency_len
                CHECK (length(currency_code) = 3)');
            DB::statement("COMMENT ON TABLE pos_z_report_counts IS 'Per-tender cash count rows for end-of-shift Z reports'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_z_report_counts');
    }
};
