<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Wave D, Task 15 — expense settlement: `paid_at` records the moment the
     * unpaid-expense AP liability was settled via POST /expenses/{id}/pay
     * (distinct from `payment_date`, which is caller-supplied and may be
     * backdated).
     */
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('is_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
