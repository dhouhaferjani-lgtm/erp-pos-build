<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_results', function (Blueprint $table): void {
            $table->uuid('tracking_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Catalog-accepted rows have no tracking id; they cannot survive a
        // NOT NULL restore - delete them first or the change() fails.
        DB::table('enrichment_results')->whereNull('tracking_id')->delete();

        Schema::table('enrichment_results', function (Blueprint $table): void {
            $table->uuid('tracking_id')->nullable(false)->change();
        });
    }
};
