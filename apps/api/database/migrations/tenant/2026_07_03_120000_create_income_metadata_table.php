<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('income_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->onDelete('cascade');
            // The "category" of an income is a class-7 revenue GL account, selected
            // directly (unlike expenses which route through an expense_categories table).
            $table->foreignUuid('income_account_id')->nullable()->constrained('accounts')->onDelete('set null');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->onDelete('set null');
            $table->foreignUuid('payment_repository_id')->nullable()->constrained('payment_repositories')->onDelete('set null');
            $table->date('payment_date')->nullable();
            $table->boolean('is_received')->default(true);
            $table->string('reference_number')->nullable();
            $table->string('source_name')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();

            $table->index('document_id');
            $table->index('income_account_id');
            $table->index('payment_date');
            $table->index('is_received');
            $table->unique('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('income_metadata');
    }
};
