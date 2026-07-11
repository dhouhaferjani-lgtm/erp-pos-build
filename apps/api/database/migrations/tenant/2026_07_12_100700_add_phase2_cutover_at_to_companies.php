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
        if (! Schema::hasColumn('companies', 'phase2_cutover_at')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->timestamp('phase2_cutover_at')->nullable();
            });

        }

        DB::table('companies')->whereNull('phase2_cutover_at')->update([
            'phase2_cutover_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'phase2_cutover_at')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('phase2_cutover_at');
            });
        }
    }
};
