<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            // Cached balance fields - source of truth is still GL
            // These are denormalized for performance (avoid summing GL on every request)

            // For customers: what they owe us (positive = they owe, negative = we owe them)
            $table->decimal('receivable_balance', 15, 4)->default(0)->after('type');

            // For customers: advance payments/credits (what we owe them before invoice)
            $table->decimal('credit_balance', 15, 4)->default(0)->after('receivable_balance');

            // For suppliers: what we owe them
            $table->decimal('payable_balance', 15, 4)->default(0)->after('credit_balance');

            // When balances were last recalculated from GL
            $table->timestamp('balance_updated_at')->nullable()->after('payable_balance');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn([
                'receivable_balance',
                'credit_balance',
                'payable_balance',
                'balance_updated_at',
            ]);
        });
    }
};
