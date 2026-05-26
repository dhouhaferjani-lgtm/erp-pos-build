<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // null = use country default
            $table->boolean('payment_tolerance_enabled')->nullable();
            $table->decimal('payment_tolerance_percentage', 5, 4)->nullable();
            $table->decimal('max_payment_tolerance_amount', 15, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'payment_tolerance_enabled',
                'payment_tolerance_percentage',
                'max_payment_tolerance_amount',
            ]);
        });
    }
};
