<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make partner_id nullable on payments table.
 *
 * POS transactions may not have an associated customer/partner (walk-in customers).
 * The Treasury payment record should still be created for audit trail purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('partner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('partner_id')->nullable(false)->change();
        });
    }
};
