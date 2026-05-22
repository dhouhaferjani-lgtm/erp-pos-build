<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_cash_drawer_operations', function (Blueprint $table): void {
            $table->uuid('approval_id')->nullable()->after('receipt_id');
            $table->uuid('approval_fiscal_event_id')->nullable()->after('approval_id');
            $table->string('approval_scope')->nullable()->after('approval_fiscal_event_id');
            $table->uuid('approval_supervisor_user_id')->nullable()->after('approval_scope');
            $table->string('approval_target_hash', 64)->nullable()->after('approval_supervisor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_cash_drawer_operations', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_id',
                'approval_fiscal_event_id',
                'approval_scope',
                'approval_supervisor_user_id',
                'approval_target_hash',
            ]);
        });
    }
};
