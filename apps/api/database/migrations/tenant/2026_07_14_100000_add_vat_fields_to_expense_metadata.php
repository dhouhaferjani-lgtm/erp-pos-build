<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('vendor_name');
            $table->decimal('vat_deductible_percent', 5, 2)->nullable()->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->dropColumn(['vat_rate', 'vat_deductible_percent']);
        });
    }
};
