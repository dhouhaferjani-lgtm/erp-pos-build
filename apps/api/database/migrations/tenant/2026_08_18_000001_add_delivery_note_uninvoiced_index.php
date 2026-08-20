<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS documents_dn_uninvoiced_idx
            ON documents (company_id, partner_id, document_date)
            WHERE type = 'delivery_note'
              AND status = 'confirmed'
              AND (payload->>'invoiced_at') IS NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS documents_dn_uninvoiced_idx');
    }
};
