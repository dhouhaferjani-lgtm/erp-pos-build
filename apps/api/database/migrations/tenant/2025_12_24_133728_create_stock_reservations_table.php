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
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->uuid('location_id');
            $table->decimal('quantity', 15, 4);

            // Source tracking (polymorphic)
            $table->string('source_type', 50);  // ReservationSource enum value
            $table->uuid('source_id');
            $table->uuid('source_line_id')->nullable(); // For line-level tracking

            // Expiration management
            $table->timestamp('expires_at')->nullable(); // NULL = never expires
            $table->timestamp('expired_at')->nullable(); // When actually expired

            // Release tracking
            $table->timestamp('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->string('release_reason', 50)->nullable(); // ReleaseReason enum

            // Priority for conflict resolution (future use)
            $table->integer('priority')->default(0);

            // Audit & fraud detection
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('released_by')->references('id')->on('users')->onDelete('set null');

            // Indexes for common queries
            $table->index(['product_id', 'location_id', 'released_at'], 'idx_reservations_active');
            $table->index(['expires_at'], 'idx_reservations_expiring');
            $table->index(['source_type', 'source_id'], 'idx_reservations_source');
            $table->index(['company_id', 'expired_at'], 'idx_reservations_fraud_detection');
        });

        // Add check constraint for quantity (PostgreSQL only - not supported by SQLite)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT chk_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
