<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const CONSTRAINTS = [
        'partners_credit_balance_non_negative' => 'credit_balance >= 0',
        'partners_payable_balance_non_negative' => 'payable_balance >= 0',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('UPDATE partners SET credit_balance = 0, balance_updated_at = NULL WHERE credit_balance < 0');
        DB::statement('UPDATE partners SET payable_balance = 0, balance_updated_at = NULL WHERE payable_balance < 0');

        foreach (self::CONSTRAINTS as $name => $condition) {
            DB::statement("ALTER TABLE partners DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE partners ADD CONSTRAINT {$name} CHECK ({$condition})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::CONSTRAINTS) as $name) {
            DB::statement("ALTER TABLE partners DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
