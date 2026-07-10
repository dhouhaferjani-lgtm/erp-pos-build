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
        Schema::create('replenishment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id');
            $table->uuid('location_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->decimal('requested_qty', 15, 4)->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('request_count')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('source_channel', 10);
            $table->uuid('requested_by_user_id');
            $table->timestampTz('first_requested_at');
            $table->timestampTz('last_requested_at');
            $table->uuid('client_request_uuid')->nullable();
            $table->uuid('sourcing_document_id')->nullable();
            $table->string('fulfillment_type', 20)->nullable();
            $table->uuid('fulfillment_id')->nullable();
            $table->uuid('processed_by_user_id')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('processed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'status', 'location_id']);
            $table->unique(
                ['tenant_id', 'company_id', 'client_request_uuid'],
                'replenishment_client_uuid_unique',
            );
        });

        DB::statement("CREATE UNIQUE INDEX replenishment_open_non_variant
            ON replenishment_requests (company_id, location_id, product_id)
            WHERE variant_id IS NULL AND status IN ('pending','in_progress')");
        DB::statement("CREATE UNIQUE INDEX replenishment_open_with_variant
            ON replenishment_requests (company_id, location_id, product_id, variant_id)
            WHERE variant_id IS NOT NULL AND status IN ('pending','in_progress')");

        Schema::create('replenishment_capture_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('client_request_uuid');
            $table->uuid('request_id');
            $table->timestampTz('applied_at');
            $table->unique(
                ['tenant_id', 'client_request_uuid'],
                'replenishment_receipt_uuid_unique',
            );
            $table->foreign('request_id')
                ->references('id')
                ->on('replenishment_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_capture_receipts');
        Schema::dropIfExists('replenishment_requests');
    }
};
