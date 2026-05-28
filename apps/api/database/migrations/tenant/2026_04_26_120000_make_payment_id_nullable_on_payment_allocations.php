<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make payment_allocations.payment_id nullable.
 *
 * Phase 3 / A2 close-with-tolerance creates a synthetic allocation row that
 * has no payment behind it (the residual is written off, not paid). Modelling
 * this as a nullable payment_id is cleaner than spawning a fake Payment row
 * with amount = 0.
 *
 * Discriminator for "tolerance-only allocation" stays implicit: payment_id IS
 * NULL AND tolerance_writeoff = amount AND tolerance_writeoff > 0. Existing
 * rows are left untouched (none have NULL payment_id today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->uuid('payment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->uuid('payment_id')->nullable(false)->change();
        });
    }
};
