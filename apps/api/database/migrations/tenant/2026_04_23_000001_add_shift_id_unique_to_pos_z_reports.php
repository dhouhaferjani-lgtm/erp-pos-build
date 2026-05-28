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
        // Defensive cleanup: if any shift has more than one Z report, keep the earliest and delete the rest.
        // In production this should be zero rows — but we guard against stale dev data.
        $duplicates = DB::table('pos_z_reports')
            ->select('shift_id')
            ->groupBy('shift_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shift_id');

        foreach ($duplicates as $shiftId) {
            $keep = DB::table('pos_z_reports')
                ->where('shift_id', $shiftId)
                ->orderBy('generated_at')
                ->value('id');
            DB::table('pos_z_reports')
                ->where('shift_id', $shiftId)
                ->where('id', '!=', $keep)
                ->delete();
        }

        Schema::table('pos_z_reports', function (Blueprint $table): void {
            $table->unique('shift_id', 'pos_z_reports_shift_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_z_reports', function (Blueprint $table): void {
            $table->dropUnique('pos_z_reports_shift_id_unique');
        });
    }
};
