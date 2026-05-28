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
        Schema::create('country_payment_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 2);
            $table->foreign('country_code')->references('code')->on('countries');

            // Payment Tolerance
            $table->boolean('payment_tolerance_enabled')->default(true);
            $table->decimal('payment_tolerance_percentage', 5, 4)->default(0.0050); // 0.5%
            $table->decimal('max_payment_tolerance_amount', 15, 4)->default(0.50);
            $table->string('underpayment_writeoff_purpose', 50)->default('payment_tolerance_expense');
            $table->string('overpayment_writeoff_purpose', 50)->default('payment_tolerance_income');

            // Extensibility: FX (Phase 2)
            $table->string('realized_fx_gain_purpose', 50)->default('realized_fx_gain');
            $table->string('realized_fx_loss_purpose', 50)->default('realized_fx_loss');

            // Extensibility: Cash Discounts (Phase 2)
            $table->boolean('cash_discount_enabled')->default(false);
            $table->string('sales_discount_purpose', 50)->default('sales_discount');

            $table->timestamps();
            $table->unique('country_code');
        });

        // Seed defaults for currently supported countries
        // Only seed countries that exist in the countries table
        $existingCountries = DB::table('countries')->whereIn('code', ['TN', 'FR'])->pluck('code');

        $settings = [];
        foreach ($existingCountries as $countryCode) {
            $settings[] = [
                'country_code' => $countryCode,
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => 0.0050,
                'max_payment_tolerance_amount' => $countryCode === 'TN' ? 0.100 : 0.50,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($settings)) {
            DB::table('country_payment_settings')->insert($settings);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('country_payment_settings');
    }
};
