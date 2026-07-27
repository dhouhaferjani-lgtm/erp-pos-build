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
        Schema::table('statement_import_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('matching_window_days')->default(5);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE statement_import_profiles ADD CONSTRAINT statement_import_profiles_matching_window_check CHECK (matching_window_days <= 30)');
        }
    }

    public function down(): void
    {
        Schema::table('statement_import_profiles', function (Blueprint $table): void {
            $table->dropColumn('matching_window_days');
        });
    }
};
