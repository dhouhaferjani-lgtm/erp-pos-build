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
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->boolean('blind_count_used')->default(false)->after('variance');
            $table->foreignUuid('manager_override_by')->nullable()->after('blind_count_used')
                ->constrained('users')->restrictOnDelete();
            $table->string('variance_severity', 12)->nullable()->after('manager_override_by');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_variance_severity_enum
                CHECK (variance_severity IS NULL OR variance_severity IN ('info','warning','critical'))");
        }
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropForeign(['manager_override_by']);
            $table->dropColumn(['blind_count_used', 'manager_override_by', 'variance_severity']);
        });
    }
};
