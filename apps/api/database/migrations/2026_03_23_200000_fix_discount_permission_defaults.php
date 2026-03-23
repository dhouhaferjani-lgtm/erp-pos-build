<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('users')->where('can_discount', false)->update(['can_discount' => true]);
            DB::table('pos_terminals')->where('max_discount_percent', '0.00')->update(['max_discount_percent' => '100.00']);

            return;
        }

        // Enable discounts for admin/super_admin users
        DB::statement("
            UPDATE users
            SET can_discount = true
            WHERE id IN (
                SELECT model_id FROM model_has_roles
                JOIN roles ON roles.id = model_has_roles.role_id
                WHERE roles.name IN ('super_admin', 'admin')
                AND model_has_roles.model_type = 'App\\Modules\\Identity\\Domain\\User'
            )
        ");

        // Fix terminal default: 0% → 100%
        DB::statement('
            UPDATE pos_terminals
            SET max_discount_percent = 100.00
            WHERE max_discount_percent = 0.00
        ');

        // Change column default for future terminals
        DB::statement('ALTER TABLE pos_terminals ALTER COLUMN max_discount_percent SET DEFAULT 100.00');
    }

    public function down(): void
    {
        // Data migration — not reversible
    }
};
