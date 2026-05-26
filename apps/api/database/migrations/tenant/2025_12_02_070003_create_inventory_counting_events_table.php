<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Counting module - Events table.
 *
 * Stores all events related to inventory counting with hash chain
 * for tamper-proofing and audit trail.
 *
 * Event types include:
 * - counting_created, counting_scheduled, counting_activated
 * - count_started, count_completed
 * - item_counted, item_recounted
 * - item_flagged, item_resolved
 * - counting_finalized, counting_cancelled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counting_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('counting_id');
            $table->uuid('item_id')->nullable(); // Optional - for item-level events

            $table->string('event_type', 50);
            $table->jsonb('event_data')->default('{}');
            $table->uuid('user_id')->nullable();

            // Hash chain for tamper-proofing
            $table->string('previous_hash', 64);
            $table->string('event_hash', 64);

            $table->timestampTz('created_at');

            // Foreign keys
            $table->foreign('counting_id')
                ->references('id')
                ->on('inventory_countings')
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on('inventory_counting_items')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index(['counting_id', 'created_at']);
            $table->index('event_type');
            $table->index('event_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counting_events');
    }
};
