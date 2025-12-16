<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add billing-specific columns to existing plans table
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'price_yearly')) {
                $table->decimal('price_yearly', 10, 2)->nullable()->after('price_monthly');
            }
            if (! Schema::hasColumn('plans', 'trial_days')) {
                $table->unsignedSmallInteger('trial_days')->default(14)->after('currency');
            }
            if (! Schema::hasColumn('plans', 'stripe_monthly_price_id')) {
                $table->string('stripe_monthly_price_id')->nullable();
            }
            if (! Schema::hasColumn('plans', 'stripe_yearly_price_id')) {
                $table->string('stripe_yearly_price_id')->nullable();
            }
            if (! Schema::hasColumn('plans', 'is_public')) {
                $table->boolean('is_public')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn([
                'price_yearly',
                'trial_days',
                'stripe_monthly_price_id',
                'stripe_yearly_price_id',
                'is_public',
            ]);
        });
    }
};
