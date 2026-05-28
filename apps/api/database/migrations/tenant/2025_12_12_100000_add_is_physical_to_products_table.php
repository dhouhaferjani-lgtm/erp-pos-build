<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds is_physical column to products table to distinguish
     * physical goods (parts, consumables) from services.
     *
     * This is critical for Tunisia fiscal compliance:
     * - Physical products require delivery notes before invoicing
     * - Services can be invoiced directly from sales orders
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_physical')
                ->default(true)
                ->after('type')
                ->comment('False for services, true for parts/consumables');
        });

        // Set is_physical based on existing type
        // Services are non-physical, everything else is physical
        DB::table('products')
            ->where('type', 'service')
            ->update(['is_physical' => false]);

        DB::table('products')
            ->whereIn('type', ['part', 'consumable'])
            ->update(['is_physical' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_physical');
        });
    }
};
