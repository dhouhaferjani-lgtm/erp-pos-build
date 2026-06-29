<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('name');
            $t->string('slug');
            $t->uuid('canonical_brand_id')->nullable();
            $t->uuid('logo_media_id')->nullable();
            $t->string('website_url')->nullable();
            $t->string('country_of_origin', 2)->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'slug']);
            $t->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
