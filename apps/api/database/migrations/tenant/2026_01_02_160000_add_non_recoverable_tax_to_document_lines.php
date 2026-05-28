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
            if (! Schema::hasColumn('document_lines', 'non_recoverable_tax')) {
                $table->decimal('non_recoverable_tax', 15, 3)
                    ->default(0)
                    ->after('allocated_costs')
                    ->comment('Non-recoverable taxes allocated to this line (adds to product cost)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('document_lines', 'non_recoverable_tax')) {
                $table->dropColumn('non_recoverable_tax');
            }
        });
    }
};
