<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_history_searches', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();

            // The cashier who performed the search.
            $table->uuid('cashier_id')->index();

            // The terminal from which the search originated.
            $table->uuid('terminal_id');

            // Nullable: the resolved partner (null when the search found no partner
            // or when the search was rejected before resolution).
            $table->uuid('partner_id')->nullable()->index();

            // SHA-256 hex hash of the raw search input. We never store the raw
            // input to protect customer PII.
            $table->string('search_terms_hash', 64);

            // Number of receipt results returned.
            $table->integer('result_count')->default(0);

            // True when the search was rejected by the minimum-specificity check.
            $table->boolean('was_rejected')->default(false);

            // Rejection reason (null when not rejected).
            $table->string('rejection_reason')->nullable();

            // Audit timestamp only (no updated_at — this table is append-only).
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_history_searches');
    }
};
