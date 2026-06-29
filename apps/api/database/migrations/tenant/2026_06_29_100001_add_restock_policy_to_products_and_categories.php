<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: products table has a column named 'type' (not 'product_type'),
        // so ->after() anchor is omitted to avoid runtime errors on PostgreSQL.
        Schema::table('products', fn (Blueprint $t) => $t->string('restock_policy', 32)->nullable());
        Schema::table('categories', fn (Blueprint $t) => $t->string('restock_policy', 32)->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('restock_policy'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('restock_policy'));
    }
};
