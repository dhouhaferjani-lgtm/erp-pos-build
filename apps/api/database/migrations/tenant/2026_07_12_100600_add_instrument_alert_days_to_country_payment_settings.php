<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('country_payment_settings', 'instrument_alert_days')) {
            Schema::table('country_payment_settings', function (Blueprint $table): void {
                $table->smallInteger('instrument_alert_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('country_payment_settings', 'instrument_alert_days')) {
            Schema::table('country_payment_settings', function (Blueprint $table): void {
                $table->dropColumn('instrument_alert_days');
            });
        }
    }
};
