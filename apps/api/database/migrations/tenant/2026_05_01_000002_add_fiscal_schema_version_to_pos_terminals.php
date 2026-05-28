<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->smallInteger('fiscal_schema_version')->default(2)->after('current_year');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table): void {
            $table->dropColumn('fiscal_schema_version');
        });
    }
};
