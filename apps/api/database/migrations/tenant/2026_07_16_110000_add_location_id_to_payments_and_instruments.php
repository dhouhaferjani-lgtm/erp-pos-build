<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-location financial dimension. Only payments and instruments gain this
 * nullable origin location; document and allocation dimensions are separate
 * accounting concerns and are intentionally untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'location_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->uuid('location_id')->nullable()->after('repository_id');
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
                $table->index('location_id', 'idx_payments_location');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'location_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->uuid('location_id')->nullable()->after('repository_id');
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
                $table->index('location_id', 'idx_payment_instruments_location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'location_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('idx_payments_location');
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasColumn('payment_instruments', 'location_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('idx_payment_instruments_location');
                $table->dropColumn('location_id');
            });
        }
    }
};
