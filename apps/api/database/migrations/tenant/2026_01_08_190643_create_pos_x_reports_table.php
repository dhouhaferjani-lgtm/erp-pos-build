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
     * Creates pos_x_reports table for mid-shift snapshots.
     * Non-destructive reports that can be generated multiple times during a shift.
     * Not fiscally critical - no hash chain required.
     */
    public function up(): void
    {
        Schema::create('pos_x_reports', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Terminal and Shift
            $table->foreignUuid('terminal_id')
                ->constrained('pos_terminals')
                ->restrictOnDelete();
            $table->foreignUuid('shift_id')
                ->nullable()
                ->constrained('pos_shifts')
                ->restrictOnDelete();

            // User who generated the report
            $table->foreignUuid('generated_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Report Data (JSONB snapshot)
            $table->jsonb('snapshot_data');

            // Generation Timestamp
            $table->timestamp('generated_at');

            // Indexes
            $table->index('terminal_id');
            $table->index('shift_id');
            $table->index('generated_by');
            $table->index(['terminal_id', 'generated_at']);
            $table->index(['shift_id', 'generated_at']);
        });

        // PostgreSQL-specific comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Table Comments
            DB::statement('COMMENT ON TABLE pos_x_reports IS \'Mid-shift snapshots (non-destructive, can be generated multiple times)\'');
            DB::statement('COMMENT ON COLUMN pos_x_reports.snapshot_data IS \'JSONB: {
                "sales_count": int,
                "gross_sales": decimal,
                "net_sales": decimal,
                "tax_amount": decimal,
                "refunds_count": int,
                "refunds_amount": decimal,
                "voids_count": int,
                "vat_breakdown": [{"rate": decimal, "net": decimal, "vat": decimal, "gross": decimal}],
                "payment_methods": [{"type": string, "count": int, "amount": decimal}]
            }\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_x_reports');
    }
};
