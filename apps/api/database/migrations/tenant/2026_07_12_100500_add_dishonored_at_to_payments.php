<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'dishonored_at')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->timestampTz('dishonored_at')->nullable()->after('reconciled_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'dishonored_at')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropColumn('dishonored_at');
            });
        }
    }
};
