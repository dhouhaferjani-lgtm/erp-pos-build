<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->uuid('brand_id')->nullable()->after('category_id');
            $t->string('brand_source')->nullable()->after('brand_id');
            $t->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
            $t->index(['tenant_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropForeign(['brand_id']);
            $t->dropIndex(['tenant_id', 'brand_id']);
            $t->dropColumn(['brand_id', 'brand_source']);
        });
    }
};
