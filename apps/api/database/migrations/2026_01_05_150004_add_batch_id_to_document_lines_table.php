<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('product_batches')->nullOnDelete();

            $table->index('batch_id', 'idx_document_lines_batch');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropIndex('idx_document_lines_batch');
            $table->dropColumn('batch_id');
        });
    }
};
