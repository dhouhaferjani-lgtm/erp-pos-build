<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create trigger for PostgreSQL (not SQLite for tests)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('
                CREATE TRIGGER credit_note_allocation_balance_update
                AFTER INSERT OR UPDATE OR DELETE ON credit_note_allocations
                FOR EACH ROW
                EXECUTE FUNCTION update_document_balance_due();
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop trigger for PostgreSQL (not SQLite for tests)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS credit_note_allocation_balance_update ON credit_note_allocations;
            ');
        }
    }
};
