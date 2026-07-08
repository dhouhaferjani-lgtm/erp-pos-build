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
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'max_discount_percent')) {
                $table->decimal('max_discount_percent', 5, 2)->nullable()->after('minimum_margin_override');
            }
        });

        Schema::table('categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('categories', 'max_discount_percent')) {
                $table->decimal('max_discount_percent', 5, 2)->nullable()->after('minimum_margin_override');
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'default_max_discount_percent')) {
                $table->decimal('default_max_discount_percent', 5, 2)->nullable()->after('allow_below_cost_sales');
            }

            if (! Schema::hasColumn('companies', 'discount_floor_mode')) {
                $table->string('discount_floor_mode', 32)->default('Advisory')->after('default_max_discount_percent');
            }

            if (! Schema::hasColumn('companies', 'price_entry_mode')) {
                $table->string('price_entry_mode', 8)->default('Ht')->after('discount_floor_mode');
            }
        });

        $this->addRangeChecks();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'max_discount_percent')) {
                $table->dropColumn('max_discount_percent');
            }
        });

        Schema::table('categories', function (Blueprint $table): void {
            if (Schema::hasColumn('categories', 'max_discount_percent')) {
                $table->dropColumn('max_discount_percent');
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('companies', 'default_max_discount_percent') ? 'default_max_discount_percent' : null,
                Schema::hasColumn('companies', 'discount_floor_mode') ? 'discount_floor_mode' : null,
                Schema::hasColumn('companies', 'price_entry_mode') ? 'price_entry_mode' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function addRangeChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_max_discount_percent_range CHECK (max_discount_percent IS NULL OR max_discount_percent BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_max_discount_percent_range CHECK (max_discount_percent IS NULL OR max_discount_percent BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_default_max_discount_percent_range CHECK (default_max_discount_percent IS NULL OR default_max_discount_percent BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_discount_floor_mode_valid CHECK (discount_floor_mode IN ('Advisory', 'WarnRequiresPermission', 'Block'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_price_entry_mode_valid CHECK (price_entry_mode IN ('Ht', 'Ttc'))");
    }
};
