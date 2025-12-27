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
        Schema::table('companies', function (Blueprint $table) {
            // Fiscal year validation tracking
            $table->timestamp('fiscal_year_validated_at')->nullable()->after('fiscal_year_start_month');
            $table->uuid('fiscal_year_validated_by')->nullable()->after('fiscal_year_validated_at');

            // First transaction tracking (permanent lock)
            $table->timestamp('first_transaction_posted_at')->nullable()->after('fiscal_year_validated_by');
            $table->uuid('first_transaction_document_id')->nullable()->after('first_transaction_posted_at');

            // Foreign key for validated_by
            $table->foreign('fiscal_year_validated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['fiscal_year_validated_by']);
            $table->dropColumn([
                'fiscal_year_validated_at',
                'fiscal_year_validated_by',
                'first_transaction_posted_at',
                'first_transaction_document_id',
            ]);
        });
    }
};
