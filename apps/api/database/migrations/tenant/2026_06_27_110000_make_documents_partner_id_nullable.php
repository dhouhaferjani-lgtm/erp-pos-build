<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make partner_id nullable on documents table.
 *
 * Expense documents do not always have a known vendor in the partner registry
 * (e.g. petty-cash expenses paid to an anonymous supplier). The existing
 * ExpenseService::create() intentionally omits partner_id for this reason.
 * This migration relaxes the NOT NULL constraint so that expense creation
 * succeeds without requiring a partner record.
 *
 * Other document types (quotes, orders, invoices) already set partner_id
 * wherever applicable — making the column nullable does not break them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->uuid('partner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->uuid('partner_id')->nullable(false)->change();
        });
    }
};
