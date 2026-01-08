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
        Schema::table('document_lines', function (Blueprint $table): void {
            // Add tax_amount for calculated line tax
            if (! Schema::hasColumn('document_lines', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->nullable()->after('tax_rate');
            }

            // Add tax_recoverable flag for purchase tax recoverability
            if (! Schema::hasColumn('document_lines', 'tax_recoverable')) {
                $table->boolean('tax_recoverable')->default(true)->after('tax_amount');
            }

            // Add recoverable_tax_amount for recoverable portion
            if (! Schema::hasColumn('document_lines', 'recoverable_tax_amount')) {
                $table->decimal('recoverable_tax_amount', 15, 2)->nullable()->after('tax_recoverable');
            }

            // Add non_recoverable_tax_amount for portion added to inventory cost
            if (! Schema::hasColumn('document_lines', 'non_recoverable_tax_amount')) {
                $table->decimal('non_recoverable_tax_amount', 15, 2)->nullable()->after('recoverable_tax_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $columns = ['tax_amount', 'tax_recoverable', 'recoverable_tax_amount', 'non_recoverable_tax_amount'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('document_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
