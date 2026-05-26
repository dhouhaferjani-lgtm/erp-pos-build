<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add is_historical to journal_entries
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('source_id');

            // Index for filtering historical entries
            $table->index(['company_id', 'is_historical']);
        });

        // Add is_historical to stock_movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('user_id');

            // Index for filtering historical movements
            $table->index(['company_id', 'is_historical']);
        });

        // Add is_historical and external_document_number to documents
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('reference');
            $table->string('external_document_number', 100)->nullable()->after('is_historical');

            // Index for filtering historical documents
            $table->index(['company_id', 'is_historical']);
            $table->index(['company_id', 'external_document_number']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_historical']);
            $table->dropIndex(['company_id', 'external_document_number']);
            $table->dropColumn(['is_historical', 'external_document_number']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_historical']);
            $table->dropColumn('is_historical');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_historical']);
            $table->dropColumn('is_historical');
        });
    }
};
