<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_sellable_xor');

        DB::statement(<<<'SQL'
            ALTER TABLE pos_receipt_lines
            ADD CONSTRAINT pos_receipt_lines_sellable_xor CHECK (
                NOT (product_id IS NOT NULL AND composite_item_id IS NOT NULL)
                AND (
                    product_id IS NOT NULL
                    OR composite_item_id IS NOT NULL
                    OR (product_code IS NOT NULL AND product_name IS NOT NULL)
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_sellable_xor');

        DB::statement(<<<'SQL'
            ALTER TABLE pos_receipt_lines
            ADD CONSTRAINT pos_receipt_lines_sellable_xor CHECK (
                (product_id IS NOT NULL AND composite_item_id IS NULL)
                OR (product_id IS NULL AND composite_item_id IS NOT NULL)
            )
        SQL);
    }
};
