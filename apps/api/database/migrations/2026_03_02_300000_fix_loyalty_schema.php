<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->smallInteger('points_expiry_months')->nullable()->after('currency');
            $table->decimal('welcome_bonus_points', 15, 2)->nullable()->after('points_expiry_months');
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->timestamp('tier_changed_at')->nullable()->after('tier_qualified_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->dropColumn(['description', 'points_expiry_months', 'welcome_bonus_points']);
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->dropColumn('tier_changed_at');
        });
    }
};
