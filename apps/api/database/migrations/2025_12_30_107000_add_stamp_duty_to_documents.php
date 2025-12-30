<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Split tax_amount into line_tax_amount and stamp_duty_amount
            $table->decimal('line_tax_amount', 15, 2)->nullable()->after('tax_amount');
            $table->decimal('stamp_duty_amount', 10, 3)->nullable()->default('0.000')->after('line_tax_amount');

            // tax_amount will become computed total of line_tax + stamp_duty
            // We keep it for backward compatibility
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['line_tax_amount', 'stamp_duty_amount']);
        });
    }
};
