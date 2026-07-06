<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            // A location in onboarding mode allows selling below zero while its
            // opening stock is still being counted (resolves to PosStockPolicy::Off
            // regardless of any override or the company-wide policy).
            $table->boolean('onboarding_mode')->default(false)->after('pos_enabled');

            // Per-location override of the company-wide POS stock policy. NULL
            // means inherit the company's `pos_stock_policy`. Stored as a plain
            // string (not FK'd to the enum) so an unrecognized value degrades to
            // "no override" rather than breaking inserts; validated at the
            // request layer via Illuminate\Validation\Rules\Enum.
            $table->string('pos_stock_policy_override', 10)->nullable()->after('onboarding_mode');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_mode', 'pos_stock_policy_override']);
        });
    }
};
