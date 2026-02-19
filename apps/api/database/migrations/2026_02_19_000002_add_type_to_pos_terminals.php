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
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->string('code', 20)->change();
            $table->string('type', 20)->default('physical')->after('location_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the old strict POS[0-9]{2} code format constraint
            DB::statement('ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS pos_terminals_code_format');

            // Add a looser constraint: alphanumeric + hyphens, 2-20 chars
            DB::statement("ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_code_format CHECK (code ~ '^[A-Za-z0-9\\-]{2,20}$')");

            // Add partial unique index: only one active web terminal per (company_id, location_id)
            DB::statement('CREATE UNIQUE INDEX pos_terminals_unique_web_per_location ON pos_terminals (company_id, location_id) WHERE type = \'web\' AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS pos_terminals_unique_web_per_location');

            // Restore original code format constraint
            DB::statement('ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS pos_terminals_code_format');
            DB::statement("ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_code_format CHECK (code ~ '^POS[0-9]{2}$')");
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
