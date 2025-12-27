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
        Schema::create('expense_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('expense_category_id')->nullable()->constrained('expense_categories')->onDelete('set null');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->onDelete('set null');
            $table->foreignUuid('payment_repository_id')->nullable()->constrained('payment_repositories')->onDelete('set null');
            $table->date('payment_date')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('receipt_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->timestamps();

            $table->index('document_id');
            $table->index('expense_category_id');
            $table->index('payment_date');
            $table->index('is_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_metadata');
    }
};
