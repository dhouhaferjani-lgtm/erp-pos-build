<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'stock_transfer_lines' => 'stock_transfer_lines_received_within_sent',
        'stock_transfer_line_batch_allocations' => 'stock_transfer_line_batch_allocations_received_within_sent',
    ];

    private const COUNTERS = [
        'quantity_received',
        'quantity_damaged',
        'quantity_written_off',
        'quantity_returned',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            foreach (self::COUNTERS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->decimal($column, 15, 4)->default(0);
                });
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $constraint) {
            $this->addCheck($table, $constraint);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table => $constraint) {
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS '.$constraint);
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            foreach (self::COUNTERS as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }

    private function addCheck(string $table, string $constraint): void
    {
        $existing = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$constraint]);

        if ($existing !== null) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.$table.' ADD CONSTRAINT '.$constraint.' CHECK ('
            .'quantity_received >= 0 AND quantity_damaged >= 0 '
            .'AND quantity_written_off >= 0 AND quantity_returned >= 0 '
            .'AND quantity_received + quantity_damaged + quantity_written_off + quantity_returned <= quantity)'
        );
    }
};
