<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates `workshop_work_order_status_transitions` — an append-only audit
     * table of every status change on a WorkOrder. `context` JSONB carries
     * free-form per-transition metadata (approval payload, cancellation
     * reason with sub-reason, etc.).
     */
    public function up(): void
    {
        Schema::create('workshop_work_order_status_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('work_order_id')->constrained('workshop_work_orders')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason_code', 32)->nullable();
            $table->foreignUuid('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('triggered_at')->useCurrent();
            $table->jsonb('context')->nullable();

            $table->index(['work_order_id', 'triggered_at'], 'idx_wwost_work_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_work_order_status_transitions');
    }
};
