<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_images')) {
            $count = DB::table('product_images')->count();
            Log::info('Dropping product_images', ['discarded_rows' => $count]);
            Schema::drop('product_images');
        }
    }

    public function down(): void
    {
        // no-op: table replaced by media_* model; restore via re-seed
    }
};
