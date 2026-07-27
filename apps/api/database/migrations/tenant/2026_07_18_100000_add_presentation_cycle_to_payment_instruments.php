<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_instruments')
            || Schema::hasColumn('payment_instruments', 'presentation_cycle')) {
            return;
        }

        Schema::table('payment_instruments', function (Blueprint $table): void {
            $table->unsignedInteger('presentation_cycle')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_instruments')
            || ! Schema::hasColumn('payment_instruments', 'presentation_cycle')) {
            return;
        }

        Schema::table('payment_instruments', function (Blueprint $table): void {
            $table->dropColumn('presentation_cycle');
        });
    }
};
