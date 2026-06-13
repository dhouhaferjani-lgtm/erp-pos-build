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

            // Discarding rows is intentional for Stage 1: there is no production
            // data at this point, and demo tenants are re-seeded via the new
            // media_assets / media_attachments model.  Any real-data migration
            // (back-fill product_images rows into the new model) is deferred to
            // a later stage once production tenants have live data.
            Schema::drop('product_images');
        }
    }

    public function down(): void
    {
        // no-op: table replaced by media_* model; restore via re-seed
    }
};
