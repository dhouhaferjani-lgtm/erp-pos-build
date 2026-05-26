<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add product_code column for SKU/article reference separate from description.
     *
     * This enables:
     * - Display ARTICLE and DESCRIPTION as separate columns in documents
     * - Track product code even if product is deleted
     * - Support non-product lines with manual codes
     */
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->string('product_code', 100)
                ->nullable()
                ->after('product_id')
                ->comment('Product SKU/article code - preserved even if product deleted');

            $table->index('product_code', 'idx_document_lines_product_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropIndex('idx_document_lines_product_code');
            $table->dropColumn('product_code');
        });
    }
};
