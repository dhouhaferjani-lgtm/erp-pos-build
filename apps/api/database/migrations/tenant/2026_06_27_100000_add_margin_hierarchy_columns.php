<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('target_margin_override', 5, 2)->nullable()->after('default_tax_rate');
            $table->decimal('minimum_margin_override', 5, 2)->nullable()->after('target_margin_override');
        });

        Schema::table('products', function (Blueprint $table) {
            // Safe default 'manual': no existing price can be auto-clobbered (Task 14 backfill flips proven-auto rows).
            $table->string('pricing_mode', 16)->default('manual')->after('minimum_margin_override');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['target_margin_override', 'minimum_margin_override']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
