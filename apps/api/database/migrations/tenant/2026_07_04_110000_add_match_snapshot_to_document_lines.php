<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->decimal('price_match_basis', 15, 6)->nullable()->after('accrual_unit_cost');
            $table->uuid('matched_receipt_line_id')->nullable()->after('price_match_basis');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn(['price_match_basis', 'matched_receipt_line_id']);
        });
    }
};
