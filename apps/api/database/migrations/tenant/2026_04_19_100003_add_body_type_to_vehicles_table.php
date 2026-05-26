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
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('body_type', 32)->nullable()->after('transmission');
        });

        // PostgreSQL supports COMMENT ON COLUMN; SQLite ignores column comments.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN vehicles.partner_id IS 'DEPRECATED - use vehicle_ownership_history for current owner. Column will be dropped in a fast-follow migration.'");
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('body_type');
        });
    }
};
