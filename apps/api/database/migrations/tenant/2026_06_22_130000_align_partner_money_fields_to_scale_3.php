<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'receivable_balance',
        'credit_balance',
        'payable_balance',
        'credit_limit',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE partners ALTER COLUMN {$column} TYPE NUMERIC(15, 3)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE partners ALTER COLUMN {$column} TYPE NUMERIC(15, 4)");
        }
    }
};
