<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add original_line_id to pos_receipt_lines for accurate return line matching.
     *
     * When a return receipt is created, each return line now stores a direct FK
     * to the original sale line it corresponds to. This eliminates ambiguity when
     * the same product appears on multiple lines of the original receipt.
     */
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->foreignUuid('original_line_id')
                ->nullable()
                ->after('receipt_id')
                ->constrained('pos_receipt_lines')
                ->restrictOnDelete();

            $table->index('original_line_id');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropForeign(['original_line_id']);
            $table->dropIndex(['original_line_id']);
            $table->dropColumn('original_line_id');
        });
    }
};
