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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::table('menu_category_items', function (Blueprint $table) {
                $table->uuid('product_id')->nullable()->after('composite_item_id');
            });

            DB::statement('ALTER TABLE menu_category_items ALTER COLUMN composite_item_id DROP NOT NULL');
            DB::statement('ALTER TABLE menu_category_items DROP CONSTRAINT IF EXISTS menu_cat_item_unique');
            DB::statement('DROP INDEX IF EXISTS menu_cat_item_unique');

            Schema::table('menu_category_items', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });

            DB::statement("
                ALTER TABLE menu_category_items ADD CONSTRAINT menu_cat_item_one_sellable
                CHECK (
                    (composite_item_id IS NOT NULL AND product_id IS NULL)
                    OR (composite_item_id IS NULL AND product_id IS NOT NULL)
                )
            ");

            DB::statement('CREATE UNIQUE INDEX menu_cat_composite_unique ON menu_category_items (menu_category_id, composite_item_id) WHERE composite_item_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX menu_cat_product_unique ON menu_category_items (menu_category_id, product_id) WHERE product_id IS NOT NULL');
            DB::statement('CREATE INDEX menu_category_items_product_id_idx ON menu_category_items (product_id) WHERE product_id IS NOT NULL');
        } else {
            // SQLite: recreate table since it doesn't support ALTER COLUMN
            $rows = DB::table('menu_category_items')->get();

            Schema::dropIfExists('menu_category_items');

            Schema::create('menu_category_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('menu_category_id');
                $table->uuid('composite_item_id')->nullable();
                $table->uuid('product_id')->nullable();
                $table->decimal('override_price', 15, 4)->nullable();
                $table->integer('display_order')->default(0);
                $table->boolean('is_available')->default(true);
                $table->timestamps();

                $table->foreign('menu_category_id')->references('id')->on('menu_categories')->cascadeOnDelete();
                $table->foreign('composite_item_id')->references('id')->on('composite_items')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->index(['menu_category_id', 'display_order']);
            });

            // Re-insert existing data
            foreach ($rows as $row) {
                DB::table('menu_category_items')->insert((array) $row);
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS menu_cat_composite_unique');
            DB::statement('DROP INDEX IF EXISTS menu_cat_product_unique');
            DB::statement('DROP INDEX IF EXISTS menu_category_items_product_id_idx');
            DB::statement('ALTER TABLE menu_category_items DROP CONSTRAINT IF EXISTS menu_cat_item_one_sellable');

            DB::table('menu_category_items')->whereNotNull('product_id')->delete();

            Schema::table('menu_category_items', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            });

            DB::statement('ALTER TABLE menu_category_items ALTER COLUMN composite_item_id SET NOT NULL');
            DB::statement('CREATE UNIQUE INDEX menu_cat_item_unique ON menu_category_items (menu_category_id, composite_item_id)');
        } else {
            DB::table('menu_category_items')->whereNotNull('product_id')->delete();

            Schema::table('menu_category_items', function (Blueprint $table) {
                $table->dropColumn('product_id');
            });
        }
    }
};
