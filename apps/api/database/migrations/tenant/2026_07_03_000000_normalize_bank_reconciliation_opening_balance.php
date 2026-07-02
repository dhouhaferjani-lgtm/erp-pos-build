<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bank_reconciliations', 'opening_balance')) {
            Schema::table('bank_reconciliations', function (Blueprint $table): void {
                $table->decimal('opening_balance', 15, 3)->default(0)->after('statement_date');
            });

            return;
        }

        DB::table('bank_reconciliations')
            ->whereNull('opening_balance')
            ->update(['opening_balance' => '0.000']);

        Schema::table('bank_reconciliations', function (Blueprint $table): void {
            $table->decimal('opening_balance', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bank_reconciliations', 'opening_balance')) {
            return;
        }

        Schema::table('bank_reconciliations', function (Blueprint $table): void {
            $table->decimal('opening_balance', 15, 3)->change();
        });
    }
};
