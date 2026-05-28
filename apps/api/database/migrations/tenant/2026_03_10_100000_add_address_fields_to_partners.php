<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add address fields to partners for CRM contact management.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('street_address', 255)->nullable()->after('notes');
            $table->string('street_address_2', 255)->nullable()->after('street_address');
            $table->string('city', 100)->nullable()->after('street_address_2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->char('country', 2)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'street_address',
                'street_address_2',
                'city',
                'state',
                'postal_code',
                'country',
            ]);
        });
    }
};
