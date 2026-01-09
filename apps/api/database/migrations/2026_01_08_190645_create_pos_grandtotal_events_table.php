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
     * Creates pos_grandtotal_events table for NF525 grand totals.
     * Critical for NF525 certification - separate hash chains per event type.
     * Tracks both period totals (reset) and perpetual totals (never reset).
     */
    public function up(): void
    {
        Schema::create('pos_grandtotal_events', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Terminal
            $table->foreignUuid('terminal_id')
                ->constrained('pos_terminals')
                ->restrictOnDelete();

            // Event Type (NF525 Critical)
            $table->string('event_type', 20);

            // Period Boundaries
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Period Totals (Reset at each closing)
            $table->jsonb('period_totals');

            // Perpetual Totals (NEVER Reset - cumulative since activation)
            $table->jsonb('perpetual_totals');

            // Hash Chain (Separate chain per event_type)
            $table->char('fiscal_hash', 64);
            $table->char('previous_hash', 64)->nullable();

            // Sequence Number (per event_type)
            $table->integer('sequence_number');

            // User who generated the event
            $table->foreignUuid('generated_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Generation Timestamp
            $table->timestamp('generated_at');

            // Indexes
            $table->index('terminal_id');
            $table->index('event_type');
            $table->index('generated_by');
            $table->index(['terminal_id', 'event_type', 'sequence_number']);
            $table->index(['terminal_id', 'generated_at']);

            // Unique constraint for terminal + event_type + sequence_number
            $table->unique(['terminal_id', 'event_type', 'sequence_number'], 'pos_grandtotal_terminal_type_seq');
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_grandtotal_events ADD CONSTRAINT pos_grandtotal_event_type CHECK (
                event_type IN (\'DAILY\', \'MONTHLY\', \'YEARLY\')
            )');
            DB::statement('ALTER TABLE pos_grandtotal_events ADD CONSTRAINT pos_grandtotal_sequence CHECK (
                sequence_number > 0
            )');
            DB::statement('ALTER TABLE pos_grandtotal_events ADD CONSTRAINT pos_grandtotal_hash_length CHECK (
                length(fiscal_hash) = 64 AND
                (previous_hash IS NULL OR length(previous_hash) = 64)
            )');
            DB::statement('ALTER TABLE pos_grandtotal_events ADD CONSTRAINT pos_grandtotal_period CHECK (
                period_end > period_start
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_grandtotal_events IS \'NF525 grand total events (CRITICAL for certification)\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.event_type IS \'DAILY: End of day | MONTHLY: End of month | YEARLY: End of year\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.period_totals IS \'JSONB: Totals for this period (reset at next closing)\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.perpetual_totals IS \'JSONB: Cumulative totals since terminal activation (NEVER RESET)\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.fiscal_hash IS \'SHA-256 hash: event_type|sequence|period_start|period_end|period_totals|perpetual_totals\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.previous_hash IS \'Hash of previous event of SAME TYPE (separate chains per event_type)\'');
            DB::statement('COMMENT ON COLUMN pos_grandtotal_events.sequence_number IS \'Sequential number per event_type (DAILY:1, DAILY:2... MONTHLY:1, MONTHLY:2...)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_grandtotal_events');
    }
};
