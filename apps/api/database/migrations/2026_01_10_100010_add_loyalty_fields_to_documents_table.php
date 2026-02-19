<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Link to loyalty member
            if (! Schema::hasColumn('documents', 'loyalty_member_id')) {
                $table->uuid('loyalty_member_id')->nullable()->after('vehicle_id');
                $table->foreign('loyalty_member_id')->references('id')->on('loyalty_members')->nullOnDelete();
            }

            // Loyalty discount amount applied
            if (! Schema::hasColumn('documents', 'loyalty_discount_amount')) {
                $table->decimal('loyalty_discount_amount', 15, 2)->nullable()->after('loyalty_member_id');
            }

            // Link to transaction that awarded/deducted points
            if (! Schema::hasColumn('documents', 'loyalty_transaction_id')) {
                $table->uuid('loyalty_transaction_id')->nullable()->after('loyalty_discount_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'loyalty_member_id')) {
                $table->dropForeign(['loyalty_member_id']);
                $table->dropColumn('loyalty_member_id');
            }

            if (Schema::hasColumn('documents', 'loyalty_discount_amount')) {
                $table->dropColumn('loyalty_discount_amount');
            }

            if (Schema::hasColumn('documents', 'loyalty_transaction_id')) {
                $table->dropColumn('loyalty_transaction_id');
            }
        });
    }
};
