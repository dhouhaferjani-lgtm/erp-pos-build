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
     * Creates pos_z_reports table for end-of-day closings.
     * Fiscally critical - hash chained for NF525 compliance.
     * Automatically closes shift and creates GRANDTOTAL_DAILY event.
     */
    public function up(): void
    {
        Schema::create('pos_z_reports', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Terminal and Shift
            $table->foreignUuid('terminal_id')
                ->constrained('pos_terminals')
                ->restrictOnDelete();
            $table->foreignUuid('shift_id')
                ->constrained('pos_shifts')
                ->restrictOnDelete();

            // Z Report Number (sequential, never resets)
            $table->integer('z_number');

            // Hash Chain (NF525 Critical)
            $table->char('fiscal_hash', 64);
            $table->char('previous_z_hash', 64)->nullable();

            // Report Data (JSONB)
            $table->jsonb('report_data');

            // User who generated the report
            $table->foreignUuid('generated_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Generation Timestamp
            $table->timestamp('generated_at');

            // Indexes
            $table->index('terminal_id');
            $table->index('shift_id');
            $table->index('generated_by');
            $table->index(['terminal_id', 'z_number']);
            $table->index(['terminal_id', 'generated_at']);

            // Unique constraint for terminal + z_number
            $table->unique(['terminal_id', 'z_number'], 'pos_z_reports_terminal_z_number');
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_z_reports ADD CONSTRAINT pos_z_reports_z_number CHECK (z_number > 0)');
            DB::statement('ALTER TABLE pos_z_reports ADD CONSTRAINT pos_z_reports_hash_length CHECK (
                length(fiscal_hash) = 64 AND
                (previous_z_hash IS NULL OR length(previous_z_hash) = 64)
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_z_reports IS \'End-of-day closings (fiscally critical, hash chained for NF525)\'');
            DB::statement('COMMENT ON COLUMN pos_z_reports.z_number IS \'Sequential Z report number (never resets, continuous since terminal activation)\'');
            DB::statement('COMMENT ON COLUMN pos_z_reports.fiscal_hash IS \'SHA-256 hash of Z report (z_number|timestamp|shift_id|totals)\'');
            DB::statement('COMMENT ON COLUMN pos_z_reports.previous_z_hash IS \'Hash of previous Z report (NULL for first Z report)\'');
            DB::statement('COMMENT ON COLUMN pos_z_reports.report_data IS \'JSONB: Complete Z report content (includes all X report data + shift closing details + variance)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_z_reports');
    }
};
