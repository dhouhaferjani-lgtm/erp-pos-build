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
     * Sequence table for `workshop_work_orders.work_order_number` — mirrors
     * the `document_sequences` pattern that backs `DocumentNumberingService`.
     *
     * One row per `(tenant_id, company_id, year)` carrying a monotonic
     * `last_number`. Pessimistic `FOR UPDATE` lock in
     * `EloquentWorkOrderSequence::next()` (Task 8) guarantees gap-free
     * numbering under concurrent creation.
     */
    public function up(): void
    {
        Schema::create('workshop_work_order_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->integer('year');
            $table->integer('last_number')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'year'], 'uq_wwos_tenant_company_year');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE workshop_work_order_sequences ADD CONSTRAINT chk_wwos_last_number_nonneg CHECK (last_number >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_work_order_sequences');
    }
};
