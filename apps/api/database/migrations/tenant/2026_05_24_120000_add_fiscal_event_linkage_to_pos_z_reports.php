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
        Schema::table('pos_z_reports', function (Blueprint $table): void {
            $table->binary('canonical_bytes')->nullable();
            $table->char('canonical_bytes_hash', 64)->nullable();
            $table->uuid('fiscal_event_id')->nullable();
            $table->unique('fiscal_event_id', 'pos_z_reports_fiscal_event_id_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE pos_z_reports
                ADD CONSTRAINT pos_z_reports_fiscal_event_id_fk
                FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_z_reports DROP CONSTRAINT IF EXISTS pos_z_reports_fiscal_event_id_fk');
        }

        Schema::table('pos_z_reports', function (Blueprint $table): void {
            $table->dropUnique('pos_z_reports_fiscal_event_id_unique');
            $table->dropColumn(['fiscal_event_id', 'canonical_bytes_hash', 'canonical_bytes']);
        });
    }
};
