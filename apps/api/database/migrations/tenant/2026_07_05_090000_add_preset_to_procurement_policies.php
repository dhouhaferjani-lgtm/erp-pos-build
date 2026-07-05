<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_policies', function (Blueprint $table): void {
            $table->string('preset', 20)->nullable()->after('company_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "ALTER TABLE procurement_policies ADD CONSTRAINT procurement_policies_preset_check CHECK (preset IS NULL OR preset IN ('complet', 'standard', 'leger'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE procurement_policies DROP CONSTRAINT IF EXISTS procurement_policies_preset_check');
        }

        Schema::table('procurement_policies', function (Blueprint $table): void {
            $table->dropColumn('preset');
        });
    }
};
