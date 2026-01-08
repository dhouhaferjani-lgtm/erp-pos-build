<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('vertical', 50)
                ->default('retail')
                ->after('name');

            $table->jsonb('enabled_extras')
                ->nullable()
                ->default('[]')
                ->after('vertical');

            $table->string('signup_source', 100)
                ->nullable()
                ->after('enabled_extras');

            $table->jsonb('signup_tracking')
                ->nullable()
                ->after('signup_source');

            $table->index('vertical');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['vertical']);
            $table->dropColumn([
                'vertical',
                'enabled_extras',
                'signup_source',
                'signup_tracking',
            ]);
        });
    }
};
