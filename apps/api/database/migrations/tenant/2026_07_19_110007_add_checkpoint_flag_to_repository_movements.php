<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('repository_movements', 'recorded_behind_checkpoint')) {
            return;
        }
        Schema::table('repository_movements', function (Blueprint $table): void {
            $table->boolean('recorded_behind_checkpoint')->default(false)->after('recorded_while_frozen');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('repository_movements', 'recorded_behind_checkpoint')) {
            return;
        }
        Schema::table('repository_movements', function (Blueprint $table): void {
            $table->dropColumn('recorded_behind_checkpoint');
        });
    }
};
