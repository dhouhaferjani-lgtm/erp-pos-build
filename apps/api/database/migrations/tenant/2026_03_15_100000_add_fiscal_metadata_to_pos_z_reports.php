<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_z_reports', function (Blueprint $table) {
            $table->jsonb('receipt_snapshots')->nullable()->after('report_data');
            $table->jsonb('grand_totals')->nullable()->after('receipt_snapshots');
        });
    }

    public function down(): void
    {
        Schema::table('pos_z_reports', function (Blueprint $table) {
            $table->dropColumn(['receipt_snapshots', 'grand_totals']);
        });
    }
};
