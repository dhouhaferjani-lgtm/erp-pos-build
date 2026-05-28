<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('sent_at');
            $table->timestamp('served_at')->nullable()->after('ready_at');

            $table->foreign('table_id')->references('id')->on('pos_tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropColumn(['ready_at', 'served_at']);
        });
    }
};
