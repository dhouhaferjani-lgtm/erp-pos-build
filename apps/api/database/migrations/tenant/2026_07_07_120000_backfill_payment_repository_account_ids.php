<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_repositories')
            ->whereNull('account_id')
            ->whereNotNull('gl_account_id')
            ->update(['account_id' => DB::raw('gl_account_id')]);
    }

    public function down(): void
    {
        // No rollback: clearing account_id would make existing Treasury routing unsafe.
    }
};
