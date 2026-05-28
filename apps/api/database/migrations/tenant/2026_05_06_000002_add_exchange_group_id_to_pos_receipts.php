<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds exchange_group_id to pos_receipts.
 *
 * When a receipt is part of an exchange transaction, both the return half and
 * the sale half carry the same exchange_group_id UUID. This value is committed
 * into both receipts' v3 canonical hash payload (spec §3.4 / §5.1) so that a
 * tampering detector can verify the link between the two halves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->uuid('exchange_group_id')->nullable()->after('refund_request_id');
            $table->index('exchange_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropIndex(['exchange_group_id']);
            $table->dropColumn('exchange_group_id');
        });
    }
};
