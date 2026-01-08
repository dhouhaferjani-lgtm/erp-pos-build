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
        Schema::table('document_tax_details', function (Blueprint $table): void {
            // Add sequence_order for tax stacking order
            if (! Schema::hasColumn('document_tax_details', 'sequence_order')) {
                $table->integer('sequence_order')->default(1)->after('document_id');
            }

            // Add tax_code for reference to tax configuration
            if (! Schema::hasColumn('document_tax_details', 'tax_code')) {
                $table->string('tax_code', 50)->nullable()->after('sequence_order');
            }

            // Add tax_fixed_amount for fixed amount taxes
            if (! Schema::hasColumn('document_tax_details', 'tax_fixed_amount')) {
                $table->decimal('tax_fixed_amount', 10, 3)->nullable()->after('tax_rate');
            }

            // Drop updated_at if it exists (records should be immutable)
            if (Schema::hasColumn('document_tax_details', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_tax_details', function (Blueprint $table): void {
            $columns = ['sequence_order', 'tax_code', 'tax_fixed_amount'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('document_tax_details', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Re-add updated_at if it doesn't exist
            if (! Schema::hasColumn('document_tax_details', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
