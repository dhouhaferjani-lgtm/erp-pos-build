<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->boolean('is_training_mode')->default(false)->after('is_active');
        });

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->boolean('is_training')->default(false)->after('is_voided');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn('is_training_mode');
        });

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn('is_training');
        });
    }
};
