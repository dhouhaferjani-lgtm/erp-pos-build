<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add support for mobile-initiated counting operations.
 *
 * Allows managers to create and build counting operations directly from
 * the mobile app, with draft mode for incremental product addition over
 * multiple days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_countings', function (Blueprint $table) {
            // Mobile creation tracking
            $table->boolean('created_on_mobile')->default(false)->after('created_by_user_id');

            // Optional title for draft counts (e.g., "Brake Pads Spot Check")
            $table->string('title', 255)->nullable()->after('company_id');

            // Track modifications for draft editing
            $table->timestampTz('last_modified_at')->nullable()->after('updated_at');
            $table->uuid('last_modified_by_user_id')->nullable()->after('last_modified_at');

            // Foreign key for last modifier
            $table->foreign('last_modified_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Index for draft queries (managers checking their drafts)
            $table->index(['status', 'created_by_user_id'], 'idx_draft_creator');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_countings', function (Blueprint $table) {
            $table->dropForeign(['last_modified_by_user_id']);
            $table->dropIndex('idx_draft_creator');
            $table->dropColumn([
                'created_on_mobile',
                'title',
                'last_modified_at',
                'last_modified_by_user_id',
            ]);
        });
    }
};
