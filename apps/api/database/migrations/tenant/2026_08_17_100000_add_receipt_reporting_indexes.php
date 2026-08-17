<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS pos_receipts_company_location_posted_at_idx '
            .'ON pos_receipts (company_id, location_id, posted_at DESC)'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS pos_receipts_company_posted_at_production_idx '
            .'ON pos_receipts (company_id, posted_at DESC) '
            .'WHERE training_flag = false'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pos_receipts_company_posted_at_production_idx');
        DB::statement('DROP INDEX IF EXISTS pos_receipts_company_location_posted_at_idx');
    }
};
