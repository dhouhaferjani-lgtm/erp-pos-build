<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make loyalty_members polymorphic (partner or contact) and add target_type to programs.
     */
    public function up(): void
    {
        // Add polymorphic columns to loyalty_members
        Schema::table('loyalty_members', function (Blueprint $table) {
            $table->string('loyaltyable_type', 50)->nullable()->after('customer_id');
            $table->uuid('loyaltyable_id')->nullable()->after('loyaltyable_type');

            $table->index(['loyaltyable_type', 'loyaltyable_id']);
        });

        // Migrate existing customer_id data to polymorphic columns
        DB::table('loyalty_members')
            ->whereNotNull('customer_id')
            ->update([
                'loyaltyable_type' => 'partner',
                'loyaltyable_id' => DB::raw('customer_id'),
            ]);

        // Add target_type to loyalty_programs
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->string('target_type', 20)->default('contact')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->dropColumn('target_type');
        });

        Schema::table('loyalty_members', function (Blueprint $table) {
            $table->dropIndex(['loyaltyable_type', 'loyaltyable_id']);
            $table->dropColumn(['loyaltyable_type', 'loyaltyable_id']);
        });
    }
};
