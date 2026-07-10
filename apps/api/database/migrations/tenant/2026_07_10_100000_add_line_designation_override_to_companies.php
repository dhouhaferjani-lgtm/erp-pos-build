<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'line_designation_override_enabled')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('line_designation_override_enabled')
                ->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'line_designation_override_enabled')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('line_designation_override_enabled');
        });
    }
};
