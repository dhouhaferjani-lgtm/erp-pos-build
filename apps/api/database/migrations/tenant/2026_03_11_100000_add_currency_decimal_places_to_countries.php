<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->tinyInteger('currency_decimal_places')->default(2)->after('currency_symbol');
        });

        // Set 3 decimal places for ISO 4217 3-decimal currencies
        DB::table('countries')
            ->whereIn('currency_code', ['TND', 'LYD', 'BHD', 'IQD', 'JOD', 'KWD', 'OMR'])
            ->update(['currency_decimal_places' => 3]);
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn('currency_decimal_places');
        });
    }
};
